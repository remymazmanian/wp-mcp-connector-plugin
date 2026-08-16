<?php
/**
 * Authentication: Application Passwords (primary) and Bearer tokens (optional).
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the acting user for MCP requests and issues/revokes Bearer tokens.
 *
 * Design notes:
 *
 * - Application Passwords are the primary path and need no code here: core's
 *   own `wp_authenticate_application_password` handles `Authorization: Basic`.
 *   We only assert afterwards that the resulting user really did authenticate
 *   via a header, never via a cookie.
 * - Bearer tokens are opt-in. The secret is never stored: only a SHA-256 hash
 *   is, and comparison is constant time.
 * - Cookie authentication is rejected outright on MCP routes. WordPress already
 *   requires a nonce for cookie-authenticated REST writes, but an MCP endpoint
 *   that silently accepted an admin's browser session would be a CSRF surface
 *   with a very large blast radius, so we close it explicitly.
 */
class WPMCP_Auth {

	/**
	 * Prefix for issued tokens. Makes leaked credentials greppable and lets
	 * secret scanners recognise them.
	 */
	const TOKEN_PREFIX = 'wpmcp_';

	/**
	 * The user resolved from a Bearer token during this request, if any.
	 *
	 * @var int
	 */
	private static $bearer_user_id = 0;

	/**
	 * The scope carried by the Bearer token used on this request.
	 *
	 * Null when the request did not present a token, or when the token has no
	 * scope of its own and simply inherits the site profile.
	 *
	 * @var array{profile:string,tools:string[],label:string}|null
	 */
	private static $current_scope = null;

	/**
	 * Hooks authentication filters.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'determine_current_user', array( $this, 'authenticate_bearer' ), 25 );
		add_filter( 'rest_authentication_errors', array( $this, 'guard_rest_authentication' ), 20 );

		// Local development over plain HTTP: core disables Application Passwords
		// unless the request is SSL or the environment type is 'local'. Sites
		// running Local by Flywheel on http:// hit this, so we surface a filter
		// rather than making people edit core behaviour blindly.
		add_filter( 'wp_is_application_passwords_available', array( $this, 'maybe_allow_app_passwords_locally' ), 5 );
	}

	/**
	 * Whether the current request targets one of this plugin's REST routes.
	 *
	 * @return bool
	 */
	public static function is_mcp_request() {
		$route = '';

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$route = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}

		return false !== strpos( $route, '/' . WPMCP_REST_NAMESPACE . '/' );
	}

	/**
	 * Reads the Authorization header across the various ways servers expose it.
	 *
	 * Apache with CGI/FastCGI strips Authorization unless rewritten, which is why
	 * REDIRECT_HTTP_AUTHORIZATION is checked too.
	 *
	 * @return string Raw header value, or empty string.
	 */
	public static function get_authorization_header() {
		$candidates = array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );

		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
			}
		}

		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();

			foreach ( (array) $headers as $name => $value ) {
				if ( 0 === strcasecmp( $name, 'Authorization' ) ) {
					return trim( (string) $value );
				}
			}
		}

		return '';
	}

	/**
	 * Resolves a Bearer token to a WordPress user.
	 *
	 * Runs on determine_current_user. Returns the incoming value untouched for
	 * every request that is not a Bearer-authenticated MCP call, so this filter
	 * is inert for the rest of the site.
	 *
	 * @param int|false $user_id User ID determined so far.
	 * @return int|false
	 */
	public function authenticate_bearer( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		if ( ! self::is_mcp_request() ) {
			return $user_id;
		}

		$settings     = wpmcp()->settings();
		$allow_static = (bool) $settings->get( 'allow_bearer' );
		$allow_oauth  = (bool) $settings->get( 'oauth_enabled' );

		// Two independent switches feed one header. OAuth access tokens keep
		// working when hand-issued tokens are turned off, and vice versa.
		if ( ! $allow_static && ! $allow_oauth ) {
			return $user_id;
		}

		$header = self::get_authorization_header();

		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return $user_id;
		}

		$token = trim( substr( $header, 7 ) );

		// An OAuth access token is a Bearer credential too, and carries the
		// scope that was consented to at authorization time.
		$oauth = $allow_oauth ? WPMCP_OAuth::resolve_access_token( $token ) : null;

		if ( is_array( $oauth ) ) {
			self::$bearer_user_id = (int) $oauth['user_id'];
			self::$current_scope  = array(
				'profile' => WPMCP_OAuth::scopes()[ $oauth['scope'] ] ?? '',
				'tools'   => array(),
				'label'   => sprintf(
					/* translators: %s: OAuth client name. */
					__( 'OAuth: %s', 'wp-mcp-connector' ),
					isset( WPMCP_OAuth::get_clients()[ $oauth['client_id'] ]['client_name'] )
						? WPMCP_OAuth::get_clients()[ $oauth['client_id'] ]['client_name']
						: $oauth['client_id']
				),
			);

			WPMCP_OAuth::touch_grant( $oauth['grant_id'] );

			return self::$bearer_user_id;
		}

		if ( ! $allow_static ) {
			return $user_id;
		}

		$record = self::find_token( $token );

		if ( is_wp_error( $record ) || null === $record ) {
			return $user_id;
		}

		self::$bearer_user_id = (int) $record['user_id'];

		$scope_profile = isset( $record['scope_profile'] ) ? (string) $record['scope_profile'] : '';
		$scope_tools   = isset( $record['scope_tools'] ) ? (array) $record['scope_tools'] : array();

		if ( '' !== $scope_profile || $scope_tools ) {
			self::$current_scope = array(
				'profile' => $scope_profile,
				'tools'   => $scope_tools,
				'label'   => isset( $record['label'] ) ? (string) $record['label'] : '',
			);
		}

		self::touch_token( $record['id'] );

		return self::$bearer_user_id;
	}

	/**
	 * The scope of the credential used on this request.
	 *
	 * Consumed by WPMCP_Settings::is_tool_enabled(), where it can only ever
	 * narrow what the site profile already allows.
	 *
	 * @return array{profile:string,tools:string[],label:string}|null
	 */
	public static function current_scope() {
		return self::$current_scope;
	}

	/**
	 * Rejects unauthenticated and cookie-authenticated MCP requests.
	 *
	 * @param WP_Error|true|null $result Current authentication result.
	 * @return WP_Error|true|null
	 */
	public function guard_rest_authentication( $result ) {
		if ( ! self::is_mcp_request() ) {
			return $result;
		}

		// An existing error (bad app password, for instance) stands.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_user_logged_in() ) {
			return $result; // Route permission callbacks produce the 401 with a helpful message.
		}

		if ( self::$bearer_user_id > 0 ) {
			return $result;
		}

		// Application Passwords set this global; its absence on a logged-in user
		// means the session came from a cookie.
		if ( empty( $GLOBALS['wp_rest_application_password_status'] ) && ! self::has_basic_auth_header() ) {
			return new WP_Error(
				'wpmcp_cookie_auth_rejected',
				__( 'Browser cookie sessions cannot be used for MCP. Authenticate with an Application Password or a Bearer token.', 'wp-mcp-connector' ),
				array( 'status' => 401 )
			);
		}

		return $result;
	}

	/**
	 * Whether the request carried HTTP Basic credentials.
	 *
	 * @return bool
	 */
	private static function has_basic_auth_header() {
		if ( 0 === stripos( self::get_authorization_header(), 'Basic ' ) ) {
			return true;
		}

		return ! empty( $_SERVER['PHP_AUTH_USER'] );
	}

	/**
	 * Allows Application Passwords on plain HTTP for local environments only.
	 *
	 * @param bool $available Whether core considers app passwords available.
	 * @return bool
	 */
	public function maybe_allow_app_passwords_locally( $available ) {
		if ( $available ) {
			return true;
		}

		if ( 'local' === wp_get_environment_type() || ( defined( 'WP_MCP_ALLOW_INSECURE_AUTH' ) && WP_MCP_ALLOW_INSECURE_AUTH ) ) {
			return true;
		}

		return $available;
	}

	/**
	 * Describes how the current request authenticated. Used by the logger and
	 * by the health endpoint.
	 *
	 * @return string One of 'bearer', 'application-password', 'cookie', 'none'.
	 */
	public static function current_auth_method() {
		if ( self::$bearer_user_id > 0 ) {
			return 'bearer';
		}

		if ( ! empty( $GLOBALS['wp_rest_application_password_status'] ) || self::has_basic_auth_header() ) {
			return 'application-password';
		}

		return is_user_logged_in() ? 'cookie' : 'none';
	}

	/* ---------------------------------------------------------------------
	 * Bearer token storage
	 * ------------------------------------------------------------------ */

	/**
	 * Returns all stored token records (hashes only, never secrets).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_tokens() {
		$tokens = get_option( WPMCP_OPTION_TOKENS, array() );

		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Issues a new Bearer token.
	 *
	 * The plaintext secret is returned exactly once and never persisted. Callers
	 * must show it to the admin immediately or discard it.
	 *
	 * @param int      $user_id       User the token acts as.
	 * @param string   $label         Human label, e.g. "Claude Desktop, laptop".
	 * @param int      $ttl_days      Days until expiry; 0 for no expiry.
	 * @param string   $scope_profile Optional profile this token is limited to. Can only
	 *                                narrow the site profile, never widen it.
	 * @param string[] $scope_tools   Optional explicit tool allowlist for this token.
	 * @return array{token:string,record:array<string,mixed>}|WP_Error
	 */
	public static function issue_token( $user_id, $label, $ttl_days = 0, $scope_profile = '', $scope_tools = array() ) {
		$user = get_user_by( 'id', (int) $user_id );

		if ( ! $user ) {
			return new WP_Error( 'wpmcp_unknown_user', __( 'That user does not exist.', 'wp-mcp-connector' ) );
		}

		$scope_profile = sanitize_key( $scope_profile );

		if ( '' !== $scope_profile && ! array_key_exists( $scope_profile, WPMCP_Settings::profiles() ) ) {
			return new WP_Error( 'wpmcp_bad_scope', __( 'That is not a recognised permission profile.', 'wp-mcp-connector' ) );
		}

		$id     = bin2hex( random_bytes( 6 ) );
		$secret = bin2hex( random_bytes( 24 ) );
		$token  = self::TOKEN_PREFIX . $id . '_' . $secret;

		$record = array(
			'id'            => $id,
			'label'         => sanitize_text_field( $label ),
			'user_id'       => (int) $user_id,
			'hash'          => hash( 'sha256', $secret ),
			'created'       => time(),
			'last_used'     => 0,
			'expires'       => $ttl_days > 0 ? time() + ( (int) $ttl_days * DAY_IN_SECONDS ) : 0,
			'scope_profile' => $scope_profile,
			'scope_tools'   => array_values( array_filter( array_map( 'sanitize_key', (array) $scope_tools ) ) ),
		);

		$tokens   = self::get_tokens();
		$tokens[] = $record;

		update_option( WPMCP_OPTION_TOKENS, $tokens, 'yes' );

		return array(
			'token'  => $token,
			'record' => $record,
		);
	}

	/**
	 * Revokes a token by its public id.
	 *
	 * @param string $id Token id.
	 * @return bool Whether anything was removed.
	 */
	public static function revoke_token( $id ) {
		$tokens = self::get_tokens();
		$kept   = array();
		$found  = false;

		foreach ( $tokens as $record ) {
			if ( isset( $record['id'] ) && hash_equals( (string) $record['id'], (string) $id ) ) {
				$found = true;
				continue;
			}

			$kept[] = $record;
		}

		if ( $found ) {
			update_option( WPMCP_OPTION_TOKENS, $kept, 'yes' );
		}

		return $found;
	}

	/**
	 * Looks up a presented token.
	 *
	 * @param string $token Raw token string.
	 * @return array<string,mixed>|null|WP_Error Record on success, null when unknown.
	 */
	private static function find_token( $token ) {
		if ( 0 !== strpos( $token, self::TOKEN_PREFIX ) ) {
			return null;
		}

		$body  = substr( $token, strlen( self::TOKEN_PREFIX ) );
		$parts = explode( '_', $body, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		list( $id, $secret ) = $parts;
		$presented           = hash( 'sha256', $secret );

		foreach ( self::get_tokens() as $record ) {
			if ( ! isset( $record['id'], $record['hash'] ) ) {
				continue;
			}

			if ( ! hash_equals( (string) $record['id'], (string) $id ) ) {
				continue;
			}

			if ( ! hash_equals( (string) $record['hash'], $presented ) ) {
				return null;
			}

			if ( ! empty( $record['expires'] ) && $record['expires'] < time() ) {
				return null;
			}

			return $record;
		}

		return null;
	}

	/**
	 * Records last-used time for a token, at most once a minute to avoid
	 * writing an autoloaded option on every single call.
	 *
	 * @param string $id Token id.
	 * @return void
	 */
	private static function touch_token( $id ) {
		$tokens  = self::get_tokens();
		$changed = false;

		foreach ( $tokens as $index => $record ) {
			if ( isset( $record['id'] ) && hash_equals( (string) $record['id'], (string) $id ) ) {
				if ( time() - (int) $record['last_used'] > MINUTE_IN_SECONDS ) {
					$tokens[ $index ]['last_used'] = time();
					$changed                       = true;
				}
				break;
			}
		}

		if ( $changed ) {
			update_option( WPMCP_OPTION_TOKENS, $tokens, 'yes' );
		}
	}
}

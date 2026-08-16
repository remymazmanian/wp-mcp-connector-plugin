<?php
/**
 * OAuth 2.1 authorization server.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal OAuth 2.1 authorization server, scoped to this plugin's MCP
 * endpoints and nothing else.
 *
 * Hosted MCP clients (Grok, and increasingly others) will not accept a static
 * credential: the current MCP specification expects a remote server to be an
 * OAuth 2.1 authorization server, advertised through discovery documents. This
 * class provides exactly that, and no more.
 *
 * What it implements:
 *
 *   RFC 8414  authorization server metadata   /.well-known/oauth-authorization-server
 *   RFC 9728  protected resource metadata     /.well-known/oauth-protected-resource
 *   RFC 7636  PKCE, S256 only, mandatory
 *   RFC 7591  dynamic client registration     /mcp-oauth/register
 *             authorization code + refresh    /mcp-oauth/authorize, /mcp-oauth/token
 *
 * Deliberate omissions: no implicit grant, no password grant, no client
 * credentials grant, no `plain` PKCE. OAuth 2.1 removes the first three and the
 * fourth is downgrade bait. Clients are public: no client secret is issued, so
 * there is no shared secret to leak from a hosted client's storage.
 *
 * These endpoints are served outside the REST API on purpose. The consent
 * screen is a browser page authenticated by a WordPress cookie, and the MCP
 * REST routes deliberately reject cookie authentication; keeping the two apart
 * means neither has to weaken for the other.
 */
class WPMCP_OAuth {

	const OPTION_CLIENTS = 'wpmcp_oauth_clients';
	const OPTION_GRANTS  = 'wpmcp_oauth_grants';

	/**
	 * Where issued credentials live.
	 *
	 * Deliberately an option and not a transient. On a site with a persistent
	 * object cache WordPress keeps transients in that cache and never writes them
	 * to the database, so a single wp_cache_flush, a Breeze purge on deploy, or
	 * Redis evicting under memory pressure silently destroys every access and
	 * refresh token and forces every connected client to re-authorize. That looked
	 * exactly like the connector being unreliable. Options survive all of it.
	 */
	const OPTION_TOKENS = 'wpmcp_oauth_tokens';

	const PREFIX_ACCESS  = 'wpmcpat_';
	const PREFIX_REFRESH = 'wpmcpre_';

	const CODE_TTL    = 90;             // Seconds. Long enough for a redirect, short enough to be useless if logged.
	const ACCESS_TTL  = HOUR_IN_SECONDS;
	const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * How long a rotated refresh token keeps replaying its replacement.
	 *
	 * Long enough to cover a dropped response or a client retry, short enough
	 * that a captured token is not broadly reusable.
	 */
	const REFRESH_GRACE = 2 * MINUTE_IN_SECONDS;

	/**
	 * Base path for the browser-facing and machine-facing endpoints.
	 */
	const BASE = 'mcp-oauth';

	/**
	 * Hooks the request handler.
	 *
	 * Runs on init at priority 1 so it lands before plugins that take over the
	 * front end on template_redirect, such as a coming-soon screen. A discovery
	 * document that 503s because the site is not launched yet would make the
	 * whole flow undiscoverable.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'handle_request' ), 1 );
	}

	/**
	 * Routes an incoming request if it targets one of our endpoints.
	 *
	 * @return void
	 */
	public function handle_request() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$path = '/' . trim( $path, '/' );

		// Protected resource metadata may be requested at the bare path or with
		// the resource's own path appended, per RFC 9728. Accept both.
		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			$this->send_json( $this->protected_resource_metadata() );
		}

		if ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) ) {
			$this->send_json( $this->authorization_server_metadata() );
		}

		if ( 0 !== strpos( $path, '/' . self::BASE . '/' ) ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			$this->send_json( array( 'error' => 'temporarily_unavailable' ), 503 );
		}

		switch ( substr( $path, strlen( '/' . self::BASE . '/' ) ) ) {
			case 'authorize':
				$this->handle_authorize();
				break;

			case 'token':
				$this->handle_token();
				break;

			case 'register':
				$this->handle_register();
				break;
		}
	}

	/**
	 * Whether OAuth is switched on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = wpmcp()->settings();

		return (bool) $settings->get( 'enabled' ) && (bool) $settings->get( 'oauth_enabled' );
	}

	/* ---------------------------------------------------------------------
	 * Discovery
	 * ------------------------------------------------------------------ */

	/**
	 * RFC 8414 authorization server metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function authorization_server_metadata() {
		return array(
			'issuer'                                => home_url( '/' ),
			'authorization_endpoint'                => home_url( '/' . self::BASE . '/authorize' ),
			'token_endpoint'                        => home_url( '/' . self::BASE . '/token' ),
			'registration_endpoint'                 => home_url( '/' . self::BASE . '/register' ),
			'scopes_supported'                      => array_keys( self::scopes() ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'service_documentation'                 => home_url( '/' ),
		);
	}

	/**
	 * RFC 9728 protected resource metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function protected_resource_metadata() {
		return array(
			'resource'                 => rest_url( WPMCP_REST_NAMESPACE . '/mcp' ),
			'authorization_servers'    => array( home_url( '/' ) ),
			'scopes_supported'         => array_keys( self::scopes() ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => sprintf(
				/* translators: %s: site name. */
				__( 'WordPress MCP: %s', 'wp-mcp-connector' ),
				get_bloginfo( 'name' )
			),
		);
	}

	/**
	 * The scopes this server understands.
	 *
	 * Each maps onto a permission profile, so an OAuth grant and a manually
	 * issued token are limited by exactly the same machinery.
	 *
	 * @return array<string,string> Scope name => profile slug ('' means site default).
	 */
	public static function scopes() {
		return array(
			'mcp'           => '',
			'mcp:read_only' => 'read_only',
			'mcp:author'    => 'author',
			'mcp:editor'    => 'editor',
			'mcp:admin'     => 'admin',
		);
	}

	/**
	 * Scope breadth, narrowest first. Used to compare two scopes.
	 *
	 * @return string[]
	 */
	private static function scope_rank() {
		return array( 'mcp:read_only', 'mcp:author', 'mcp:editor', 'mcp:admin' );
	}

	/**
	 * Resolves a requested scope string to a single granted scope.
	 *
	 * Most clients read scopes_supported from the discovery document and request
	 * every scope they find. That is a request for the default, not a preference
	 * for the narrowest, so treating it as the latter silently hands out a
	 * read-only connection to a client that asked for everything.
	 *
	 * The rule is therefore:
	 *
	 *   - the site owner sets the default a connection receives;
	 *   - a client asking for exactly one specific scope may narrow below that
	 *     default, because a deliberate request for less is worth honouring;
	 *   - nothing a client asks for can exceed the default.
	 *
	 * @param string $requested Space-delimited scope string.
	 * @return string Granted scope name.
	 */
	public static function resolve_scope( $requested ) {
		$default = (string) wpmcp()->settings()->get( 'oauth_default_scope', 'mcp' );

		if ( ! array_key_exists( $default, self::scopes() ) ) {
			$default = 'mcp';
		}

		$wanted = preg_split( '/[\s+]+/', trim( (string) $requested ) ) ?: array();
		$wanted = array_values( array_intersect( array_filter( $wanted ), array_keys( self::scopes() ) ) );

		// The bare 'mcp' scope expresses no preference, so it is not a choice.
		$specific = array_values( array_diff( $wanted, array( 'mcp' ) ) );

		if ( 1 !== count( $specific ) ) {
			return $default;
		}

		$rank      = self::scope_rank();
		$client_at = array_search( $specific[0], $rank, true );
		$default_at = array_search( $default, $rank, true );

		// A default of 'mcp' means "whatever the site profile allows", which is
		// the widest thing on offer, so any specific request narrows it.
		if ( false === $default_at ) {
			return $specific[0];
		}

		if ( false === $client_at ) {
			return $default;
		}

		return $client_at < $default_at ? $specific[0] : $default;
	}

	/* ---------------------------------------------------------------------
	 * Authorization endpoint
	 * ------------------------------------------------------------------ */

	/**
	 * Handles GET (consent screen) and POST (decision) on /mcp-oauth/authorize.
	 *
	 * @return void
	 */
	private function handle_authorize() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth parameters, validated below; the POST decision carries its own nonce.
		$client_id     = isset( $_REQUEST['client_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client_id'] ) ) : '';
		$redirect_uri  = isset( $_REQUEST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_uri'] ) ) : '';
		$response_type = isset( $_REQUEST['response_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['response_type'] ) ) : '';
		$state         = isset( $_REQUEST['state'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['state'] ) ) : '';
		$challenge     = isset( $_REQUEST['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['code_challenge'] ) ) : '';
		$method        = isset( $_REQUEST['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['code_challenge_method'] ) ) : '';
		$scope         = isset( $_REQUEST['scope'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['scope'] ) ) : 'mcp';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$client = self::get_client( $client_id );

		// Errors involving client_id or redirect_uri must be shown to the user,
		// never redirected: an unvalidated redirect_uri is an open redirect.
		if ( ! $client ) {
			$this->render_error( __( 'Unknown client', 'wp-mcp-connector' ), __( 'This application is not registered with the site. If you are setting up a connector, register it first or enable dynamic client registration.', 'wp-mcp-connector' ) );
		}

		if ( ! self::redirect_uri_allowed( $client, $redirect_uri ) ) {
			$this->render_error(
				__( 'Invalid redirect URI', 'wp-mcp-connector' ),
				sprintf(
					/* translators: %s: list of registered redirect URIs. */
					__( 'That redirect address is not registered for this application. Registered: %s', 'wp-mcp-connector' ),
					implode( ', ', (array) $client['redirect_uris'] )
				)
			);
		}

		// From here on, protocol errors can safely go back to the client.
		if ( 'code' !== $response_type ) {
			$this->redirect_error( $redirect_uri, 'unsupported_response_type', __( 'Only the authorization code flow is supported.', 'wp-mcp-connector' ), $state );
		}

		if ( '' === $challenge || 'S256' !== $method ) {
			$this->redirect_error( $redirect_uri, 'invalid_request', __( 'PKCE with code_challenge_method=S256 is required.', 'wp-mcp-connector' ), $state );
		}

		$granted_scope = self::resolve_scope( $scope );

		// Not logged in: send them through wp-login and come straight back.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->current_url() ) );
			exit;
		}

		$capability = (string) wpmcp()->settings()->get( 'capability', 'edit_posts' );

		if ( ! current_user_can( $capability ) ) {
			$this->render_error(
				__( 'Not permitted', 'wp-mcp-connector' ),
				sprintf(
					/* translators: %s: capability slug. */
					__( 'Your WordPress account does not have the "%s" capability this connector requires. Sign in as a user who does.', 'wp-mcp-connector' ),
					$capability
				)
			);
		}

		$is_decision = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];

		if ( ! $is_decision ) {
			$this->render_consent( $client, $redirect_uri, $state, $challenge, $granted_scope );
		}

		check_admin_referer( 'wpmcp_oauth_consent' );

		if ( empty( $_POST['approve'] ) ) {
			$this->redirect_error( $redirect_uri, 'access_denied', __( 'The user declined the request.', 'wp-mcp-connector' ), $state );
		}

		$code = self::issue_code( $client_id, get_current_user_id(), $granted_scope, $redirect_uri, $challenge );

		$location = add_query_arg(
			array_filter(
				array(
					'code'  => rawurlencode( $code ),
					'state' => '' !== $state ? rawurlencode( $state ) : null,
				)
			),
			$redirect_uri
		);

		// wp_redirect, not wp_safe_redirect: the target is an external client
		// address, already validated against the client's registration.
		wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Renders the consent screen.
	 *
	 * @param array<string,mixed> $client       Client record.
	 * @param string              $redirect_uri Validated redirect URI.
	 * @param string              $state        Client state.
	 * @param string              $challenge    PKCE challenge.
	 * @param string              $scope        Granted scope.
	 * @return void
	 */
	private function render_consent( array $client, $redirect_uri, $state, $challenge, $scope ) {
		$profiles = WPMCP_Settings::profiles();
		$profile  = self::scopes()[ $scope ];
		$user     = wp_get_current_user();
		$registry = wpmcp()->registry();

		$effective = $profile ? $profile : (string) wpmcp()->settings()->get( 'profile' );
		$tools     = array();

		foreach ( $registry->all() as $tool ) {
			if ( WPMCP_Settings::profile_allows( $tool, $effective, (array) wpmcp()->settings()->get( 'enabled_tools', array() ) ) && current_user_can( $tool['capability'] ) ) {
				$tools[] = $tool;
			}
		}

		$destructive = array_filter(
			$tools,
			static function ( $tool ) {
				return ! empty( $tool['annotations']['destructiveHint'] );
			}
		);

		$this->render_page(
			__( 'Authorize connection', 'wp-mcp-connector' ),
			function () use ( $client, $redirect_uri, $state, $challenge, $scope, $profiles, $effective, $tools, $destructive, $user ) {
				?>
				<h1><?php esc_html_e( 'Authorize connection', 'wp-mcp-connector' ); ?></h1>

				<p class="lead">
					<?php
					printf(
						/* translators: 1: client application name, 2: site name. */
						esc_html__( '%1$s is asking to manage %2$s on your behalf.', 'wp-mcp-connector' ),
						'<strong>' . esc_html( $client['client_name'] ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						'<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					);
					?>
				</p>

				<dl class="facts">
					<dt><?php esc_html_e( 'Signed in as', 'wp-mcp-connector' ); ?></dt>
					<dd><?php echo esc_html( $user->user_login ); ?> (<?php echo esc_html( implode( ', ', (array) $user->roles ) ); ?>)</dd>

					<dt><?php esc_html_e( 'Access level', 'wp-mcp-connector' ); ?></dt>
					<dd>
						<?php echo esc_html( isset( $profiles[ $effective ] ) ? $profiles[ $effective ]['label'] : $effective ); ?>
						<span class="muted">(<?php echo esc_html( $scope ); ?>)</span>
					</dd>

					<dt><?php esc_html_e( 'Tools granted', 'wp-mcp-connector' ); ?></dt>
					<dd><?php echo esc_html( count( $tools ) ); ?></dd>

					<dt><?php esc_html_e( 'Returns to', 'wp-mcp-connector' ); ?></dt>
					<dd class="mono"><?php echo esc_html( $redirect_uri ); ?></dd>
				</dl>

				<?php if ( $destructive ) : ?>
					<div class="warn">
						<strong><?php esc_html_e( 'This grant includes tools that change or remove things permanently:', 'wp-mcp-connector' ); ?></strong>
						<ul>
							<?php foreach ( $destructive as $tool ) : ?>
								<li><code><?php echo esc_html( $tool['name'] ); ?></code> &mdash; <?php echo esc_html( $tool['title'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<details>
					<summary><?php esc_html_e( 'Show all tools this grant allows', 'wp-mcp-connector' ); ?></summary>
					<ul class="tools">
						<?php foreach ( $tools as $tool ) : ?>
							<li><code><?php echo esc_html( $tool['name'] ); ?></code> <span class="muted"><?php echo esc_html( $tool['title'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				</details>

				<p class="muted small">
					<?php esc_html_e( 'Approving creates a token that acts as you. It expires after 30 days of disuse and can be revoked at any time under Settings, MCP Connector. Only approve if you started this yourself.', 'wp-mcp-connector' ); ?>
				</p>

				<form method="post" action="">
					<?php wp_nonce_field( 'wpmcp_oauth_consent' ); ?>
					<input type="hidden" name="client_id" value="<?php echo esc_attr( $client['client_id'] ); ?>" />
					<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>" />
					<input type="hidden" name="response_type" value="code" />
					<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" />
					<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $challenge ); ?>" />
					<input type="hidden" name="code_challenge_method" value="S256" />
					<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>" />

					<div class="actions">
						<button type="submit" name="approve" value="1" class="primary"><?php esc_html_e( 'Approve', 'wp-mcp-connector' ); ?></button>
						<button type="submit" name="deny" value="1" class="secondary"><?php esc_html_e( 'Cancel', 'wp-mcp-connector' ); ?></button>
					</div>
				</form>
				<?php
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Token endpoint
	 * ------------------------------------------------------------------ */

	/**
	 * Handles POST /mcp-oauth/token.
	 *
	 * @return void
	 */
	private function handle_token() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			$this->send_json( array( 'error' => 'invalid_request' ), 405 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Token endpoint is machine-to-machine; PKCE and the single-use code are the protection.
		$grant_type = isset( $_POST['grant_type'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_type'] ) ) : '';

		if ( 'authorization_code' === $grant_type ) {
			$this->grant_authorization_code();
		}

		if ( 'refresh_token' === $grant_type ) {
			$this->grant_refresh_token();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->send_json(
			array(
				'error'             => 'unsupported_grant_type',
				'error_description' => 'Supported grant types are authorization_code and refresh_token.',
			),
			400
		);
	}

	/**
	 * Exchanges an authorization code for tokens.
	 *
	 * @return void
	 */
	private function grant_authorization_code() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$code          = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_uri'] ) ) : '';
		$verifier      = isset( $_POST['code_verifier'] ) ? sanitize_text_field( wp_unslash( $_POST['code_verifier'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$key    = self::code_key( $code );
		$record = self::store_get( $key );

		// Single use: consume it before doing anything else, so a replayed code
		// cannot race a second exchange.
		self::store_delete( $key );

		if ( ! is_array( $record ) ) {
			$this->send_json(
				array(
					'error'             => 'invalid_grant',
					'error_description' => 'That authorization code is unknown, already used, or expired.',
				),
				400
			);
		}

		if ( ! hash_equals( (string) $record['client_id'], $client_id ) ) {
			$this->send_json( array( 'error' => 'invalid_grant', 'error_description' => 'The code was issued to a different client.' ), 400 );
		}

		if ( ! hash_equals( (string) $record['redirect_uri'], $redirect_uri ) ) {
			$this->send_json( array( 'error' => 'invalid_grant', 'error_description' => 'redirect_uri does not match the one used to obtain the code.' ), 400 );
		}

		// PKCE: the verifier must hash to the challenge captured at authorize time.
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		if ( '' === $verifier || ! hash_equals( (string) $record['code_challenge'], $computed ) ) {
			$this->send_json( array( 'error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.' ), 400 );
		}

		$grant_id = self::create_grant( $record['client_id'], (int) $record['user_id'], (string) $record['scope'] );

		$this->send_json( self::issue_tokens( $grant_id, (int) $record['user_id'], (string) $record['scope'], (string) $record['client_id'] ) );
	}

	/**
	 * Exchanges a refresh token for a new pair.
	 *
	 * @return void
	 */
	private function grant_refresh_token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$refresh = isset( $_POST['refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['refresh_token'] ) ) : '';

		$key    = self::token_key( self::PREFIX_REFRESH, $refresh );
		$record = self::store_get( $key );

		if ( ! is_array( $record ) || ! self::get_grant( $record['grant_id'] ) ) {
			$this->send_json(
				array(
					'error'             => 'invalid_grant',
					'error_description' => 'That refresh token is unknown, already used, expired, or its grant was revoked.',
				),
				400
			);
		}

		// Refresh tokens rotate, so a stolen one is usable at most once. The
		// naive version of that deletes the presented token before issuing the
		// replacement, which quietly bricks a connection whenever the response
		// fails to arrive: the client still holds a token the server has already
		// destroyed, and the only way back is a full re-authorization the user
		// has to notice and perform.
		//
		// So a rotated token is retired rather than deleted, keeping the pair it
		// produced for a short grace window. Presenting it again inside that
		// window replays the same replacement instead of failing, which survives
		// a dropped response without widening the reuse window meaningfully.
		if ( isset( $record['rotated_to'] ) && is_array( $record['rotated_to'] ) ) {
			$this->send_json( $record['rotated_to'] );
		}

		$issued = self::issue_tokens( $record['grant_id'], (int) $record['user_id'], (string) $record['scope'], (string) $record['client_id'] );

		$record['rotated_to'] = $issued;
		self::store_put( $key, $record, self::REFRESH_GRACE );

		$this->send_json( $issued );
	}

	/* ---------------------------------------------------------------------
	 * Dynamic client registration
	 * ------------------------------------------------------------------ */

	/**
	 * Handles POST /mcp-oauth/register (RFC 7591).
	 *
	 * @return void
	 */
	private function handle_register() {
		if ( ! wpmcp()->settings()->get( 'oauth_dynamic_registration' ) ) {
			$this->send_json(
				array(
					'error'             => 'access_denied',
					'error_description' => 'Dynamic client registration is disabled on this site. An administrator can register your client by hand under Settings, MCP Connector.',
				),
				403
			);
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			$this->send_json( array( 'error' => 'invalid_request' ), 405 );
		}

		$body = json_decode( file_get_contents( 'php://input' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_array( $body ) ) {
			$this->send_json( array( 'error' => 'invalid_client_metadata', 'error_description' => 'Body must be a JSON object.' ), 400 );
		}

		$redirect_uris = isset( $body['redirect_uris'] ) ? (array) $body['redirect_uris'] : array();
		$clean         = array();

		foreach ( $redirect_uris as $uri ) {
			$uri = esc_url_raw( (string) $uri );

			if ( self::is_valid_redirect_uri( $uri ) ) {
				$clean[] = $uri;
			}
		}

		if ( ! $clean ) {
			$this->send_json(
				array(
					'error'             => 'invalid_redirect_uri',
					'error_description' => 'At least one https redirect URI is required. http is accepted only for 127.0.0.1 and localhost.',
				),
				400
			);
		}

		$name = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : __( 'Unnamed client', 'wp-mcp-connector' );

		$client = self::register_client( $name, $clean, true );

		$this->send_json(
			array(
				'client_id'                  => $client['client_id'],
				'client_id_issued_at'        => $client['created'],
				'client_name'                => $client['client_name'],
				'redirect_uris'              => $client['redirect_uris'],
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			),
			201
		);
	}

	/* ---------------------------------------------------------------------
	 * Clients
	 * ------------------------------------------------------------------ */

	/**
	 * All registered clients.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_clients() {
		$clients = get_option( self::OPTION_CLIENTS, array() );

		return is_array( $clients ) ? $clients : array();
	}

	/**
	 * Fetches one client.
	 *
	 * @param string $client_id Client id.
	 * @return array<string,mixed>|null
	 */
	public static function get_client( $client_id ) {
		$clients = self::get_clients();

		return isset( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
	}

	/**
	 * Registers a client.
	 *
	 * @param string   $name          Display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 * @param bool     $dynamic       Whether it self-registered.
	 * @return array<string,mixed>
	 */
	public static function register_client( $name, array $redirect_uris, $dynamic = false ) {
		$client = array(
			'client_id'     => 'wpmcp-' . bin2hex( random_bytes( 8 ) ),
			'client_name'   => $name,
			'redirect_uris' => array_values( $redirect_uris ),
			'created'       => time(),
			'dynamic'       => (bool) $dynamic,
		);

		$clients                            = self::get_clients();
		$clients[ $client['client_id'] ]    = $client;

		update_option( self::OPTION_CLIENTS, $clients, 'no' );

		return $client;
	}

	/**
	 * Removes a client and every grant issued to it.
	 *
	 * @param string $client_id Client id.
	 * @return bool
	 */
	public static function delete_client( $client_id ) {
		$clients = self::get_clients();

		if ( ! isset( $clients[ $client_id ] ) ) {
			return false;
		}

		unset( $clients[ $client_id ] );
		update_option( self::OPTION_CLIENTS, $clients, 'no' );

		foreach ( self::get_grants() as $grant ) {
			if ( $grant['client_id'] === $client_id ) {
				self::revoke_grant( $grant['id'] );
			}
		}

		return true;
	}

	/**
	 * Whether a redirect URI is acceptable for registration.
	 *
	 * HTTPS everywhere, except loopback, which OAuth 2.1 permits for native
	 * clients and which local development needs.
	 *
	 * @param string $uri Candidate URI.
	 * @return bool
	 */
	public static function is_valid_redirect_uri( $uri ) {
		$parts = wp_parse_url( $uri );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! empty( $parts['fragment'] ) ) {
			return false;
		}

		if ( 'https' === $parts['scheme'] ) {
			return true;
		}

		return 'http' === $parts['scheme'] && in_array( $parts['host'], array( '127.0.0.1', 'localhost', '[::1]' ), true );
	}

	/**
	 * Exact-match check of a redirect URI against a client's registration.
	 *
	 * Exact match only: prefix matching is how open redirects get built.
	 *
	 * @param array<string,mixed> $client       Client record.
	 * @param string              $redirect_uri Candidate.
	 * @return bool
	 */
	public static function redirect_uri_allowed( array $client, $redirect_uri ) {
		if ( '' === $redirect_uri ) {
			return false;
		}

		foreach ( (array) $client['redirect_uris'] as $registered ) {
			if ( hash_equals( (string) $registered, $redirect_uri ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Grants and tokens
	 * ------------------------------------------------------------------ */

	/**
	 * All grants.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_grants() {
		$grants = get_option( self::OPTION_GRANTS, array() );

		return is_array( $grants ) ? $grants : array();
	}

	/**
	 * Fetches one grant.
	 *
	 * @param string $grant_id Grant id.
	 * @return array<string,mixed>|null
	 */
	public static function get_grant( $grant_id ) {
		$grants = self::get_grants();

		return isset( $grants[ $grant_id ] ) ? $grants[ $grant_id ] : null;
	}

	/**
	 * Creates (or refreshes) the grant record for a client/user/scope triple.
	 *
	 * Re-authorizing the same client at the same scope updates the existing
	 * grant rather than accumulating duplicates in the admin list.
	 *
	 * @param string $client_id Client id.
	 * @param int    $user_id   User id.
	 * @param string $scope     Granted scope.
	 * @return string Grant id.
	 */
	public static function create_grant( $client_id, $user_id, $scope ) {
		$grants = self::get_grants();

		foreach ( $grants as $id => $grant ) {
			if ( $grant['client_id'] === $client_id && (int) $grant['user_id'] === (int) $user_id && $grant['scope'] === $scope ) {
				$grants[ $id ]['last_used'] = time();
				update_option( self::OPTION_GRANTS, $grants, 'no' );

				return $id;
			}
		}

		$client = self::get_client( $client_id );
		$id     = bin2hex( random_bytes( 8 ) );

		$grants[ $id ] = array(
			'id'          => $id,
			'client_id'   => $client_id,
			'client_name' => $client ? $client['client_name'] : $client_id,
			'user_id'     => (int) $user_id,
			'scope'       => $scope,
			'created'     => time(),
			'last_used'   => time(),
		);

		update_option( self::OPTION_GRANTS, $grants, 'no' );

		return $id;
	}

	/**
	 * Revokes a grant. Its access and refresh tokens stop working immediately,
	 * because every token check re-reads the grant.
	 *
	 * @param string $grant_id Grant id.
	 * @return bool
	 */
	public static function revoke_grant( $grant_id ) {
		$grants = self::get_grants();

		if ( ! isset( $grants[ $grant_id ] ) ) {
			return false;
		}

		unset( $grants[ $grant_id ] );
		update_option( self::OPTION_GRANTS, $grants, 'no' );

		return true;
	}

	/**
	 * Issues an access token and a refresh token.
	 *
	 * @param string $grant_id  Grant id.
	 * @param int    $user_id   User id.
	 * @param string $scope     Granted scope.
	 * @param string $client_id Client id.
	 * @return array<string,mixed> Token response body.
	 */
	private static function issue_tokens( $grant_id, $user_id, $scope, $client_id ) {
		$access  = self::PREFIX_ACCESS . bin2hex( random_bytes( 24 ) );
		$refresh = self::PREFIX_REFRESH . bin2hex( random_bytes( 24 ) );

		$payload = array(
			'grant_id'  => $grant_id,
			'user_id'   => (int) $user_id,
			'scope'     => $scope,
			'client_id' => $client_id,
		);

		self::store_put( self::token_key( self::PREFIX_ACCESS, $access ), $payload, self::ACCESS_TTL );
		self::store_put( self::token_key( self::PREFIX_REFRESH, $refresh ), $payload, self::REFRESH_TTL );

		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		);
	}

	/**
	 * Resolves an access token presented as a Bearer credential.
	 *
	 * Called from WPMCP_Auth. Returns null for anything that is not a live
	 * access token belonging to a grant that still exists.
	 *
	 * @param string $token Presented token.
	 * @return array<string,mixed>|null
	 */
	public static function resolve_access_token( $token ) {
		if ( 0 !== strpos( $token, self::PREFIX_ACCESS ) ) {
			return null;
		}

		$record = self::store_get( self::token_key( self::PREFIX_ACCESS, $token ) );

		if ( ! is_array( $record ) ) {
			return null;
		}

		$grant = self::get_grant( $record['grant_id'] );

		if ( ! $grant ) {
			return null; // Revoked while the access token was still within its hour.
		}

		// The grant, not the token, is the source of truth for scope. Changing a
		// connection's access level therefore takes effect on the next call
		// rather than whenever the client happens to refresh an hour later.
		$record['scope'] = $grant['scope'];

		return $record;
	}

	/**
	 * Changes the scope of an existing grant.
	 *
	 * @param string $grant_id Grant id.
	 * @param string $scope    New scope name.
	 * @return bool
	 */
	public static function update_grant_scope( $grant_id, $scope ) {
		$grants = self::get_grants();

		if ( ! isset( $grants[ $grant_id ] ) || ! array_key_exists( $scope, self::scopes() ) ) {
			return false;
		}

		$grants[ $grant_id ]['scope'] = $scope;
		update_option( self::OPTION_GRANTS, $grants, 'no' );

		return true;
	}

	/**
	 * Records that a grant was used, at most once a minute.
	 *
	 * @param string $grant_id Grant id.
	 * @return void
	 */
	public static function touch_grant( $grant_id ) {
		$grants = self::get_grants();

		if ( isset( $grants[ $grant_id ] ) && time() - (int) $grants[ $grant_id ]['last_used'] > MINUTE_IN_SECONDS ) {
			$grants[ $grant_id ]['last_used'] = time();
			update_option( self::OPTION_GRANTS, $grants, 'no' );
		}
	}

	/**
	 * Issues a single-use authorization code.
	 *
	 * @param string $client_id    Client id.
	 * @param int    $user_id      User id.
	 * @param string $scope        Granted scope.
	 * @param string $redirect_uri Redirect URI used.
	 * @param string $challenge    PKCE challenge.
	 * @return string The code.
	 */
	private static function issue_code( $client_id, $user_id, $scope, $redirect_uri, $challenge ) {
		$code = bin2hex( random_bytes( 24 ) );

		self::store_put(
			self::code_key( $code ),
			array(
				'client_id'      => $client_id,
				'user_id'        => (int) $user_id,
				'scope'          => $scope,
				'redirect_uri'   => $redirect_uri,
				'code_challenge' => $challenge,
			),
			self::CODE_TTL
		);

		return $code;
	}

	/**
	 * Cache key for a code. Only the hash is stored, never the code itself.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function code_key( $code ) {
		return 'wpmcp_oac_' . hash( 'sha256', (string) $code );
	}

	/**
	 * Cache key for a token.
	 *
	 * @param string $prefix Token prefix.
	 * @param string $token  Token.
	 * @return string
	 */
	private static function token_key( $prefix, $token ) {
		return 'wpmcp_o' . substr( $prefix, 5, 2 ) . '_' . hash( 'sha256', (string) $token );
	}

	/* ---------------------------------------------------------------------
	 * Output helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Sends a JSON response and stops.
	 *
	 * @param array<string,mixed> $data   Body.
	 * @param int                 $status HTTP status.
	 * @return never
	 */
	private function send_json( array $data, $status = 200 ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		// Discovery documents are meant to be fetched cross-origin by clients.
		header( 'Access-Control-Allow-Origin: *' );

		echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Redirects an OAuth error back to the client.
	 *
	 * @param string $redirect_uri Validated redirect URI.
	 * @param string $error        Error code.
	 * @param string $description  Human description.
	 * @param string $state        Client state.
	 * @return never
	 */
	private function redirect_error( $redirect_uri, $error, $description, $state ) {
		$location = add_query_arg(
			array_filter(
				array(
					'error'             => rawurlencode( $error ),
					'error_description' => rawurlencode( $description ),
					'state'             => '' !== $state ? rawurlencode( $state ) : null,
				)
			),
			$redirect_uri
		);

		wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Renders a standalone error page.
	 *
	 * @param string $title   Heading.
	 * @param string $message Body.
	 * @return never
	 */
	private function render_error( $title, $message ) {
		status_header( 400 );

		$this->render_page(
			$title,
			function () use ( $title, $message ) {
				?>
				<h1><?php echo esc_html( $title ); ?></h1>
				<p class="lead"><?php echo esc_html( $message ); ?></p>
				<p class="muted small"><?php esc_html_e( 'Nothing was authorized. You can close this window.', 'wp-mcp-connector' ); ?></p>
				<?php
			}
		);
	}

	/**
	 * Renders a minimal standalone page.
	 *
	 * Self-contained rather than themed: the consent screen must look the same
	 * and behave the same whatever the active theme does, and it must render on
	 * a site whose front end is otherwise behind a coming-soon page.
	 *
	 * @param string   $title Page title.
	 * @param callable $body  Renders the body.
	 * @return never
	 */
	private function render_page( $title, callable $body ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		?>
<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' — ' . get_bloginfo( 'name' ) ); ?></title>
	<style>
		:root { color-scheme: light dark; --bg:#f6f7f7; --card:#fff; --fg:#1e1e1e; --muted:#646970; --line:#dcdcde; --accent:#2271b1; --warn:#8a2424; --warnbg:#fcf0f1; }
		@media (prefers-color-scheme: dark) {
			:root { --bg:#12151a; --card:#1b1f26; --fg:#e6e8eb; --muted:#9ba1a8; --line:#2c323b; --accent:#5aa2e0; --warn:#f0a3a3; --warnbg:#2a1d1e; }
		}
		* { box-sizing: border-box; }
		body { margin:0; padding:32px 16px; background:var(--bg); color:var(--fg);
		       font:16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
		.card { max-width:560px; margin:0 auto; background:var(--card); border:1px solid var(--line);
		        border-radius:12px; padding:28px; }
		h1 { margin:0 0 12px; font-size:22px; line-height:1.25; }
		.lead { margin:0 0 20px; }
		dl.facts { display:grid; grid-template-columns:auto 1fr; gap:8px 16px; margin:0 0 20px;
		           padding:16px; background:var(--bg); border-radius:8px; }
		dl.facts dt { color:var(--muted); font-size:14px; }
		dl.facts dd { margin:0; font-size:14px; }
		.mono, code { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:13px; word-break:break-all; }
		.muted { color:var(--muted); }
		.small { font-size:13px; }
		.warn { background:var(--warnbg); color:var(--warn); border-radius:8px; padding:14px 16px; margin:0 0 20px; font-size:14px; }
		.warn ul { margin:8px 0 0; padding-left:20px; }
		details { margin:0 0 20px; font-size:14px; }
		summary { cursor:pointer; color:var(--accent); }
		ul.tools { list-style:none; margin:12px 0 0; padding:0; }
		ul.tools li { padding:3px 0; }
		.actions { display:flex; gap:10px; margin-top:8px; }
		button { font:inherit; font-size:15px; padding:10px 20px; border-radius:6px; cursor:pointer; border:1px solid var(--line); }
		button.primary { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
		button.secondary { background:transparent; color:var(--fg); }
	</style>
</head>
<body>
	<div class="card">
		<?php $body(); ?>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * The current request URL, for the post-login return trip.
	 *
	 * @return string
	 */
	private function current_url() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return home_url( $path );
	}

	/* ---------------------------------------------------------------------
	 * Durable credential store
	 *
	 * A small option holding hashed keys to payloads with their own expiry.
	 * Everything here outlives a cache flush, which transients on this site
	 * do not. Expired rows are collected on write, so the option cannot grow
	 * without bound and no scheduled task is needed.
	 * ------------------------------------------------------------------ */

	/**
	 * Reads a stored credential, treating an expired one as absent.
	 *
	 * @param string $key Storage key.
	 * @return mixed|false
	 */
	private static function store_get( $key ) {
		$all = get_option( self::OPTION_TOKENS, array() );

		if ( ! is_array( $all ) || ! isset( $all[ $key ] ) ) {
			return false;
		}

		$row = $all[ $key ];

		if ( ! isset( $row['expires'], $row['value'] ) || $row['expires'] < time() ) {
			return false;
		}

		return $row['value'];
	}

	/**
	 * Writes a credential with an expiry.
	 *
	 * @param string $key   Storage key.
	 * @param mixed  $value Payload.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	private static function store_put( $key, $value, $ttl ) {
		$all = get_option( self::OPTION_TOKENS, array() );
		$all = is_array( $all ) ? $all : array();
		$now = time();

		foreach ( $all as $existing => $row ) {
			if ( ! isset( $row['expires'] ) || $row['expires'] < $now ) {
				unset( $all[ $existing ] );
			}
		}

		$all[ $key ] = array(
			'value'   => $value,
			'expires' => $now + (int) $ttl,
		);

		// Never autoloaded: this is read on MCP requests only, and can hold a
		// month of refresh tokens.
		update_option( self::OPTION_TOKENS, $all, 'no' );
	}

	/**
	 * Removes a credential.
	 *
	 * @param string $key Storage key.
	 * @return void
	 */
	private static function store_delete( $key ) {
		$all = get_option( self::OPTION_TOKENS, array() );

		if ( is_array( $all ) && isset( $all[ $key ] ) ) {
			unset( $all[ $key ] );
			update_option( self::OPTION_TOKENS, $all, 'no' );
		}
	}
}

<?php
/**
 * Settings accessor and permission profiles.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, validates and exposes plugin settings.
 *
 * Everything security-relevant funnels through here, so that the answer to
 * "is this tool allowed right now?" lives in exactly one place.
 */
class WPMCP_Settings {

	/**
	 * Cached settings array.
	 *
	 * @var array<string,mixed>|null
	 */
	private $cache = null;

	/**
	 * Default settings.
	 *
	 * Deliberately conservative: the server is off until an admin turns it on,
	 * and the starting profile can read the site and draft content but cannot
	 * publish, delete, upload or touch options.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'              => false,
			'profile'              => 'author',
			'enabled_tools'        => array(),
			'capability'           => 'edit_posts',
			'allow_app_passwords'  => true,
			'allow_bearer'         => false,
			'oauth_enabled'        => false,
			'oauth_dynamic_registration' => true,
			'oauth_default_scope'  => 'mcp',
			'sse_enabled'          => true,
			'sse_max_duration'     => 60,
			'rate_limit_requests'  => 120,
			'rate_limit_window'    => 60,
			'max_upload_bytes'     => 8 * MB_IN_BYTES,
			'allowed_option_keys'  => array( 'blogname', 'blogdescription', 'posts_per_page', 'date_format', 'time_format', 'start_of_week' ),
			'allowed_media_hosts'  => array(),
			'log_enabled'          => true,
			'require_https'        => true,
		);
	}

	/**
	 * Permission profiles.
	 *
	 * A profile is a named bundle of tools. `custom` means "use enabled_tools
	 * verbatim". Profiles are additive going down the list.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function profiles() {
		return array(
			'read_only' => array(
				'label'       => __( 'Read only', 'wp-mcp-connector' ),
				'description' => __( 'Inspect content, media, comments, plugins and themes. Nothing is ever written.', 'wp-mcp-connector' ),
			),
			'author'    => array(
				'label'       => __( 'Author', 'wp-mcp-connector' ),
				'description' => __( 'Everything in Read only, plus creating and updating posts, pages, terms and media. No deleting, no publishing controls beyond the user\'s own capabilities.', 'wp-mcp-connector' ),
			),
			'editor'    => array(
				'label'       => __( 'Editor', 'wp-mcp-connector' ),
				'description' => __( 'Everything in Author, plus trashing content, moderating comments and replying to them.', 'wp-mcp-connector' ),
			),
			'admin'     => array(
				'label'       => __( 'Administrator', 'wp-mcp-connector' ),
				'description' => __( 'Every tool, including permanent deletion, option writes and the emulated CLI. Grant only to a dedicated account you control.', 'wp-mcp-connector' ),
			),
			'custom'    => array(
				'label'       => __( 'Custom', 'wp-mcp-connector' ),
				'description' => __( 'Exactly the tools ticked below, and nothing else.', 'wp-mcp-connector' ),
			),
		);
	}

	/**
	 * Returns all settings, merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( WPMCP_OPTION_SETTINGS, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		/**
		 * Filters the effective plugin settings.
		 *
		 * Useful for pinning configuration in wp-config or an mu-plugin so that
		 * it cannot be loosened from the admin screen.
		 *
		 * @param array<string,mixed> $settings Merged settings.
		 */
		$this->cache = apply_filters( 'wpmcp_settings', wp_parse_args( $stored, self::defaults() ) );

		return $this->cache;
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persists a settings array after sanitising it.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed> The sanitised, saved settings.
	 */
	public function save( array $input ) {
		$clean = $this->sanitize( $input );

		update_option( WPMCP_OPTION_SETTINGS, $clean, 'yes' );
		$this->cache = null;

		return $clean;
	}

	/**
	 * Sanitises a settings payload.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ) {
		$defaults = self::defaults();
		$clean    = array();

		$clean['enabled']             = ! empty( $input['enabled'] );
		$clean['allow_app_passwords'] = ! empty( $input['allow_app_passwords'] );
		$clean['allow_bearer']        = ! empty( $input['allow_bearer'] );
		$clean['oauth_enabled']       = ! empty( $input['oauth_enabled'] );
		$clean['oauth_dynamic_registration'] = ! empty( $input['oauth_dynamic_registration'] );

		$scope                       = isset( $input['oauth_default_scope'] ) ? sanitize_text_field( $input['oauth_default_scope'] ) : 'mcp';
		$clean['oauth_default_scope'] = array_key_exists( $scope, WPMCP_OAuth::scopes() ) ? $scope : 'mcp';
		$clean['sse_enabled']         = ! empty( $input['sse_enabled'] );
		$clean['log_enabled']         = ! empty( $input['log_enabled'] );
		$clean['require_https']       = ! empty( $input['require_https'] );

		$profile           = isset( $input['profile'] ) ? sanitize_key( $input['profile'] ) : $defaults['profile'];
		$clean['profile']  = array_key_exists( $profile, self::profiles() ) ? $profile : $defaults['profile'];

		$capability           = isset( $input['capability'] ) ? sanitize_key( $input['capability'] ) : $defaults['capability'];
		$clean['capability']  = '' !== $capability ? $capability : $defaults['capability'];

		$clean['sse_max_duration']    = $this->clamp_int( $input, 'sse_max_duration', 5, 300, $defaults['sse_max_duration'] );
		$clean['rate_limit_requests'] = $this->clamp_int( $input, 'rate_limit_requests', 1, 100000, $defaults['rate_limit_requests'] );
		$clean['rate_limit_window']   = $this->clamp_int( $input, 'rate_limit_window', 1, 3600, $defaults['rate_limit_window'] );
		$clean['max_upload_bytes']    = $this->clamp_int( $input, 'max_upload_bytes', 1024, 512 * MB_IN_BYTES, $defaults['max_upload_bytes'] );

		$tools                  = isset( $input['enabled_tools'] ) && is_array( $input['enabled_tools'] ) ? $input['enabled_tools'] : array();
		$clean['enabled_tools'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $tools ) ) ) );

		$option_keys = isset( $input['allowed_option_keys'] ) ? $this->to_list( $input['allowed_option_keys'] ) : $defaults['allowed_option_keys'];

		// sanitize_key strips the asterisk, which silently turned the "allow
		// every option" wildcard into an empty list and locked the tool down
		// while reporting that it was open. Keep the wildcard verbatim.
		$clean['allowed_option_keys'] = array_values(
			array_filter(
				array_map(
					static function ( $key ) {
						$key = trim( (string) $key );

						return '*' === $key ? '*' : sanitize_key( $key );
					},
					$option_keys
				)
			)
		);

		$hosts                        = isset( $input['allowed_media_hosts'] ) ? $this->to_list( $input['allowed_media_hosts'] ) : $defaults['allowed_media_hosts'];
		$clean['allowed_media_hosts'] = array_values( array_filter( array_map( array( $this, 'sanitize_host' ), $hosts ) ) );

		$clean = wp_parse_args( $clean, $defaults );

		// The settings screen is split across tabs, so a form only ever carries
		// some of these keys. Without an explicit list of what a form owns, an
		// unchecked box on the visible tab is indistinguishable from a checked
		// box on a tab that was not submitted, and saving one tab would silently
		// reset the others. A form therefore declares its own fields and every
		// other key is carried over from what is already stored.
		if ( isset( $input['_fields'] ) ) {
			$owned   = array_map( 'sanitize_key', (array) $input['_fields'] );
			$current = $this->all();

			foreach ( array_keys( $clean ) as $key ) {
				if ( ! in_array( $key, $owned, true ) && array_key_exists( $key, $current ) ) {
					$clean[ $key ] = $current[ $key ];
				}
			}
		}

		return $clean;
	}

	/**
	 * Normalises a textarea or array into a flat list of strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private function to_list( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		return preg_split( '/[\r\n,]+/', (string) $value ) ?: array();
	}

	/**
	 * Sanitises a hostname.
	 *
	 * @param string $host Raw host.
	 * @return string
	 */
	public function sanitize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '#^https?://#', '', $host );
		$host = trim( (string) $host, '/' );

		return preg_match( '/^[a-z0-9.\-]+$/', $host ) ? $host : '';
	}

	/**
	 * Reads an integer from input and clamps it to a range.
	 *
	 * @param array<string,mixed> $input   Input array.
	 * @param string              $key     Key to read.
	 * @param int                 $min     Minimum.
	 * @param int                 $max     Maximum.
	 * @param int                 $default Fallback.
	 * @return int
	 */
	private function clamp_int( array $input, $key, $min, $max, $default ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return $default;
		}

		return max( $min, min( $max, (int) $input[ $key ] ) );
	}

	/**
	 * Whether a given tool is exposed under the current configuration.
	 *
	 * This is the allowlist gate. It runs before, and independently of, the
	 * per-tool capability check: a tool must be both exposed and permitted.
	 *
	 * @param array<string,mixed> $tool Tool definition from the registry.
	 * @return bool
	 */
	public function is_tool_enabled( array $tool ) {
		$profile = $this->get( 'profile' );
		$enabled = self::profile_allows( $tool, $profile, (array) $this->get( 'enabled_tools', array() ) );

		// A credential may carry its own, narrower scope. Both gates must pass,
		// so a token can restrict what the site profile allows but can never
		// reach past it: issuing a token is not a way to grant more access.
		$scope = WPMCP_Auth::current_scope();

		if ( $enabled && is_array( $scope ) ) {
			if ( '' !== $scope['profile'] ) {
				$enabled = self::profile_allows( $tool, $scope['profile'], $scope['tools'] );
			}

			if ( $enabled && ! empty( $scope['tools'] ) ) {
				$enabled = in_array( $tool['name'], $scope['tools'], true );
			}
		}

		/**
		 * Filters whether an individual tool is exposed to MCP clients.
		 *
		 * Returning false here removes the tool from tools/list and makes
		 * tools/call reject it, regardless of the caller's capabilities.
		 *
		 * @param bool                $enabled Whether the tool is exposed.
		 * @param array<string,mixed> $tool    Tool definition.
		 * @param string              $profile Active permission profile.
		 */
		return (bool) apply_filters( 'wpmcp_is_tool_enabled', $enabled, $tool, $profile );
	}

	/**
	 * Whether a named profile exposes a tool.
	 *
	 * Extracted so the site profile and a credential's own scope are evaluated
	 * by identical rules, rather than by two implementations that could drift.
	 *
	 * @param array<string,mixed> $tool          Tool definition.
	 * @param string              $profile       Profile slug.
	 * @param string[]            $custom_tools  Tool list used when the profile is 'custom'.
	 * @return bool
	 */
	public static function profile_allows( array $tool, $profile, array $custom_tools = array() ) {
		if ( 'custom' === $profile ) {
			return in_array( $tool['name'], $custom_tools, true );
		}

		$profiles = isset( $tool['profiles'] ) ? (array) $tool['profiles'] : array();

		return in_array( $profile, $profiles, true );
	}
}

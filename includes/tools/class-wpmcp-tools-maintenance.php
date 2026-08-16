<?php
/**
 * Maintenance tools: caches, options, site health, emulated WP-CLI.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe operational actions.
 *
 * The CLI tool here is an emulator, not a shell. It never calls proc_open,
 * exec or the wp binary: a real shell reachable over the network by a language
 * model is an arbitrary code execution hole no amount of argument filtering
 * would close. Instead a fixed set of read-mostly commands is mapped onto the
 * equivalent WordPress function calls, so the surface is exactly what is listed
 * below and nothing more.
 */
class WPMCP_Tools_Maintenance {

	/**
	 * Registers the tools.
	 *
	 * @param WPMCP_Registry $registry Registry.
	 * @return void
	 */
	public function register( WPMCP_Registry $registry ) {
		$registry->add(
			array(
				'name'         => 'wp_get_site_health',
				'title'        => __( 'Run site health checks', 'wp-mcp-connector' ),
				'description'  => __( 'Report the state of the install: WordPress, PHP and database versions, pending updates, HTTPS, object cache, cron, debug flags and disk paths. Start here when the user reports that something is slow, broken or out of date.', 'wp-mcp-connector' ),
				'group'        => 'maintenance',
				'capability'   => 'manage_options',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'get_site_health' ),
				'input_schema' => WPMCP_Schema::object( array() ),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_get_option',
				'title'        => __( 'Read a site option', 'wp-mcp-connector' ),
				'description'  => __( 'Read one WordPress option by name. An administrator sets which options are readable, and that list is often open to everything, so try the name you want rather than assuming it is barred. Distinguish the two replies: a refusal names the allowlist explicitly, whereas a null or false value means no option by that name exists, usually a guessed name rather than a blocked one. If you do not know the exact name, ask for it instead of trying variations.', 'wp-mcp-connector' ),
				'group'        => 'maintenance',
				'capability'   => 'manage_options',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'get_option_value' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'name' => WPMCP_Schema::string( __( 'Option name, for example blogname.', 'wp-mcp-connector' ) ),
					),
					array( 'name' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_update_option',
				'title'        => __( 'Write a site option', 'wp-mcp-connector' ),
				'description'  => __( 'Change one WordPress option. Restricted to the same administrator-defined allowlist as wp_get_option, and refused outright for anything not on it. Options are site-wide settings, so state the old and new value to the user and get confirmation before calling this.', 'wp-mcp-connector' ),
				'group'        => 'maintenance',
				'capability'   => 'manage_options',
				'profiles'     => array( 'admin' ),
				'annotations'  => array( 'destructiveHint' => true ),
				'callback'     => array( $this, 'update_option_value' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'name'  => WPMCP_Schema::string( __( 'Option name.', 'wp-mcp-connector' ) ),
						'value' => WPMCP_Schema::string( __( 'New value, as a string. Numeric strings are stored as integers.', 'wp-mcp-connector' ) ),
					),
					array( 'name', 'value' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_flush_caches',
				'title'        => __( 'Flush caches', 'wp-mcp-connector' ),
				'description'  => __( 'Clear the WordPress object cache, expired transients and, optionally, the rewrite rules. Use this after content or permalink changes that are not showing up. It does not touch a page cache plugin, a CDN or PHP opcache, so if the front end still looks stale after this, say so rather than repeating the call.', 'wp-mcp-connector' ),
				'group'        => 'maintenance',
				'capability'   => 'manage_options',
				'profiles'     => array( 'editor', 'admin' ),
				'annotations'  => array(
					'destructiveHint' => false,
					'idempotentHint'  => true,
				),
				'callback'     => array( $this, 'flush_caches' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'rewrite_rules' => WPMCP_Schema::boolean( __( 'Also regenerate permalink rewrite rules. Defaults to false.', 'wp-mcp-connector' ) ),
					)
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_run_cli_command',
				'title'        => __( 'Run an emulated WP-CLI command', 'wp-mcp-connector' ),
				'description'  => __( 'Run one of a fixed set of WP-CLI style commands, for people who think in wp commands. Nothing is executed in a shell: each command maps to an equivalent WordPress API call, and anything outside the list is refused. Call it with no arguments to see the supported commands. Prefer the dedicated tools where one exists, since they return richer structured data.', 'wp-mcp-connector' ),
				'group'        => 'maintenance',
				'capability'   => 'manage_options',
				'profiles'     => array( 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'run_cli_command' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'command' => WPMCP_Schema::string( __( 'The command, for example "wp core version" or "wp plugin list --status=active". Omit to list what is supported.', 'wp-mcp-connector' ) ),
					)
				),
			)
		);
	}

	/**
	 * Site health report.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>
	 */
	public function get_site_health( array $args ) {
		global $wpdb;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$core_updates   = get_site_transient( 'update_core' );
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );

		$core_update = null;

		if ( isset( $core_updates->updates[0] ) && 'upgrade' === $core_updates->updates[0]->response ) {
			$core_update = $core_updates->updates[0]->current;
		}

		$upload_dir = wp_get_upload_dir();

		$checks = array(
			'wordpress_version' => array(
				'value'  => get_bloginfo( 'version' ),
				'update' => $core_update,
				'status' => $core_update ? 'update-available' : 'good',
			),
			'php_version'       => array(
				'value'  => PHP_VERSION,
				'status' => version_compare( PHP_VERSION, '8.1', '>=' ) ? 'good' : 'outdated',
			),
			'database'          => array(
				'value'  => $wpdb->db_version(),
				'server' => $wpdb->db_server_info(),
				'status' => 'good',
			),
			'https'             => array(
				'value'  => is_ssl() ? 'enabled' : 'not detected on this request',
				'status' => is_ssl() ? 'good' : 'check',
			),
			'object_cache'      => array(
				'value'  => wp_using_ext_object_cache() ? 'persistent' : 'database only',
				'status' => wp_using_ext_object_cache() ? 'good' : 'check',
			),
			'cron'              => array(
				'value'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'WP-Cron disabled, expects a system cron' : 'WP-Cron enabled',
				'due'    => count( (array) _get_cron_array() ),
				'status' => 'good',
			),
			'debug'             => array(
				'wp_debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
				'status'           => ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY && 'production' === wp_get_environment_type() ) ? 'warning' : 'good',
			),
			'updates_pending'   => array(
				'plugins' => isset( $plugin_updates->response ) ? count( (array) $plugin_updates->response ) : 0,
				'themes'  => isset( $theme_updates->response ) ? count( (array) $theme_updates->response ) : 0,
			),
			'uploads'           => array(
				'path'     => $upload_dir['basedir'],
				'writable' => wp_is_writable( $upload_dir['basedir'] ),
				'status'   => wp_is_writable( $upload_dir['basedir'] ) ? 'good' : 'warning',
			),
			'environment'       => array(
				'value'  => wp_get_environment_type(),
				'status' => 'good',
			),
		);

		return array(
			'checks'  => $checks,
			'summary' => $this->health_summary( $checks ),
		);
	}

	/**
	 * Reads an allowlisted option.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_option_value( array $args ) {
		$name    = sanitize_key( (string) $args['name'] );
		$allowed = $this->allowed_options();

		if ( ! $this->option_permitted( $name, $allowed ) ) {
			return $this->option_not_allowed( $name, $allowed );
		}

		return array(
			'name'  => $name,
			'value' => get_option( $name ),
		);
	}

	/**
	 * Writes an allowlisted option.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update_option_value( array $args ) {
		$name    = sanitize_key( (string) $args['name'] );
		$allowed = $this->allowed_options();

		if ( ! $this->option_permitted( $name, $allowed ) ) {
			return $this->option_not_allowed( $name, $allowed );
		}

		$old = get_option( $name );
		$new = (string) $args['value'];

		// Keep the stored type stable: an option read as an int should not come
		// back as a numeric string and quietly change behaviour elsewhere.
		if ( is_int( $old ) || ( is_string( $old ) && ctype_digit( $old ) && ctype_digit( $new ) ) ) {
			$new = (int) $new;
		} else {
			$new = sanitize_text_field( $new );
		}

		update_option( $name, $new );

		return array(
			'updated' => true,
			'name'    => $name,
			'before'  => $old,
			'after'   => get_option( $name ),
		);
	}

	/**
	 * Flushes caches.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>
	 */
	public function flush_caches( array $args ) {
		$done = array();

		wp_cache_flush();
		$done[] = __( 'Object cache flushed.', 'wp-mcp-connector' );

		$deleted = $this->delete_expired_transients();
		/* translators: %d: number of expired transients removed. */
		$done[] = sprintf( __( 'Removed %d expired transients.', 'wp-mcp-connector' ), $deleted );

		if ( ! empty( $args['rewrite_rules'] ) ) {
			flush_rewrite_rules( false );
			$done[] = __( 'Rewrite rules regenerated.', 'wp-mcp-connector' );
		}

		return array(
			'flushed' => true,
			'actions' => $done,
			'note'    => __( 'Page cache plugins, CDNs and PHP opcache are not affected by this and may still be serving old output.', 'wp-mcp-connector' ),
		);
	}

	/**
	 * Runs an emulated CLI command.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function run_cli_command( array $args ) {
		$commands = $this->command_map();

		if ( empty( $args['command'] ) ) {
			return array(
				'supported' => array_keys( $commands ),
				'note'      => __( 'These are emulated: each one maps to a WordPress function call, not to a shell. Flags are passed as --key=value.', 'wp-mcp-connector' ),
			);
		}

		$raw    = trim( (string) $args['command'] );
		$parsed = $this->parse_command( $raw );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( ! isset( $commands[ $parsed['command'] ] ) ) {
			return new WP_Error(
				'wpmcp_cli_not_supported',
				sprintf(
					/* translators: 1: attempted command, 2: supported commands. */
					__( '"%1$s" is not one of the emulated commands. This is not a shell, so only these are available: %2$s', 'wp-mcp-connector' ),
					$parsed['command'],
					implode( ', ', array_keys( $commands ) )
				)
			);
		}

		$output = call_user_func( $commands[ $parsed['command'] ], $parsed['flags'], $parsed['positional'] );

		if ( is_wp_error( $output ) ) {
			return $output;
		}

		return array(
			'command' => 'wp ' . $parsed['command'],
			'flags'   => $parsed['flags'],
			'output'  => $output,
		);
	}

	/* ---------------------------------------------------------------------
	 * CLI emulation
	 * ------------------------------------------------------------------ */

	/**
	 * The supported command map.
	 *
	 * @return array<string,callable>
	 */
	private function command_map() {
		$content = new WPMCP_Tools_Content();
		$site    = new WPMCP_Tools_Site();

		return array(
			'core version'   => function () {
				return array( 'version' => get_bloginfo( 'version' ) );
			},
			'core check-update' => function () {
				$updates = get_site_transient( 'update_core' );

				return array(
					'current'   => get_bloginfo( 'version' ),
					'available' => isset( $updates->updates[0]->current ) ? $updates->updates[0]->current : null,
				);
			},
			'option get'     => function ( $flags, $positional ) {
				return $this->get_option_value( array( 'name' => isset( $positional[0] ) ? $positional[0] : '' ) );
			},
			'post list'      => function ( $flags ) use ( $content ) {
				return $content->list_posts(
					array(
						'post_type' => isset( $flags['post_type'] ) ? $flags['post_type'] : 'post',
						'status'    => isset( $flags['post_status'] ) ? $flags['post_status'] : 'any',
						'per_page'  => isset( $flags['posts_per_page'] ) ? max( 1, min( 100, (int) $flags['posts_per_page'] ) ) : 20,
					)
				);
			},
			'plugin list'    => function ( $flags ) use ( $site ) {
				return $site->list_plugins( array( 'status' => isset( $flags['status'] ) ? $flags['status'] : 'all' ) );
			},
			'theme list'     => function () use ( $site ) {
				return $site->list_themes( array() );
			},
			'user list'      => function ( $flags ) use ( $site ) {
				return $site->list_users( array( 'role' => isset( $flags['role'] ) ? $flags['role'] : '' ) );
			},
			'cache flush'    => function () {
				return $this->flush_caches( array() );
			},
			'rewrite flush'  => function () {
				return $this->flush_caches( array( 'rewrite_rules' => true ) );
			},
			'transient delete --expired' => function () {
				return array( 'deleted' => $this->delete_expired_transients() );
			},
			'db check'       => function () {
				global $wpdb;

				return array(
					'connected' => (bool) $wpdb->check_connection( false ),
					'version'   => $wpdb->db_version(),
					'prefix'    => $wpdb->prefix,
				);
			},
			'site health'    => function () {
				return $this->get_site_health( array() );
			},
		);
	}

	/**
	 * Splits a command string into a command name, flags and positionals.
	 *
	 * @param string $raw Raw command.
	 * @return array{command:string,flags:array<string,string>,positional:string[]}|WP_Error
	 */
	private function parse_command( $raw ) {
		// Refuse anything that looks like shell composition before parsing, so a
		// crafted string can never be mistaken for a single command.
		if ( preg_match( '/[;&|`$><\n\r]/', $raw ) ) {
			return new WP_Error(
				'wpmcp_cli_unsafe',
				__( 'That command contains shell metacharacters. This tool is an emulator, not a shell, and only accepts a plain command with --key=value flags.', 'wp-mcp-connector' )
			);
		}

		$parts = preg_split( '/\s+/', trim( $raw ) );
		$parts = array_values( array_filter( (array) $parts ) );

		if ( $parts && 'wp' === $parts[0] ) {
			array_shift( $parts );
		}

		$words      = array();
		$flags      = array();
		$positional = array();

		foreach ( $parts as $part ) {
			if ( 0 === strpos( $part, '--' ) ) {
				$flag = substr( $part, 2 );

				if ( false !== strpos( $flag, '=' ) ) {
					list( $key, $value ) = explode( '=', $flag, 2 );
					$flags[ sanitize_key( $key ) ] = sanitize_text_field( trim( $value, "\"'" ) );
				} else {
					$flags[ sanitize_key( $flag ) ] = true;
				}

				continue;
			}

			// The first two bare words form the command name ("post list"); any
			// further bare words are positional arguments.
			if ( count( $words ) < 2 ) {
				$words[] = sanitize_key( $part );
			} else {
				$positional[] = sanitize_text_field( $part );
			}
		}

		$command = implode( ' ', $words );

		// One special case: `transient delete --expired` is keyed with its flag,
		// because the bare form would be far too broad to allow.
		if ( 'transient delete' === $command && isset( $flags['expired'] ) ) {
			$command = 'transient delete --expired';
		}

		if ( '' === $command ) {
			return new WP_Error( 'wpmcp_cli_empty', __( 'No command given.', 'wp-mcp-connector' ) );
		}

		return array(
			'command'    => $command,
			'flags'      => $flags,
			'positional' => $positional,
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The option allowlist.
	 *
	 * @return string[]
	 */
	private function allowed_options() {
		/**
		 * Filters which options MCP clients may read and write.
		 *
		 * A single "*" entry means every option, for an operator who wants the
		 * connector unrestricted. Two keys stay guarded even then: siteurl and
		 * home. Writing either one wrongly makes the site and its admin
		 * unreachable in the same instant, so recovery needs database access
		 * rather than another tool call. Every other option is fair game.
		 *
		 * @param string[] $allowed Option names, or array( '*' ).
		 */
		return (array) apply_filters( 'wpmcp_allowed_option_keys', (array) wpmcp()->settings()->get( 'allowed_option_keys', array() ) );
	}

	/**
	 * Whether an option may be touched.
	 *
	 * @param string   $name    Option name.
	 * @param string[] $allowed Allowlist.
	 * @return bool
	 */
	private function option_permitted( $name, array $allowed ) {
		if ( in_array( '*', $allowed, true ) ) {
			return ! in_array( $name, array( 'siteurl', 'home' ), true );
		}

		return in_array( $name, $allowed, true );
	}

	/**
	 * The refusal message for a non-allowlisted option.
	 *
	 * @param string   $name    Requested name.
	 * @param string[] $allowed Allowed names.
	 * @return WP_Error
	 */
	private function option_not_allowed( $name, array $allowed ) {
		return new WP_Error(
			'wpmcp_option_not_allowed',
			sprintf(
				/* translators: 1: requested option name, 2: allowed option names. */
				__( 'The option "%1$s" is not on this site\'s allowlist, so it cannot be read or written over MCP. Allowed: %2$s. An administrator can extend the list under Settings, MCP Connector.', 'wp-mcp-connector' ),
				$name,
				$allowed ? implode( ', ', $allowed ) : __( '(none)', 'wp-mcp-connector' )
			)
		);
	}

	/**
	 * Deletes expired transients from the options table.
	 *
	 * @return int Number removed.
	 */
	private function delete_expired_transients() {
		global $wpdb;

		$now = time();

		// Find timeout rows that have passed, then remove both halves of the pair.
		$expired = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		$deleted = 0;

		foreach ( (array) $expired as $timeout_name ) {
			$key = str_replace( '_transient_timeout_', '', $timeout_name );

			if ( delete_transient( $key ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Turns the health checks into a one-line verdict per problem.
	 *
	 * @param array<string,mixed> $checks Checks.
	 * @return string[]
	 */
	private function health_summary( array $checks ) {
		$notes = array();

		if ( 'update-available' === $checks['wordpress_version']['status'] ) {
			/* translators: 1: current version, 2: available version. */
			$notes[] = sprintf( __( 'WordPress %1$s is installed; %2$s is available.', 'wp-mcp-connector' ), $checks['wordpress_version']['value'], $checks['wordpress_version']['update'] );
		}

		if ( 'outdated' === $checks['php_version']['status'] ) {
			/* translators: %s: PHP version. */
			$notes[] = sprintf( __( 'PHP %s is older than the 8.1 minimum WordPress recommends.', 'wp-mcp-connector' ), $checks['php_version']['value'] );
		}

		if ( 'good' !== $checks['https']['status'] ) {
			$notes[] = __( 'This request did not arrive over HTTPS.', 'wp-mcp-connector' );
		}

		if ( 'warning' === $checks['debug']['status'] ) {
			$notes[] = __( 'WP_DEBUG_DISPLAY is on in a production environment, so PHP errors may be visible to visitors.', 'wp-mcp-connector' );
		}

		if ( $checks['updates_pending']['plugins'] || $checks['updates_pending']['themes'] ) {
			/* translators: 1: plugin update count, 2: theme update count. */
			$notes[] = sprintf( __( '%1$d plugin and %2$d theme updates are waiting.', 'wp-mcp-connector' ), $checks['updates_pending']['plugins'], $checks['updates_pending']['themes'] );
		}

		if ( ! $checks['uploads']['writable'] ) {
			$notes[] = __( 'The uploads directory is not writable, so media uploads will fail.', 'wp-mcp-connector' );
		}

		if ( ! $notes ) {
			$notes[] = __( 'No problems found.', 'wp-mcp-connector' );
		}

		return $notes;
	}
}

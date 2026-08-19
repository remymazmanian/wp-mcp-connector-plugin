<?php
/**
 * WordPress Abilities API integration.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors every MCP tool into the core Abilities API (WordPress 6.9+).
 *
 * This is the "modern WordPress approach" half of the architecture. Registering
 * abilities means the same capabilities are reachable three ways, from one
 * definition:
 *
 *   1. This plugin's own MCP endpoints (always).
 *   2. The core Abilities REST API, at /wp-json/wp/v2/abilities.
 *   3. The official MCP Adapter, if the site has it, which can expose the same
 *      abilities as its own MCP server.
 *
 * The whole class is a no-op on WordPress older than 6.9, which is why the
 * plugin never requires Composer or a feature plugin to work.
 */
class WPMCP_Abilities {

	const CATEGORY = 'wp-mcp-connector';

	/**
	 * Hooks the registration actions.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// If the official MCP Adapter is installed, hand it our abilities too.
		add_action( 'mcp_adapter_init', array( $this, 'register_with_adapter' ) );
	}

	/**
	 * Registers the ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Site management', 'wp-mcp-connector' ),
				'description' => __( 'Read and manage posts, pages, media, taxonomies, comments and site configuration.', 'wp-mcp-connector' ),
			)
		);
	}

	/**
	 * Registers one ability per MCP tool.
	 *
	 * The permission callback deliberately repeats every check the MCP path
	 * makes. Abilities can be invoked through core's own REST API, which never
	 * passes through this plugin's transport, so the allowlist and rate limit
	 * have to be enforced here as well rather than assumed.
	 *
	 * @return void
	 */
	public function register_abilities() {
		$registry = wpmcp()->registry();

		foreach ( $registry->all() as $tool ) {
			$name = self::CATEGORY . '/' . str_replace( '_', '-', $tool['name'] );

			$args = array(
				'label'               => $tool['title'],
				'description'         => $tool['description'],
				'category'            => self::CATEGORY,
				'input_schema'        => WPMCP_Schema::normalize( $tool['input_schema'] ),
				'execute_callback'    => $this->make_execute_callback( $tool ),
				'permission_callback' => $this->make_permission_callback( $tool ),
				'meta'                => array(
					// Both flags on purpose, and both are load-bearing.
					//
					// 'public' is the unified exposure flag added in WordPress
					// 7.1: one declaration that means "external clients may see
					// this", which the REST API, the MCP Adapter and AI agents
					// all read. It defaults to false, so it has to be explicit.
					//
					// 'show_in_rest' stays because this plugin supports 6.4+ and
					// abilities register on 6.9+. WordPress 6.9 and 7.0 predate
					// 'public' and would expose nothing without it. In 7.1 the
					// resolution order is show_in_rest ?? public ?? false, so the
					// two agree rather than fight. Do not "tidy" this to one key
					// until the plugin's floor is WordPress 7.1.
					'public'       => true,
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => ! empty( $tool['annotations']['readOnlyHint'] ),
						'destructive' => ! empty( $tool['annotations']['destructiveHint'] ),
						'idempotent'  => ! empty( $tool['annotations']['idempotentHint'] ),
					),
				),
			);

			// The key must be absent, not null, for tools without an output
			// schema: WP_Ability::get_output_schema() is typed to return an
			// array, and a stored null makes every execute() call fatal.
			if ( ! empty( $tool['output_schema'] ) ) {
				$args['output_schema'] = WPMCP_Schema::normalize( $tool['output_schema'] );
			}

			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Builds the execute callback for one tool.
	 *
	 * @param array<string,mixed> $tool Tool definition.
	 * @return callable
	 */
	private function make_execute_callback( array $tool ) {
		return function ( $input = array() ) use ( $tool ) {
			$args  = is_array( $input ) ? $input : array();
			$clean = WPMCP_Schema::validate( $args, $tool['input_schema'] );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$result = call_user_func( $tool['callback'], $clean );

			WPMCP_Logger::record( $tool['name'], $clean, ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );

			return $result;
		};
	}

	/**
	 * Builds the permission callback for one tool.
	 *
	 * @param array<string,mixed> $tool Tool definition.
	 * @return callable
	 */
	private function make_permission_callback( array $tool ) {
		return function () use ( $tool ) {
			$settings = wpmcp()->settings();

			if ( ! $settings->get( 'enabled' ) ) {
				return new WP_Error( 'wpmcp_disabled', __( 'The MCP connector is switched off on this site.', 'wp-mcp-connector' ) );
			}

			if ( ! $settings->is_tool_enabled( $tool ) ) {
				return new WP_Error(
					'wpmcp_tool_disabled',
					sprintf(
						/* translators: %s: tool name. */
						__( 'The ability "%s" is not exposed under the current permission profile.', 'wp-mcp-connector' ),
						$tool['name']
					)
				);
			}

			if ( ! current_user_can( $tool['capability'] ) ) {
				return new WP_Error(
					'wpmcp_forbidden',
					sprintf(
						/* translators: %s: capability slug. */
						__( 'This requires the "%s" capability.', 'wp-mcp-connector' ),
						$tool['capability']
					)
				);
			}

			return WPMCP_Rate_Limiter::consume( get_current_user_id() );
		};
	}

	/**
	 * Hands the abilities to the official MCP Adapter when it is installed.
	 *
	 * The adapter's signature has moved between releases, so this stays
	 * defensive: it checks the method exists, wraps the call, and treats a
	 * failure as "the adapter is not usable here" rather than fatalling the
	 * site. This plugin's own endpoints keep working either way.
	 *
	 * @param mixed $adapter Adapter instance passed by the action.
	 * @return void
	 */
	public function register_with_adapter( $adapter = null ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$abilities = array();

		foreach ( wpmcp()->registry()->all() as $tool ) {
			if ( ! wpmcp()->settings()->is_tool_enabled( $tool ) ) {
				continue;
			}

			$abilities[] = self::CATEGORY . '/' . str_replace( '_', '-', $tool['name'] );
		}

		if ( ! $abilities ) {
			return;
		}

		try {
			$adapter->create_server(
				'wp-mcp-connector',
				'mcp',
				'adapter',
				__( 'WordPress site management', 'wp-mcp-connector' ),
				__( 'Manage posts, pages, media, taxonomies and comments on this WordPress site.', 'wp-mcp-connector' ),
				WPMCP_VERSION,
				array(),
				$abilities
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP MCP Connector: MCP Adapter registration skipped: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}

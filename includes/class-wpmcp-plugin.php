<?php
/**
 * Plugin orchestrator.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the subsystems together and owns the shared tool registry.
 */
class WPMCP_Plugin {

	/**
	 * Tool registry, populated once on demand.
	 *
	 * @var WPMCP_Registry|null
	 */
	private $registry = null;

	/**
	 * Settings accessor.
	 *
	 * @var WPMCP_Settings|null
	 */
	private $settings = null;

	/**
	 * Boots the plugin.
	 *
	 * @return void
	 */
	public function init() {
		$this->settings = new WPMCP_Settings();

		// Authentication has to attach early: determine_current_user runs before
		// REST routing, and Bearer tokens must resolve to a user before any
		// permission callback fires.
		( new WPMCP_Auth() )->init();

		// OAuth endpoints live outside the REST API and must be able to answer
		// before a coming-soon plugin takes over the front end.
		( new WPMCP_OAuth() )->init();

		// Admin UI: settings page, token issuing, tool allowlist.
		if ( is_admin() ) {
			( new WPMCP_Admin() )->init();
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Mirror every tool into the core Abilities API when the site has it
		// (WordPress 6.9+). Harmless no-op on older installs.
		if ( function_exists( 'wp_register_ability' ) ) {
			( new WPMCP_Abilities() )->init();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads translations on init to avoid the just-in-time textdomain notice.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-mcp-connector', false, dirname( plugin_basename( WPMCP_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the REST transport routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		( new WPMCP_Rest() )->register_routes();
	}

	/**
	 * Returns the settings accessor.
	 *
	 * @return WPMCP_Settings
	 */
	public function settings() {
		if ( null === $this->settings ) {
			$this->settings = new WPMCP_Settings();
		}

		return $this->settings;
	}

	/**
	 * Returns the fully populated tool registry.
	 *
	 * Tool providers are instantiated lazily so that a plain front-end page load
	 * never pays for building two dozen JSON schemas.
	 *
	 * @return WPMCP_Registry
	 */
	public function registry() {
		if ( null !== $this->registry ) {
			return $this->registry;
		}

		$registry = new WPMCP_Registry();

		$providers = array(
			new WPMCP_Tools_Content(),
			new WPMCP_Tools_Taxonomy(),
			new WPMCP_Tools_Media(),
			new WPMCP_Tools_Site(),
			new WPMCP_Tools_Comments(),
			new WPMCP_Tools_Maintenance(),
		);

		/**
		 * Filters the list of tool provider objects.
		 *
		 * Each provider must expose a register( WPMCP_Registry $registry ) method.
		 * This is the extension point for adding site-specific tools without
		 * forking the plugin.
		 *
		 * @param object[] $providers Tool providers.
		 */
		$providers = apply_filters( 'wpmcp_tool_providers', $providers );

		foreach ( $providers as $provider ) {
			if ( is_object( $provider ) && method_exists( $provider, 'register' ) ) {
				$provider->register( $registry );
			}
		}

		$this->registry = $registry;

		return $this->registry;
	}
}

<?php
/**
 * Plugin Name: WP MCP Connector
 * Description: Serves this WordPress site to AI clients (Claude, Claude Code, Cursor, Grok, ChatGPT) as a Model Context Protocol server, over Streamable HTTP and legacy HTTP+SSE, authenticated with Application Passwords, Bearer tokens, or a built-in OAuth 2.1 server.
 * Version:     1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author:      Remy Mazmanian
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-mcp-connector
 *
 * WP MCP Connector
 * Copyright (C) 2026 Remy Mazmanian
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMCP_VERSION', '1.0.0' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_URL', plugin_dir_url( __FILE__ ) );

/**
 * REST namespace. Every endpoint lives under /wp-json/mcp/v1/.
 */
define( 'WPMCP_REST_NAMESPACE', 'mcp/v1' );

/**
 * Option keys. Settings and tokens are stored separately so that a settings
 * reset never destroys issued credentials, and so tokens can be autoloaded
 * independently.
 */
define( 'WPMCP_OPTION_SETTINGS', 'wpmcp_settings' );
define( 'WPMCP_OPTION_TOKENS', 'wpmcp_tokens' );
define( 'WPMCP_OPTION_LOG', 'wpmcp_recent_log' );

/**
 * MCP protocol revisions this server can speak, newest first. The client's
 * requested version is honoured when we know it; otherwise we answer with our
 * newest and let the client decide whether to continue.
 */
define( 'WPMCP_PROTOCOL_VERSIONS', '2025-06-18,2025-03-26,2024-11-05' );

/**
 * Autoloader.
 *
 * Maps WPMCP_Foo_Bar to includes/class-wpmcp-foo-bar.php, and anything under
 * the WPMCP_Tools_ prefix to includes/tools/. Kept deliberately tiny: the
 * plugin must run on a stock host with no Composer and no vendor directory.
 *
 * @param string $class_name Fully qualified class name being loaded.
 * @return void
 */
function wpmcp_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'WPMCP_' ) ) {
		return;
	}

	$slug = strtolower( str_replace( '_', '-', $class_name ) );
	$file = WPMCP_DIR . 'includes/class-' . $slug . '.php';

	if ( 0 === strpos( $class_name, 'WPMCP_Tools_' ) ) {
		$file = WPMCP_DIR . 'includes/tools/class-' . $slug . '.php';
	}

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'wpmcp_autoload' );

/**
 * Returns the plugin singleton.
 *
 * @return WPMCP_Plugin
 */
function wpmcp() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new WPMCP_Plugin();
	}

	return $instance;
}

/**
 * Boots on plugins_loaded so that companion plugins (SEO, caching) have already
 * declared themselves before the SEO adapter picks a backend.
 */
add_action( 'plugins_loaded', array( wpmcp(), 'init' ), 20 );

/**
 * Activation: seed conservative defaults. Least privilege means the fresh
 * install can read and draft, and nothing else, until an admin opts in.
 *
 * @return void
 */
function wpmcp_activate() {
	$existing = get_option( WPMCP_OPTION_SETTINGS );

	if ( ! is_array( $existing ) ) {
		add_option( WPMCP_OPTION_SETTINGS, WPMCP_Settings::defaults(), '', 'yes' );
	}

	if ( ! is_array( get_option( WPMCP_OPTION_TOKENS ) ) ) {
		add_option( WPMCP_OPTION_TOKENS, array(), '', 'yes' );
	}
}
register_activation_hook( __FILE__, 'wpmcp_activate' );

/**
 * Deactivation: drop transient state (sessions, rate-limit windows) so a
 * reactivation never resumes a half-open session. Tokens and settings survive.
 *
 * @return void
 */
function wpmcp_deactivate() {
	WPMCP_Session::purge_all();
}
register_deactivation_hook( __FILE__, 'wpmcp_deactivate' );

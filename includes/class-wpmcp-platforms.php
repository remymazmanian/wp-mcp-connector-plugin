<?php
/**
 * Connection recipes for individual AI clients.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the per-platform connection instructions shown on the Connect tab.
 *
 * Two honesty rules govern everything in this file:
 *
 * 1. **No credential ever appears in a snippet.** Placeholders are written in
 *    SCREAMING_CASE so it is obvious what has to be substituted, and so a
 *    screenshot of this screen never leaks anything.
 * 2. **Menu paths drift; endpoints do not.** Client UIs rename their connector
 *    screens between releases, so anywhere the exact wording is likely to have
 *    moved, the recipe carries a `caveat` saying so. The endpoint URL and the
 *    auth method are the load-bearing parts and those are generated from this
 *    site's real configuration.
 */
class WPMCP_Platforms {

	/**
	 * Connection method vocabulary.
	 *
	 * Platforms are grouped by *how* they connect rather than by vendor,
	 * because that is the thing that actually differs between them and the
	 * thing that decides what the operator has to do next.
	 *
	 * @return array<string,array{label:string,hint:string}>
	 */
	public static function methods() {
		return array(
			'oauth'   => array(
				'label' => __( 'Browser approval', 'wp-mcp-connector' ),
				'hint'  => __( 'Paste the URL, approve on a WordPress consent screen. No credential to copy.', 'wp-mcp-connector' ),
			),
			'command' => array(
				'label' => __( 'One command', 'wp-mcp-connector' ),
				'hint'  => __( 'Run a single line in your terminal.', 'wp-mcp-connector' ),
			),
			'file'    => array(
				'label' => __( 'Config file', 'wp-mcp-connector' ),
				'hint'  => __( 'Add a block to the client\'s JSON config.', 'wp-mcp-connector' ),
			),
			'bridge'  => array(
				'label' => __( 'Local bridge', 'wp-mcp-connector' ),
				'hint'  => __( 'For clients that only speak stdio.', 'wp-mcp-connector' ),
			),
		);
	}

	/**
	 * Monoline icons for each method, drawn on a 24px grid at a 1.5px stroke.
	 *
	 * Deliberately not vendor logos: shipping third-party marks raises
	 * trademark questions, and an abstract stand-in for a logo helps nobody
	 * scan. The platform name does the identifying; the icon says how it
	 * connects, which is the information the operator is missing.
	 *
	 * @param string $method Method key.
	 * @return string Inline SVG.
	 */
	public static function icon( $method ) {
		$paths = array(
			// Key.
			'oauth'   => '<circle cx="8" cy="12" r="3.25"/><path d="M11.25 12H20"/><path d="M17 12v3"/><path d="M20 12v2.5"/>',
			// Terminal prompt.
			'command' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M7.5 10l2.5 2-2.5 2"/><path d="M12.5 14.5h4"/>',
			// Braces on a document.
			'file'    => '<path d="M14 3.5H7A2 2 0 0 0 5 5.5v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5z"/><path d="M14 3.5v5h5"/><path d="M10.5 12.5c-.8 0-.8 1.5-.8 1.5s0 1.5.8 1.5"/><path d="M13.5 12.5c.8 0 .8 1.5.8 1.5s0 1.5-.8 1.5"/>',
			// Two linked nodes.
			'bridge'  => '<circle cx="6" cy="12" r="2.75"/><circle cx="18" cy="12" r="2.75"/><path d="M8.75 12h6.5"/>',
		);

		$path = isset( $paths[ $method ] ) ? $paths[ $method ] : $paths['file'];

		return '<svg class="wpmcp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/**
	 * The endpoint URLs this site actually serves.
	 *
	 * @return array<string,string>
	 */
	public static function endpoints() {
		return array(
			'http'      => rest_url( WPMCP_REST_NAMESPACE . '/mcp' ),
			'sse'       => rest_url( WPMCP_REST_NAMESPACE . '/sse' ),
			'health'    => rest_url( WPMCP_REST_NAMESPACE . '/health' ),
			'authorize' => home_url( '/' . WPMCP_OAuth::BASE . '/authorize' ),
			'token'     => home_url( '/' . WPMCP_OAuth::BASE . '/token' ),
			'discovery' => home_url( '/.well-known/oauth-authorization-server' ),
		);
	}

	/**
	 * The standard mcpServers JSON block, which most file-configured clients
	 * accept in some form.
	 *
	 * @param bool $with_header Whether to include an Authorization header.
	 * @return string
	 */
	private static function server_json( $with_header = true ) {
		$url = self::endpoints()['http'];

		$server = array( 'url' => $url );

		if ( $with_header ) {
			$server['headers'] = array( 'Authorization' => 'Basic BASE64_OF_USERNAME:APP_PASSWORD' );
		}

		return wp_json_encode(
			array( 'mcpServers' => array( 'wordpress' => $server ) ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * The bridge invocation block, for stdio-only clients.
	 *
	 * @return string
	 */
	private static function bridge_json() {
		return wp_json_encode(
			array(
				'mcpServers' => array(
					'wordpress' => array(
						'command' => 'node',
						'args'    => array( '/absolute/path/to/wp-mcp-connector/bridge/dist/index.js' ),
						'env'     => array(
							'WP_MCP_URL'      => self::endpoints()['http'],
							'WP_MCP_USERNAME' => 'YOUR_WP_USERNAME',
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Every platform recipe.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		$e            = self::endpoints();
		$oauth_on     = (bool) wpmcp()->settings()->get( 'oauth_enabled' );
		$oauth_needed = __( 'This client needs OAuth. Turn it on under Security before connecting.', 'wp-mcp-connector' );

		$platforms = array(
			'claude-code'    => array(
				'name'    => __( 'Claude Code', 'wp-mcp-connector' ),
				'method'  => 'command',
				'summary' => __( 'Adds the site to every project you work on. The fastest route if you already have the CLI.', 'wp-mcp-connector' ),
				'steps'   => array(
					__( 'Generate an Application Password on your WordPress profile.', 'wp-mcp-connector' ),
					__( 'Run the command below, substituting your username and that password.', 'wp-mcp-connector' ),
					__( 'Confirm with "claude mcp list".', 'wp-mcp-connector' ),
				),
				'blocks'  => array(
					array(
						'label' => __( 'Terminal', 'wp-mcp-connector' ),
						'lang'  => 'bash',
						'code'  => "claude mcp add --scope user --transport http wordpress \\\n  " . $e['http'] . " \\\n  --header \"Authorization: Basic \$(printf '%s' 'USERNAME:APP_PASSWORD' | base64)\"",
					),
				),
				'tip'     => __( 'Type the password into a shell variable first with "read -rs APW" so it never lands in your history.', 'wp-mcp-connector' ),
			),

			'claude-desktop' => array(
				'name'    => __( 'Claude Desktop', 'wp-mcp-connector' ),
				'method'  => $oauth_on ? 'oauth' : 'bridge',
				'summary' => __( 'Connects as a custom connector. Nothing to copy beyond the URL.', 'wp-mcp-connector' ),
				'steps'   => array(
					__( 'Open Settings, then Connectors, then Add custom connector.', 'wp-mcp-connector' ),
					__( 'Paste the server URL below.', 'wp-mcp-connector' ),
					__( 'Approve the connection on the WordPress screen that opens.', 'wp-mcp-connector' ),
				),
				'blocks'  => array(
					array(
						'label' => __( 'Server URL', 'wp-mcp-connector' ),
						'lang'  => 'url',
						'code'  => $e['http'],
					),
				),
				'fallback' => array(
					'label'  => __( 'If your build has no custom connector option', 'wp-mcp-connector' ),
					'note'   => __( 'Use the local bridge instead. Build it once with "npm install && npm run build" inside the plugin\'s bridge folder, then add this to claude_desktop_config.json.', 'wp-mcp-connector' ),
					'blocks' => array(
						array(
							'label' => __( 'claude_desktop_config.json', 'wp-mcp-connector' ),
							'lang'  => 'json',
							'code'  => self::bridge_json(),
						),
					),
				),
				'caveat'  => $oauth_on ? '' : $oauth_needed,
			),

			'grok'           => array(
				'name'    => __( 'Grok', 'wp-mcp-connector' ),
				'method'  => 'oauth',
				'summary' => __( 'Hosted, so it will not accept a pasted password. It authorizes through your own consent screen instead.', 'wp-mcp-connector' ),
				'steps'   => array(
					__( 'Open Settings, then Connectors, then add a custom connector.', 'wp-mcp-connector' ),
					__( 'Give it a name and paste the server URL below.', 'wp-mcp-connector' ),
					__( 'Approve on the WordPress screen, checking the access level it asks for.', 'wp-mcp-connector' ),
				),
				'blocks'  => array(
					array(
						'label' => __( 'Server URL', 'wp-mcp-connector' ),
						'lang'  => 'url',
						'code'  => $e['http'],
					),
				),
				'tip'     => __( 'If it shows an OAuth Credentials Required dialog asking for a Client ID, that means OAuth is switched off here. Turn it on under Security rather than inventing values for those fields.', 'wp-mcp-connector' ),
				'caveat'  => $oauth_on ? '' : $oauth_needed,
			),

			'chatgpt'        => array(
				'name'    => __( 'ChatGPT', 'wp-mcp-connector' ),
				'method'  => 'oauth',
				'summary' => __( 'Also hosted, so it takes the same browser-approval route as Grok.', 'wp-mcp-connector' ),
				'steps'   => array(
					__( 'Open Settings, then Connectors, and add a custom MCP connector.', 'wp-mcp-connector' ),
					__( 'Paste the server URL below.', 'wp-mcp-connector' ),
					__( 'Approve on the WordPress screen that opens.', 'wp-mcp-connector' ),
				),
				'blocks'  => array(
					array(
						'label' => __( 'Server URL', 'wp-mcp-connector' ),
						'lang'  => 'url',
						'code'  => $e['http'],
					),
				),
				'caveat'  => $oauth_on
					? __( 'Custom MCP connectors are not available on every ChatGPT plan, and the menu wording moves between releases. The URL is the part that matters.', 'wp-mcp-connector' )
					: $oauth_needed,
			),

			'cursor'         => array(
				'name'    => __( 'Cursor', 'wp-mcp-connector' ),
				'method'  => 'file',
				'summary' => __( 'Reads MCP servers from a JSON file. Use the global path for every project, or the project path for one.', 'wp-mcp-connector' ),
				'steps'   => array(
					__( 'Create ~/.cursor/mcp.json, or .cursor/mcp.json inside a single project.', 'wp-mcp-connector' ),
					__( 'Paste the block below and substitute the encoded credential.', 'wp-mcp-connector' ),
					__( 'Reload Cursor.', 'wp-mcp-connector' ),
				),
				'blocks'  => array(
					array(
						'label' => __( '~/.cursor/mcp.json', 'wp-mcp-connector' ),
						'lang'  => 'json',
						'code'  => self::server_json( true ),
					),
					array(
						'label' => __( 'Generate the encoded credential', 'wp-mcp-connector' ),
						'lang'  => 'bash',
						'code'  => "printf '%s' 'USERNAME:APP_PASSWORD' | base64",
					),
				),
			),

			'windsurf'       => array(
				'name'    => __( 'Windsurf', 'wp-mcp-connector' ),
				'method'  => 'file',
				'summary' => __( 'Same shape as Cursor, in Windsurf\'s own config file.', 'wp-mcp-connector' ),
				'steps'   => array(
					__( 'Open ~/.codeium/windsurf/mcp_config.json.', 'wp-mcp-connector' ),
					__( 'Paste the block below and substitute the encoded credential.', 'wp-mcp-connector' ),
					__( 'Reload the MCP servers from Windsurf\'s settings panel.', 'wp-mcp-connector' ),
				),
				'blocks'  => array(
					array(
						'label' => __( 'mcp_config.json', 'wp-mcp-connector' ),
						'lang'  => 'json',
						'code'  => self::server_json( true ),
					),
				),
				'caveat'  => __( 'Windsurf has renamed the URL key between releases; some builds want "serverUrl" rather than "url". If it refuses to load, check its own docs for the current key and keep the value.', 'wp-mcp-connector' ),
			),

			'zed'            => array(
				'name'    => __( 'Zed', 'wp-mcp-connector' ),
				'method'  => 'file',
				'summary' => __( 'Registers MCP servers as context servers in the editor settings.', 'wp-mcp-connector' ),
				'steps'   => array(
					__( 'Open the command palette and choose "zed: open settings".', 'wp-mcp-connector' ),
					__( 'Add the context_servers block below.', 'wp-mcp-connector' ),
					__( 'Reload the window.', 'wp-mcp-connector' ),
				),
				'blocks'  => array(
					array(
						'label' => __( 'settings.json', 'wp-mcp-connector' ),
						'lang'  => 'json',
						'code'  => wp_json_encode(
							array(
								'context_servers' => array(
									'wordpress' => array(
										'source'  => 'custom',
										'command' => 'node',
										'args'    => array( '/absolute/path/to/wp-mcp-connector/bridge/dist/index.js' ),
										'env'     => array(
											'WP_MCP_URL'      => $e['http'],
											'WP_MCP_USERNAME' => 'YOUR_WP_USERNAME',
										),
									),
								),
							),
							JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
						),
					),
				),
				'caveat'  => __( 'Zed\'s context server schema has changed more than once. This uses the bridge, which works on every version that can launch a command; a build with native HTTP support can take the URL directly.', 'wp-mcp-connector' ),
			),

			'other'          => array(
				'name'    => __( 'Any other client', 'wp-mcp-connector' ),
				'method'  => 'file',
				'summary' => __( 'The raw facts, for a client not listed here.', 'wp-mcp-connector' ),
				'steps'   => array(),
				'facts'   => true,
				'blocks'  => array(
					array(
						'label' => __( 'Verify the connection from a terminal', 'wp-mcp-connector' ),
						'lang'  => 'bash',
						'code'  => "curl -u 'USERNAME:APP_PASSWORD' " . $e['health'],
					),
				),
			),
		);

		/**
		 * Filters the platform connection recipes.
		 *
		 * @param array<string,array<string,mixed>> $platforms Recipes.
		 */
		return apply_filters( 'wpmcp_platform_recipes', $platforms );
	}

	/**
	 * Raw connection facts, shown on the generic card.
	 *
	 * @return array<string,array{label:string,value:string,mono:bool}>
	 */
	public static function facts() {
		$e        = self::endpoints();
		$settings = wpmcp()->settings();

		$facts = array(
			array(
				'label' => __( 'Streamable HTTP', 'wp-mcp-connector' ),
				'value' => $e['http'],
				'mono'  => true,
			),
			array(
				'label' => __( 'Legacy HTTP+SSE', 'wp-mcp-connector' ),
				'value' => $settings->get( 'sse_enabled' ) ? $e['sse'] : __( 'Disabled under Security.', 'wp-mcp-connector' ),
				'mono'  => (bool) $settings->get( 'sse_enabled' ),
			),
			array(
				'label' => __( 'Protocol revisions', 'wp-mcp-connector' ),
				'value' => implode( ', ', WPMCP_Server::supported_versions() ),
				'mono'  => true,
			),
			array(
				'label' => __( 'Authentication', 'wp-mcp-connector' ),
				'value' => implode( ', ', self::active_auth_methods() ),
				'mono'  => false,
			),
		);

		if ( $settings->get( 'oauth_enabled' ) ) {
			$facts[] = array(
				'label' => __( 'OAuth discovery', 'wp-mcp-connector' ),
				'value' => $e['discovery'],
				'mono'  => true,
			);
		}

		return $facts;
	}

	/**
	 * Human names for the authentication methods currently switched on.
	 *
	 * @return string[]
	 */
	public static function active_auth_methods() {
		$settings = wpmcp()->settings();
		$active   = array();

		if ( $settings->get( 'allow_app_passwords' ) ) {
			$active[] = __( 'Application Passwords', 'wp-mcp-connector' );
		}

		if ( $settings->get( 'allow_bearer' ) ) {
			$active[] = __( 'Bearer tokens', 'wp-mcp-connector' );
		}

		if ( $settings->get( 'oauth_enabled' ) ) {
			$active[] = __( 'OAuth 2.1', 'wp-mcp-connector' );
		}

		return $active ? $active : array( __( 'None enabled', 'wp-mcp-connector' ) );
	}
}

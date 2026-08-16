<?php
/**
 * Tool registry.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds every tool definition and answers "which of these may the caller see
 * and run right now?".
 */
class WPMCP_Registry {

	/**
	 * Tools keyed by name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $tools = array();

	/**
	 * Registers a tool.
	 *
	 * @param array<string,mixed> $tool {
	 *     Tool definition.
	 *
	 *     @type string   $name          Required. Snake_case identifier exposed to the model.
	 *     @type string   $title         Required. Short human title.
	 *     @type string   $description   Required. What it does and when to reach for it. This is
	 *                                   the text the model reasons over, so it should say when NOT
	 *                                   to use the tool as well as when to.
	 *     @type string   $group         Required. UI grouping: content, taxonomy, media, site,
	 *                                   comments, maintenance.
	 *     @type string   $capability    Required. WordPress capability the caller must hold.
	 *     @type callable $callback      Required. fn( array $args ): array|WP_Error.
	 *     @type array    $input_schema  Required. JSON Schema for arguments.
	 *     @type array    $output_schema Optional. JSON Schema for the result.
	 *     @type array    $annotations   Optional. MCP behavioural hints.
	 *     @type string[] $profiles      Optional. Profiles that expose this tool.
	 * }
	 * @return void
	 */
	public function add( array $tool ) {
		$required = array( 'name', 'title', 'description', 'group', 'capability', 'callback', 'input_schema' );

		foreach ( $required as $key ) {
			if ( empty( $tool[ $key ] ) ) {
				_doing_it_wrong( __METHOD__, esc_html( sprintf( 'MCP tool definition is missing "%s".', $key ) ), '1.0.0' );
				return;
			}
		}

		$tool = wp_parse_args(
			$tool,
			array(
				'output_schema' => null,
				'annotations'   => array(),
				'profiles'      => array( 'admin' ),
			)
		);

		$tool['annotations'] = wp_parse_args(
			$tool['annotations'],
			array(
				'title'           => $tool['title'],
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => false,
				'openWorldHint'   => false,
			)
		);

		$this->tools[ $tool['name'] ] = $tool;
	}

	/**
	 * Returns every registered tool, ignoring configuration and capabilities.
	 * Used by the admin screen to render the allowlist.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all() {
		return $this->tools;
	}

	/**
	 * Returns a single tool definition.
	 *
	 * @param string $name Tool name.
	 * @return array<string,mixed>|null
	 */
	public function get( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	/**
	 * Returns the tools the current user may both see and call.
	 *
	 * Two independent gates apply, and a tool must pass both:
	 *   1. the site's allowlist/profile (WPMCP_Settings::is_tool_enabled)
	 *   2. the acting user's WordPress capability
	 *
	 * Hiding tools the user cannot run keeps the model from wasting turns on
	 * calls that would only ever fail.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function available() {
		$settings  = wpmcp()->settings();
		$available = array();

		foreach ( $this->tools as $name => $tool ) {
			if ( ! $settings->is_tool_enabled( $tool ) ) {
				continue;
			}

			if ( ! current_user_can( $tool['capability'] ) ) {
				continue;
			}

			$available[ $name ] = $tool;
		}

		return $available;
	}

	/**
	 * Converts a tool definition to its MCP wire representation.
	 *
	 * @param array<string,mixed> $tool            Tool definition.
	 * @param string              $protocol_version Negotiated protocol revision.
	 * @return array<string,mixed>
	 */
	public function to_mcp( array $tool, $protocol_version = '2025-06-18' ) {
		$payload = array(
			'name'        => $tool['name'],
			'description' => $tool['description'],
			'inputSchema' => WPMCP_Schema::normalize( $tool['input_schema'] ),
			'annotations' => $tool['annotations'],
		);

		// `title` as a sibling of `name` and `outputSchema` both arrived in the
		// 2025-06-18 revision. Older clients can choke on unknown keys, so they
		// are only emitted when the negotiated version supports them.
		if ( version_compare( $protocol_version, '2025-06-18', '>=' ) ) {
			$payload['title'] = $tool['title'];

			if ( ! empty( $tool['output_schema'] ) ) {
				$payload['outputSchema'] = WPMCP_Schema::normalize( $tool['output_schema'] );
			}
		}

		return $payload;
	}
}

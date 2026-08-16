<?php
/**
 * JSON-RPC 2.0 / MCP protocol engine.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a JSON-RPC message into a JSON-RPC response.
 *
 * This class is transport agnostic on purpose: Streamable HTTP and legacy
 * HTTP+SSE both hand messages here and differ only in how they frame the
 * result. That keeps protocol behaviour identical across transports and means
 * a bug fixed for one is fixed for both.
 */
class WPMCP_Server {

	/* JSON-RPC error codes. */
	const PARSE_ERROR      = -32700;
	const INVALID_REQUEST  = -32600;
	const METHOD_NOT_FOUND = -32601;
	const INVALID_PARAMS   = -32602;
	const INTERNAL_ERROR   = -32603;

	/* MCP-flavoured application errors. */
	const UNAUTHORIZED = -32001;
	const RATE_LIMITED = -32002;

	/**
	 * Session id for this exchange, if any.
	 *
	 * @var string
	 */
	private $session_id = '';

	/**
	 * Protocol revision negotiated with the client.
	 *
	 * @var string
	 */
	private $protocol_version = '2025-06-18';

	/**
	 * Constructor.
	 *
	 * @param string $session_id       Existing session id, or empty.
	 * @param string $protocol_version Protocol revision to assume until initialize says otherwise.
	 */
	public function __construct( $session_id = '', $protocol_version = '' ) {
		$this->session_id = (string) $session_id;

		if ( $protocol_version ) {
			$this->protocol_version = $protocol_version;
		}
	}

	/**
	 * Returns the session id, which initialize may have created.
	 *
	 * @return string
	 */
	public function session_id() {
		return $this->session_id;
	}

	/**
	 * Returns the negotiated protocol revision.
	 *
	 * @return string
	 */
	public function protocol_version() {
		return $this->protocol_version;
	}

	/**
	 * Returns the supported protocol revisions, newest first.
	 *
	 * @return string[]
	 */
	public static function supported_versions() {
		return array_map( 'trim', explode( ',', WPMCP_PROTOCOL_VERSIONS ) );
	}

	/**
	 * Handles a single JSON-RPC message.
	 *
	 * @param mixed $message Decoded message.
	 * @return array<string,mixed>|null Response, or null for notifications.
	 */
	public function handle( $message ) {
		if ( ! is_array( $message ) || ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] || empty( $message['method'] ) ) {
			return $this->error( isset( $message['id'] ) ? $message['id'] : null, self::INVALID_REQUEST, __( 'Not a valid JSON-RPC 2.0 request. Every message needs "jsonrpc": "2.0" and a "method".', 'wp-mcp-connector' ) );
		}

		$method = (string) $message['method'];
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$id     = array_key_exists( 'id', $message ) ? $message['id'] : null;

		// A message without an id is a notification: acknowledge nothing.
		$is_notification = ! array_key_exists( 'id', $message );

		try {
			switch ( $method ) {
				case 'initialize':
					$result = $this->handle_initialize( $params );
					break;

				case 'notifications/initialized':
				case 'initialized':
					if ( $this->session_id ) {
						WPMCP_Session::update( $this->session_id, array( 'initialized' => true ) );
					}
					return null;

				case 'notifications/cancelled':
				case 'notifications/roots/list_changed':
					return null;

				case 'ping':
					$result = new stdClass();
					break;

				case 'tools/list':
					$result = $this->handle_tools_list( $params );
					break;

				case 'tools/call':
					$result = $this->handle_tools_call( $params );
					break;

				case 'resources/list':
					$result = $this->handle_resources_list();
					break;

				case 'resources/templates/list':
					$result = array( 'resourceTemplates' => array() );
					break;

				case 'resources/read':
					$result = $this->handle_resources_read( $params );
					break;

				case 'prompts/list':
					$result = $this->handle_prompts_list();
					break;

				case 'prompts/get':
					$result = $this->handle_prompts_get( $params );
					break;

				case 'logging/setLevel':
					$result = new stdClass();
					break;

				default:
					if ( $is_notification ) {
						return null;
					}

					return $this->error(
						$id,
						self::METHOD_NOT_FOUND,
						sprintf(
							/* translators: %s: JSON-RPC method name. */
							__( 'Unknown method "%s". This server implements initialize, ping, tools/list, tools/call, resources/list, resources/read, prompts/list and prompts/get.', 'wp-mcp-connector' ),
							$method
						)
					);
			}
		} catch ( Throwable $e ) {
			// A tool that throws must not take the whole connection down, and the
			// exception text must not leak file paths to the client.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP MCP Connector: uncaught exception in ' . $method . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return $this->error( $id, self::INTERNAL_ERROR, __( 'The server hit an unexpected error handling that request. Check the site error log for details.', 'wp-mcp-connector' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $id, $result );
		}

		if ( $is_notification ) {
			return null;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * initialize: negotiates protocol version and advertises capabilities.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return array<string,mixed>
	 */
	private function handle_initialize( array $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$supported = self::supported_versions();

		// Echo the client's version when we speak it; otherwise answer with our
		// newest and let the client decide whether to proceed.
		$this->protocol_version = in_array( $requested, $supported, true ) ? $requested : $supported[0];

		if ( ! $this->session_id ) {
			$this->session_id = WPMCP_Session::create( get_current_user_id(), $this->protocol_version, 'http' );
		} else {
			WPMCP_Session::update( $this->session_id, array( 'protocol_version' => $this->protocol_version ) );
		}

		$user = wp_get_current_user();

		return array(
			'protocolVersion' => $this->protocol_version,
			'capabilities'    => array(
				// The list genuinely does change: an administrator can switch
				// permission profile, a credential's scope can be narrowed, and
				// a plugin update can add tools. Declaring it static made every
				// connected client cache its tools forever and never discover
				// new ones. See WPMCP_Rest::tools_revision().
				'tools'     => array( 'listChanged' => true ),
				'resources' => array(
					'subscribe'   => false,
					'listChanged' => false,
				),
				'prompts'   => array( 'listChanged' => false ),
				'logging'   => new stdClass(),
			),
			'serverInfo'      => array(
				'name'    => 'wp-mcp-connector',
				'title'   => sprintf(
					/* translators: %s: site name. */
					__( 'WordPress: %s', 'wp-mcp-connector' ),
					get_bloginfo( 'name' )
				),
				'version' => WPMCP_VERSION,
			),
			'instructions'    => sprintf(
				/* translators: 1: site name, 2: site URL, 3: display name of the connected user, 4: role list. */
				__( 'You are connected to the WordPress site "%1$s" at %2$s, acting as the user %3$s (role: %4$s). Use wp_get_site_info first if you need to know what post types, taxonomies and plugins exist. Prefer wp_search_content over listing everything when looking for a specific page. Content tools accept and return raw HTML or block markup, so preserve existing block comments (<!-- wp:... -->) when editing a post body. Destructive tools trash by default rather than deleting permanently; pass force only when the user has explicitly asked for permanent deletion.', 'wp-mcp-connector' ),
				get_bloginfo( 'name' ),
				home_url( '/' ),
				$user->display_name ? $user->display_name : $user->user_login,
				implode( ', ', (array) $user->roles )
			),
		);
	}

	/**
	 * tools/list.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return array<string,mixed>
	 */
	private function handle_tools_list( array $params ) {
		$registry = wpmcp()->registry();
		$tools    = array();

		foreach ( $registry->available() as $tool ) {
			$tools[] = $registry->to_mcp( $tool, $this->protocol_version );
		}

		return array( 'tools' => $tools );
	}

	/**
	 * tools/call: the guarded path from model intent to WordPress write.
	 *
	 * Order matters here. Existence, exposure, capability and rate limit are all
	 * checked before a single argument is trusted, and argument validation runs
	 * before the callback ever sees the data.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return array<string,mixed>|WP_Error
	 */
	private function handle_tools_call( array $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $name ) {
			return new WP_Error( 'wpmcp_missing_tool_name', __( 'tools/call requires a "name" parameter naming the tool to run.', 'wp-mcp-connector' ), array( 'code' => self::INVALID_PARAMS ) );
		}

		$registry = wpmcp()->registry();
		$tool     = $registry->get( $name );

		if ( null === $tool ) {
			$known = implode( ', ', array_keys( $registry->available() ) );

			return new WP_Error(
				'wpmcp_unknown_tool',
				sprintf(
					/* translators: 1: requested tool name, 2: comma separated list of available tools. */
					__( 'No tool named "%1$s". Available tools: %2$s', 'wp-mcp-connector' ),
					$name,
					$known ? $known : __( '(none, check the plugin settings)', 'wp-mcp-connector' )
				),
				array( 'code' => self::INVALID_PARAMS )
			);
		}

		if ( ! wpmcp()->settings()->is_tool_enabled( $tool ) ) {
			$scope = WPMCP_Auth::current_scope();

			// Say which of the two gates closed, so the answer is actionable:
			// widening a token's scope and changing the site profile are
			// different jobs in different places.
			$message = is_array( $scope ) && '' !== $scope['profile']
				? sprintf(
					/* translators: 1: tool name, 2: token label, 3: scope profile slug. */
					__( 'The tool "%1$s" is outside the scope of the credential you are using ("%2$s", scoped to %3$s). Do not retry. A site administrator would need to issue a credential with a wider scope.', 'wp-mcp-connector' ),
					$name,
					$scope['label'] ? $scope['label'] : __( 'unnamed token', 'wp-mcp-connector' ),
					$scope['profile']
				)
				: sprintf(
					/* translators: %s: tool name. */
					__( 'The tool "%s" is not exposed on this site. An administrator can enable it under Settings, MCP Connector.', 'wp-mcp-connector' ),
					$name
				);

			return new WP_Error( 'wpmcp_tool_disabled', $message, array( 'code' => self::UNAUTHORIZED ) );
		}

		if ( ! current_user_can( $tool['capability'] ) ) {
			return new WP_Error(
				'wpmcp_forbidden',
				sprintf(
					/* translators: 1: tool name, 2: capability slug. */
					__( 'The connected WordPress user lacks the "%2$s" capability needed by "%1$s". Do not retry; tell the user which capability is missing.', 'wp-mcp-connector' ),
					$name,
					$tool['capability']
				),
				array( 'code' => self::UNAUTHORIZED )
			);
		}

		$allowed = WPMCP_Rate_Limiter::consume( get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			$allowed->add_data( array( 'code' => self::RATE_LIMITED ), 'wpmcp_rate_limited' );

			return $allowed;
		}

		$clean = WPMCP_Schema::validate( $args, $tool['input_schema'] );

		if ( is_wp_error( $clean ) ) {
			// Argument problems are the model's to fix, so they come back as a
			// tool result with isError rather than a protocol error: that way the
			// model sees the message and can correct its next call.
			WPMCP_Logger::record( $name, $args, false, $clean->get_error_message() );

			return $this->tool_error( $clean->get_error_message() );
		}

		$result = call_user_func( $tool['callback'], $clean );

		if ( is_wp_error( $result ) ) {
			WPMCP_Logger::record( $name, $clean, false, $result->get_error_message() );

			return $this->tool_error( $result->get_error_message() );
		}

		WPMCP_Logger::record( $name, $clean, true );

		return $this->tool_result( $result, $tool );
	}

	/**
	 * Wraps a successful tool return in MCP content blocks.
	 *
	 * @param mixed               $result Tool return value.
	 * @param array<string,mixed> $tool   Tool definition.
	 * @return array<string,mixed>
	 */
	private function tool_result( $result, array $tool ) {
		$text = is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$payload = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => (string) $text,
				),
			),
			'isError' => false,
		);

		// structuredContent is 2025-06-18 and later, and only meaningful when the
		// tool declared an output schema.
		if ( ! empty( $tool['output_schema'] ) && is_array( $result ) && version_compare( $this->protocol_version, '2025-06-18', '>=' ) ) {
			$payload['structuredContent'] = $result;
		}

		return $payload;
	}

	/**
	 * Wraps a tool failure as an error result the model can read and act on.
	 *
	 * @param string $message Human readable message.
	 * @return array<string,mixed>
	 */
	private function tool_error( $message ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}

	/**
	 * resources/list.
	 *
	 * @return array<string,mixed>
	 */
	private function handle_resources_list() {
		return array(
			'resources' => array(
				array(
					'uri'         => 'wordpress://site/info',
					'name'        => 'site-info',
					'title'       => __( 'Site information', 'wp-mcp-connector' ),
					'description' => __( 'Name, URL, WordPress version, active theme, post types, taxonomies and the connected user.', 'wp-mcp-connector' ),
					'mimeType'    => 'application/json',
				),
				array(
					'uri'         => 'wordpress://content/recent',
					'name'        => 'recent-content',
					'title'       => __( 'Recent content', 'wp-mcp-connector' ),
					'description' => __( 'The twenty most recently modified posts and pages, with status and permalink.', 'wp-mcp-connector' ),
					'mimeType'    => 'application/json',
				),
			),
		);
	}

	/**
	 * resources/read.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return array<string,mixed>|WP_Error
	 */
	private function handle_resources_read( array $params ) {
		$uri = isset( $params['uri'] ) ? (string) $params['uri'] : '';

		switch ( $uri ) {
			case 'wordpress://site/info':
				if ( ! current_user_can( 'read' ) ) {
					return new WP_Error( 'wpmcp_forbidden', __( 'You cannot read this resource.', 'wp-mcp-connector' ), array( 'code' => self::UNAUTHORIZED ) );
				}

				$data = ( new WPMCP_Tools_Site() )->get_site_info( array() );
				break;

			case 'wordpress://content/recent':
				if ( ! current_user_can( 'edit_posts' ) ) {
					return new WP_Error( 'wpmcp_forbidden', __( 'You cannot read this resource.', 'wp-mcp-connector' ), array( 'code' => self::UNAUTHORIZED ) );
				}

				$data = ( new WPMCP_Tools_Content() )->list_posts(
					array(
						'post_type' => 'any',
						'per_page'  => 20,
						'orderby'   => 'modified',
						'status'    => 'any',
					)
				);
				break;

			default:
				return new WP_Error(
					'wpmcp_unknown_resource',
					sprintf(
						/* translators: %s: requested resource URI. */
						__( 'Unknown resource "%s". Call resources/list to see what is available.', 'wp-mcp-connector' ),
						$uri
					),
					array( 'code' => self::INVALID_PARAMS )
				);
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'contents' => array(
				array(
					'uri'      => $uri,
					'mimeType' => 'application/json',
					'text'     => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				),
			),
		);
	}

	/**
	 * The prompt catalogue.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function prompts() {
		return array(
			'draft_post'      => array(
				'title'       => __( 'Draft a post', 'wp-mcp-connector' ),
				'description' => __( 'Research the site\'s existing coverage of a topic, then draft a new post that does not duplicate it.', 'wp-mcp-connector' ),
				'arguments'   => array(
					array(
						'name'        => 'topic',
						'description' => __( 'What the post should be about.', 'wp-mcp-connector' ),
						'required'    => true,
					),
				),
				'template'    => 'Search this WordPress site for existing coverage of "%1$s" using wp_search_content. Summarise what already exists, then create a new draft post on that topic that complements rather than repeats it. Set a working title, an excerpt, and an SEO title and meta description. Leave it as a draft and give me the edit link.',
			),
			'seo_audit'       => array(
				'title'       => __( 'SEO audit', 'wp-mcp-connector' ),
				'description' => __( 'Check published content for missing or weak SEO metadata.', 'wp-mcp-connector' ),
				'arguments'   => array(
					array(
						'name'        => 'post_type',
						'description' => __( 'post or page. Defaults to post.', 'wp-mcp-connector' ),
						'required'    => false,
					),
				),
				'template'    => 'List the published %1$s entries on this site with wp_list_posts, then fetch each one with wp_get_post and report which are missing an SEO title, a meta description, or a featured image. Give me a table sorted worst first. Do not change anything yet.',
			),
			'comment_triage'  => array(
				'title'       => __( 'Triage the comment queue', 'wp-mcp-connector' ),
				'description' => __( 'Review pending comments and recommend approve, spam or trash for each.', 'wp-mcp-connector' ),
				'arguments'   => array(),
				'template'    => 'List the pending comments with wp_list_comments. For each one, quote it, then recommend approve, spam or trash with a one line reason. Do not act on any of them until I confirm.',
			),
		);
	}

	/**
	 * prompts/list.
	 *
	 * @return array<string,mixed>
	 */
	private function handle_prompts_list() {
		$prompts = array();

		foreach ( $this->prompts() as $name => $prompt ) {
			$prompts[] = array(
				'name'        => $name,
				'title'       => $prompt['title'],
				'description' => $prompt['description'],
				'arguments'   => $prompt['arguments'],
			);
		}

		return array( 'prompts' => $prompts );
	}

	/**
	 * prompts/get.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return array<string,mixed>|WP_Error
	 */
	private function handle_prompts_get( array $params ) {
		$name    = isset( $params['name'] ) ? (string) $params['name'] : '';
		$prompts = $this->prompts();

		if ( ! isset( $prompts[ $name ] ) ) {
			return new WP_Error(
				'wpmcp_unknown_prompt',
				sprintf(
					/* translators: 1: requested prompt name, 2: available prompt names. */
					__( 'Unknown prompt "%1$s". Available: %2$s', 'wp-mcp-connector' ),
					$name,
					implode( ', ', array_keys( $prompts ) )
				),
				array( 'code' => self::INVALID_PARAMS )
			);
		}

		$prompt    = $prompts[ $name ];
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$first     = '';

		if ( ! empty( $prompt['arguments'][0]['name'] ) ) {
			$key   = $prompt['arguments'][0]['name'];
			$first = isset( $arguments[ $key ] ) ? sanitize_text_field( (string) $arguments[ $key ] ) : '';
		}

		if ( 'seo_audit' === $name && '' === $first ) {
			$first = 'post';
		}

		return array(
			'description' => $prompt['description'],
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => sprintf( $prompt['template'], $first ),
					),
				),
			),
		);
	}

	/**
	 * Builds a JSON-RPC error response.
	 *
	 * @param mixed               $id      Request id.
	 * @param int                 $code    JSON-RPC error code.
	 * @param string              $message Message.
	 * @param array<string,mixed> $data    Optional data payload.
	 * @return array<string,mixed>
	 */
	public function error( $id, $code, $message, array $data = array() ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( $data ) {
			$error['data'] = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}

	/**
	 * Converts a WP_Error into a JSON-RPC error response.
	 *
	 * @param mixed    $id    Request id.
	 * @param WP_Error $error Error.
	 * @return array<string,mixed>
	 */
	private function error_from_wp_error( $id, WP_Error $error ) {
		$data = $error->get_error_data();
		$code = is_array( $data ) && isset( $data['code'] ) ? (int) $data['code'] : self::INTERNAL_ERROR;

		$payload = array( 'reason' => $error->get_error_code() );

		if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
			$payload['retryAfter'] = (int) $data['retry_after'];
		}

		return $this->error( $id, $code, $error->get_error_message(), $payload );
	}
}

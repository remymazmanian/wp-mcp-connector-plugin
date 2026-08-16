<?php
/**
 * REST transports: Streamable HTTP and legacy HTTP+SSE.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the MCP server over HTTP.
 *
 * Routes, all under /wp-json/mcp/v1/:
 *
 *   POST   /mcp        Streamable HTTP. One JSON-RPC message or a batch.
 *   GET    /mcp        405 by design: this server never initiates messages,
 *                      so there is no standalone stream to open.
 *   DELETE /mcp        Ends a session.
 *   GET    /sse        Legacy HTTP+SSE. Opens the event stream and announces
 *                      the message endpoint.
 *   POST   /messages   Legacy HTTP+SSE. Accepts client messages; the reply is
 *                      delivered on the matching stream.
 *   GET    /health     Diagnostics for setup: is it on, did auth work, what can
 *                      this user see.
 */
class WPMCP_Rest {

	/**
	 * Registers all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// A 401 from an MCP endpoint must tell the client where the authorization
		// server is (RFC 9728). Without this, a hosted client has nothing to
		// discover and falls back to asking a human to type OAuth details by hand.
		add_filter( 'rest_post_dispatch', array( $this, 'add_authenticate_header' ), 10, 3 );

		register_rest_route(
			WPMCP_REST_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_streamable_post' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_streamable_get' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'handle_streamable_delete' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			WPMCP_REST_NAMESPACE,
			'/sse',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_sse' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			WPMCP_REST_NAMESPACE,
			'/messages',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_sse_message' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'session_id' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( 'WPMCP_Session', 'is_valid_id' ),
					),
				),
			)
		);

		register_rest_route(
			WPMCP_REST_NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Attaches WWW-Authenticate to 401 responses from our routes.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public function add_authenticate_header( $response, $server, $request ) {
		if ( ! $response instanceof WP_HTTP_Response || 401 !== $response->get_status() ) {
			return $response;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/' . WPMCP_REST_NAMESPACE ) ) {
			return $response;
		}

		if ( ! wpmcp()->settings()->get( 'oauth_enabled' ) ) {
			return $response;
		}

		$response->header(
			'WWW-Authenticate',
			sprintf(
				'Bearer realm="%s", resource_metadata="%s"',
				esc_attr( get_bloginfo( 'name' ) ),
				home_url( '/.well-known/oauth-protected-resource' )
			)
		);

		return $response;
	}

	/**
	 * Gatekeeper for every route.
	 *
	 * Five checks, cheapest first, each with a message aimed at whoever is
	 * setting the connection up rather than at a developer reading a stack trace.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		$settings = wpmcp()->settings();

		if ( ! $settings->get( 'enabled' ) ) {
			return new WP_Error(
				'wpmcp_disabled',
				__( 'The MCP server is switched off on this site. An administrator can enable it under Settings, MCP Connector.', 'wp-mcp-connector' ),
				array( 'status' => 503 )
			);
		}

		if ( $settings->get( 'require_https' ) && ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			return new WP_Error(
				'wpmcp_insecure_transport',
				__( 'MCP requests must use HTTPS. Credentials sent over plain HTTP would be readable in transit.', 'wp-mcp-connector' ),
				array( 'status' => 403 )
			);
		}

		$origin_check = $this->check_origin();

		if ( is_wp_error( $origin_check ) ) {
			return $origin_check;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wpmcp_unauthorized',
				__( 'Authentication required. Send an Application Password as HTTP Basic auth (username plus the generated password), or a Bearer token if the site has them enabled.', 'wp-mcp-connector' ),
				array( 'status' => 401 )
			);
		}

		$capability = (string) $settings->get( 'capability', 'edit_posts' );

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'wpmcp_forbidden',
				sprintf(
					/* translators: %s: capability slug. */
					__( 'The authenticated user does not have the "%s" capability required to use this MCP server.', 'wp-mcp-connector' ),
					$capability
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Rejects cross-origin browser requests.
	 *
	 * Native MCP clients send no Origin header at all, so this only ever fires
	 * for a browser, which is precisely the DNS-rebinding case worth blocking.
	 *
	 * @return true|WP_Error
	 */
	private function check_origin() {
		if ( empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			return true;
		}

		$origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		$host   = wp_parse_url( $origin, PHP_URL_HOST );
		$site   = wp_parse_url( home_url(), PHP_URL_HOST );

		/**
		 * Filters the hostnames allowed to call the MCP endpoints from a browser.
		 *
		 * @param string[] $allowed Allowed hostnames.
		 */
		$allowed = apply_filters( 'wpmcp_allowed_origins', array( $site ) );

		if ( in_array( $host, (array) $allowed, true ) ) {
			return true;
		}

		return new WP_Error(
			'wpmcp_bad_origin',
			__( 'Requests from this origin are not allowed.', 'wp-mcp-connector' ),
			array( 'status' => 403 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Streamable HTTP
	 * ------------------------------------------------------------------ */

	/**
	 * POST /mcp: the primary transport.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_streamable_post( $request ) {
		$session_id = $this->read_session_id( $request );

		if ( $session_id ) {
			$session = WPMCP_Session::get( $session_id );

			if ( null === $session ) {
				// The specification permits 404 here, and a well-behaved client
				// answers it by re-running initialize. Several hosted clients do
				// not: they cache the session id indefinitely, never retry the
				// handshake, and surface the connector as having no tools at all,
				// which is impossible to diagnose from the client side.
				//
				// Nothing is protected by refusing. Authorization comes from the
				// Bearer credential or Application Password on every request; a
				// session id is a continuity hint, not a permission. So an
				// unknown one is replaced rather than rejected, and the new id
				// travels back in the response header for the client to adopt.
				$session_id = WPMCP_Session::create(
					get_current_user_id(),
					WPMCP_Server::supported_versions()[0],
					'http'
				);

				$session = WPMCP_Session::get( $session_id );
			}

			if ( (int) $session['user_id'] !== get_current_user_id() ) {
				return $this->json_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => null,
						'error'   => array(
							'code'    => WPMCP_Server::UNAUTHORIZED,
							'message' => __( 'This session belongs to a different user.', 'wp-mcp-connector' ),
						),
					),
					403
				);
			}
		}

		$protocol = (string) $request->get_header( 'mcp-protocol-version' );
		$server   = new WPMCP_Server( $session_id, $protocol );
		$payload  = json_decode( $request->get_body(), true );

		if ( null === $payload && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->json_response(
				$server->error( null, WPMCP_Server::PARSE_ERROR, __( 'Request body is not valid JSON.', 'wp-mcp-connector' ) ),
				400
			);
		}

		// A top-level array is a JSON-RPC batch.
		$is_batch = is_array( $payload ) && array_keys( $payload ) === range( 0, count( $payload ) - 1 ) && $payload;
		$messages = $is_batch ? $payload : array( $payload );
		$replies  = array();

		foreach ( $messages as $message ) {
			$reply = $server->handle( $message );

			if ( null !== $reply ) {
				$replies[] = $reply;
			}
		}

		$headers = array();

		if ( $server->session_id() ) {
			$headers['Mcp-Session-Id'] = $server->session_id();
		}

		// Nothing to say back: every message was a notification or a response.
		if ( ! $replies ) {
			return $this->json_response( null, 202, $headers );
		}

		// If the set of tools this caller can see has changed since their
		// session recorded it, tell them. Declaring listChanged without ever
		// sending the notification would be the same broken promise in the
		// other direction, so the notification rides out on the next reply as
		// an event stream, which Streamable HTTP allows for a POST response.
		if ( $server->session_id() && $this->tools_changed( $server->session_id() ) ) {
			$this->send_sse_reply(
				array_merge(
					array(
						array(
							'jsonrpc' => '2.0',
							'method'  => 'notifications/tools/list_changed',
						),
					),
					$replies
				),
				$headers
			);
		}

		return $this->json_response( $is_batch ? $replies : $replies[0], 200, $headers );
	}

	/**
	 * GET /mcp.
	 *
	 * The spec allows a server with no server-initiated messages to answer 405,
	 * and doing so keeps a PHP worker from being pinned open for nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_streamable_get( $request ) {
		return $this->json_response(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => WPMCP_Server::INVALID_REQUEST,
					'message' => __( 'This server does not open a standalone SSE stream on GET /mcp. Send requests as POST, or use the legacy /sse transport.', 'wp-mcp-connector' ),
				),
			),
			405,
			array( 'Allow' => 'POST, DELETE' )
		);
	}

	/**
	 * DELETE /mcp: ends a session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_streamable_delete( $request ) {
		$session_id = $this->read_session_id( $request );

		if ( $session_id ) {
			$session = WPMCP_Session::get( $session_id );

			if ( $session && (int) $session['user_id'] === get_current_user_id() ) {
				WPMCP_Session::destroy( $session_id );
			}
		}

		return $this->json_response( null, 204 );
	}

	/* ---------------------------------------------------------------------
	 * Legacy HTTP+SSE
	 * ------------------------------------------------------------------ */

	/**
	 * GET /sse: opens the legacy event stream.
	 *
	 * Writes directly to the output buffer and exits, because a WP_REST_Response
	 * cannot stream. The loop polls the session queue that POST /messages fills.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void|WP_Error
	 */
	public function handle_sse( $request ) {
		$settings = wpmcp()->settings();

		if ( ! $settings->get( 'sse_enabled' ) ) {
			return new WP_Error(
				'wpmcp_sse_disabled',
				__( 'The legacy HTTP+SSE transport is disabled on this site. Use the Streamable HTTP endpoint at /wp-json/mcp/v1/mcp instead.', 'wp-mcp-connector' ),
				array( 'status' => 404 )
			);
		}

		$session_id = WPMCP_Session::create( get_current_user_id(), WPMCP_Server::supported_versions()[0], 'sse' );
		$endpoint   = add_query_arg( 'session_id', $session_id, rest_url( WPMCP_REST_NAMESPACE . '/messages' ) );
		$deadline   = time() + max( 5, (int) $settings->get( 'sse_max_duration' ) );

		$this->start_stream();

		// The endpoint event is how a legacy client learns where to POST.
		$this->send_event( 'endpoint', $endpoint );

		while ( time() < $deadline ) {
			if ( connection_aborted() ) {
				break;
			}

			foreach ( WPMCP_Session::drain( $session_id ) as $message ) {
				$this->send_event( 'message', wp_json_encode( $message ) );
			}

			// A comment line is a valid SSE keepalive and stops proxies from
			// closing an idle connection.
			echo ": keepalive\n\n";
			$this->flush_output();

			usleep( 400000 );
		}

		WPMCP_Session::destroy( $session_id );
		exit;
	}

	/**
	 * POST /messages: legacy client-to-server channel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_sse_message( $request ) {
		$session_id = (string) $request->get_param( 'session_id' );
		$session    = WPMCP_Session::get( $session_id );

		if ( null === $session || (int) $session['user_id'] !== get_current_user_id() ) {
			return $this->json_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => WPMCP_Server::INVALID_REQUEST,
						'message' => __( 'Unknown or expired session. Reconnect to /wp-json/mcp/v1/sse to obtain a new one.', 'wp-mcp-connector' ),
					),
				),
				404
			);
		}

		$server  = new WPMCP_Server( $session_id, $session['protocol_version'] );
		$payload = json_decode( $request->get_body(), true );

		if ( null === $payload && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->json_response( $server->error( null, WPMCP_Server::PARSE_ERROR, __( 'Request body is not valid JSON.', 'wp-mcp-connector' ) ), 400 );
		}

		$is_batch = is_array( $payload ) && array_keys( $payload ) === range( 0, count( $payload ) - 1 ) && $payload;
		$messages = $is_batch ? $payload : array( $payload );

		foreach ( $messages as $message ) {
			$reply = $server->handle( $message );

			if ( null !== $reply ) {
				WPMCP_Session::enqueue( $session_id, $reply );
			}
		}

		// The reply travels on the SSE stream, so the POST itself just ack's.
		return $this->json_response( null, 202 );
	}

	/* ---------------------------------------------------------------------
	 * Diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * GET /health.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_health( $request ) {
		$settings = wpmcp()->settings();
		$user     = wp_get_current_user();
		$registry = wpmcp()->registry();

		return $this->json_response(
			array(
				'ok'               => true,
				'plugin'           => 'wp-mcp-connector',
				'version'          => WPMCP_VERSION,
				'wordpress'        => get_bloginfo( 'version' ),
				'php'              => PHP_VERSION,
				'site'             => array(
					'name' => get_bloginfo( 'name' ),
					'url'  => home_url( '/' ),
				),
				'protocolVersions' => WPMCP_Server::supported_versions(),
				'transports'       => array(
					'streamable_http' => rest_url( WPMCP_REST_NAMESPACE . '/mcp' ),
					'sse'             => $settings->get( 'sse_enabled' ) ? rest_url( WPMCP_REST_NAMESPACE . '/sse' ) : null,
				),
				'auth'             => array(
					'method' => WPMCP_Auth::current_auth_method(),
					'user'   => $user->user_login,
					'roles'  => array_values( (array) $user->roles ),
					'scope'  => WPMCP_Auth::current_scope(),
				),
				'profile'          => $settings->get( 'profile' ),
				'toolsAvailable'   => array_keys( $registry->available() ),
				'toolsTotal'       => count( $registry->all() ),
				'abilitiesApi'     => function_exists( 'wp_register_ability' ),
				'mcpAdapter'       => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
			),
			200
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * A fingerprint of the tools this caller can currently see.
	 *
	 * Availability is per user and per credential scope, so this is computed
	 * from the resolved list rather than from the registry as a whole.
	 *
	 * @return string
	 */
	public function tools_revision() {
		return md5( implode( ',', array_keys( wpmcp()->registry()->available() ) ) );
	}

	/**
	 * Whether the caller's visible tools differ from what their session recorded,
	 * updating the session as a side effect so the notification fires once.
	 *
	 * @param string $session_id Session id.
	 * @return bool
	 */
	private function tools_changed( $session_id ) {
		$session = WPMCP_Session::get( $session_id );

		if ( null === $session ) {
			return false;
		}

		$current = $this->tools_revision();
		$known   = isset( $session['tools_rev'] ) ? $session['tools_rev'] : '';

		if ( $current === $known ) {
			return false;
		}

		WPMCP_Session::update( $session_id, array( 'tools_rev' => $current ) );

		// A session that never recorded a revision is mid-handshake; the client
		// is about to call tools/list anyway, so telling it the list changed
		// would be noise.
		return '' !== $known;
	}

	/**
	 * Writes several JSON-RPC messages as an event stream and stops.
	 *
	 * Used when a reply has to carry a notification alongside it.
	 *
	 * @param array<int,array<string,mixed>> $messages Messages, in order.
	 * @param array<string,string>           $headers  Extra headers.
	 * @return never
	 */
	private function send_sse_reply( array $messages, array $headers ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Accel-Buffering: no' );
		header( 'MCP-Protocol-Version: ' . WPMCP_Server::supported_versions()[0] );

		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}

		foreach ( $messages as $message ) {
			$this->send_event( 'message', wp_json_encode( $message ) );
		}

		exit;
	}

	/**
	 * Reads the session id from the header, falling back to a query argument
	 * for clients that cannot set custom headers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function read_session_id( $request ) {
		$id = (string) $request->get_header( 'mcp-session-id' );

		if ( '' === $id ) {
			$id = (string) $request->get_param( 'session_id' );
		}

		return WPMCP_Session::is_valid_id( $id ) ? $id : '';
	}

	/**
	 * Builds a JSON REST response with the headers MCP clients expect.
	 *
	 * @param mixed                 $data    Response body, or null for no body.
	 * @param int                   $status  HTTP status.
	 * @param array<string,string>  $headers Extra headers.
	 * @return WP_REST_Response
	 */
	private function json_response( $data, $status = 200, array $headers = array() ) {
		$response = new WP_REST_Response( $data, $status );

		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'MCP-Protocol-Version', WPMCP_Server::supported_versions()[0] );

		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}

	/**
	 * Prepares the process for a long-lived event stream.
	 *
	 * @return void
	 */
	private function start_stream() {
		// Clear anything WordPress has buffered so far, or the first event will
		// arrive behind a wall of markup.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'Connection: keep-alive' );
			// Tells nginx not to buffer the stream, which otherwise delivers
			// nothing until the connection closes.
			header( 'X-Accel-Buffering: no' );
		}

		ignore_user_abort( false );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Writes one SSE event.
	 *
	 * @param string $event Event name.
	 * @param string $data  Payload.
	 * @return void
	 */
	private function send_event( $event, $data ) {
		echo 'event: ' . $event . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( explode( "\n", (string) $data ) as $line ) {
			echo 'data: ' . $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo "\n";
		$this->flush_output();
	}

	/**
	 * Pushes buffered output to the client.
	 *
	 * @return void
	 */
	private function flush_output() {
		if ( ob_get_level() > 0 ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		flush();
	}
}

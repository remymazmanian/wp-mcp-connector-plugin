<?php
/**
 * MCP session state.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks MCP sessions and, for the legacy HTTP+SSE transport, the queue of
 * messages waiting to be flushed down an open event stream.
 *
 * Streamable HTTP only needs the session record (to validate Mcp-Session-Id and
 * to remember the negotiated protocol version). The legacy transport needs the
 * queue as well, because its POST endpoint and its GET stream are two different
 * PHP processes that have to hand messages to each other.
 */
class WPMCP_Session {

	const PREFIX = 'wpmcp_sess_';
	const QUEUE  = 'wpmcp_queue_';

	/**
	 * Session lifetime.
	 *
	 * A day rather than an hour. Sessions hold no authority of their own, so a
	 * long life costs nothing, while a short one guarantees that any client
	 * left connected overnight comes back to an id the server has forgotten.
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * Creates a session.
	 *
	 * @param int    $user_id          Acting user.
	 * @param string $protocol_version Negotiated protocol revision.
	 * @param string $transport        'http' or 'sse'.
	 * @return string Session id.
	 */
	public static function create( $user_id, $protocol_version, $transport = 'http' ) {
		$id = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::PREFIX . $id,
			array(
				'user_id'          => (int) $user_id,
				'protocol_version' => $protocol_version,
				'transport'        => $transport,
				'created'          => time(),
				'initialized'      => false,
			),
			self::TTL
		);

		return $id;
	}

	/**
	 * Fetches a session record.
	 *
	 * @param string $id Session id.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		if ( ! self::is_valid_id( $id ) ) {
			return null;
		}

		$data = get_transient( self::PREFIX . $id );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Merges changes into a session record and refreshes its lifetime.
	 *
	 * @param string              $id      Session id.
	 * @param array<string,mixed> $changes Fields to update.
	 * @return void
	 */
	public static function update( $id, array $changes ) {
		$data = self::get( $id );

		if ( null === $data ) {
			return;
		}

		set_transient( self::PREFIX . $id, array_merge( $data, $changes ), self::TTL );
	}

	/**
	 * Destroys a session and its pending queue.
	 *
	 * @param string $id Session id.
	 * @return void
	 */
	public static function destroy( $id ) {
		if ( ! self::is_valid_id( $id ) ) {
			return;
		}

		delete_transient( self::PREFIX . $id );
		delete_transient( self::QUEUE . $id );
	}

	/**
	 * Appends a message to a session's outbound queue (legacy SSE only).
	 *
	 * @param string              $id      Session id.
	 * @param array<string,mixed> $message JSON-RPC message.
	 * @return void
	 */
	public static function enqueue( $id, array $message ) {
		if ( ! self::is_valid_id( $id ) ) {
			return;
		}

		$queue = get_transient( self::QUEUE . $id );
		$queue = is_array( $queue ) ? $queue : array();

		$queue[] = $message;

		set_transient( self::QUEUE . $id, $queue, self::TTL );
	}

	/**
	 * Drains a session's outbound queue.
	 *
	 * @param string $id Session id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function drain( $id ) {
		if ( ! self::is_valid_id( $id ) ) {
			return array();
		}

		$key   = self::QUEUE . $id;
		$queue = self::read_queue_uncached( $key );

		if ( ! is_array( $queue ) || ! $queue ) {
			return array();
		}

		delete_transient( $key );

		return $queue;
	}

	/**
	 * Reads a queue without trusting the per-request options cache.
	 *
	 * The SSE stream polls in a loop inside one long-lived PHP process, while
	 * POST /messages writes the queue from a different process. Without an
	 * external object cache, WordPress remembers the first cache miss in
	 * `notoptions` for the life of the request, so a plain get_transient() in
	 * the loop would return false forever and no reply would ever be delivered.
	 * Reading the row directly, and evicting the stale cache entries, is what
	 * makes the legacy transport actually work on a default install.
	 *
	 * @param string $key Transient key.
	 * @return mixed Queue array, or false when empty.
	 */
	private static function read_queue_uncached( $key ) {
		if ( wp_using_ext_object_cache() ) {
			return get_transient( $key );
		}

		global $wpdb;

		$option  = '_transient_' . $key;
		$timeout = '_transient_timeout_' . $key;

		// Evict both halves of the transient pair, plus the negative cache that
		// would otherwise short-circuit the lookup.
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( $timeout, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);

		if ( null === $raw ) {
			return false;
		}

		return maybe_unserialize( $raw );
	}

	/**
	 * Validates a session id's shape before it is ever used in a cache key.
	 *
	 * @param mixed $id Candidate id.
	 * @return bool
	 */
	public static function is_valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[a-f0-9]{32}$/', $id );
	}

	/**
	 * Deletes every session and queue. Called on deactivation.
	 *
	 * @return void
	 */
	public static function purge_all() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$like = $wpdb->esc_like( '_transient_' . self::QUEUE ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		wp_cache_flush();
	}
}

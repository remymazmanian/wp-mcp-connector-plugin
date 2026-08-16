<?php
/**
 * Per-user request throttling.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sliding-window rate limiter backed by the object cache, falling back to
 * transients.
 *
 * An agent that loops on a failing tool can otherwise issue hundreds of writes
 * a minute. The window is per acting user, not per IP, because every MCP client
 * behind one Application Password shares an IP anyway.
 */
class WPMCP_Rate_Limiter {

	const GROUP = 'wpmcp_rate';

	/**
	 * Consumes one unit of the caller's budget.
	 *
	 * @param int $user_id Acting user.
	 * @return true|WP_Error True when allowed, WP_Error with retry_after when not.
	 */
	public static function consume( $user_id ) {
		$settings = wpmcp()->settings();
		$limit    = (int) $settings->get( 'rate_limit_requests' );
		$window   = max( 1, (int) $settings->get( 'rate_limit_window' ) );

		if ( $limit <= 0 ) {
			return true;
		}

		$bucket = (int) floor( time() / $window );
		$key    = 'wpmcp_rl_' . (int) $user_id . '_' . $bucket;
		$count  = (int) self::read( $key );

		if ( $count >= $limit ) {
			$retry_after = ( ( $bucket + 1 ) * $window ) - time();

			return new WP_Error(
				'wpmcp_rate_limited',
				sprintf(
					/* translators: 1: request limit, 2: window in seconds, 3: seconds to wait. */
					__( 'Rate limit reached: %1$d requests per %2$d seconds. Wait %3$d seconds before retrying, and consider batching the work into fewer calls.', 'wp-mcp-connector' ),
					$limit,
					$window,
					max( 1, $retry_after )
				),
				array(
					'status'      => 429,
					'retry_after' => max( 1, $retry_after ),
				)
			);
		}

		self::write( $key, $count + 1, $window * 2 );

		return true;
	}

	/**
	 * Reads a counter.
	 *
	 * @param string $key Cache key.
	 * @return int
	 */
	private static function read( $key ) {
		if ( wp_using_ext_object_cache() ) {
			$value = wp_cache_get( $key, self::GROUP );

			return false === $value ? 0 : (int) $value;
		}

		return (int) get_transient( $key );
	}

	/**
	 * Writes a counter.
	 *
	 * @param string $key   Cache key.
	 * @param int    $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	private static function write( $key, $value, $ttl ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );
			return;
		}

		set_transient( $key, $value, $ttl );
	}
}

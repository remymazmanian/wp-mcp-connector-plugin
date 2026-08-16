<?php
/**
 * Activity log.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a short rolling record of tool calls so an admin can answer "what did
 * the AI actually do to my site?" without turning on WP_DEBUG.
 *
 * Arguments are recorded in summary form only: a long post body has no business
 * sitting in an autoloaded option, and neither do credentials that a confused
 * client might have passed as a tool argument.
 */
class WPMCP_Logger {

	const MAX_ENTRIES = 100;

	/**
	 * Records a completed tool call.
	 *
	 * @param string              $tool     Tool name.
	 * @param array<string,mixed> $args     Arguments as received.
	 * @param bool                $success  Whether it succeeded.
	 * @param string              $message  Error message when it did not.
	 * @return void
	 */
	public static function record( $tool, array $args, $success, $message = '' ) {
		if ( ! wpmcp()->settings()->get( 'log_enabled' ) ) {
			return;
		}

		$entries = get_option( WPMCP_OPTION_LOG, array() );
		$entries = is_array( $entries ) ? $entries : array();

		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'tool'    => $tool,
				'user'    => get_current_user_id(),
				'auth'    => WPMCP_Auth::current_auth_method(),
				'args'    => self::summarize( $args ),
				'success' => (bool) $success,
				'message' => mb_substr( (string) $message, 0, 300 ),
			)
		);

		update_option( WPMCP_OPTION_LOG, array_slice( $entries, 0, self::MAX_ENTRIES ), 'no' );
	}

	/**
	 * Returns recent log entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries() {
		$entries = get_option( WPMCP_OPTION_LOG, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( WPMCP_OPTION_LOG, array(), 'no' );
	}

	/**
	 * Reduces arguments to something safe and small enough to store.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,string>
	 */
	private static function summarize( array $args ) {
		$summary  = array();
		$sensitive = array( 'password', 'token', 'secret', 'key', 'base64_data' );

		foreach ( $args as $key => $value ) {
			foreach ( $sensitive as $needle ) {
				if ( false !== stripos( (string) $key, $needle ) ) {
					$summary[ $key ] = '[redacted]';
					continue 2;
				}
			}

			if ( is_scalar( $value ) ) {
				$string = (string) $value;

				// For a long argument the length is the interesting fact, not the
				// first 120 characters of it. This is what makes one client's
				// real per-call payload comparable with another's: a hosted model
				// that claims to send 4,000 characters and lands 773 is only
				// visible if the arrival size is written down.
				if ( mb_strlen( $string ) > 200 ) {
					/* translators: %d: number of characters received. */
					$summary[ $key ] = sprintf( __( '[%d characters]', 'wp-mcp-connector' ), mb_strlen( $string ) );
				} else {
					$summary[ $key ] = $string;
				}
			} elseif ( is_array( $value ) ) {
				/* translators: %d: number of items. */
				$summary[ $key ] = sprintf( __( '[%d items]', 'wp-mcp-connector' ), count( $value ) );
			} else {
				$summary[ $key ] = '[object]';
			}
		}

		return $summary;
	}
}

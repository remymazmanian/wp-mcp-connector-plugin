<?php
/**
 * Uninstall: removes everything the plugin stored. Deactivating keeps settings,
 * tokens and OAuth clients; deleting the plugin from the Plugins screen runs
 * this file and removes them, which revokes every token and OAuth grant.
 *
 * WP MCP Connector
 * Copyright (C) 2026 Remy Mazmanian
 * GPL-2.0-or-later. See LICENSE.
 *
 * @package WPMCP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes the plugin's options, transients and temporary upload files from the
 * current site.
 *
 * @return void
 */
function wpmcp_uninstall_site() {
	global $wpdb;

	// Chunked uploads that never finished leave their temporary files behind.
	$uploads = get_option( 'wpmcp_uploads_in_progress', array() );

	foreach ( is_array( $uploads ) ? $uploads : array() as $row ) {
		if ( ! empty( $row['session']['tmp'] ) && is_string( $row['session']['tmp'] ) && file_exists( $row['session']['tmp'] ) ) {
			wp_delete_file( $row['session']['tmp'] );
		}
	}

	$options = array(
		'wpmcp_settings',
		'wpmcp_tokens',
		'wpmcp_recent_log',
		'wpmcp_oauth_clients',
		'wpmcp_oauth_grants',
		'wpmcp_oauth_tokens',
		'wpmcp_uploads_in_progress',
		'wpmcp_sessions_purged',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Sessions, message queues, rate-limit windows, registration counters and
	// legacy upload records. In a persistent object cache these were never in
	// the options table and expire on their own.
	foreach ( array( 'wpmcp_sess_', 'wpmcp_queue_', 'wpmcp_rl_', 'wpmcp_dcr_', 'wpmcp_upload_' ) as $prefix ) {
		foreach ( array( '_transient_', '_transient_timeout_' ) as $kind ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $kind . $prefix ) . '%'
				)
			);
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wpmcp_site_id ) {
		switch_to_blog( $wpmcp_site_id );
		wpmcp_uninstall_site();
		restore_current_blog();
	}
} else {
	wpmcp_uninstall_site();
}

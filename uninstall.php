<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
// Settings and job history are removed; uploaded files in uploads/ofp-private are kept on purpose
// so customer artwork is never lost by an accidental uninstall. Delete that folder manually if needed.
delete_option( 'ofp_settings' );
delete_option( 'ofp_db_version' );
delete_option( 'ofp_kick_key' );
delete_option( 'ofp_staff_key' );
delete_transient( 'ofp_update_manifest' );
delete_transient( 'ofp_deapi_models' );
delete_option( 'ofp_enh_migrated' );
delete_option( 'ofp_worker_seen' );
delete_transient( 'ofp_replicate_version' );
wp_clear_scheduled_hook( 'ofp_tick' );
wp_clear_scheduled_hook( 'ofp_daily_cleanup' );
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ofp_jobs" ); // phpcs:ignore WordPress.DB

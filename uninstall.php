<?php
/**
 * Uninstall cleanup for Init Reading Position
 *
 * Removes all plugin options, transients, and scheduled cron events.
 * The custom database table (wp_init_rp_positions) and user reading data
 * are intentionally preserved — uninstalling a plugin should not silently
 * destroy user content that may be re-used if the plugin is reinstalled.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	$sites = get_sites( [ 'number' => 0 ] );
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		init_plugin_suite_reading_position_uninstall_site();
		restore_current_blog();
	}
} else {
	init_plugin_suite_reading_position_uninstall_site();
}

/**
 * Delete all plugin data for a single site.
 * Safe to call on every site in a multisite network.
 */
function init_plugin_suite_reading_position_uninstall_site() {
	// === Plugin settings ===
	delete_option( 'init_plugin_suite_reading_position_post_types' );
	delete_option( 'init_plugin_suite_reading_position_selector' );
	delete_option( 'init_plugin_suite_reading_position_auto_clear_on_end' );
	delete_option( 'init_plugin_suite_reading_position_cleanup_stale_enabled' );
	delete_option( 'init_plugin_suite_reading_position_cleanup_stale_days' );
	delete_option( 'init_plugin_suite_reading_position_cleanup_stale_percent' );
	delete_option( 'init_plugin_suite_reading_position_cleanup_inactive_enabled' );
	delete_option( 'init_plugin_suite_reading_position_cleanup_inactive_days' );

	// === Internal state options ===
	delete_option( 'irp_plugin_db_version' );
	delete_option( 'irp_migration_done' );
	delete_option( 'irp_cleanup_stale_cursor' );
	delete_option( 'irp_cleanup_stale_last_run' );
	delete_option( 'irp_cleanup_inactive_cursor' );
	delete_option( 'irp_cleanup_inactive_last_run' );

	// === Transients ===
	delete_transient( 'irp_migration_lock' );
	delete_transient( 'irp_cleanup_stale_lock' );
	delete_transient( 'irp_cleanup_inactive_lock' );

	// === Scheduled cron events ===
	wp_clear_scheduled_hook( 'init_plugin_suite_reading_position_migration_event' );
	wp_clear_scheduled_hook( 'init_plugin_suite_reading_position_cleanup_daily_event' );
	wp_clear_scheduled_hook( 'init_plugin_suite_reading_position_cleanup_stale_event' );
	wp_clear_scheduled_hook( 'init_plugin_suite_reading_position_cleanup_inactive_event' );
}

<?php
/**
 * Background migration runner (user_meta → custom table).
 *
 * @package InitReadingPosition
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init_plugin_suite_reading_position_migration_event', 'init_plugin_suite_reading_position_migration_runner' );

/**
 * Chạy 1 batch migration, tự lên lịch batch kế tiếp nếu vẫn còn dữ liệu.
 */
function init_plugin_suite_reading_position_migration_runner() {
	// LOCK.
	if ( get_transient( 'irp_migration_lock' ) ) {
		return;
	}
	set_transient( 'irp_migration_lock', 1, 60 ); // Lock 1 phút là đủ.

	$has_more = init_plugin_suite_reading_position_maybe_migrate();

	if ( $has_more ) {
		// Schedule lại cái mới sau 5s.
		wp_schedule_single_event( time() + 5, 'init_plugin_suite_reading_position_migration_event' );
	}

	delete_transient( 'irp_migration_lock' );
}

register_activation_hook( INIT_PLUGIN_SUITE_RP_FILE, 'init_plugin_suite_reading_position_schedule_migration' );

/**
 * Lên lịch migration khi kích hoạt plugin.
 */
function init_plugin_suite_reading_position_schedule_migration() {
	if ( ! wp_next_scheduled( 'init_plugin_suite_reading_position_migration_event' ) ) {
		wp_schedule_single_event( time() + 5, 'init_plugin_suite_reading_position_migration_event' );
	}
}

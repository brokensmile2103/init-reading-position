<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init_plugin_suite_rp_migration_event', 'init_plugin_suite_rp_migration_runner' );

function init_plugin_suite_rp_migration_runner() {
    // LOCK
    if ( get_transient( 'irp_migration_lock' ) ) return;
    set_transient( 'irp_migration_lock', 1, 60 ); // Lock 1 phút là đủ

    $has_more = init_plugin_suite_rp_maybe_migrate();

    if ( $has_more ) {
        // Schedule lại cái mới sau 30s thay vì 180s
        wp_schedule_single_event( time() + 30, 'init_plugin_suite_rp_migration_event' );
    }

    delete_transient( 'irp_migration_lock' );
}

register_activation_hook( INIT_PLUGIN_SUITE_RP_FILE, function () {
	if ( ! wp_next_scheduled( 'init_plugin_suite_rp_migration_event' ) ) {
		wp_schedule_single_event( time() + 30, 'init_plugin_suite_rp_migration_event' );
	}
} );

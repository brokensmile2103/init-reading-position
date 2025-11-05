<?php
/**
 * Uninstall cleanup for Init Reading Position
 *
 * Removes plugin options. User meta keys are per-post/device and not enumerated here to avoid heavy scans.
 * If you later track user-meta keys via a registry option, you can delete them safely on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// === Delete plugin options ===
delete_option( 'init_plugin_suite_reading_position_post_types' );
delete_option( 'init_plugin_suite_reading_position_selector' );

// Done.

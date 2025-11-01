<?php
/**
 * Plugin Name: Init Reading Position
 * Description: Remembers where readers left off in a post and automatically scrolls back to that spot when they return. Lightweight, localStorage-based.
 * Plugin URI: https://inithtml.com/plugin/init-reading-position/
 * Version: 1.0.1
 * Author: Init HTML
 * Author URI: https://inithtml.com/
 * Text Domain: init-reading-position
 * Domain Path: /languages
 * Requires at least: 5.5
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// === Constants (standardized to INIT_PLUGIN_SUITE_READING_POSITION_*) ===
define( 'INIT_PLUGIN_SUITE_RP_VERSION', '1.0.1' );
define( 'INIT_PLUGIN_SUITE_RP_FILE',    __FILE__ );
define( 'INIT_PLUGIN_SUITE_RP_PATH',    plugin_dir_path( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_RP_URL',     plugin_dir_url( __FILE__ ) );

// Optional helpers (same canonical prefix).
define( 'INIT_PLUGIN_SUITE_RP_SLUG',              'init-reading-position' );
define( 'INIT_PLUGIN_SUITE_RP_NAMESPACE',         'initrepo/v1' );
define( 'INIT_PLUGIN_SUITE_RP_OPTION_POST_TYPES', 'init_plugin_suite_reading_position_post_types' );

// === Enqueue script if post type is enabled ===
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_singular() ) return;

    $enabled_types = get_option( INIT_PLUGIN_SUITE_RP_OPTION_POST_TYPES, [] );
    $enabled_types = apply_filters( 'init_plugin_suite_reading_position_enabled_types', $enabled_types );

    if ( ! in_array( get_post_type(), $enabled_types, true ) ) return;

    wp_enqueue_script(
        'init-plugin-suite-reading-position',
        INIT_PLUGIN_SUITE_RP_URL . 'assets/js/script.js',
        [],
        INIT_PLUGIN_SUITE_RP_VERSION,
        true
    );

    $delay           = (int) apply_filters( 'init_plugin_suite_reading_position_delay', 1000 );
    $user_logged_in  = is_user_logged_in();
    $scroll_position = 0;

    if ( $user_logged_in ) {
        $post_id = get_the_ID();
        $device  = 'PC';

        if ( wp_is_mobile() ) {
            $device = 'Mobile';
        } elseif ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below
            $user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
            if ( stripos( $user_agent, 'iPad' ) !== false ) {
                $device = 'Tablet';
            }
        }

        // Meta key standardized to plugin prefix as well.
        $meta_key = '_init_plugin_suite_reading_position_' . $post_id . '_' . sanitize_key( $device );
        $data     = get_user_meta( get_current_user_id(), $meta_key, true );

        if ( is_array( $data ) && isset( $data['scrollTop'] ) ) {
            $scroll_position = (int) $data['scrollTop'];
        }
    }

    $localized = [
        'restUrl'       => esc_url_raw( rest_url( INIT_PLUGIN_SUITE_RP_NAMESPACE ) ),
        'postId'        => get_the_ID(),
        'delay'         => $delay,
        'loggedIn'      => $user_logged_in,
        'savedPosition' => $scroll_position,
        'nonce'         => wp_create_nonce( 'wp_rest' ),
    ];

    $localized = apply_filters( 'init_plugin_suite_reading_position_localized_data', $localized, get_the_ID() );

    wp_localize_script( 'init-plugin-suite-reading-position', 'InitRPData', $localized );
} );

// === Admin settings page + REST API ===
if ( is_admin() ) {
    require_once INIT_PLUGIN_SUITE_RP_PATH . 'includes/settings-page.php';
}
require_once INIT_PLUGIN_SUITE_RP_PATH . 'includes/rest-api.php';

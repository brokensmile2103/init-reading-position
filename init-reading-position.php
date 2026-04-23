<?php
/**
 * Plugin Name: Init Reading Position
 * Description: Remembers where readers left off in a post and automatically scrolls back to that spot when they return. Lightweight, localStorage-based.
 * Plugin URI: https://inithtml.com/plugin/init-reading-position/
 * Version: 1.6
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
define( 'INIT_PLUGIN_SUITE_RP_VERSION', '1.6' );
define( 'INIT_PLUGIN_SUITE_RP_FILE',    __FILE__ );
define( 'INIT_PLUGIN_SUITE_RP_PATH',    plugin_dir_path( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_RP_URL',     plugin_dir_url( __FILE__ ) );

// Optional helpers (same canonical prefix).
define( 'INIT_PLUGIN_SUITE_RP_SLUG',      'init-reading-position' );
define( 'INIT_PLUGIN_SUITE_RP_NAMESPACE', 'initrepo/v1' );

// === Enqueue script if post type is enabled ===
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_singular() ) return;

    $enabled_types = get_option( 'init_plugin_suite_reading_position_post_types', [] );
    $enabled_types = apply_filters( 'init_plugin_suite_reading_position_enabled_types', $enabled_types );

    if ( ! in_array( get_post_type(), $enabled_types, true ) ) return;

    wp_enqueue_script(
        'init-plugin-suite-reading-position',
        INIT_PLUGIN_SUITE_RP_URL . 'assets/js/script.js',
        [],
        INIT_PLUGIN_SUITE_RP_VERSION,
        true
    );

    $delay          = (int) apply_filters( 'init_plugin_suite_reading_position_delay', 1000 );
    $user_logged_in = is_user_logged_in();

    // Device detection được trust hoàn toàn từ JS (getDevice()).
    // PHP chỉ cần truyền savedPositions cho logged-in user.
    // JS sẽ gửi đúng device string lên REST API khi save/delete.
    $saved_positions = [
        'pc'     => 0,
        'mobile' => 0,
        'tablet' => 0,
    ];

    if ( $user_logged_in ) {
        $user_id = get_current_user_id();
        $post_id = get_the_ID();

        $data = init_plugin_suite_reading_position_get(
            $user_id,
            $post_id,
            [ 'pc', 'mobile', 'tablet' ]
        );

        $saved_positions = [
            'pc'     => ! empty( $data['pc']['scrollTop'] ) ? (int) $data['pc']['scrollTop'] : 0,
            'mobile' => ! empty( $data['mobile']['scrollTop'] ) ? (int) $data['mobile']['scrollTop'] : 0,
            'tablet' => ! empty( $data['tablet']['scrollTop'] ) ? (int) $data['tablet']['scrollTop'] : 0,
        ];
    }

    $selector   = (string) get_option( 'init_plugin_suite_reading_position_selector', '' );
    $auto_clear = (bool) get_option( 'init_plugin_suite_reading_position_auto_clear_on_end', 1 );

    $localized = [
        'restUrl'        => esc_url_raw( rest_url( INIT_PLUGIN_SUITE_RP_NAMESPACE ) ),
        'postId'         => get_the_ID(),
        'delay'          => $delay,
        'loggedIn'       => $user_logged_in,
        'savedPositions' => $saved_positions,
        'nonce'          => wp_create_nonce( 'wp_rest' ),
        'selector'       => $selector,
        'autoClearOnEnd' => $auto_clear,
    ];

    $localized = apply_filters( 'init_plugin_suite_reading_position_localized_data', $localized, get_the_ID() );

    wp_localize_script( 'init-plugin-suite-reading-position', 'InitRPData', $localized );
} );

// === Admin settings page + REST API ===
if ( is_admin() ) {
    require_once INIT_PLUGIN_SUITE_RP_PATH . 'includes/settings-page.php';
}
require_once INIT_PLUGIN_SUITE_RP_PATH . 'includes/init.php';
require_once INIT_PLUGIN_SUITE_RP_PATH . 'includes/cron.php';
require_once INIT_PLUGIN_SUITE_RP_PATH . 'includes/rest-api.php';

// ==========================
// Settings link
// ==========================

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'init_plugin_suite_reading_position_add_settings_link' );
// Add a "Settings" link to the plugin row in the Plugins admin screen
function init_plugin_suite_reading_position_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'options-general.php?page=' . INIT_PLUGIN_SUITE_RP_SLUG ) . '">' . __( 'Settings', 'init-reading-position' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

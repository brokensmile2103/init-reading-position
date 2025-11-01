<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API endpoint cho Init Reading Position
 */

add_action( 'rest_api_init', function () {
    register_rest_route(
        INIT_PLUGIN_SUITE_RP_NAMESPACE,
        '/scroll',
        [
            [
                'methods'             => WP_REST_Server::CREATABLE, // POST → save
                'callback'            => 'init_plugin_suite_reading_position_handle_scroll_request',
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE, // DELETE → xóa
                'callback'            => 'init_plugin_suite_reading_position_handle_scroll_request',
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            ],
        ]
    );
} );

/**
 * Handle scroll data saving (POST) and deleting (DELETE)
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_reading_position_handle_scroll_request( WP_REST_Request $request ) {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return new WP_Error( 'not_logged_in', __( 'Authentication required.', 'init-reading-position' ), [ 'status' => 401 ] );
    }

    $post_id = (int) $request->get_param( 'post_id' );
    if ( $post_id <= 0 || ! get_post_status( $post_id ) ) {
        return new WP_Error( 'invalid_post', __( 'Invalid post.', 'init-reading-position' ), [ 'status' => 400 ] );
    }

    $device = (string) $request->get_param( 'device' );
    if ( $device === '' ) {
        $device = wp_is_mobile() ? 'mobile' : 'pc';
    }
    $device = sanitize_key( $device );

    // Meta key chuẩn hóa
    $meta_key = apply_filters(
        'init_plugin_suite_reading_position_meta_key',
        "_init_plugin_suite_reading_position_{$post_id}_{$device}",
        $post_id,
        $device
    );

    $method = $request->get_method();

    // ===== DELETE: remove meta =====
    if ( $method === 'DELETE' || $request->get_param( 'action' ) === 'delete' ) {
        $should_delete = apply_filters(
            'init_plugin_suite_reading_position_should_delete',
            true,
            $post_id,
            $device,
            $user_id
        );

        if ( $should_delete ) {
            delete_user_meta( $user_id, $meta_key );
            // xóa thêm key cũ (back-compat)
            $legacy_key = "_init_rp_{$post_id}_{$device}";
            if ( $legacy_key !== $meta_key ) {
                delete_user_meta( $user_id, $legacy_key );
            }
            return rest_ensure_response( [ 'deleted' => true ] );
        }
        return rest_ensure_response( [ 'deleted' => false ] );
    }

    // ===== POST: save/update meta =====
    $scrollTop     = max( 0, (int) ( $request->get_param( 'scroll' ) ?? 0 ) );
    $percent       = min( 100, max( 0, (int) ( $request->get_param( 'percent' ) ?? 0 ) ) );
    $screen_height = max( 0, (int) ( $request->get_param( 'screen_height' ) ?? 0 ) );

    $data = [
        'scrollTop'    => $scrollTop,
        'percent'      => $percent,
        'screenHeight' => $screen_height,
        'updated'      => current_time( 'mysql', true ),
        'postId'       => $post_id,
        'device'       => $device,
    ];

    $data = apply_filters(
        'init_plugin_suite_reading_position_data_to_store',
        $data,
        $post_id,
        $device,
        $user_id
    );

    update_user_meta( $user_id, $meta_key, $data );

    return rest_ensure_response( [
        'success'  => true,
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_key' => $meta_key,
        'data'     => $data,
    ] );
}

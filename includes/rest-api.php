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
 * Handle scroll data saving (POST) and deleting (DELETE).
 *
 * Đã refactor: không còn dùng update_user_meta / delete_user_meta.
 * Mọi thao tác đọc/ghi đi qua custom table thông qua các helper trong db.php:
 *   - init_plugin_suite_rp_upsert()
 *   - init_plugin_suite_rp_delete()
 *   - init_plugin_suite_rp_get()
 *
 * Fallback về meta cũ vẫn được xử lý trong init_plugin_suite_rp_get() cho
 * các user chưa được migrate, đảm bảo backward-compatibility.
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

    $method = $request->get_method();

    // ===== DELETE =====
    if ( $method === 'DELETE' || $request->get_param( 'action' ) === 'delete' ) {
        $should_delete = apply_filters(
            'init_plugin_suite_reading_position_should_delete',
            true,
            $post_id,
            $device,
            $user_id
        );

        if ( $should_delete ) {
            $deleted = init_plugin_suite_rp_delete( $user_id, $post_id, $device );
            return rest_ensure_response( [ 'deleted' => $deleted ] );
        }

        return rest_ensure_response( [ 'deleted' => false ] );
    }

    // ===== POST: save/update =====
    $scroll_top    = max( 0, (int) ( $request->get_param( 'scroll' )        ?? 0 ) );
    $percent       = min( 100, max( 0, (int) ( $request->get_param( 'percent' )       ?? 0 ) ) );
    $screen_height = max( 0, (int) ( $request->get_param( 'screen_height' ) ?? 0 ) );
    $updated_at    = current_time( 'mysql', true );

    // Giữ lại filter để developer bên ngoài có thể can thiệp vào data trước khi lưu
    $data = apply_filters(
        'init_plugin_suite_reading_position_data_to_store',
        [
            'scrollTop'    => $scroll_top,
            'percent'      => $percent,
            'screenHeight' => $screen_height,
            'updated'      => $updated_at,
            'postId'       => $post_id,
            'device'       => $device,
        ],
        $post_id,
        $device,
        $user_id
    );

    // Đọc lại các giá trị sau filter (developer có thể đã chỉnh)
    $scroll_top    = max( 0, (int) ( $data['scrollTop']    ?? $scroll_top ) );
    $percent       = min( 100, max( 0, (int) ( $data['percent']       ?? $percent ) ) );
    $screen_height = max( 0, (int) ( $data['screenHeight'] ?? $screen_height ) );
    $updated_at    = sanitize_text_field( $data['updated'] ?? $updated_at );

    $saved = init_plugin_suite_rp_upsert(
        $user_id,
        $post_id,
        $device,
        $scroll_top,
        $percent,
        $screen_height,
        $updated_at
    );

    if ( ! $saved ) {
        return new WP_Error( 'db_error', __( 'Could not save reading position.', 'init-reading-position' ), [ 'status' => 500 ] );
    }

    return rest_ensure_response( [
        'success' => true,
        'data'    => $data,
    ] );
}

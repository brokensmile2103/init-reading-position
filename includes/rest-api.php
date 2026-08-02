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
 * Mọi thao tác đọc/ghi đi qua custom table thông qua các helper trong init.php:
 *   - init_plugin_suite_reading_position_upsert()
 *   - init_plugin_suite_reading_position_delete()
 *   - init_plugin_suite_reading_position_get()
 *
 * Fallback về meta cũ vẫn được xử lý trong init_plugin_suite_reading_position_get() cho
 * các user chưa được migrate, đảm bảo backward-compatibility.
 *
 * Rate limiting: tối đa 1 POST save / 5 giây / user (wp_cache-based), trừ
 * request `final=1` (lưu cuối trước khi rời trang) luôn được cho qua.
 * Chỉ hoạt động khi site có Object Cache (Redis/Memcached) — zero DB writes.
 * Không có Object Cache → wp_cache là in-memory per-request → rate limit
 * không xuyên request được, nhưng hoàn toàn vô hại; JS MIN_SEND_GAP_MS
 * vẫn là lớp bảo vệ chính cho trường hợp đó. Khi bị throttle, response trả
 * `success:false, throttled:true` (KHÔNG giả vờ thành công) để JS biết và
 * thử lại ở checkpoint kế tiếp thay vì đánh dấu nhầm là đã đồng bộ.
 * DELETE không bị rate-limit vì là thao tác dọn dẹp, ít khi xảy ra.
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

    $allowed_devices = [ 'pc', 'mobile', 'tablet' ];
    $device          = sanitize_key( (string) $request->get_param( 'device' ) );
    if ( ! in_array( $device, $allowed_devices, true ) ) {
        $device = 'pc';
    }

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
            $deleted = init_plugin_suite_reading_position_delete( $user_id, $post_id, $device );
            return rest_ensure_response( [ 'deleted' => $deleted ] );
        }

        return rest_ensure_response( [ 'deleted' => false ] );
    }

    // ===== POST: rate limiting (5s / user, wp_cache-based) =====
    // DELETE không bị giới hạn vì là thao tác dọn dẹp cuối bài.
    // wp_cache → zero DB writes; hoạt động tốt nhất khi có Object Cache.
    // Không có Object Cache thì rate limit không xuyên request được,
    // nhưng JS MIN_SEND_GAP_MS vẫn là lớp bảo vệ chính.
    //
    // `final=1` (gửi từ sendFinalPosition() qua sendBeacon/keepalive khi tab
    // bị ẩn/đóng) LUÔN bỏ qua rate limit VÀ luôn ghi DB thật: đây là cơ hội
    // lưu cuối cùng trước khi rời trang, không có lần thử lại nào sau đó.
    $is_final     = (bool) $request->get_param( 'final' );
    $is_heartbeat = ! $is_final && (bool) $request->get_param( 'heartbeat' );

    // ===== Heartbeat trên site có persistent object cache: ghi cache, bỏ DB =====
    // Heartbeat chỉ là lưới an toàn trong lúc đọc tiếp (không phải checkpoint
    // thật như reversal/final) — ở traffic rất lớn (chục nghìn reader đang
    // đọc đồng thời, ví dụ giờ cao điểm của theme Init Manga), heartbeat mỗi
    // 30s/reader cộng dồn thành hàng trăm UPSERT/giây liên tục vào MySQL, phần
    // lớn sẽ bị ghi đè lại chỉ vài chục giây sau bởi chính heartbeat kế tiếp
    // hoặc final flush. Ghi cache-only ở đây giảm tải DB đáng kể mà không ảnh
    // hưởng độ chính xác: trang tải lại vẫn đọc đúng vị trí (ưu tiên cache),
    // dữ liệu được ghi thật vào DB ở lần reversal/final kế tiếp.
    //
    // wp_using_ext_object_cache() kiểm tra có object cache PERSISTENT không
    // (Redis/Memcached...). Không có thì cache chỉ sống trong 1 request — ghi
    // cache-only lúc đó coi như mất dữ liệu, nên fallback về ghi DB bình
    // thường như trước, đúng tinh thần "graceful degradation" mà rate limiter
    // ở trên cũng đang áp dụng.
    if ( $is_heartbeat && wp_using_ext_object_cache() ) {
        $data = init_plugin_suite_reading_position_extract_payload( $request, $post_id, $device, $user_id );

        init_plugin_suite_reading_position_cache_only_update(
            $user_id,
            $post_id,
            $device,
            $data['scrollTop'],
            $data['percent'],
            $data['screenHeight'],
            $data['updated']
        );

        return rest_ensure_response( [
            'success' => true,
            'cached'  => true, // đã lưu vào cache, chưa chắc đã ghi DB — chỉ để debug/log, JS không cần phân biệt
            'data'    => $data,
        ] );
    }

    if ( ! $is_final ) {
        $rate_key           = 'irp_rl_' . $user_id;
        $rate_limit_seconds = (int) apply_filters( 'init_plugin_suite_reading_position_rate_limit', 5 );
        if ( wp_cache_get( $rate_key, 'irp_rate_limit' ) ) {
            // Trả throttled:true (KHÔNG phải success) để JS biết request này
            // chưa thực sự được lưu và cần thử lại ở checkpoint kế tiếp.
            return rest_ensure_response( [ 'success' => false, 'throttled' => true ] );
        }
        wp_cache_set( $rate_key, 1, 'irp_rate_limit', $rate_limit_seconds );
    }

    // ===== POST: save/update =====
    $data = init_plugin_suite_reading_position_extract_payload( $request, $post_id, $device, $user_id );

    $saved = init_plugin_suite_reading_position_upsert(
        $user_id,
        $post_id,
        $device,
        $data['scrollTop'],
        $data['percent'],
        $data['screenHeight'],
        $data['updated']
    );

    if ( ! $saved ) {
        return new WP_Error( 'db_error', __( 'Could not save reading position.', 'init-reading-position' ), [ 'status' => 500 ] );
    }

    return rest_ensure_response( [
        'success' => true,
        'data'    => $data,
    ] );
}

/**
 * Đọc + chuẩn hóa scroll/percent/screen_height từ request, áp dụng filter
 * `init_plugin_suite_reading_position_data_to_store` rồi đọc lại giá trị sau
 * filter (developer bên ngoài có thể đã chỉnh). Dùng chung cho cả nhánh ghi
 * DB thật và nhánh ghi cache-only (heartbeat) để tránh trùng lặp logic.
 *
 * @param WP_REST_Request $request
 * @param int              $post_id
 * @param string           $device
 * @param int              $user_id
 * @return array{scrollTop:int,percent:int,screenHeight:int,updated:string,postId:int,device:string}
 */
function init_plugin_suite_reading_position_extract_payload( WP_REST_Request $request, $post_id, $device, $user_id ) {
    $scroll_top    = max( 0, (int) ( $request->get_param( 'scroll' )        ?? 0 ) );
    $percent       = min( 100, max( 0, (int) ( $request->get_param( 'percent' )       ?? 0 ) ) );
    $screen_height = max( 0, (int) ( $request->get_param( 'screen_height' ) ?? 0 ) );
    $updated_at    = current_time( 'mysql', true );

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

    return [
        'scrollTop'    => max( 0, (int) ( $data['scrollTop']    ?? $scroll_top ) ),
        'percent'      => min( 100, max( 0, (int) ( $data['percent']       ?? $percent ) ) ),
        'screenHeight' => max( 0, (int) ( $data['screenHeight'] ?? $screen_height ) ),
        'updated'      => sanitize_text_field( $data['updated'] ?? $updated_at ),
        'postId'       => (int) $post_id,
        'device'       => $device,
    ];
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cleanup dữ liệu thừa (tùy chọn, MẶC ĐỊNH TẮT HOÀN TOÀN).
 *
 * Hai lượt dọn độc lập, tự bật/tắt riêng qua trang Settings:
 *
 *   - "stale"    : xóa TỪNG DÒNG đọc dở đã lâu không cập nhật (updated_at)
 *                  VÀ có % (percent) rất thấp — tức gần như chắc chắn người
 *                  đọc đã bỏ hẳn bài đó, không phải đang đọc dở có giá trị.
 *   - "inactive" : xóa TOÀN BỘ dòng của 1 user nếu MAX(updated_at) trên tất
 *                  cả các bài của user đó đã quá lâu không đổi — tức cả tài
 *                  khoản coi như đã ngừng đọc, không chỉ 1 bài cụ thể.
 *
 * Cả hai chạy theo batch nhỏ (khóa bằng transient + tự tái lập lịch qua
 * WP-Cron), đúng pattern init_plugin_suite_reading_position_migration_runner()
 * ở cron.php — không quét toàn bảng trong 1 lần, không cần thêm index mới
 * (đi theo thứ tự PRIMARY KEY cho lượt "stale", theo tiền tố user_id của
 * UNIQUE KEY user_post_device cho lượt "inactive").
 *
 * Sau khi quét hết 1 lượt toàn bảng, mỗi lượt tạm dừng hẳn và chỉ khởi động
 * lượt kế tiếp qua 1 cron chạy 1 lần/ngày (daily kickoff) nếu lượt trước đã
 * hoàn tất cách đây đủ lâu — tránh quét liên tục không nghỉ trên site có
 * bảng rất lớn.
 */

define( 'INIT_PLUGIN_SUITE_RP_CLEANUP_ROW_BATCH', 1000 );
define( 'INIT_PLUGIN_SUITE_RP_CLEANUP_USER_BATCH', 200 );

// ==========================
// Scheduling
// ==========================

register_activation_hook( INIT_PLUGIN_SUITE_RP_FILE, 'init_plugin_suite_reading_position_cleanup_schedule_daily_event' );
add_action( 'admin_init', 'init_plugin_suite_reading_position_cleanup_schedule_daily_event' );

/**
 * Đảm bảo cron ngày (daily kickoff) luôn được lên lịch — kể cả trên site
 * nâng cấp từ bản chưa có tính năng này (activation hook không chạy lại).
 */
function init_plugin_suite_reading_position_cleanup_schedule_daily_event() {
	if ( ! wp_next_scheduled( 'init_plugin_suite_reading_position_cleanup_daily_event' ) ) {
		wp_schedule_event( time(), 'daily', 'init_plugin_suite_reading_position_cleanup_daily_event' );
	}
}

add_action( 'init_plugin_suite_reading_position_cleanup_daily_event', 'init_plugin_suite_reading_position_cleanup_daily_kickoff' );
add_action( 'init_plugin_suite_reading_position_cleanup_stale_event', 'init_plugin_suite_reading_position_cleanup_stale_tick' );
add_action( 'init_plugin_suite_reading_position_cleanup_inactive_event', 'init_plugin_suite_reading_position_cleanup_inactive_tick' );

/**
 * Khởi động lại ngay khi admin vừa bật 1 trong 2 tùy chọn cleanup từ trang
 * Settings — không cần đợi tới cron ngày kế tiếp mới bắt đầu.
 *
 * @param mixed $old_value
 * @param mixed $new_value
 */
function init_plugin_suite_reading_position_cleanup_maybe_kickoff_now( $old_value, $new_value ) {
	if ( (bool) $new_value ) {
		init_plugin_suite_reading_position_cleanup_daily_kickoff();
	}
}
add_action( 'update_option_init_plugin_suite_reading_position_cleanup_stale_enabled', 'init_plugin_suite_reading_position_cleanup_maybe_kickoff_now', 10, 2 );
add_action( 'update_option_init_plugin_suite_reading_position_cleanup_inactive_enabled', 'init_plugin_suite_reading_position_cleanup_maybe_kickoff_now', 10, 2 );

/**
 * Chỉ khởi động 1 lượt quét mới cho từng tính năng nếu: đang bật, không có
 * lượt nào đang chạy dở (chưa có tick kế tiếp nào được lên lịch), và lượt
 * trước đã hoàn tất cách đây đủ lâu (hoặc chưa từng chạy).
 */
function init_plugin_suite_reading_position_cleanup_daily_kickoff() {
	$grace = 20 * HOUR_IN_SECONDS;

	if (
		(bool) get_option( 'init_plugin_suite_reading_position_cleanup_stale_enabled', 0 )
		&& ! wp_next_scheduled( 'init_plugin_suite_reading_position_cleanup_stale_event' )
	) {
		$last_run = (int) get_option( 'irp_cleanup_stale_last_run', 0 );
		if ( 0 === $last_run || ( time() - $last_run ) > $grace ) {
			wp_schedule_single_event( time() + 10, 'init_plugin_suite_reading_position_cleanup_stale_event' );
		}
	}

	if (
		(bool) get_option( 'init_plugin_suite_reading_position_cleanup_inactive_enabled', 0 )
		&& ! wp_next_scheduled( 'init_plugin_suite_reading_position_cleanup_inactive_event' )
	) {
		$last_run = (int) get_option( 'irp_cleanup_inactive_last_run', 0 );
		if ( 0 === $last_run || ( time() - $last_run ) > $grace ) {
			wp_schedule_single_event( time() + 10, 'init_plugin_suite_reading_position_cleanup_inactive_event' );
		}
	}
}

// ==========================
// Lượt "stale" — từng dòng đọc dở bị bỏ
// ==========================

/**
 * Xử lý 1 batch của lượt "stale", đi tuần tự theo PRIMARY KEY (id) — không
 * cần thêm index mới. Với mỗi dòng trong batch: nếu vừa cũ hơn ngưỡng ngày
 * vừa thấp hơn ngưỡng % thì xóa; ngược lại giữ nguyên. Con trỏ luôn tiến
 * tới id lớn nhất ĐÃ QUÉT trong batch (không phải id lớn nhất bị xóa) nên
 * vẫn tiến đều dù batch đó không có dòng nào khớp điều kiện xóa.
 */
function init_plugin_suite_reading_position_cleanup_stale_tick() {
	if ( get_transient( 'irp_cleanup_stale_lock' ) ) {
		return;
	}
	set_transient( 'irp_cleanup_stale_lock', 1, 60 );

	if ( ! (bool) get_option( 'init_plugin_suite_reading_position_cleanup_stale_enabled', 0 ) ) {
		// Bị tắt giữa chừng — dừng hẳn tại đây, con trỏ giữ nguyên để lượt
		// sau (nếu được bật lại) tiếp tục đúng chỗ thay vì quét lại từ đầu.
		delete_transient( 'irp_cleanup_stale_lock' );
		return;
	}

	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	$cursor  = (int) get_option( 'irp_cleanup_stale_cursor', 0 );
	$days    = (int) get_option( 'init_plugin_suite_reading_position_cleanup_stale_days', 365 );
	$percent = (int) get_option( 'init_plugin_suite_reading_position_cleanup_stale_percent', 10 );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$rows = $wpdb->get_results(
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT id, user_id, post_id, device, percent, updated_at FROM {$table}
			 WHERE id > %d ORDER BY id ASC LIMIT %d",
			$cursor,
			INIT_PLUGIN_SUITE_RP_CLEANUP_ROW_BATCH
		),
		ARRAY_A
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	if ( empty( $rows ) ) {
		init_plugin_suite_reading_position_cleanup_finish_pass( 'stale' );
		delete_transient( 'irp_cleanup_stale_lock' );
		return;
	}

	$to_delete = [];
	$last_id   = $cursor;

	foreach ( $rows as $row ) {
		$last_id = (int) $row['id'];

		if ( (int) $row['percent'] < $percent && $row['updated_at'] < $cutoff ) {
			$to_delete[] = (int) $row['id'];
			init_plugin_suite_reading_position_invalidate_cache( (int) $row['user_id'], (int) $row['post_id'], $row['device'] );
		}
	}

	if ( ! empty( $to_delete ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $to_delete ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($placeholders)", ...$to_delete )
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		);
	}

	update_option( 'irp_cleanup_stale_cursor', $last_id, false );
	delete_transient( 'irp_cleanup_stale_lock' );

	if ( count( $rows ) === INIT_PLUGIN_SUITE_RP_CLEANUP_ROW_BATCH ) {
		wp_schedule_single_event( time() + 10, 'init_plugin_suite_reading_position_cleanup_stale_event' );
	} else {
		// Batch cuối chưa đầy — đã chạm cuối bảng ngay trong lượt này.
		init_plugin_suite_reading_position_cleanup_finish_pass( 'stale' );
	}
}

// ==========================
// Lượt "inactive" — toàn bộ tài khoản ngừng đọc
// ==========================

/**
 * Xử lý 1 batch của lượt "inactive", đi theo user_id (tiền tố của UNIQUE
 * KEY user_post_device có sẵn — không cần thêm index mới). Với mỗi user
 * trong batch, tính MAX(updated_at) trên đúng các dòng của user đó; nếu
 * mốc mới nhất vẫn cũ hơn ngưỡng thì xóa TOÀN BỘ dòng của user này.
 */
function init_plugin_suite_reading_position_cleanup_inactive_tick() {
	if ( get_transient( 'irp_cleanup_inactive_lock' ) ) {
		return;
	}
	set_transient( 'irp_cleanup_inactive_lock', 1, 60 );

	if ( ! (bool) get_option( 'init_plugin_suite_reading_position_cleanup_inactive_enabled', 0 ) ) {
		delete_transient( 'irp_cleanup_inactive_lock' );
		return;
	}

	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	$cursor = (int) get_option( 'irp_cleanup_inactive_cursor', 0 );
	$days   = (int) get_option( 'init_plugin_suite_reading_position_cleanup_inactive_days', 730 );
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$user_ids = $wpdb->get_col(
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$table} WHERE user_id > %d ORDER BY user_id ASC LIMIT %d",
			$cursor,
			INIT_PLUGIN_SUITE_RP_CLEANUP_USER_BATCH
		)
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	if ( empty( $user_ids ) ) {
		init_plugin_suite_reading_position_cleanup_finish_pass( 'inactive' );
		delete_transient( 'irp_cleanup_inactive_lock' );
		return;
	}

	$user_ids     = array_map( 'intval', $user_ids );
	$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$activity = $wpdb->get_results(
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->prepare(
			"SELECT user_id, MAX(updated_at) AS last_activity FROM {$table}
			 WHERE user_id IN ($placeholders) GROUP BY user_id",
			...$user_ids
		),
		ARRAY_A
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	);

	$stale_users = [];
	foreach ( $activity as $row ) {
		if ( $row['last_activity'] < $cutoff ) {
			$stale_users[] = (int) $row['user_id'];
		}
	}

	if ( ! empty( $stale_users ) ) {
		$del_placeholders = implode( ',', array_fill( 0, count( $stale_users ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$affected = $wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				"SELECT user_id, post_id, device FROM {$table} WHERE user_id IN ($del_placeholders)",
				...$stale_users
			),
			ARRAY_A
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare( "DELETE FROM {$table} WHERE user_id IN ($del_placeholders)", ...$stale_users )
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		);

		foreach ( $affected as $row ) {
			init_plugin_suite_reading_position_invalidate_cache( (int) $row['user_id'], (int) $row['post_id'], $row['device'] );
		}
	}

	update_option( 'irp_cleanup_inactive_cursor', max( $user_ids ), false );
	delete_transient( 'irp_cleanup_inactive_lock' );

	if ( count( $user_ids ) === INIT_PLUGIN_SUITE_RP_CLEANUP_USER_BATCH ) {
		wp_schedule_single_event( time() + 10, 'init_plugin_suite_reading_position_cleanup_inactive_event' );
	} else {
		init_plugin_suite_reading_position_cleanup_finish_pass( 'inactive' );
	}
}

// ==========================
// Helper dùng chung
// ==========================

/**
 * Đánh dấu 1 lượt quét (stale hoặc inactive) đã hoàn tất toàn bộ bảng —
 * xóa con trỏ (lượt sau bắt đầu lại từ đầu) và ghi mốc thời gian hoàn tất
 * để daily kickoff biết khi nào nên khởi động lượt kế tiếp.
 *
 * @param string $which 'stale' hoặc 'inactive'.
 */
function init_plugin_suite_reading_position_cleanup_finish_pass( $which ) {
	delete_option( "irp_cleanup_{$which}_cursor" );
	update_option( "irp_cleanup_{$which}_last_run", time(), false );
}

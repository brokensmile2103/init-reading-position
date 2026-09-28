<?php
/**
 * Database schema, migration and core read/write helpers.
 *
 * @package InitReadingPosition
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ==========================
// Create / upgrade database
// ==========================

register_activation_hook( INIT_PLUGIN_SUITE_RP_FILE, 'init_plugin_suite_reading_position_on_activation' );
add_action( 'wpmu_new_blog', 'init_plugin_suite_reading_position_on_new_blog', 10, 6 );
add_action( 'admin_init', 'init_plugin_suite_reading_position_maybe_upgrade' );

// Tracks the one-time redundant-index cleanup (schema < 1.7 → 1.7). Bump only if a future
// version needs another index migration to run again.
define( 'INIT_PLUGIN_SUITE_RP_INDEX_MIGRATION_VERSION', 1 );

// Số dòng tối đa xử lý trong 1 lượt SELECT + DELETE khi xóa hàng loạt theo post/user.
define( 'INIT_PLUGIN_SUITE_RP_DELETE_BATCH', 1000 );

/**
 * So sánh phiên bản schema đã lưu với phiên bản plugin, chạy nâng cấp nếu cần (admin_init).
 */
function init_plugin_suite_reading_position_maybe_upgrade() {
	$current_db_version = get_option( 'irp_plugin_db_version', '0.0.0' );
	if ( version_compare( $current_db_version, INIT_PLUGIN_SUITE_RP_VERSION, '<' ) ) {
		init_plugin_suite_reading_position_check_table();
	}
}

/**
 * Activation hook – single site hoặc toàn multisite network.
 */
function init_plugin_suite_reading_position_on_activation() {
	if ( is_multisite() ) {
		$sites = get_sites( array( 'number' => 0 ) );
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			init_plugin_suite_reading_position_create_table();
			restore_current_blog();
		}
	} else {
		init_plugin_suite_reading_position_create_table();
	}

	update_option( 'irp_plugin_db_version', INIT_PLUGIN_SUITE_RP_VERSION );
}

/**
 * Tạo bảng cho site mới trong multisite.
 *
 * @param int    $blog_id Site ID.
 * @param int    $user_id User ID (unused).
 * @param string $domain  Site domain (unused).
 * @param string $path    Site path (unused).
 * @param int    $site_id Network ID (unused).
 * @param array  $meta    Site meta (unused).
 */
function init_plugin_suite_reading_position_on_new_blog( $blog_id, $user_id, $domain, $path, $site_id, $meta ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	switch_to_blog( $blog_id );
	init_plugin_suite_reading_position_create_table();
	restore_current_blog();
}

/**
 * Kiểm tra bảng có tồn tại chưa.
 *
 * Dùng prepare() + esc_like() vì `_` trong prefix bảng là ký tự đại diện của LIKE.
 *
 * @return bool
 */
function init_plugin_suite_reading_position_table_exists() {
	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
}

/**
 * Kiểm tra & tạo bảng nếu chưa tồn tại (admin_init).
 */
function init_plugin_suite_reading_position_check_table() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! init_plugin_suite_reading_position_table_exists() ) {
		init_plugin_suite_reading_position_create_table();
	}

	init_plugin_suite_reading_position_maybe_drop_redundant_index();

	if ( ! wp_next_scheduled( 'init_plugin_suite_reading_position_migration_event' ) ) {
		wp_schedule_single_event( time() + 30, 'init_plugin_suite_reading_position_migration_event' );
	}

	update_option( 'irp_plugin_db_version', INIT_PLUGIN_SUITE_RP_VERSION );
}

/**
 * Dọn index `user_id` dư thừa còn sót lại từ schema < 1.7 (xem docblock của
 * init_plugin_suite_reading_position_create_table()). Idempotent, chỉ chạy 1 lần
 * (tracked qua option `irp_index_migration_done`), an toàn cho cả site cài mới
 * (schema 1.7 vốn không tạo index này ngay từ đầu) lẫn site nâng cấp.
 */
function init_plugin_suite_reading_position_maybe_drop_redundant_index() {
	$done = (int) get_option( 'irp_index_migration_done', 0 );
	if ( $done >= INIT_PLUGIN_SUITE_RP_INDEX_MIGRATION_VERSION ) {
		return;
	}

	if ( ! init_plugin_suite_reading_position_table_exists() ) {
		// Bảng chưa tồn tại (chưa qua activation) – không có gì để dọn, thử lại ở lần check_table() kế tiếp.
		return;
	}

	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$index_exists = $wpdb->get_row( "SHOW INDEX FROM {$table} WHERE Key_name = 'user_id'" );

	if ( $index_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX user_id" );
	}

	update_option( 'irp_index_migration_done', INIT_PLUGIN_SUITE_RP_INDEX_MIGRATION_VERSION, false );
}

/**
 * Tạo bảng wp_init_rp_positions.
 *
 * Schema:
 *   id            – PK
 *   user_id       – FK users.ID
 *   post_id       – ID bài viết
 *   device        – 'pc' | 'mobile' | key tuỳ ý (sanitize_key)
 *   scroll_top    – px từ top
 *   percent       – 0-100
 *   screen_height – chiều cao màn hình (px)
 *   updated_at    – UTC timestamp ISO 8601 (mysql)
 *
 * Index:
 *   user_post_device – UNIQUE để mỗi (user, post, device) chỉ có 1 row → dùng INSERT … ON DUPLICATE KEY UPDATE.
 *                      Nhờ leftmost-prefix rule của MySQL, index này cũng tự phục vụ mọi query lọc theo
 *                      user_id hoặc (user_id, post_id) → KHÔNG cần thêm KEY user_id riêng (đỡ 1 B-tree phải
 *                      maintain mỗi lần INSERT/UPDATE, giảm write overhead).
 *   post_id           – tìm tất cả user đọc 1 bài (admin use-case), không nằm trong prefix của unique key nên vẫn cần riêng.
 *
 * Site nâng cấp từ bản < 1.7 có thể còn index `user_id` cũ; xem
 * init_plugin_suite_reading_position_maybe_drop_redundant_index() để dọn an toàn, chạy đúng 1 lần.
 */
function init_plugin_suite_reading_position_create_table() {
	global $wpdb;
	$table_name      = $wpdb->prefix . 'init_rp_positions';
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		post_id BIGINT UNSIGNED NOT NULL,
		device VARCHAR(32) NOT NULL DEFAULT 'pc',
		scroll_top INT UNSIGNED NOT NULL DEFAULT 0,
		percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
		screen_height INT UNSIGNED NOT NULL DEFAULT 0,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_post_device (user_id, post_id, device),
		KEY post_id (post_id)
	) $charset_collate;";

	dbDelta( $sql );
}

// ==========================
// Migration: user_meta → DB
// ==========================

define( 'INIT_PLUGIN_SUITE_RP_MIGRATION_VERSION', 2 );

/**
 * Pattern LIKE (đã esc_like) cho các meta key cũ cần migrate.
 *
 * Phải escape vì `_` là ký tự đại diện của LIKE: pattern thô `_init_rp_%` vừa
 * khớp nhầm meta key của plugin khác (rồi bị xóa như meta rác), vừa khiến MySQL
 * không dùng được index `meta_key` → quét toàn bảng usermeta mỗi batch.
 *
 * @return string[]
 */
function init_plugin_suite_reading_position_meta_like_patterns() {
	global $wpdb;

	return array(
		$wpdb->esc_like( '_init_plugin_suite_reading_position_' ) . '%', // Canonical.
		$wpdb->esc_like( '_init_rp_' ) . '%',                            // Legacy.
	);
}

/**
 * Migrate reading positions từ user_meta sang custom table.
 *
 * Meta key pattern (canonical):
 *   _init_plugin_suite_reading_position_{post_id}_{device}
 *
 * Legacy pattern (back-compat, từ phiên bản cũ):
 *   _init_rp_{post_id}_{device}
 *
 * Chạy theo batch 200 user/lần (idempotent – xóa meta sau khi migrate xong).
 *
 * @return bool True nếu vẫn còn dữ liệu cần migrate ở batch sau.
 */
function init_plugin_suite_reading_position_maybe_migrate() {
	$done = (int) get_option( 'irp_migration_done', 0 );
	if ( $done >= INIT_PLUGIN_SUITE_RP_MIGRATION_VERSION ) {
		return false;
	}

	if ( ! init_plugin_suite_reading_position_table_exists() ) {
		return false;
	}

	global $wpdb;
	$meta_patterns = init_plugin_suite_reading_position_meta_like_patterns();

	// Lấy 200 user đầu tiên còn meta (không dùng OFFSET vì meta bị delete sau khi xử lý).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$user_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
			 WHERE ( meta_key LIKE %s OR meta_key LIKE %s )
			 ORDER BY user_id ASC
			 LIMIT 200",
			$meta_patterns[0],
			$meta_patterns[1]
		)
	);

	if ( empty( $user_ids ) ) {
		update_option( 'irp_migration_done', INIT_PLUGIN_SUITE_RP_MIGRATION_VERSION, false );
		return false;
	}

	foreach ( $user_ids as $user_id ) {
		init_plugin_suite_reading_position_migrate_user( (int) $user_id );
	}

	// Chỉ cần biết còn sót ÍT NHẤT 1 dòng hay không – không cần COUNT(DISTINCT) cả bảng.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$has_remaining = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->usermeta}
			 WHERE ( meta_key LIKE %s OR meta_key LIKE %s )
			 LIMIT 1",
			$meta_patterns[0],
			$meta_patterns[1]
		)
	);

	if ( null === $has_remaining ) {
		update_option( 'irp_migration_done', INIT_PLUGIN_SUITE_RP_MIGRATION_VERSION, false );
		return false;
	}

	return true;
}

/**
 * Kiểm tra migration user_meta → DB đã hoàn tất chưa (memoized theo request).
 * Dùng để bỏ qua get_meta_fallback() khi đã chắc chắn không còn meta cũ nào –
 * tránh 1-2 lượt get_user_meta() thừa cho mỗi lần đọc chưa có cache.
 *
 * @return bool
 */
function init_plugin_suite_reading_position_migration_is_done() {
	static $done = null;

	if ( null === $done ) {
		$done = ( (int) get_option( 'irp_migration_done', 0 ) >= INIT_PLUGIN_SUITE_RP_MIGRATION_VERSION );
	}

	return $done;
}

/**
 * Migrate tất cả reading position meta của 1 user vào DB.
 *
 * @param int $user_id User ID.
 */
function init_plugin_suite_reading_position_migrate_user( $user_id ) {
	global $wpdb;
	$meta_patterns = init_plugin_suite_reading_position_meta_like_patterns();

	// Lấy tất cả meta key khớp pattern của user này.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->usermeta}
			 WHERE user_id = %d
			   AND ( meta_key LIKE %s OR meta_key LIKE %s )",
			$user_id,
			$meta_patterns[0],
			$meta_patterns[1]
		),
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		return;
	}

	foreach ( $rows as $row ) {
		$meta_key   = $row['meta_key'];
		$meta_value = maybe_unserialize( $row['meta_value'] );

		if ( ! is_array( $meta_value ) ) {
			// Meta rác – xóa luôn.
			delete_user_meta( $user_id, $meta_key );
			continue;
		}

		// Parse post_id + device từ meta key.
		// Canonical: _init_plugin_suite_reading_position_{post_id}_{device}.
		// Legacy:    _init_rp_{post_id}_{device}.
		$parsed = init_plugin_suite_reading_position_parse_meta_key( $meta_key );
		if ( ! $parsed ) {
			delete_user_meta( $user_id, $meta_key );
			continue;
		}

		$post_id       = $parsed['post_id'];
		$device        = $parsed['device'];
		$scroll_top    = max( 0, (int) ( $meta_value['scrollTop'] ?? 0 ) );
		$percent       = min( 100, max( 0, (int) ( $meta_value['percent'] ?? 0 ) ) );
		$screen_height = max( 0, (int) ( $meta_value['screenHeight'] ?? 0 ) );
		$updated_at    = sanitize_text_field( $meta_value['updated'] ?? current_time( 'mysql', true ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updated_at ) ) {
			$updated_at = current_time( 'mysql', true );
		}

		init_plugin_suite_reading_position_upsert(
			$user_id,
			$post_id,
			$device,
			$scroll_top,
			$percent,
			$screen_height,
			$updated_at
		);

		delete_user_meta( $user_id, $meta_key );
	}
}

/**
 * Parse post_id và device từ meta key.
 *
 * @param string $meta_key Meta key.
 * @return array|null  [ 'post_id' => int, 'device' => string ] hoặc null nếu không match.
 */
function init_plugin_suite_reading_position_parse_meta_key( $meta_key ) {
	// Canonical pattern.
	if ( preg_match( '/^_init_plugin_suite_reading_position_(\d+)_(.+)$/', $meta_key, $m ) ) {
		return array(
			'post_id' => (int) $m[1],
			'device'  => sanitize_key( $m[2] ),
		);
	}

	// Legacy pattern.
	if ( preg_match( '/^_init_rp_(\d+)_(.+)$/', $meta_key, $m ) ) {
		return array(
			'post_id' => (int) $m[1],
			'device'  => sanitize_key( $m[2] ),
		);
	}

	return null;
}

// ==========================
// Core DB helpers
// ==========================

/**
 * Tên bảng reading position (có prefix).
 *
 * @return string
 */
function init_plugin_suite_reading_position_table() {
	global $wpdb;
	return $wpdb->prefix . 'init_rp_positions';
}

/**
 * Upsert (INSERT … ON DUPLICATE KEY UPDATE) một reading position.
 *
 * @param int    $user_id       User ID.
 * @param int    $post_id       Post ID.
 * @param string $device        Device key.
 * @param int    $scroll_top    Scroll offset (px).
 * @param int    $percent       Progress (0-100).
 * @param int    $screen_height Viewport height (px).
 * @param string $updated_at    MySQL datetime UTC.
 * @return bool
 */
function init_plugin_suite_reading_position_upsert( $user_id, $post_id, $device, $scroll_top, $percent, $screen_height, $updated_at = '' ) {
	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	if ( '' === $updated_at ) {
		$updated_at = current_time( 'mysql', true );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (user_id, post_id, device, scroll_top, percent, screen_height, updated_at)
			 VALUES (%d, %d, %s, %d, %d, %d, %s)
			 ON DUPLICATE KEY UPDATE
			   scroll_top    = VALUES(scroll_top),
			   percent       = VALUES(percent),
			   screen_height = VALUES(screen_height),
			   updated_at    = VALUES(updated_at)",
			$user_id,
			$post_id,
			$device,
			$scroll_top,
			$percent,
			$screen_height,
			$updated_at
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( false === $result ) {
		return false;
	}

	init_plugin_suite_reading_position_invalidate_cache( $user_id, $post_id, $device );
	return true;
}

/**
 * Lấy reading position của 1 (user, post, device) từ DB.
 *
 * Truyền mảng device để lấy nhiều device trong 1 query (bulk mode), kết quả
 * trả về dạng [ device => row|null ] theo đúng thứ tự device yêu cầu.
 *
 * @param int          $user_id User ID.
 * @param int          $post_id Post ID.
 * @param string|array $device  Device key hoặc danh sách device key.
 * @return array|null  Row dạng legacy hoặc null nếu không có.
 */
function init_plugin_suite_reading_position_get( $user_id, $post_id, $device = 'pc' ) {
	$user_id = (int) $user_id;
	$post_id = (int) $post_id;

	if ( is_array( $device ) ) {
		return init_plugin_suite_reading_position_get_bulk( $user_id, $post_id, $device );
	}

	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	$device    = sanitize_key( $device );
	$cache_key = init_plugin_suite_reading_position_cache_key( $user_id, $post_id, $device );
	$group     = init_plugin_suite_reading_position_cache_group();

	$cached = wp_cache_get( $cache_key, $group );
	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : null;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, post_id, device, scroll_top, percent, screen_height, updated_at
			 FROM {$table}
			 WHERE user_id = %d AND post_id = %d AND device = %s
			 LIMIT 1",
			$user_id,
			$post_id,
			$device
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $row ) {
		$data = init_plugin_suite_reading_position_row_to_legacy( $row );
		wp_cache_set( $cache_key, $data, $group, init_plugin_suite_reading_position_cache_ttl() );
		return $data;
	}

	// Chỉ còn thử đọc user_meta cũ khi migration chưa xác nhận xong; phần lớn site
	// đã migrate từ lâu nên nhánh này thường được bỏ qua hoàn toàn.
	$data = init_plugin_suite_reading_position_migration_is_done()
		? null
		: init_plugin_suite_reading_position_get_meta_fallback( $user_id, $post_id, $device );

	wp_cache_set( $cache_key, $data ?? '', $group, init_plugin_suite_reading_position_cache_ttl() );
	return $data;
}

/**
 * Bulk mode của init_plugin_suite_reading_position_get(): nhiều device trong 1 query.
 *
 * Cache bulk là 1 map [ device => row|null ] dùng chung cho mọi tổ hợp device:
 * cache hit chỉ khi map đã có đủ các device được hỏi, device còn thiếu được
 * query bổ sung rồi gộp vào map (trước đây key bulk cố định nhưng nội dung phụ
 * thuộc danh sách device của lần gọi đầu → gọi với tổ hợp khác có thể nhận
 * thiếu dữ liệu).
 *
 * Khi cache miss trên site có persistent object cache, các entry cache đơn lẻ
 * (có thể là dữ liệu heartbeat cache-only mới hơn DB) được ưu tiên hơn row DB
 * – tránh trường hợp cache bulk đã hết hạn trong lúc đọc dài khiến trang tải
 * lại quay về vị trí cũ trong DB.
 *
 * @param int   $user_id User ID.
 * @param int   $post_id Post ID.
 * @param array $devices Danh sách device key.
 * @return array
 */
function init_plugin_suite_reading_position_get_bulk( $user_id, $post_id, array $devices ) {
	$devices = array_values( array_unique( array_map( 'sanitize_key', $devices ) ) );

	if ( empty( $devices ) ) {
		return array();
	}

	$cache_key = init_plugin_suite_reading_position_bulk_cache_key( $user_id, $post_id );
	$group     = init_plugin_suite_reading_position_cache_group();

	$cached = wp_cache_get( $cache_key, $group );
	$cached = is_array( $cached ) ? $cached : array();

	$missing = array();
	foreach ( $devices as $d ) {
		if ( ! array_key_exists( $d, $cached ) ) {
			$missing[] = $d;
		}
	}

	if ( ! empty( $missing ) ) {
		global $wpdb;
		$table        = init_plugin_suite_reading_position_table();
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_id, device, scroll_top, percent, screen_height, updated_at
				 FROM {$table}
				 WHERE user_id = %d AND post_id = %d AND device IN ($placeholders)",
				array_merge( array( $user_id, $post_id ), $missing )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$fresh = array_fill_keys( $missing, null );

		if ( $rows ) {
			foreach ( $rows as $row ) {
				if ( array_key_exists( $row['device'], $fresh ) ) {
					$fresh[ $row['device'] ] = init_plugin_suite_reading_position_row_to_legacy( $row );
				}
			}
		}

		if ( wp_using_ext_object_cache() ) {
			$single_keys = array();
			foreach ( $missing as $d ) {
				$single_keys[ $d ] = init_plugin_suite_reading_position_cache_key( $user_id, $post_id, $d );
			}

			$singles = init_plugin_suite_reading_position_cache_get_multiple( array_values( $single_keys ), $group );

			foreach ( $single_keys as $d => $key ) {
				if ( isset( $singles[ $key ] ) && is_array( $singles[ $key ] ) ) {
					$fresh[ $d ] = $singles[ $key ];
				}
			}
		}

		if ( ! init_plugin_suite_reading_position_migration_is_done() ) {
			foreach ( $fresh as $d => $value ) {
				if ( null === $value ) {
					$fresh[ $d ] = init_plugin_suite_reading_position_get_meta_fallback( $user_id, $post_id, $d );
				}
			}
		}

		// Union (+) thay vì array_merge() để không đánh số lại key dạng số.
		$cached = $fresh + $cached;
		wp_cache_set( $cache_key, $cached, $group, init_plugin_suite_reading_position_cache_ttl() );
	}

	$result = array();
	foreach ( $devices as $d ) {
		$result[ $d ] = $cached[ $d ];
	}

	return $result;
}

/**
 * Xóa reading position của 1 (user, post, device).
 *
 * @param int    $user_id User ID.
 * @param int    $post_id Post ID.
 * @param string $device  Device key.
 * @return bool
 */
function init_plugin_suite_reading_position_delete( $user_id, $post_id, $device ) {
	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->delete(
		$table,
		array(
			'user_id' => (int) $user_id,
			'post_id' => (int) $post_id,
			'device'  => sanitize_key( $device ),
		),
		array( '%d', '%d', '%s' )
	);

	// Xóa meta cũ (back-compat) phòng khi migration chưa chạy hết. Sau khi migration
	// đã xác nhận xong thì không còn meta nào để xóa – bỏ qua 2 query thừa.
	if ( ! init_plugin_suite_reading_position_migration_is_done() ) {
		$canonical = "_init_plugin_suite_reading_position_{$post_id}_{$device}";
		$legacy    = "_init_rp_{$post_id}_{$device}";
		delete_user_meta( $user_id, $canonical );
		if ( $legacy !== $canonical ) {
			delete_user_meta( $user_id, $legacy );
		}
	}

	init_plugin_suite_reading_position_invalidate_cache( $user_id, $post_id, $device );

	return false !== $deleted;
}

/**
 * Xóa hàng loạt reading position theo 1 cột (post_id hoặc user_id), chia batch.
 *
 * Mỗi lượt chỉ SELECT tối đa INIT_PLUGIN_SUITE_RP_DELETE_BATCH dòng rồi DELETE
 * theo PRIMARY KEY – bài viết/tài khoản có hàng trăm nghìn dòng không còn nạp
 * toàn bộ vào bộ nhớ PHP cùng lúc, cũng không giữ lock 1 câu DELETE khổng lồ.
 * Cache của từng dòng bị xóa được invalidate ngay sau DELETE của batch đó.
 *
 * @param string $column 'post_id' hoặc 'user_id'.
 * @param int    $value  Giá trị cần xóa.
 * @return int Số dòng đã xóa.
 */
function init_plugin_suite_reading_position_delete_where( $column, $value ) {
	if ( ! in_array( $column, array( 'post_id', 'user_id' ), true ) ) {
		return 0;
	}

	$value = (int) $value;
	if ( $value <= 0 ) {
		return 0;
	}

	global $wpdb;
	$table = init_plugin_suite_reading_position_table();
	$batch = INIT_PLUGIN_SUITE_RP_DELETE_BATCH;
	$total = 0;

	do {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, post_id, device FROM {$table} WHERE {$column} = %d LIMIT %d",
				$value,
				$batch
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$fetched = is_array( $rows ) ? count( $rows ) : 0;
		if ( 0 === $fetched ) {
			break;
		}

		$ids     = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$deleted = init_plugin_suite_reading_position_delete_ids( $ids );

		if ( ! $deleted ) {
			// Lỗi DB hoặc không xóa được dòng nào – dừng để tránh lặp vô hạn.
			break;
		}

		$total += $deleted;
		init_plugin_suite_reading_position_invalidate_cache_many( $rows );
	} while ( $fetched === $batch );

	return $total;
}

/**
 * DELETE theo danh sách PRIMARY KEY.
 *
 * @param int[] $ids Row IDs.
 * @return int Số dòng đã xóa (0 nếu lỗi).
 */
function init_plugin_suite_reading_position_delete_ids( array $ids ) {
	if ( empty( $ids ) ) {
		return 0;
	}

	global $wpdb;
	$table        = init_plugin_suite_reading_position_table();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($placeholders)", $ids ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	return (int) $deleted;
}

/**
 * Xóa toàn bộ reading position của 1 bài viết – dọn dữ liệu mồ côi khi bài
 * viết bị xóa vĩnh viễn (post_id không còn trỏ tới nội dung nào cả).
 *
 * @param int $post_id Post ID.
 * @return int Số dòng đã xóa.
 */
function init_plugin_suite_reading_position_delete_by_post( $post_id ) {
	return init_plugin_suite_reading_position_delete_where( 'post_id', $post_id );
}
add_action( 'before_delete_post', 'init_plugin_suite_reading_position_delete_by_post' );

/**
 * Xóa toàn bộ reading position của 1 user – dọn dữ liệu mồ côi khi tài
 * khoản bị xóa vĩnh viễn (user_id không còn trỏ tới tài khoản nào cả).
 *
 * @param int $user_id User ID.
 * @return int Số dòng đã xóa.
 */
function init_plugin_suite_reading_position_delete_by_user( $user_id ) {
	return init_plugin_suite_reading_position_delete_where( 'user_id', $user_id );
}
add_action( 'deleted_user', 'init_plugin_suite_reading_position_delete_by_user' );

/**
 * Chuyển 1 DB row sang format legacy (tương thích với code cũ đọc meta).
 *
 * @param array $row DB row.
 * @return array
 */
function init_plugin_suite_reading_position_row_to_legacy( array $row ) {
	return array(
		'scrollTop'    => (int) $row['scroll_top'],
		'percent'      => (int) $row['percent'],
		'screenHeight' => (int) $row['screen_height'],
		'updated'      => $row['updated_at'],
		'postId'       => (int) $row['post_id'],
		'device'       => $row['device'],
		// Internal.
		'_id'          => (int) $row['id'],
	);
}

/**
 * Fallback: đọc meta cũ khi row DB chưa tồn tại.
 *
 * @param int    $user_id User ID.
 * @param int    $post_id Post ID.
 * @param string $device  Device key.
 * @return array|null
 */
function init_plugin_suite_reading_position_get_meta_fallback( $user_id, $post_id, $device ) {
	$canonical = "_init_plugin_suite_reading_position_{$post_id}_{$device}";
	$data      = get_user_meta( $user_id, $canonical, true );

	if ( empty( $data ) || ! is_array( $data ) ) {
		$legacy = "_init_rp_{$post_id}_{$device}";
		if ( $legacy !== $canonical ) {
			$data = get_user_meta( $user_id, $legacy, true );
		}
	}

	return ( ! empty( $data ) && is_array( $data ) ) ? $data : null;
}

// ==========================
// Cache helpers
// ==========================

/**
 * Object cache group.
 *
 * @return string
 */
function init_plugin_suite_reading_position_cache_group() {
	return 'irp_positions';
}

/**
 * Object cache TTL (seconds).
 *
 * @return int
 */
function init_plugin_suite_reading_position_cache_ttl() {
	return 10 * MINUTE_IN_SECONDS;
}

/**
 * Cache key cho 1 (user, post, device).
 *
 * @param int    $user_id User ID.
 * @param int    $post_id Post ID.
 * @param string $device  Device key.
 * @return string
 */
function init_plugin_suite_reading_position_cache_key( $user_id, $post_id, $device ) {
	return 'pos_' . (int) $user_id . '_' . (int) $post_id . '_' . sanitize_key( $device );
}

/**
 * Cache key cho map nhiều device của 1 (user, post).
 *
 * @param int $user_id User ID.
 * @param int $post_id Post ID.
 * @return string
 */
function init_plugin_suite_reading_position_bulk_cache_key( $user_id, $post_id ) {
	return 'pos_bulk_' . (int) $user_id . '_' . (int) $post_id . '_pc_mobile_tablet';
}

/**
 * Đọc nhiều cache key trong 1 lượt (1 round-trip với Redis/Memcached).
 *
 * Tự fallback khi object-cache drop-in cũ không định nghĩa wp_cache_get_multiple()
 * (WP < 6.1 không có lớp tương thích cho drop-in).
 *
 * @param string[] $keys  Cache keys.
 * @param string   $group Cache group.
 * @return array [ key => value|false ]
 */
function init_plugin_suite_reading_position_cache_get_multiple( array $keys, $group ) {
	if ( function_exists( 'wp_cache_get_multiple' ) ) {
		return wp_cache_get_multiple( $keys, $group );
	}

	$values = array();
	foreach ( $keys as $key ) {
		$values[ $key ] = wp_cache_get( $key, $group );
	}
	return $values;
}

/**
 * Xóa cache khi có thay đổi.
 *
 * @param int    $user_id User ID.
 * @param int    $post_id Post ID.
 * @param string $device  Device key.
 */
function init_plugin_suite_reading_position_invalidate_cache( $user_id, $post_id, $device ) {
	$group = init_plugin_suite_reading_position_cache_group();

	wp_cache_delete( init_plugin_suite_reading_position_cache_key( $user_id, $post_id, $device ), $group );
	wp_cache_delete( init_plugin_suite_reading_position_bulk_cache_key( $user_id, $post_id ), $group );
}

/**
 * Xóa cache cho nhiều dòng cùng lúc (dùng khi xóa hàng loạt).
 *
 * Gộp key trùng (nhiều device cùng chung 1 key bulk) và dùng
 * wp_cache_delete_multiple() khi có để giảm round-trip tới Redis/Memcached.
 *
 * @param array $rows Mỗi phần tử có user_id, post_id, device.
 */
function init_plugin_suite_reading_position_invalidate_cache_many( array $rows ) {
	if ( empty( $rows ) ) {
		return;
	}

	$group = init_plugin_suite_reading_position_cache_group();
	$keys  = array();

	foreach ( $rows as $row ) {
		$user_id = (int) $row['user_id'];
		$post_id = (int) $row['post_id'];

		$keys[ init_plugin_suite_reading_position_cache_key( $user_id, $post_id, $row['device'] ) ] = true;
		$keys[ init_plugin_suite_reading_position_bulk_cache_key( $user_id, $post_id ) ]            = true;
	}

	$keys = array_keys( $keys );

	if ( function_exists( 'wp_cache_delete_multiple' ) ) {
		wp_cache_delete_multiple( $keys, $group );
		return;
	}

	foreach ( $keys as $key ) {
		wp_cache_delete( $key, $group );
	}
}

/**
 * Ghi CHỈ vào object cache — KHÔNG đụng tới DB.
 *
 * Dùng cho các lần lưu "heartbeat" (lưới an toàn trong lúc đang đọc tiếp,
 * không phải checkpoint thật sự như reversal/final) trên site có persistent
 * object cache (Redis/Memcached). Mục đích: ở traffic rất lớn (hàng chục
 * nghìn reader đang đọc đồng thời — ví dụ theme Init Manga giờ cao điểm),
 * heartbeat cứ 30s/reader cộng dồn lại thành hàng trăm UPSERT/giây liên tục
 * vào MySQL dù phần lớn trong số đó sẽ bị ghi đè lại chỉ vài chục giây sau.
 *
 * Vì trang tải lại luôn đọc qua init_plugin_suite_reading_position_get()
 * (ưu tiên cache trước DB), việc chỉ cập nhật cache vẫn đảm bảo hiển thị
 * đúng vị trí ngay cả khi DB tạm thời chưa được ghi. Dữ liệu sẽ được ghi
 * thật vào DB ở lần reversal-sync hoặc final-flush kế tiếp (xem
 * includes/rest-api.php) — nghĩa là trong trường hợp xấu nhất (site sập/
 * cache bị evict giữa hai checkpoint đó), phạm vi có thể mất chỉ tương đương
 * đúng bằng khoảng cách giữa 2 checkpoint thật, không hơn so với heartbeat
 * ghi DB trực tiếp trước đây.
 *
 * KHÔNG gọi hàm này nếu site không có persistent object cache — cache khi đó
 * chỉ tồn tại trong 1 request nên ghi cache-only tương đương với mất dữ liệu
 * hoàn toàn. Caller (rest-api.php) tự kiểm tra wp_using_ext_object_cache()
 * trước khi gọi.
 *
 * @param int    $user_id       User ID.
 * @param int    $post_id       Post ID.
 * @param string $device        Device key.
 * @param int    $scroll_top    Scroll offset (px).
 * @param int    $percent       Progress (0-100).
 * @param int    $screen_height Viewport height (px).
 * @param string $updated_at    MySQL datetime UTC.
 */
function init_plugin_suite_reading_position_cache_only_update( $user_id, $post_id, $device, $scroll_top, $percent, $screen_height, $updated_at ) {
	$data = array(
		'scrollTop'    => (int) $scroll_top,
		'percent'      => (int) $percent,
		'screenHeight' => (int) $screen_height,
		'updated'      => $updated_at,
		'postId'       => (int) $post_id,
		'device'       => sanitize_key( $device ),
		// Chưa có row DB thật cho bản ghi cache-only này tại thời điểm này.
		'_id'          => 0,
	);

	$group = init_plugin_suite_reading_position_cache_group();
	$ttl   = init_plugin_suite_reading_position_cache_ttl();

	wp_cache_set( init_plugin_suite_reading_position_cache_key( $user_id, $post_id, $device ), $data, $group, $ttl );

	// Đồng bộ luôn bulk cache (dùng khi localize cả 3 device một lần) để
	// tránh đọc trúng giá trị cũ từ DB nếu bulk cache đang có sẵn. Nếu bulk
	// cache chưa có, lần đọc bulk kế tiếp tự ưu tiên entry đơn lẻ ở trên.
	$bulk_key = init_plugin_suite_reading_position_bulk_cache_key( $user_id, $post_id );
	$bulk     = wp_cache_get( $bulk_key, $group );
	if ( is_array( $bulk ) ) {
		$bulk[ $data['device'] ] = $data;
		wp_cache_set( $bulk_key, $bulk, $group, $ttl );
	}
}

// ==========================
// Plugin update hook
// ==========================

add_action( 'upgrader_process_complete', 'init_plugin_suite_reading_position_on_update', 10, 2 );

/**
 * Chạy lại kiểm tra schema + lên lịch migration ngay sau khi plugin được cập nhật.
 *
 * @param WP_Upgrader $upgrader_object Upgrader instance (unused).
 * @param array       $options         Update details.
 */
function init_plugin_suite_reading_position_on_update( $upgrader_object, $options ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
	if (
		isset( $options['action'], $options['type'] ) &&
		'update' === $options['action'] &&
		'plugin' === $options['type'] &&
		! empty( $options['plugins'] )
	) {
		foreach ( (array) $options['plugins'] as $plugin_path ) {
			if ( plugin_basename( INIT_PLUGIN_SUITE_RP_FILE ) === $plugin_path ) {
				init_plugin_suite_reading_position_check_table();

				// Reset + schedule lại luôn cho chắc.
				wp_clear_scheduled_hook( 'init_plugin_suite_reading_position_migration_event' );
				wp_schedule_single_event( time() + 30, 'init_plugin_suite_reading_position_migration_event' );

				break;
			}
		}
	}
}

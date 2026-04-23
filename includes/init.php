<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================
// Create / upgrade database
// ==========================

register_activation_hook( INIT_PLUGIN_SUITE_RP_FILE, 'init_plugin_suite_reading_position_on_activation' );
add_action( 'wpmu_new_blog', 'init_plugin_suite_reading_position_on_new_blog', 10, 6 );

add_action( 'admin_init', function () {
	$current_db_version = get_option( 'irp_plugin_db_version', '0.0.0' );
	if ( version_compare( $current_db_version, INIT_PLUGIN_SUITE_RP_VERSION, '<' ) ) {
		init_plugin_suite_reading_position_check_table();
	}
} );

/**
 * Activation hook – single site hoặc toàn multisite network.
 */
function init_plugin_suite_reading_position_on_activation() {
	if ( is_multisite() ) {
		$sites = get_sites( [ 'number' => 0 ] );
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
 */
function init_plugin_suite_reading_position_on_new_blog( $blog_id, $user_id, $domain, $path, $site_id, $meta ) {
	switch_to_blog( $blog_id );
	init_plugin_suite_reading_position_create_table();
	restore_current_blog();
}

/**
 * Kiểm tra & tạo bảng nếu chưa tồn tại (admin_init).
 */
function init_plugin_suite_reading_position_check_table() {
	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'init_rp_positions';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
		init_plugin_suite_reading_position_create_table();
	}

	if ( ! wp_next_scheduled( 'init_plugin_suite_reading_position_migration_event' ) ) {
		wp_schedule_single_event( time() + 30, 'init_plugin_suite_reading_position_migration_event' );
	}

	update_option( 'irp_plugin_db_version', INIT_PLUGIN_SUITE_RP_VERSION );
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
 *   user_post_device – UNIQUE để mỗi (user, post, device) chỉ có 1 row → dùng INSERT … ON DUPLICATE KEY UPDATE
 *   user_id          – tìm tất cả bài của user
 *   post_id          – tìm tất cả user đọc 1 bài (admin use-case)
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
		PRIMARY KEY (id),
		UNIQUE KEY user_post_device (user_id, post_id, device),
		KEY user_id (user_id),
		KEY post_id (post_id)
	) $charset_collate;";

	dbDelta( $sql );
}

// ==========================
// Migration: user_meta → DB
// ==========================

define( 'INIT_PLUGIN_SUITE_RP_MIGRATION_VERSION', 2 );

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
 */
function init_plugin_suite_reading_position_maybe_migrate() {
	$done = (int) get_option( 'irp_migration_done', 0 );
	if ( $done >= INIT_PLUGIN_SUITE_RP_MIGRATION_VERSION ) {
		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'init_rp_positions';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
		return false;
	}

	$meta_patterns = [
		'_init_plugin_suite_reading_position_%', // canonical
		'_init_rp_%',                            // legacy
	];

	// Lấy 200 user đầu tiên còn meta (không dùng OFFSET vì meta bị delete sau khi xử lý)
	$conditions = implode( ' OR ', array_fill( 0, count( $meta_patterns ), 'meta_key LIKE %s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$user_ids = $wpdb->get_col(
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
			 WHERE ( $conditions )
			 ORDER BY user_id ASC
			 LIMIT 200",
			...$meta_patterns
		)
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	);

	if ( empty( $user_ids ) ) {
		update_option( 'irp_migration_done', INIT_PLUGIN_SUITE_RP_MIGRATION_VERSION, false );
		return false;
	}

	foreach ( $user_ids as $user_id ) {
		$user_id = (int) $user_id;
		init_plugin_suite_reading_position_migrate_user( $user_id );
	}

	// Kiểm tra còn sót meta không
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$remaining = (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
			 WHERE ( meta_key LIKE %s OR meta_key LIKE %s )",
			...$meta_patterns
		)
	);

	if ( $remaining === 0 ) {
		update_option( 'irp_migration_done', INIT_PLUGIN_SUITE_RP_MIGRATION_VERSION, false );
		return false;
	}

	return true;
}

/**
 * Migrate tất cả reading position meta của 1 user vào DB.
 *
 * @param int $user_id
 */
function init_plugin_suite_reading_position_migrate_user( $user_id ) {
	global $wpdb;

	// Lấy tất cả meta key khớp pattern của user này
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->usermeta}
			 WHERE user_id = %d
			   AND ( meta_key LIKE %s OR meta_key LIKE %s )",
			$user_id,
			'_init_plugin_suite_reading_position_%',
			'_init_rp_%'
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
			// Meta rác – xóa luôn
			delete_user_meta( $user_id, $meta_key );
			continue;
		}

		// Parse post_id + device từ meta key
		// Canonical: _init_plugin_suite_reading_position_{post_id}_{device}
		// Legacy:    _init_rp_{post_id}_{device}
		$parsed = init_plugin_suite_reading_position_parse_meta_key( $meta_key );
		if ( ! $parsed ) {
			delete_user_meta( $user_id, $meta_key );
			continue;
		}

		[ 'post_id' => $post_id, 'device' => $device ] = $parsed;

		$scroll_top    = max( 0, (int) ( $meta_value['scrollTop']    ?? 0 ) );
		$percent       = min( 100, max( 0, (int) ( $meta_value['percent']       ?? 0 ) ) );
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
 * @param string $meta_key
 * @return array|null  [ 'post_id' => int, 'device' => string ] hoặc null nếu không match.
 */
function init_plugin_suite_reading_position_parse_meta_key( $meta_key ) {
	// Canonical pattern
	if ( preg_match( '/^_init_plugin_suite_reading_position_(\d+)_(.+)$/', $meta_key, $m ) ) {
		return [
			'post_id' => (int) $m[1],
			'device'  => sanitize_key( $m[2] ),
		];
	}

	// Legacy pattern
	if ( preg_match( '/^_init_rp_(\d+)_(.+)$/', $meta_key, $m ) ) {
		return [
			'post_id' => (int) $m[1],
			'device'  => sanitize_key( $m[2] ),
		];
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
 * @param int    $user_id
 * @param int    $post_id
 * @param string $device
 * @param int    $scroll_top
 * @param int    $percent
 * @param int    $screen_height
 * @param string $updated_at   MySQL datetime UTC.
 * @return bool
 */
function init_plugin_suite_reading_position_upsert( $user_id, $post_id, $device, $scroll_top, $percent, $screen_height, $updated_at = '' ) {
	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	if ( $updated_at === '' ) {
		$updated_at = current_time( 'mysql', true );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$result = $wpdb->query(
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"INSERT INTO $table (user_id, post_id, device, scroll_top, percent, screen_height, updated_at)
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	if ( $result === false ) {
		return false;
	}

	init_plugin_suite_reading_position_invalidate_cache( $user_id, $post_id, $device );
	return true;
}

/**
 * Lấy reading position của 1 (user, post, device) từ DB.
 *
 * @param int    $user_id
 * @param int    $post_id
 * @param string $device
 * @return array|null  Row dạng legacy hoặc null nếu không có.
 */
function init_plugin_suite_reading_position_get( $user_id, $post_id, $device ) {
	$user_id = (int) $user_id;
	$post_id = (int) $post_id;
	$device  = sanitize_key( $device );

	$cache_key = init_plugin_suite_reading_position_cache_key( $user_id, $post_id, $device );
	$group     = init_plugin_suite_reading_position_cache_group();

	$cached = wp_cache_get( $cache_key, $group );
	if ( false !== $cached ) {
		return $cached ?: null; // lưu '' để biểu thị "không có"
	}

	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table WHERE user_id = %d AND post_id = %d AND device = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$user_id,
			$post_id,
			$device
		),
		ARRAY_A
	);

	if ( $row ) {
		$data = init_plugin_suite_reading_position_row_to_legacy( $row );
		wp_cache_set( $cache_key, $data, $group, init_plugin_suite_reading_position_cache_ttl() );
		return $data;
	}

	// Fallback: thử đọc meta cũ (canonical rồi legacy)
	$data = init_plugin_suite_reading_position_get_meta_fallback( $user_id, $post_id, $device );
	// Lưu cache dù null để tránh hit DB lại (lưu '' thay cho null)
	wp_cache_set( $cache_key, $data ?? '', $group, init_plugin_suite_reading_position_cache_ttl() );
	return $data;
}

/**
 * Xóa reading position của 1 (user, post, device).
 *
 * @param int    $user_id
 * @param int    $post_id
 * @param string $device
 * @return bool
 */
function init_plugin_suite_reading_position_delete( $user_id, $post_id, $device ) {
	global $wpdb;
	$table = init_plugin_suite_reading_position_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->delete(
		$table,
		[
			'user_id' => (int) $user_id,
			'post_id' => (int) $post_id,
			'device'  => sanitize_key( $device ),
		],
		[ '%d', '%d', '%s' ]
	);

	// Xóa meta cũ (back-compat) phòng khi migration chưa chạy hết
	$canonical = "_init_plugin_suite_reading_position_{$post_id}_{$device}";
	$legacy    = "_init_rp_{$post_id}_{$device}";
	delete_user_meta( $user_id, $canonical );
	if ( $legacy !== $canonical ) {
		delete_user_meta( $user_id, $legacy );
	}

	init_plugin_suite_reading_position_invalidate_cache( $user_id, $post_id, $device );

	return $deleted !== false;
}

/**
 * Chuyển 1 DB row sang format legacy (tương thích với code cũ đọc meta).
 *
 * @param array $row
 * @return array
 */
function init_plugin_suite_reading_position_row_to_legacy( array $row ) {
	return [
		'scrollTop'    => (int) $row['scroll_top'],
		'percent'      => (int) $row['percent'],
		'screenHeight' => (int) $row['screen_height'],
		'updated'      => $row['updated_at'],
		'postId'       => (int) $row['post_id'],
		'device'       => $row['device'],
		// internal
		'_id'          => (int) $row['id'],
	];
}

/**
 * Fallback: đọc meta cũ khi row DB chưa tồn tại.
 *
 * @param int    $user_id
 * @param int    $post_id
 * @param string $device
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

function init_plugin_suite_reading_position_cache_group() {
	return 'irp_positions';
}

function init_plugin_suite_reading_position_cache_ttl() {
	return 10 * MINUTE_IN_SECONDS;
}

/**
 * Cache key cho 1 (user, post, device).
 */
function init_plugin_suite_reading_position_cache_key( $user_id, $post_id, $device ) {
	return 'pos_' . (int) $user_id . '_' . (int) $post_id . '_' . sanitize_key( $device );
}

/**
 * Xóa cache khi có thay đổi.
 */
function init_plugin_suite_reading_position_invalidate_cache( $user_id, $post_id, $device ) {
	wp_cache_delete(
		init_plugin_suite_reading_position_cache_key( $user_id, $post_id, $device ),
		init_plugin_suite_reading_position_cache_group()
	);
}

// ==========================
// Plugin update hook
// ==========================

add_action( 'upgrader_process_complete', 'init_plugin_suite_reading_position_on_update', 10, 2 );

function init_plugin_suite_reading_position_on_update( $upgrader_object, $options ) {
	if (
		isset( $options['action'], $options['type'] ) &&
		$options['action'] === 'update' &&
		$options['type'] === 'plugin' &&
		! empty( $options['plugins'] )
	) {
		foreach ( $options['plugins'] as $plugin_path ) {
			if ( $plugin_path === plugin_basename( INIT_PLUGIN_SUITE_RP_FILE ) ) {
				init_plugin_suite_reading_position_check_table();

				// reset + schedule lại luôn cho chắc
				wp_clear_scheduled_hook( 'init_plugin_suite_reading_position_migration_event' );
				wp_schedule_single_event( time() + 30, 'init_plugin_suite_reading_position_migration_event' );

				break;
			}
		}
	}
}

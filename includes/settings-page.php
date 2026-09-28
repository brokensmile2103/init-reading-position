<?php
/**
 * Admin settings page.
 *
 * @package InitReadingPosition
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// === Add settings page ===
add_action( 'admin_menu', 'init_plugin_suite_reading_position_add_settings_page' );

/**
 * Register the settings page under Settings.
 */
function init_plugin_suite_reading_position_add_settings_page() {
	add_options_page(
		__( 'Init Reading Position', 'init-reading-position' ),
		__( 'Init Reading Position', 'init-reading-position' ),
		'manage_options',
		INIT_PLUGIN_SUITE_RP_SLUG,
		'init_plugin_suite_reading_position_render_settings_page'
	);
}

// === Register settings ===
add_action( 'admin_init', 'init_plugin_suite_reading_position_register_settings' );

/**
 * Register plugin options.
 */
function init_plugin_suite_reading_position_register_settings() {
	register_setting(
		'init_plugin_suite_reading_position_settings_group',
		'init_plugin_suite_reading_position_post_types',
		array(
			'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_post_types',
			'type'              => 'array',
			'default'           => array( 'post' ),
		)
	);

	// Content area selector.
	register_setting(
		'init_plugin_suite_reading_position_settings_group',
		'init_plugin_suite_reading_position_selector',
		array(
			'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_selector',
			'type'              => 'string',
			'default'           => '',
		)
	);

	// Auto-clear on content end (default ON).
	register_setting(
		'init_plugin_suite_reading_position_settings_group',
		'init_plugin_suite_reading_position_auto_clear_on_end',
		array(
			'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_bool',
			'type'              => 'boolean',
			'default'           => 1,
		)
	);

	// === Cleanup: đọc dở bị bỏ (1 dòng cụ thể) – mặc định TẮT ===
	register_setting(
		'init_plugin_suite_reading_position_settings_group',
		'init_plugin_suite_reading_position_cleanup_stale_enabled',
		array(
			'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_bool',
			'type'              => 'boolean',
			'default'           => 0,
		)
	);

	register_setting(
		'init_plugin_suite_reading_position_settings_group',
		'init_plugin_suite_reading_position_cleanup_stale_days',
		array(
			'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_stale_days',
			'type'              => 'integer',
			'default'           => 365,
		)
	);

	register_setting(
		'init_plugin_suite_reading_position_settings_group',
		'init_plugin_suite_reading_position_cleanup_stale_percent',
		array(
			'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_stale_percent',
			'type'              => 'integer',
			'default'           => 10,
		)
	);

	// === Cleanup: tài khoản không hoạt động (toàn bộ user) – mặc định TẮT ===
	register_setting(
		'init_plugin_suite_reading_position_settings_group',
		'init_plugin_suite_reading_position_cleanup_inactive_enabled',
		array(
			'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_bool',
			'type'              => 'boolean',
			'default'           => 0,
		)
	);

	register_setting(
		'init_plugin_suite_reading_position_settings_group',
		'init_plugin_suite_reading_position_cleanup_inactive_days',
		array(
			'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_inactive_days',
			'type'              => 'integer',
			'default'           => 730,
		)
	);
}

// === Render settings page ===

/**
 * Render the settings page.
 */
function init_plugin_suite_reading_position_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $all_post_types['attachment'] );

	// Chỉ fallback về mặc định khi option chưa tồn tại / hỏng. Mảng rỗng là lựa
	// chọn hợp lệ (tắt cho mọi post type) và frontend cũng đang hiểu đúng như vậy;
	// trước đây form lại hiển thị "post" được tick, lệch với hành vi thật.
	$enabled = get_option( 'init_plugin_suite_reading_position_post_types', array( 'post' ) );
	if ( ! is_array( $enabled ) ) {
		$enabled = array( 'post' );
	}

	$selector   = get_option( 'init_plugin_suite_reading_position_selector', '' );
	$auto_clear = (bool) get_option( 'init_plugin_suite_reading_position_auto_clear_on_end', 1 );

	$cleanup_stale_enabled    = (bool) get_option( 'init_plugin_suite_reading_position_cleanup_stale_enabled', 0 );
	$cleanup_stale_days       = (int) get_option( 'init_plugin_suite_reading_position_cleanup_stale_days', 365 );
	$cleanup_stale_percent    = (int) get_option( 'init_plugin_suite_reading_position_cleanup_stale_percent', 10 );
	$cleanup_inactive_enabled = (bool) get_option( 'init_plugin_suite_reading_position_cleanup_inactive_enabled', 0 );
	$cleanup_inactive_days    = (int) get_option( 'init_plugin_suite_reading_position_cleanup_inactive_days', 730 );

	// Các ô nhập số được chèn vào giữa câu đã dịch – chỉ cho phép đúng thẻ/thuộc tính cần thiết.
	$allowed_input = array(
		'input' => array(
			'type'  => true,
			'min'   => true,
			'max'   => true,
			'step'  => true,
			'style' => true,
			'name'  => true,
			'value' => true,
		),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Init Reading Position Settings', 'init-reading-position' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'init_plugin_suite_reading_position_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable for post types:', 'init-reading-position' ); ?></th>
					<td>
						<?php foreach ( $all_post_types as $type ) : ?>
							<label>
								<input type="checkbox"
										name="init_plugin_suite_reading_position_post_types[]"
										value="<?php echo esc_attr( $type->name ); ?>"
										<?php checked( in_array( $type->name, $enabled, true ) ); ?> />
								<?php echo esc_html( $type->label ); ?>
							</label><br>
						<?php endforeach; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="init_plugin_suite_reading_position_selector">
							<?php esc_html_e( 'Content area selector', 'init-reading-position' ); ?>
						</label>
					</th>
					<td>
						<input type="text"
								id="init_plugin_suite_reading_position_selector"
								name="init_plugin_suite_reading_position_selector"
								class="regular-text code"
								value="<?php echo esc_attr( $selector ); ?>"
								placeholder=".entry-content" />
						<p class="description">
							<?php esc_html_e( 'Optional. Enter a CSS selector that defines where reading progress is tracked.', 'init-reading-position' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="init_plugin_suite_reading_position_auto_clear_on_end">
							<?php esc_html_e( 'Auto-clear saved position at content end', 'init-reading-position' ); ?>
						</label>
					</th>
					<td>
						<label>
							<input type="checkbox"
									id="init_plugin_suite_reading_position_auto_clear_on_end"
									name="init_plugin_suite_reading_position_auto_clear_on_end"
									value="1"
									<?php checked( $auto_clear ); ?> />
							<?php esc_html_e( 'Automatically remove the saved reading position when the reader reaches the end of the content area.', 'init-reading-position' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e( 'Clean up abandoned progress', 'init-reading-position' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox"
									id="init_plugin_suite_reading_position_cleanup_stale_enabled"
									name="init_plugin_suite_reading_position_cleanup_stale_enabled"
									value="1"
									<?php checked( $cleanup_stale_enabled ); ?> />
							<?php esc_html_e( 'Periodically delete individual reading positions that are both old and barely started.', 'init-reading-position' ); ?>
						</label>
						<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: number of days input field, 2: percent input field */
									esc_html__( 'Delete a saved position when it has not been updated in at least %1$s days AND its progress is below %2$s%%.', 'init-reading-position' ),
									'<input type="number" min="30" step="1" style="width:80px" name="init_plugin_suite_reading_position_cleanup_stale_days" value="' . esc_attr( $cleanup_stale_days ) . '" />',
									'<input type="number" min="0" max="100" step="1" style="width:70px" name="init_plugin_suite_reading_position_cleanup_stale_percent" value="' . esc_attr( $cleanup_stale_percent ) . '" />'
								),
								$allowed_input
							);
							?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Only rows matching both conditions are removed — a position with high progress is kept even if very old, since the reader may still return to finish it.', 'init-reading-position' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e( 'Clean up inactive accounts', 'init-reading-position' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox"
									id="init_plugin_suite_reading_position_cleanup_inactive_enabled"
									name="init_plugin_suite_reading_position_cleanup_inactive_enabled"
									value="1"
									<?php checked( $cleanup_inactive_enabled ); ?> />
							<?php esc_html_e( 'Periodically delete ALL saved reading positions belonging to accounts that have not read anything in a long time.', 'init-reading-position' ); ?>
						</label>
						<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: number of days input field */
									esc_html__( 'A user is considered inactive when none of their reading positions (on any post) have been updated in at least %s days.', 'init-reading-position' ),
									'<input type="number" min="30" step="1" style="width:80px" name="init_plugin_suite_reading_position_cleanup_inactive_days" value="' . esc_attr( $cleanup_inactive_days ) . '" />'
								),
								$allowed_input
							);
							?>
						</p>
						<p class="description">
							<?php esc_html_e( 'This is separate from the option above — it looks at the account as a whole, not a single post, and removes every saved position for that account, regardless of how much progress was made.', 'init-reading-position' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

// === Sanitize callbacks ===

/**
 * Sanitize enabled post types.
 *
 * @param mixed $input Submitted value.
 * @return string[]
 */
function init_plugin_suite_reading_position_sanitize_post_types( $input ) {
	$output = array();

	if ( is_array( $input ) ) {
		$available_post_types = get_post_types( array( 'public' => true ) );
		unset( $available_post_types['attachment'] );

		foreach ( $input as $pt ) {
			if ( in_array( $pt, $available_post_types, true ) ) {
				$output[] = sanitize_key( $pt );
			}
		}
	}

	return $output;
}

/**
 * Sanitize the content area selector.
 *
 * @param mixed $input Submitted value.
 * @return string
 */
function init_plugin_suite_reading_position_sanitize_selector( $input ) {
	$input = wp_strip_all_tags( (string) $input );
	return trim( $input );
}

/**
 * Sanitize a checkbox value to 0/1.
 *
 * @param mixed $value Submitted value.
 * @return int
 */
function init_plugin_suite_reading_position_sanitize_bool( $value ) {
	return ( ! empty( $value ) ) ? 1 : 0;
}

/**
 * Sàng lọc ngưỡng "số ngày" cho cleanup dữ liệu đọc dở bị bỏ.
 * Chặn dưới 30 ngày để tránh cấu hình quá tay xóa nhầm tiến độ còn mới.
 *
 * @param mixed $value Submitted value.
 * @return int
 */
function init_plugin_suite_reading_position_sanitize_stale_days( $value ) {
	return max( 30, absint( $value ) );
}

/**
 * Sàng lọc ngưỡng "phần trăm" cho cleanup dữ liệu đọc dở bị bỏ (0-100).
 *
 * @param mixed $value Submitted value.
 * @return int
 */
function init_plugin_suite_reading_position_sanitize_stale_percent( $value ) {
	return min( 100, absint( $value ) );
}

/**
 * Sàng lọc ngưỡng "số ngày không hoạt động" cho cleanup theo tài khoản.
 * Chặn dưới 30 ngày cùng lý do với sanitize_stale_days().
 *
 * @param mixed $value Submitted value.
 * @return int
 */
function init_plugin_suite_reading_position_sanitize_inactive_days( $value ) {
	return max( 30, absint( $value ) );
}

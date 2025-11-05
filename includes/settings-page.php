<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// === Add settings page ===
add_action( 'admin_menu', function () {
    add_options_page(
        __( 'Init Reading Position', 'init-reading-position' ),
        __( 'Init Reading Position', 'init-reading-position' ),
        'manage_options',
        INIT_PLUGIN_SUITE_RP_SLUG,
        'init_plugin_suite_reading_position_render_settings_page'
    );
} );

// === Register setting ===
add_action( 'admin_init', function () {
    register_setting(
        'init_plugin_suite_reading_position_settings_group',
        'init_plugin_suite_reading_position_post_types',
        [
            'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_post_types',
            'type'              => 'array',
            'default'           => [ 'post' ],
        ]
    );

    // NEW: Register selector setting
    register_setting(
        'init_plugin_suite_reading_position_settings_group',
        'init_plugin_suite_reading_position_selector',
        [
            'sanitize_callback' => 'init_plugin_suite_reading_position_sanitize_selector',
            'type'              => 'string',
            'default'           => '', // <== mặc định rỗng
        ]
    );
} );

// === Render settings page ===
function init_plugin_suite_reading_position_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $all_post_types = get_post_types( [ 'public' => true ], 'objects' );
    unset( $all_post_types['attachment'] );

    $enabled  = get_option( 'init_plugin_suite_reading_position_post_types' );
    if ( ! is_array( $enabled ) || empty( $enabled ) ) {
        $enabled = [ 'post' ];
    }

    // NEW: get selector option
    $selector = get_option( 'init_plugin_suite_reading_position_selector', '' );
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

                <!-- NEW: Selector input -->
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
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// === Sanitize callback ===
function init_plugin_suite_reading_position_sanitize_post_types( $input ) {
    $output = [];

    if ( is_array( $input ) ) {
        $available_post_types = get_post_types( [ 'public' => true ] );
        unset( $available_post_types['attachment'] ); // bỏ media luôn

        foreach ( $input as $pt ) {
            if ( in_array( $pt, $available_post_types, true ) ) {
                $output[] = sanitize_key( $pt );
            }
        }
    }

    return $output;
}

// === Sanitize selector input ===
function init_plugin_suite_reading_position_sanitize_selector( $input ) {
    $input = wp_strip_all_tags( (string) $input );
    return trim( $input ); // không ép default, để user tự nhập
}

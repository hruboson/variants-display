<?php
/**
 * Plugin Name: Variants Display
 * Text Domain: variants_display
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;
/******************
 * ADMINISTRATION *
 ******************/
class VARIANTS_DISPLAY_Admin {

	const ATTRIBUTE_META_KEY   = '_variants_display_attribute_types';
	const ATTRIBUTE_FIELD_NAME = 'vd_attribute_display_type';
	const ALLOWED_TYPES        = [ 'default', 'pills', 'large', 'color' ];
	const OPTION_KEY = 'vd_settings';

	public static function init() {
		add_action( 'woocommerce_after_product_attribute_settings', [ __CLASS__, 'render_attribute_display_type' ], 10, 2 );
		add_action( 'woocommerce_process_product_meta',              [ __CLASS__, 'save_attribute_display_types' ] );
		add_action( 'wp_ajax_woocommerce_save_attributes', [ __CLASS__, 'save_attribute_display_types_ajax' ], 5 );

		/* attribute metadata */
		add_action( 'init', [ __CLASS__, 'register_term_color_fields' ], 20 );
    	add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_term_color_picker' ] );
		add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
		if ( get_post_type() !== 'product' ) return;
		if ( false ) return;
	}

	public static function render_attribute_display_type( $attribute, $i ) {

		$product_id = self::get_current_product_id();

		$stored = $product_id ? get_post_meta( $product_id, self::ATTRIBUTE_META_KEY, true ) : [];
		$stored = is_array( $stored ) ? $stored : [];

		$key     = sanitize_title( $attribute->get_name() );
		$current = isset( $stored[ $key ] ) ? $stored[ $key ] : 'default';

		$types = [
			'default' => __( 'Default', 'variants_display' ),
			'pills'  => __( 'Pills', 'variants_display' ),
			'large'    => __( 'Large', 'variants_display' ),
			'color'   => __( 'Color', 'variants_display' ),
		];
		?>
		<tr>
			<td colspan="2">
				<label><?php esc_html_e( 'Display type', 'variants_display' ); ?>:</label>
				<select
					name="<?php echo esc_attr( self::ATTRIBUTE_FIELD_NAME ); ?>[<?php echo esc_attr( $i ); ?>]"
					class="vd-attribute-display-type"
				>
					<?php foreach ( $types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	* Resolves the product being edited, whether we're on a normal
	* admin page load (global $post is set) or mid-AJAX where
	* WooCommerce rebuilds attribute row HTML without setting $post
	* (e.g. right after "Save attributes").
	*
	* @return int
	*/
	private static function get_current_product_id() {
		global $post;

		if ( ! empty( $post->ID ) ) {
			return (int) $post->ID;
		}

		if ( ! empty( $_REQUEST['post_id'] ) ) {
			return absint( $_REQUEST['post_id'] );
		}

		if ( ! empty( $_REQUEST['product_id'] ) ) {
			return absint( $_REQUEST['product_id'] );
		}

		return 0;
	}

	/**
	* Full product save (Update/Publish). Fields are plain top-level
	* $_POST values here.
	*
	* @param int $post_id
	*/
	public static function save_attribute_display_types( $post_id ) {
		self::save_attribute_display_types_from_array( $post_id, wp_unslash( $_POST ) );
	}

	/**
	* Standalone "Save attributes" button. WooCommerce sends the whole
	* attributes form as a single urlencoded string in $_POST['data'],
	* and the product ID as $_POST['post_id'] (NOT 'product_id').
	* We parse it the same way WC_AJAX::save_attributes() does.
	*
	* @return void
	*/
	public static function save_attribute_display_types_ajax() {

		check_ajax_referer( 'save-attributes', 'security' );

		if ( ! current_user_can( 'edit_products' ) || ! isset( $_POST['data'], $_POST['post_id'] ) ) {
			return;
		}

		$post_id = absint( $_POST['post_id'] );

		parse_str( wp_unslash( $_POST['data'] ), $data );

		self::save_attribute_display_types_from_array( $post_id, $data );
	}

	/**
	* Shared save logic used by both the full-save and AJAX-save paths.
	*
	* @param int   $post_id
	* @param array $data Either wp_unslash($_POST) or a parsed $_POST['data'] string.
	*/
	private static function save_attribute_display_types_from_array( $post_id, $data ) {

		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$display_types = [];

		if ( isset( $data['attribute_names'] ) && is_array( $data['attribute_names'] ) ) {

			$names = $data['attribute_names'];
			$types = isset( $data[ self::ATTRIBUTE_FIELD_NAME ] ) && is_array( $data[ self::ATTRIBUTE_FIELD_NAME ] )
				? $data[ self::ATTRIBUTE_FIELD_NAME ]
				: [];

			foreach ( $names as $i => $name ) {
				$key = sanitize_title( $name );
				if ( '' === $key ) continue;

				$value = isset( $types[ $i ] ) ? sanitize_key( $types[ $i ] ) : 'default';
				$display_types[ $key ] = in_array( $value, self::ALLOWED_TYPES, true ) ? $value : 'default';
			}
		}

		if ( ! empty( $display_types ) ) {
			update_post_meta( $post_id, self::ATTRIBUTE_META_KEY, $display_types );
		} else {
			delete_post_meta( $post_id, self::ATTRIBUTE_META_KEY );
		}
	}

	public static function register_term_color_fields() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) return;

		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			$taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name );

			add_action( "{$taxonomy}_add_form_fields", [ __CLASS__, 'render_term_color_add_field' ] );
			add_action( "{$taxonomy}_edit_form_fields", [ __CLASS__, 'render_term_color_edit_field' ], 10, 2 );
			add_action( "created_{$taxonomy}", [ __CLASS__, 'save_term_color_field' ] );
			add_action( "edited_{$taxonomy}",  [ __CLASS__, 'save_term_color_field' ] );
		}
	}

	public static function enqueue_term_color_picker( $hook ) {
		if ( ! in_array( $hook, [ 'edit-tags.php', 'term.php' ], true ) ) return;

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', "
			jQuery( function( \$ ) {
				\$( '.vd-term-color-hex' ).wpColorPicker();
			} );
		" );
	}

	public static function render_term_color_add_field() {
		?>
		<div class="form-field">
			<label for="vd_term_color_hex"><?php esc_html_e( 'Color (hex)', 'variants_display' ); ?></label>
			<input type="text" id="vd_term_color_hex" name="vd_term_color_hex" class="vd-term-color-hex" value="" />
			<p class="description"><?php esc_html_e( 'Optional. If set, this fills the selector button directly instead of using a variation image.', 'variants_display' ); ?></p>
		</div>
		<?php
	}

	public static function render_term_color_edit_field( $term ) {
		$hex = get_term_meta( $term->term_id, '_vd_term_color_hex', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="vd_term_color_hex"><?php esc_html_e( 'Color (hex)', 'variants_display' ); ?></label></th>
			<td>
				<input type="text" id="vd_term_color_hex" name="vd_term_color_hex" class="vd-term-color-hex" value="<?php echo esc_attr( $hex ); ?>" />
				<p class="description"><?php esc_html_e( 'Optional. If set, this fills the selector button directly instead of using a variation image.', 'variants_display' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save_term_color_field( $term_id ) {
		if ( ! current_user_can( 'manage_product_terms' ) ) return;

		if ( ! empty( $_POST['vd_term_color_hex'] ) ) {
			$hex = sanitize_hex_color( wp_unslash( $_POST['vd_term_color_hex'] ) );
			if ( $hex ) {
				update_term_meta( $term_id, '_vd_term_color_hex', $hex );
			} else {
				delete_term_meta( $term_id, '_vd_term_color_hex' ); // invalid input, don't store garbage
			}
		} else {
			delete_term_meta( $term_id, '_vd_term_color_hex' );
		}
	}

	public static function register_settings() {

		register_setting( 'vd_settings_group', self::OPTION_KEY );

		add_settings_section(
			'vd_main_section',
			__( 'General Settings', 'variants_display' ),
			null,
			'vd_settings_page'
		);

		add_settings_field(
			'vd_enable_menu',
			__( 'Enable admin menu entry', 'variants_display' ),
			[ __CLASS__, 'render_enable_menu_field' ],
			'vd_settings_page',
			'vd_main_section'
		);

		add_settings_field(
			'vd_hide_unavailable',
			__( 'Hide unavailable variants', 'variants_display' ),
			[ __CLASS__, 'render_hide_unavailable_field' ],
			'vd_settings_page',
			'vd_main_section'
		);
	}

	public static function get_settings() {
		$defaults = [
			'enable_menu'      => 0,
			'hide_unavailable' => 0,
		];

		$options = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( $options, $defaults );
	}

	public static function render_enable_menu_field() {
		$options = self::get_settings();
		?>
		<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_menu]" value="1" <?php checked( $options['enable_menu'], 1 ); ?> />
		<label><?php esc_html_e( 'Show plugin in admin sidebar', 'variants_display' ); ?></label>
		<?php
	}

	public static function render_hide_unavailable_field() {
		$options = self::get_settings();
		?>
		<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hide_unavailable]" value="1" <?php checked( $options['hide_unavailable'], 1 ); ?> />
		<label><?php esc_html_e( 'Completely hide unavailable options (instead of graying out)', 'variants_display' ); ?></label>
		<?php
	}

	public static function register_settings_page() {
		add_options_page(
			__( 'Variants Display', 'variants_display' ),
			__( 'Variants Display', 'variants_display' ),
			'manage_options',
			'vd-settings',
			[ __CLASS__, 'render_settings_page' ]
		);

		// Optional: admin sidebar menu entry (controlled by setting)
		$options = self::get_settings();

		if ( ! empty( $options['enable_menu'] ) ) {
			add_menu_page(
				__( 'Variants Display', 'variants_display' ),
				__( 'Variants Display', 'variants_display' ),
				'manage_options',
				'vd-settings',
				[ __CLASS__, 'render_settings_page' ],
				'dashicons-screenoptions',
				99
			);
		}
	}

	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Variants Display Settings', 'variants_display' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'vd_settings_group' );
				do_settings_sections( 'vd_settings_page' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}

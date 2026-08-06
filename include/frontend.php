<?php
/**
 * Plugin Name: Variants Display
 * Text Domain: variants_display
 * Domain Path: /languages
 */

class VARIANTS_DISPLAY_Frontend {

	public static function init() {
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', [ __CLASS__, 'maybe_render_custom_selector' ], 20, 2 );
		add_action( 'wp_footer', [ __CLASS__, 'print_assets' ] );
	}

	/**
	* Dispatches to the right renderer based on the stored per-attribute
	* display type. Add a new case here whenever you add a new type.
	*
	* @param string $html Original <select> markup.
	* @param array  $args options, attribute, product, selected, name, ...
	* @return string
	*/
	public static function maybe_render_custom_selector( $html, $args ) {

		$product = isset( $args['product'] ) ? $args['product'] : false;

		if ( ! $product instanceof WC_Product || empty( $args['options'] ) ) {
			return $html;
		}

		$stored = get_post_meta( $product->get_id(), VARIANTS_DISPLAY_Admin::ATTRIBUTE_META_KEY, true );
		$stored = is_array( $stored ) ? $stored : [];

		$key          = sanitize_title( $args['attribute'] );
		$display_type = isset( $stored[ $key ] ) ? $stored[ $key ] : 'default';

		switch ( $display_type ) {
			case 'pills':
				return $html . self::render_pills( $args );
			case 'color':
				return $html . self::render_color( $args );
			case 'large':
				return $html . self::render_large( $args );
			default:
				return $html; // WooCommerce's normal dropdown
		}
	}

	/**
	* Shared helper: normalizes taxonomy attributes and plain-text
	* attributes into the same simple array shape, so every renderer
	* below can loop over identical data regardless of attribute type.
	*
	* @param array $args
	* @return array[] Each item: [ 'value' => string, 'label' => string, 'selected' => bool, 'term' => WP_Term|null ]
	*/
	private static function get_items( $args ) {

		$options   = $args['options'];
		$product   = $args['product'];
		$attribute = $args['attribute'];
		$selected  = $args['selected'];
		$items     = [];

		if ( taxonomy_exists( $attribute ) ) {

			$terms = wc_get_product_terms( $product->get_id(), $attribute, [ 'fields' => 'all' ] );

			foreach ( $terms as $term ) {
				if ( ! in_array( $term->slug, $options, true ) ) continue;

				$color_hex = get_term_meta( $term->term_id, '_vd_term_color_hex', true );
				$image_url = $color_hex ? '' : self::find_variation_image( $product, $attribute, $term->slug );

				$items[] = [
					'value'     => $term->slug,
					'label'     => apply_filters( 'woocommerce_variation_option_name', $term->name, $term, $attribute, $product ),
					'selected'  => ( (string) $selected === (string) $term->slug ),
					'term'      => $term,
					'color_hex' => $color_hex ?: '',
					'image_url' => $image_url,
				];
			}
		} else {

			// custom (non-taxonomy) attributes have no term meta, so hex never applies here
			foreach ( $options as $option ) {
				$is_selected = ( sanitize_title( $selected ) === $selected )
					? ( sanitize_title( $option ) === $selected )
					: ( $option === $selected );

				$items[] = [
					'value'     => $option,
					'label'     => apply_filters( 'woocommerce_variation_option_name', $option, null, $attribute, $product ),
					'selected'  => $is_selected,
					'term'      => null,
					'color_hex' => '',
					'image_url' => self::find_variation_image( $product, $attribute, $option ),
				];
			}
		}

		return $items;
	}

	/**
	* Fallback: finds the first variation matching this attribute value
	* that has its own "Variation image" set, and returns that image's URL.
	* Used for custom (non-taxonomy) attributes, or as a fallback when a
	* taxonomy term has no swatch image of its own.
	*
	* @param WC_Product $product
	* @param string     $attribute
	* @param string     $value
	* @return string
	*/
	private static function find_variation_image( $product, $attribute, $value ) {

		$attribute_key = 'attribute_' . sanitize_title( $attribute );

		foreach ( $product->get_children() as $variation_id ) {

			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) continue;

			$variation_attrs = $variation->get_attributes();

			if ( ! isset( $variation_attrs[ sanitize_title( $attribute ) ] ) ) continue;

			$variation_value = $variation_attrs[ sanitize_title( $attribute ) ];

			// empty string on a variation means "Any <attribute>" - skip, not a match for a specific value
			if ( '' === $variation_value || $variation_value !== $value ) continue;

			if ( $variation->get_image_id() ) {
				return wp_get_attachment_image_url( $variation->get_image_id(), 'thumbnail' );
			}
		}

		return '';
	}

	/*********
	* PILLS *
	*********/
	private static function render_pills( $args ) {

		$name  = $args['name'] ? $args['name'] : 'attribute_' . sanitize_title( $args['attribute'] );
		$items = self::get_items( $args );

		ob_start();
		echo '<div class="vd-selector vd-selector--pills" data-vd-select-name="' . esc_attr( $name ) . '">';

		foreach ( $items as $item ) {

			$input_id = esc_attr( $name . '-' . sanitize_title( $item['value'] ) );

			printf(
				'<input type="radio" id="%1$s" name="vd_%2$s" value="%3$s" class="vd-option-input" data-value="%3$s" %4$s />
				<label for="%1$s" class="vd-option-label vd-option-label--pills">%5$s</label>',
				$input_id,
				esc_attr( $name ),
				esc_attr( $item['value'] ),
				checked( $item['selected'], true, false ),
				esc_html( $item['label'] )
			);
		}

		echo '</div>';
		return ob_get_clean();
	}

	/*********
	* COLOR *
	*********/
	private static function render_color( $args ) {

		$name  = $args['name'] ? $args['name'] : 'attribute_' . sanitize_title( $args['attribute'] );
		$items = self::get_items( $args );

		ob_start();

		// No more server-side guessing about whether this needs an expand
		// button (e.g. "count > 12") - that has no relationship to how many
		// swatches actually fit per row at the visitor's current viewport
		// width. JS measures the real, rendered layout and decides.
		echo '<div class="vd-color-wrapper">';

		// Right-aligned filter bar - sits at the top of this attribute's
		// value cell, i.e. the same table row WooCommerce renders the
		// attribute name in (label cell to the left, this to the right).
		echo '<div class="vd-color-header">';
		echo '<div class="vd-color-filter" data-vd-filter-for="' . esc_attr( $name ) . '">';
		printf(
			'<button type="button" class="vd-color-filter-toggle" aria-expanded="false" aria-haspopup="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" aria-hidden="true">
					<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M7 12h10M10 18h4" />
				</svg>
				<span>%s</span>
			</button>',
			esc_html__( 'Filter', 'variants_display' )
		);

		echo '<div class="vd-color-filter-panel" hidden>';
		printf(
			'<input type="text" class="vd-color-filter-search" placeholder="%s" aria-label="%s" />',
			esc_attr__( 'Search colors…', 'variants_display' ),
			esc_attr__( 'Search colors', 'variants_display' )
		);
		echo '<div class="vd-color-filter-options">';

		foreach ( $items as $item ) {
			$checkbox_id = esc_attr( $name . '-filter-' . sanitize_title( $item['value'] ) );
			printf(
				'<label for="%1$s" class="vd-color-filter-option" data-vd-filter-value="%2$s" data-vd-filter-label="%3$s">
					<input type="checkbox" id="%1$s" class="vd-color-filter-checkbox" value="%2$s" />
					<span>%4$s</span>
				</label>',
				$checkbox_id,
				esc_attr( $item['value'] ),
				esc_attr( function_exists( 'mb_strtolower' ) ? mb_strtolower( $item['label'] ) : strtolower( $item['label'] ) ),
				esc_html( $item['label'] )
			);
		}

		echo '</div>'; // .vd-color-filter-options
		echo '</div>'; // .vd-color-filter-panel
		echo '</div>'; // .vd-color-filter
		echo '</div>'; // .vd-color-header

		echo '<div class="vd-selector vd-selector--color" data-vd-select-name="' . esc_attr( $name ) . '">';

		foreach ( $items as $item ) {

			$input_id = esc_attr( $name . '-' . sanitize_title( $item['value'] ) );

			printf( '<input type="radio" id="%1$s" name="vd_%2$s" value="%3$s" class="vd-option-input" data-value="%3$s" %4$s />',
				$input_id,
				esc_attr( $name ),
				esc_attr( $item['value'] ),
				checked( $item['selected'], true, false )
			);

			if ( $item['color_hex'] ) {
				// 1) hex set on the attribute term - fill the button directly, no image
				printf(
					'<label for="%1$s" class="vd-option-label vd-option-label--color" title="%2$s" style="background-color: %3$s;"></label>',
					$input_id,
					esc_attr( $item['label'] ),
					esc_attr( $item['color_hex'] )
				);
			} elseif ( $item['image_url'] ) {
				// 2) fallback - use the matching variation's own image
				printf(
					'<label for="%1$s" class="vd-option-label vd-option-label--color" title="%2$s"><img src="%3$s" alt="%2$s" class="vd-option-image" /></label>',
					$input_id,
					esc_attr( $item['label'] ),
					esc_url( $item['image_url'] )
				);
			} else {
				// 3) fallback - plain text
				printf(
					'<label for="%1$s" class="vd-option-label vd-option-label--color" title="%2$s">%2$s</label>',
					$input_id,
					esc_html( $item['label'] )
				);
			}
		}

		echo '</div>'; // .vd-selector

		echo '
		<button type="button" class="vd-color-expand">
			<span class="vd-color-expand-text">' . esc_html__( 'Show more', 'variants_display' ) . '</span>
			<span class="vd-color-expand-arrow">
				<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M0 0h24v24H0z" fill="none" />
					<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9l6 6l6-6" />
				</svg>
			</span>
		</button>';

		echo '</div>'; // .vd-color-wrapper

		return ob_get_clean();
	}

	private static function render_large( $args ) {

		$name  = $args['name'] ? $args['name'] : 'attribute_' . sanitize_title( $args['attribute'] );
		$items = self::get_items( $args );

		ob_start();

		echo '<div class="vd-selector vd-selector--large" data-vd-select-name="' . esc_attr( $name ) . '">';

		foreach ( $items as $item ) {

			$input_id = esc_attr( $name . '-' . sanitize_title( $item['value'] ) );

			printf(
				'
				<input 
					type="radio"
					id="%1$s"
					name="vd_%2$s"
					value="%3$s"
					class="vd-option-input"
					data-value="%3$s"
					%4$s
				/>

				<label for="%1$s" class="vd-large-card">

					%5$s

					<div class="vd-large-info">
						<div class="vd-large-name">%6$s</div>
					</div>

				</label>
				',
				$input_id,
				esc_attr( $name ),
				esc_attr( $item['value'] ),
				checked( $item['selected'], true, false ),

				$item['image_url']
					? '<div class="vd-large-image">
							<img src="' . esc_url( $item['image_url'] ) . '" alt="' . esc_attr( $item['label'] ) . '">
					</div>'
					: '',

				esc_html( $item['label'] )
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	* Shared CSS (hides native select/radio, shared bridge) + one CSS
	* block per type below it, so each type's look is fully isolated.
	* Also the JS bridge that keeps the hidden select in sync with
	* whichever custom markup is currently shown.
	*/
	public static function print_assets() {

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		// WooCommerce only embeds the full variation matrix inline
		// (as data-product_variations on .variations_form) when the
		// product has fewer variations than woocommerce_ajax_variation_threshold
		// (default 30) - above that it fetches matches via AJAX instead,
		// which means our client-side availability check would have no
		// data to work with. Since we specifically need the full matrix
		// to compute cross-attribute availability, we fetch and print it
		// ourselves here, independent of that threshold.
		global $product;

		$vd_variations_by_product = [];

		if ( $product instanceof WC_Product_Variable ) {
			$vd_variations_by_product[ $product->get_id() ] = $product->get_available_variations();
		}
		?>
		<style>
			/* --- shared: hides the native select + native radio circles, don't remove --- */
			.variations select.vd-has-custom-selector,
			.vd-option-input {
				position: absolute;
				width: 1px;
				height: 1px;
				padding: 0;
				margin: -1px;
				overflow: hidden;
				clip: rect(0, 0, 0, 0);
				white-space: nowrap;
				border: 0;
			}
			.vd-selector {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
			}
			.vd-option-input:focus-visible + .vd-option-label {
				outline: 2px solid var(--theme-palette-color-1);
				outline-offset: 2px;
			}

			/*****************************
			* UNAVAILABLE (grayed out) *
			*****************************
			* Applied to any label whose paired custom radio has been
			* disabled by vdSyncSelectorStates() below, because that
			* combination has no matching (or no in-stock) variation
			* given the currently selected attributes.
			*/
			.vd-option-disabled {
				opacity: 0.35;
				cursor: not-allowed;
				pointer-events: none;
				filter: grayscale(60%);
			}

			/* text-based labels (pills, large card name, color's plain-text
			  fallback) - strike through the label text itself, in grey */
			.vd-option-label--pills.vd-option-disabled,
			.vd-option-label--color.vd-option-disabled,
			.vd-large-card.vd-option-disabled .vd-large-name {
				color: #888 !important;
				text-decoration: line-through;
				text-decoration-color: #888;
				text-decoration-thickness: 2px;
			}

			/* color swatches usually have no visible text (hex fill or
			  image), so overlay a diagonal grey strike across the box
			  instead of relying on text-decoration */
			.vd-option-label--color.vd-option-disabled {
				position: relative;
			}
			.vd-option-label--color.vd-option-disabled::after {
				content: "";
				position: absolute;
				top: 50%;
				left: -15%;
				width: 130%;
				height: 2px;
				background: #888;
				transform: rotate(-45deg);
				pointer-events: none;
			}

			/*********
			* PILLS *
			*********/
			.vd-option-label--pills {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-width: 44px;
				padding: 8px 10px;
				border: 2px solid var(--theme-palette-color-7);
				border-radius: 4px;
				cursor: pointer;
				font-size: 16px;
				background: var(--theme-palette-color-7);
			}
			.vd-selector--pills .vd-option-input:checked + .vd-option-label--pills {
				border-color: var(--theme-palette-color-1);
				background: var(--theme-palette-color-1);
				color: white;
				font-weight: 1000;
			}

			/*********
			* COLOR *
			*********/
			.vd-option-label--color {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 44px;
				height: 44px;
				padding: 0;
				border: 1px solid #ccc;
				cursor: pointer;
				overflow: hidden;
				box-sizing: border-box;
			}
			.vd-option-image {
				width: 100%;
				height: 100%;
				object-fit: cover;
			}
			.vd-selector--color .vd-option-input:checked + .vd-option-label--color {
				border-color: #000;
				border-width: 2px;
			}

			/* swatches hidden by the color-name filter (independent of the
			  availability styling above - always fully hidden, not greyed) */
			.vd-option-label--color.vd-color-filtered-out {
				display: none !important;
			}

			/*****************
			* COLOR FILTER *
			*****************/
			.vd-color-header {
				display: flex;
				justify-content: flex-end;
				margin-bottom: 8px;
			}
			/* Once JS relocates .vd-color-filter into the attribute's
			  label cell (the common case), this wrapper is left empty
			  in the value cell - collapse it instead of leaving a gap. */
			.vd-color-header:empty {
				display: none;
				margin-bottom: 0;
			}
			/* The attribute label cell, once it's holding the filter:
			  keep the "barva"/"Color" text on the left, filter on the right. */
			.vd-has-color-filter {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 8px;
			}
			.vd-color-filter {
				position: relative;
			}
			.vd-color-filter-toggle {
				display: inline-flex;
				align-items: center;
				/*gap: 6px;*/
				padding: 2px 12px;
				border: 1px solid #ccc;
				border-radius: 4px;
				background: white;
				cursor: pointer;
				font-size: 14px;
				color: inherit;
			}
			.vd-color-filter-toggle svg {
				width: 16px;
				height: 16px;
				display: block;
			}
			.vd-color-filter-toggle.vd-filter-active {
				border-color: var(--theme-palette-color-1);
				color: var(--theme-palette-color-1);
			}
			.vd-color-filter-panel {
				position: absolute;
				top: 100%;
				right: 0;
				margin-top: 6px;
				width: 220px;
				max-height: 280px;
				overflow-y: auto;
				background: white;
				border: 1px solid #ccc;
				border-radius: 6px;
				box-shadow: 0 4px 16px rgba(0, 0, 0, .15);
				padding: 10px;
				z-index: 30;
				box-sizing: border-box;
			}
			.vd-color-filter-panel[hidden] {
				display: none;
			}
			.vd-color-filter-search {
				width: 100%;
				box-sizing: border-box;
				padding: 7px 9px;
				margin-bottom: 8px;
				border: 1px solid #ddd;
				border-radius: 4px;
				font-size: 14px;
			}
			.vd-color-filter-options {
				display: flex;
				flex-direction: column;
				gap: 2px;
			}
			.vd-color-filter-option {
				display: flex;
				align-items: center;
				gap: 8px;
				font-size: 14px;
				cursor: pointer;
				padding: 5px 6px;
				border-radius: 4px;
			}
			.vd-color-filter-option:hover {
				background: var(--theme-palette-color-7, #f5f5f5);
			}
			.vd-color-filter-option[hidden] {
				display: none;
			}
			.vd-color-filter-checkbox {
				margin: 0;
				flex-shrink: 0;
			}
			.vd-color-filter-empty {
				font-size: 13px;
				color: #888;
				padding: 6px;
			}

			/****************
			* COLOR EXPAND *
			****************/
			/*
			* Whether this needs an expand button at all - and where the
			* two-row cutoff sits - depends entirely on how many swatches
			* fit per row at the current viewport width. That can't be
			* known in PHP or in static CSS, so JS measures the real
			* rendered rows and:
			*   1) sets --vd-two-row-height to the true height of 2 rows
			*   2) adds .has-expand only if there's actually a 3rd+ row
			* The 104px below is only a fallback for the brief moment
			* before JS runs (or if JS is disabled).
			*/
			.vd-color-expand {
				display: none;
				width: 100%;
				margin-top: 8px;
				padding: 6px;
				border: 0;
				background: transparent;
				cursor: pointer;
				font-size: 14px;
				align-items: center;
				justify-content: flex-end;
				gap: 6px;
				color: var(--theme-palette-color-1);
			}
			.vd-color-wrapper.has-expand .vd-color-expand {
				display: flex;
			}
			.vd-color-wrapper.has-expand .vd-selector--color {
				max-height: var(--vd-two-row-height, 104px);
				overflow: hidden;
				transition: max-height .25s ease;
			}
			.vd-color-wrapper.expanded .vd-selector--color {
				max-height: 1000px;
			}
			.vd-color-expand-arrow {
				display: inline-flex;
				align-items: center;
				transition: transform .25s ease;
			}

			.vd-color-expand-arrow svg {
				width: 18px;
				height: 18px;
				display: block;
			}
			.vd-color-wrapper.expanded .vd-color-expand-arrow {
				transform: rotate(180deg);
			}

			/*********
			* LARGE *
			*********/
			.vd-selector--large {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
				width: 100%;
			}
			.vd-large-card {
				display: flex;
				flex-direction: column;
				width: 110px;
				border-radius: 6px;
				overflow: hidden;
				cursor: pointer;
				padding: 0;
				margin: 0;
				background: white;
				transition: border-color .15s, background .15s;
				border: 2px solid transparent;
			}
			.vd-large-image {
				width: 110px;
				height: 110px;
				overflow: hidden;
				flex-shrink: 0;
			}
			.vd-large-image img {
				width: 100%;
				height: 100%;
				object-fit: cover;
				display: block;
			}
			.vd-large-info {
				display: flex;
				align-items: center;
				justify-content: center;
				width: 100%;
				padding: 8px 5px;
				box-sizing: border-box;
				background: var(--theme-palette-color-7);
			}
			.vd-large-name {
				font-weight: 600;
				font-size: 14px;
				text-align: center;
			}
			/* selected card */
			.vd-selector--large .vd-option-input:checked + .vd-large-card {
				border: 2px solid var(--theme-palette-color-1);
			}
			/* selected info footer */
			.vd-selector--large .vd-option-input:checked + .vd-large-card .vd-large-info {
				background: var(--theme-palette-color-1);
				color: white;
			}
			/* hide native radio */
			.vd-selector--large .vd-option-input {
				position:absolute;
				opacity:0;
				pointer-events:none;
			}
		</style>
		<?php

		$options = get_option( VARIANTS_DISPLAY_Admin::OPTION_KEY, [] );
		$hide_unavailable = ! empty( $options['hide_unavailable'] );

		?>

		<script>
		var vdTranslations = {
			showMore: <?php echo wp_json_encode( __( 'Show more', 'variants_display' ) ); ?>,
			showLess: <?php echo wp_json_encode( __( 'Show less', 'variants_display' ) ); ?>,
			noColorsFound: <?php echo wp_json_encode( __( 'No colors found', 'variants_display' ) ); ?>
		};

		// Full variation matrix, keyed by product ID, printed by PHP so it's
		// available regardless of WooCommerce's AJAX variation threshold.
		// Shape matches WooCommerce's own product_variations data: an array
		// per product of { attributes: { attribute_xxx: value|'' }, ... }.
		var vdProductVariations = <?php echo wp_json_encode( $vd_variations_by_product ); ?>;
		var vdSettings = {
			hideUnavailable: <?php echo $hide_unavailable ? 'true' : 'false'; ?>
		};
		</script>
		<script>
		jQuery(function($) {

			// Hide the real <select> whenever a custom selector sits next to it.
			$('.vd-selector').each(function() {
				var selectName = $(this).data('vd-select-name');
				$('select[name="' + selectName + '"]')
					.addClass('vd-has-custom-selector');
			});

			/**
			* The filter button/panel is printed inside the attribute's
			* value cell (next to the swatches), but it should visually
			* sit in the same row as the attribute's name, right-aligned.
			* WooCommerce's variation table renders the name in a sibling
			* <th class="label">/<td class="label"> cell within the same
			* <tr>, so we move the filter there once, on load. If no such
			* cell exists (a theme with different markup), it's left in
			* its original spot at the top of the value cell instead.
			*/
			function vdRelocateColorFilters() {
				$('.vd-color-filter').each(function() {
					var $filter = $(this);

					if ( $filter.data('vd-relocated') ) {
						return;
					}

					var $labelCell = $filter.closest('tr').find('> th.label, > td.label').first();

					if ( $labelCell.length ) {
						$labelCell.addClass('vd-has-color-filter').append( $filter );
					}

					$filter.data('vd-relocated', true);
				});
			}

			vdRelocateColorFilters();

			// Reflect clicks in any .vd-selector back onto the real select,
			// then trigger 'change' — the event WooCommerce's own
			// variation-matching JS is already listening for.
			$(document).on('change', '.vd-option-input', function() {
				var $input  = $(this);
				var $group  = $input.closest('.vd-selector');
				var selectName = $group.data('vd-select-name');

				var $select = $('select[name="' + selectName + '"]');

				$select.val( $input.data( 'value' ) ).trigger( 'change' );
			});

			/**
			* Keeps the custom selectors in sync with attribute availability.
			*
			* WooCommerce does NOT gray out/disable the native <option>
			* elements by default - it only rejects an invalid combination
			* after you've picked it. So instead of mirroring option state,
			* we compute validity ourselves from the full variation matrix
			* WooCommerce already embeds on the page as
			* $('.variations_form').data('product_variations') - an array
			* of { attributes: { attribute_xxx: value_or_empty_for_any }, ... }.
			*
			* An option is valid if at least one variation matches it AND
			* matches every other attribute currently selected elsewhere
			* on the form (an empty string on a variation means "Any").
			*/
			function vdGetCurrentSelections( $form ) {
				var selections = {};

				$form.find('select[name^="attribute_"]').each(function() {
					var val = $(this).val();
					if ( val ) {
						selections[ $(this).attr('name') ] = val;
					}
				});

				return selections;
			}

			/**
			* Color name filter (color type only).
			*
			* Two independent things feed into what's actually shown:
			* 1) Which filter checkboxes are even offered - only values
			*    that are currently available (not disabled by
			*    vdSyncSelectorStates) and that match the search box text.
			* 2) Which swatches are visible - hidden outright (not just
			*    greyed) if the user has checked one or more filter boxes
			*    and this swatch's value isn't among them.
			*
			* Note: once vdRelocateColorFilters() moves .vd-color-filter
			* into the label cell, it's no longer a descendant of
			* .vd-color-wrapper - so instead of .closest()/.find() to
			* connect the two, both sides carry the attribute's select
			* name (data-vd-filter-for / data-vd-select-name) and we look
			* each other up through that.
			*/
			function vdGetColorWrapperForFilter( $filter ) {
				var selectName = $filter.data('vd-filter-for');
				return $('.vd-selector--color[data-vd-select-name="' + selectName + '"]').closest('.vd-color-wrapper');
			}

			function vdGetColorFilterForWrapper( $wrapper ) {
				var selectName = $wrapper.find('.vd-selector--color').data('vd-select-name');
				return $('.vd-color-filter[data-vd-filter-for="' + selectName + '"]');
			}

			function vdUpdateFilterOptionVisibility( $wrapper ) {
				var $filter = vdGetColorFilterForWrapper( $wrapper );
				if ( ! $filter.length ) {
					return;
				}

				var searchTerm = $filter.find('.vd-color-filter-search').val().toLowerCase().trim();
				var visibleCount = 0;

				$filter.find('.vd-color-filter-option').each(function() {
					var $option = $(this);
					var value   = String( $option.data('vd-filter-value') );
					var label   = String( $option.data('vd-filter-label') || '' );

					var $swatchInput = $wrapper.find('.vd-option-input[data-value="' + value + '"]');
					var isAvailable  = $swatchInput.length ? ! $swatchInput.prop('disabled') : true;
					var matchesSearch = ! searchTerm || label.indexOf( searchTerm ) !== -1;
					var show = isAvailable && matchesSearch;

					$option.prop('hidden', ! show );

					if ( show ) {
						visibleCount++;
					}

					// A value that just became unavailable shouldn't stay
					// checked as an active filter.
					if ( ! isAvailable ) {
						$option.find('.vd-color-filter-checkbox').prop('checked', false);
					}
				});

				$filter.find('.vd-color-filter-empty').remove();
				if ( ! visibleCount ) {
					$filter.find('.vd-color-filter-options').append(
						'<div class="vd-color-filter-empty">' + vdTranslations.noColorsFound + '</div>'
					);
				}
			}

			function vdApplyColorSwatchFilter( $wrapper ) {
				var $filter = vdGetColorFilterForWrapper( $wrapper );
				var checkedValues = $filter.find('.vd-color-filter-checkbox:checked')
					.map(function() { return String( $(this).val() ); })
					.get();

				$wrapper.find('.vd-option-label--color').each(function() {
					var $label = $(this);
					var $input = $('#' + $label.attr('for'));
					var value  = $input.length ? String( $input.data('value') ) : null;
					var passesFilter = ! checkedValues.length || checkedValues.indexOf( value ) !== -1;

					$label.toggleClass( 'vd-color-filtered-out', ! passesFilter );
				});

				$filter.find('.vd-color-filter-toggle').toggleClass( 'vd-filter-active', checkedValues.length > 0 );
			}

			function vdRefreshColorFilter( $wrapper ) {
				vdUpdateFilterOptionVisibility( $wrapper );
				vdApplyColorSwatchFilter( $wrapper );
			}

			function vdSyncSelectorStates() {
				$('.variations_form').each(function() {
					var $form      = $(this);
					var productId  = $form.data('product_id');

					// Prefer our own PHP-printed matrix (always present,
					// regardless of WooCommerce's AJAX variation threshold);
					// fall back to WooCommerce's own inline data if for some
					// reason ours isn't there.
					var variations = ( typeof vdProductVariations !== 'undefined' && vdProductVariations[ productId ] )
						? vdProductVariations[ productId ]
						: $form.data('product_variations');

					if ( ! variations || ! variations.length ) {
						return;
					}

					var selections = vdGetCurrentSelections( $form );

					$form.find('.vd-selector').each(function() {
						var $group     = $(this);
						var selectName = $group.data('vd-select-name');

						// The value the native select currently holds for
						// this attribute - '' if nothing is selected (e.g.
						// after hitting "Clear"). Custom inputs should
						// always reflect this, not just whatever was last
						// clicked, since resets bypass our click handler.
						var selectVal = selections.hasOwnProperty( selectName ) ? selections[ selectName ] : '';

						$group.find('.vd-option-input').each(function() {
							var $input = $(this);
							var value  = String( $input.data('value') );

							$input.prop( 'checked', selectVal !== '' && value === selectVal );

							// Test this option against everything selected
							// on *other* attributes (ignore this group's
							// own current selection while testing it).
							var otherSelections = $.extend( {}, selections );
							delete otherSelections[ selectName ];

							var isValid = variations.some(function( variation ) {
								var attrs  = variation.attributes || {};
								var ownVal = attrs[ selectName ];

								// attribute not part of the variation matrix -
								// don't let it restrict anything.
								if ( typeof ownVal === 'undefined' ) {
									return true;
								}
								if ( ownVal !== '' && ownVal !== value ) {
									return false;
								}

								for ( var otherName in otherSelections ) {
									if ( ! otherSelections.hasOwnProperty( otherName ) ) continue;

									var otherVal = attrs[ otherName ];
									if ( typeof otherVal === 'undefined' ) continue;
									if ( otherVal !== '' && otherVal !== otherSelections[ otherName ] ) {
										return false;
									}
								}

								return true;
							});

							var isDisabled = ! isValid;

							$input.prop('disabled', isDisabled);

							var $label = $('label[for="' + $input.attr('id') + '"]');

							if (vdSettings.hideUnavailable) {
								$label.toggle(!isDisabled);
								$input.toggle(!isDisabled);
							} else {
								$label.toggleClass('vd-option-disabled', isDisabled);
							}
							// A selection that just became invalid shouldn't
							// be left checked in the custom UI.
							if ( isDisabled && $input.is(':checked') ) {
								$input.prop('checked', false);
							}
						});

						if ( $group.hasClass('vd-selector--color') ) {
							vdRefreshColorFilter( $group.closest('.vd-color-wrapper') );
						}
					});
				});
			}

			// Recompute on any attribute change, from either the native
			// select (WooCommerce's own logic, "Clear" link, etc.) or our
			// own custom inputs.
			$(document).on('change', '.variations_form select[name^="attribute_"], .vd-option-input', vdSyncSelectorStates);

			// "Clear" link / form reset.
			$(document).on('reset_data woocommerce_update_variation_values found_variation', '.variations_form', vdSyncSelectorStates);

			$(window).on('load', vdSyncSelectorStates);
			$(window).on('load', vdRelocateColorFilters);
			vdSyncSelectorStates();

			$(document).on('click', '.vd-color-expand', function() {
				var $wrapper = $(this).closest('.vd-color-wrapper');
				$wrapper.toggleClass('expanded');

				var expanded = $wrapper.hasClass('expanded');
				$(this)
					.find('.vd-color-expand-text')
					.text(
						expanded 
							? vdTranslations.showLess 
							: vdTranslations.showMore
					);
			});

			/**
			* Color filter dropdown: open/close, search-as-you-type, and
			* applying the checked colors to the swatch list.
			*/
			$(document).on('click', '.vd-color-filter-toggle', function( e ) {
				e.stopPropagation();

				var $toggle = $(this);
				var $panel  = $toggle.siblings('.vd-color-filter-panel');
				var willOpen = $panel.prop('hidden');

				// Only one filter panel open at a time.
				$('.vd-color-filter-panel').prop('hidden', true);
				$('.vd-color-filter-toggle').attr('aria-expanded', 'false');

				if ( willOpen ) {
					$panel.prop('hidden', false);
					$toggle.attr('aria-expanded', 'true');
					vdUpdateFilterOptionVisibility( vdGetColorWrapperForFilter( $toggle.closest('.vd-color-filter') ) );
					$panel.find('.vd-color-filter-search').trigger('focus');
				}
			});

			// Clicks inside the panel shouldn't bubble up and close it.
			$(document).on('click', '.vd-color-filter-panel', function( e ) {
				e.stopPropagation();
			});

			// Click anywhere else closes any open panel.
			$(document).on('click', function() {
				$('.vd-color-filter-panel').prop('hidden', true);
				$('.vd-color-filter-toggle').attr('aria-expanded', 'false');
			});

			$(document).on('input', '.vd-color-filter-search', function() {
				vdUpdateFilterOptionVisibility( vdGetColorWrapperForFilter( $(this).closest('.vd-color-filter') ) );
			});

			$(document).on('change', '.vd-color-filter-checkbox', function() {
				var $wrapper = vdGetColorWrapperForFilter( $(this).closest('.vd-color-filter') );
				vdApplyColorSwatchFilter( $wrapper );
				vdUpdateColorSwatches();
			});

			/**
			* Figures out, per color-swatch group, whether the swatches
			* actually wrap onto a 3rd+ row at the current viewport width.
			* Runs on load and on resize, so it stays correct across
			* breakpoints, container width changes, and orientation changes
			* — no hardcoded row/column counts anywhere.
			*/
			function vdUpdateColorSwatches() {
				$('.vd-color-wrapper').each(function() {
					var $wrapper  = $(this);
					var $selector = $wrapper.find('.vd-selector--color');
					var $labels   = $selector.find('.vd-option-label--color');

					if ( ! $labels.length ) {
						return;
					}

					// Drop any existing clamp so we measure the true,
					// unclamped natural height of the swatch list.
					$wrapper.removeClass('has-expand');

					var rowHeight = $labels.first().outerHeight();
					var gap       = parseFloat( $selector.css('row-gap') ) ||
					              parseFloat( $selector.css('gap') ) || 0;
					var twoRowHeight = ( rowHeight * 2 ) + gap;

					$wrapper.css('--vd-two-row-height', twoRowHeight + 'px');

					// 1px tolerance for sub-pixel rounding differences.
					var needsExpand = $selector[0].scrollHeight > ( twoRowHeight + 1 );

					$wrapper.toggleClass('has-expand', needsExpand);

					if ( ! needsExpand ) {
						$wrapper.removeClass('expanded');
						$wrapper
							.find('.vd-color-expand-text')
							.text( vdTranslations.showMore );
					}
				});
			}

			var vdResizeTimer;
			$(window).on('resize', function() {
				clearTimeout( vdResizeTimer );
				vdResizeTimer = setTimeout( vdUpdateColorSwatches, 150 );
			});

			// Re-check once everything (fonts/images) has finished loading,
			// in case that shifts wrapping right after first paint.
			$(window).on('load', vdUpdateColorSwatches);

			vdUpdateColorSwatches();
		});
		</script>
		<?php
	}
}

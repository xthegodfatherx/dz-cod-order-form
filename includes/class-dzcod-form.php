<?php
/**
 * Front-end order form: shortcode, auto-insert, assets.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the COD form.
 */
class DZCOD_Form {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_shortcode( 'dzcod_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp', array( __CLASS__, 'setup_product_page' ) );
	}

	/**
	 * On single product pages: insert the form and optionally hide Add to cart.
	 */
	public static function setup_product_page() {
		if ( ! is_product() || ! DZCOD_Settings::get( 'auto_insert' ) ) {
			return;
		}
		if ( ! apply_filters( 'dzcod_auto_insert_for_product', true, get_queried_object_id() ) ) {
			return;
		}
		if ( DZCOD_Settings::get( 'hide_add_to_cart' ) ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		}
		add_action(
			'woocommerce_single_product_summary',
			function () {
				echo self::render( get_queried_object_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
			},
			31
		);
	}

	/**
	 * Register CSS/JS (enqueued only when a form renders).
	 */
	public static function register_assets() {
		wp_register_style( 'dzcod-form', DZCOD_URL . 'assets/form.css', array(), DZCOD_VERSION );
		wp_register_script( 'dzcod-form', DZCOD_URL . 'assets/form.js', array(), DZCOD_VERSION, true );
	}

	/**
	 * Shortcode [dzcod_form product_id="123"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => 0 ), $atts, 'dzcod_form' );
		$id   = absint( $atts['product_id'] );
		if ( ! $id ) {
			global $product;
			$id = ( $product instanceof WC_Product ) ? $product->get_id() : get_queried_object_id();
		}
		return self::render( $id );
	}

	/**
	 * Localized config shared by all forms on the page.
	 *
	 * @return array
	 */
	private static function config() {
		$s      = DZCOD_Settings::get();
		$prices = array();
		foreach ( $s['prices'] as $code => $p ) {
			if ( ! empty( $p['enabled'] ) ) {
				$prices[ $code ] = array( (float) $p['home'], (float) $p['desk'] );
			}
		}
		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'dzcod_order' ),
			'dataUrl'   => DZCOD_URL . 'data/algeria.json?v=' . DZCOD_VERSION,
			'lang'      => $s['name_lang'],
			'prices'    => $prices,
			'freeOver'  => (float) $s['free_shipping_over'],
			'stopdesk'  => (bool) $s['stopdesk_enabled'],
			'currency'  => array(
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimals' => wc_get_price_decimals(),
				'pos'      => get_option( 'woocommerce_currency_pos', 'right_space' ),
				'thousand' => wc_get_price_thousand_separator(),
				'decimal'  => wc_get_price_decimal_separator(),
			),
			'i18n'      => array(
				'chooseWilaya'  => __( 'Choose your wilaya', 'dz-cod-order-form' ),
				'chooseCommune' => __( 'Choose your commune', 'dz-cod-order-form' ),
				'free'          => __( 'Free', 'dz-cod-order-form' ),
				'sending'       => __( 'Sending…', 'dz-cod-order-form' ),
				'error'         => __( 'Something went wrong. Please try again.', 'dz-cod-order-form' ),
				'chooseOption'   => __( 'Please choose an option.', 'dz-cod-order-form' ),
				'invalidName'    => __( 'Please enter your full name.', 'dz-cod-order-form' ),
				'invalidPhone'   => __( 'Please enter a valid Algerian phone number (e.g. 0555 12 34 56).', 'dz-cod-order-form' ),
				'invalidAddress' => __( 'Please enter your delivery address.', 'dz-cod-order-form' ),
			),
		);
	}

	/**
	 * Render the form for a product.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public static function render( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() || ! in_array( $product->get_type(), array( 'simple', 'variable' ), true ) ) {
			return '';
		}

		$s = DZCOD_Settings::get();
		wp_enqueue_style( 'dzcod-form' );
		wp_enqueue_script( 'dzcod-form' );
		static $localized = false;
		if ( ! $localized ) {
			wp_add_inline_script( 'dzcod-form', 'var DZCOD = ' . wp_json_encode( self::config() ) . ';', 'before' );
			$localized = true;
		}

		$variations = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_available_variations( 'objects' ) as $v ) {
				if ( ! $v->is_purchasable() || ! $v->is_in_stock() ) {
					continue;
				}
				$variations[] = array(
					'id'    => $v->get_id(),
					'label' => wc_get_formatted_variation( $v, true, false, false ),
					'price' => (float) wc_get_price_to_display( $v ),
				);
			}
			if ( empty( $variations ) ) {
				return '';
			}
		} elseif ( ! $product->is_in_stock() ) {
			return '';
		}

		$price = $product->is_type( 'variable' ) ? $variations[0]['price'] : (float) wc_get_price_to_display( $product );
		$uid   = 'dzcod-' . $product->get_id() . '-' . wp_rand( 100, 999 );
		$style = '--dzcod-primary:' . $s['primary_color'] . ';';

		ob_start();
		?>
		<form class="dzcod-form" id="<?php echo esc_attr( $uid ); ?>" style="<?php echo esc_attr( $style ); ?>" novalidate
			data-product="<?php echo esc_attr( $product->get_id() ); ?>"
			data-price="<?php echo esc_attr( $price ); ?>">
			<?php if ( '' !== $s['form_title'] ) : ?>
				<h3 class="dzcod-title"><?php echo esc_html( $s['form_title'] ); ?></h3>
			<?php endif; ?>

			<?php if ( $variations ) : ?>
				<div class="dzcod-field">
					<label for="<?php echo esc_attr( $uid ); ?>-var"><?php esc_html_e( 'Option', 'dz-cod-order-form' ); ?></label>
					<select id="<?php echo esc_attr( $uid ); ?>-var" name="variation_id" required>
						<?php foreach ( $variations as $v ) : ?>
							<option value="<?php echo esc_attr( $v['id'] ); ?>" data-price="<?php echo esc_attr( $v['price'] ); ?>"><?php echo esc_html( $v['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>

			<div class="dzcod-row">
				<div class="dzcod-field">
					<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Full name', 'dz-cod-order-form' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-name" name="name" type="text" autocomplete="name" required minlength="3">
				</div>
				<div class="dzcod-field">
					<label for="<?php echo esc_attr( $uid ); ?>-phone"><?php esc_html_e( 'Phone number', 'dz-cod-order-form' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="05XX XX XX XX" required>
				</div>
			</div>

			<div class="dzcod-row">
				<div class="dzcod-field">
					<label for="<?php echo esc_attr( $uid ); ?>-wilaya"><?php esc_html_e( 'Wilaya', 'dz-cod-order-form' ); ?></label>
					<select id="<?php echo esc_attr( $uid ); ?>-wilaya" name="wilaya" required><option value=""><?php esc_html_e( 'Loading…', 'dz-cod-order-form' ); ?></option></select>
				</div>
				<div class="dzcod-field">
					<label for="<?php echo esc_attr( $uid ); ?>-commune"><?php esc_html_e( 'Commune', 'dz-cod-order-form' ); ?></label>
					<select id="<?php echo esc_attr( $uid ); ?>-commune" name="commune" required disabled><option value=""><?php esc_html_e( 'Choose your commune', 'dz-cod-order-form' ); ?></option></select>
				</div>
			</div>

			<?php if ( $s['stopdesk_enabled'] ) : ?>
				<fieldset class="dzcod-field dzcod-delivery">
					<legend><?php esc_html_e( 'Delivery', 'dz-cod-order-form' ); ?></legend>
					<label class="dzcod-choice"><input type="radio" name="delivery_type" value="home" checked> <span><?php esc_html_e( 'Home delivery', 'dz-cod-order-form' ); ?></span> <b class="dzcod-price-home"></b></label>
					<label class="dzcod-choice"><input type="radio" name="delivery_type" value="desk"> <span><?php esc_html_e( 'Stop desk (office pickup)', 'dz-cod-order-form' ); ?></span> <b class="dzcod-price-desk"></b></label>
				</fieldset>
			<?php else : ?>
				<input type="hidden" name="delivery_type" value="home">
			<?php endif; ?>

			<?php if ( $s['show_address'] ) : ?>
				<div class="dzcod-field dzcod-address">
					<label for="<?php echo esc_attr( $uid ); ?>-address"><?php esc_html_e( 'Address', 'dz-cod-order-form' ); ?><?php echo $s['require_address'] ? '' : ' <small>(' . esc_html__( 'optional', 'dz-cod-order-form' ) . ')</small>'; ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-address" name="address" type="text" autocomplete="street-address" <?php echo $s['require_address'] ? 'data-required-home="1"' : ''; ?>>
				</div>
			<?php endif; ?>

			<?php if ( $s['show_quantity'] ) : ?>
				<div class="dzcod-field dzcod-qty">
					<label for="<?php echo esc_attr( $uid ); ?>-qty"><?php esc_html_e( 'Quantity', 'dz-cod-order-form' ); ?></label>
					<div class="dzcod-qty-box">
						<button type="button" class="dzcod-qty-btn" data-step="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'dz-cod-order-form' ); ?>">−</button>
						<input id="<?php echo esc_attr( $uid ); ?>-qty" name="qty" type="number" min="1" max="99" value="1" inputmode="numeric">
						<button type="button" class="dzcod-qty-btn" data-step="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'dz-cod-order-form' ); ?>">+</button>
					</div>
				</div>
			<?php else : ?>
				<input type="hidden" name="qty" value="1">
			<?php endif; ?>

			<div class="dzcod-hp" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this field empty', 'dz-cod-order-form' ); ?> <input type="text" name="dzcod_website" tabindex="-1" autocomplete="off"></label>
			</div>

			<div class="dzcod-summary">
				<div><span><?php esc_html_e( 'Product', 'dz-cod-order-form' ); ?></span> <b class="dzcod-sum-product"></b></div>
				<div><span><?php esc_html_e( 'Delivery', 'dz-cod-order-form' ); ?></span> <b class="dzcod-sum-shipping">—</b></div>
				<div class="dzcod-sum-total-row"><span><?php esc_html_e( 'Total', 'dz-cod-order-form' ); ?></span> <b class="dzcod-sum-total"></b></div>
			</div>

			<div class="dzcod-message" role="alert" aria-live="polite"></div>
			<button type="submit" class="dzcod-submit"><?php echo esc_html( $s['button_text'] ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}

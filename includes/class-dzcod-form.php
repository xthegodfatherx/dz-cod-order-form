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
		// Fallback commune source when the static JSON file is blocked (CDN, security plugin, firewall).
		add_action( 'wp_ajax_dzcod_communes', array( __CLASS__, 'ajax_communes' ) );
		add_action( 'wp_ajax_nopriv_dzcod_communes', array( __CLASS__, 'ajax_communes' ) );
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
		if ( ! wp_style_is( 'dzcod-form', 'registered' ) ) {
			wp_register_style( 'dzcod-form', DZCOD_URL . 'assets/form.css', array(), DZCOD_VERSION );
		}
		if ( ! wp_script_is( 'dzcod-form', 'registered' ) ) {
			wp_register_script( 'dzcod-form', DZCOD_URL . 'assets/form.js', array(), DZCOD_VERSION, true );
		}
	}

	/**
	 * Communes of one wilaya (AJAX fallback).
	 */
	public static function ajax_communes() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only data.
		$code = isset( $_REQUEST['wilaya'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wilaya'] ) ) : '';
		$all  = DZCOD_Locations::all();
		if ( ! isset( $all[ $code ] ) ) {
			wp_send_json_error();
		}
		wp_send_json_success( $all[ $code ]['c'] );
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
	 * Config passed to the script. Stored on the form itself (data-config) so it
	 * survives JS optimizers that move, defer or combine inline scripts.
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
		$req = array();
		foreach ( array( 'phone2', 'email', 'address', 'note' ) as $k ) {
			$f         = DZCOD_Settings::field( $k );
			$req[ $k ] = $f['show'] && $f['required'];
		}
		return array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'dzcod_order' ),
			'dataUrl'  => DZCOD_URL . 'data/algeria.json?v=' . DZCOD_VERSION,
			'lang'     => $s['name_lang'],
			'prices'   => $prices,
			'freeOver' => (float) $s['free_shipping_over'],
			'required' => $req,
			'currency' => array(
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimals' => wc_get_price_decimals(),
				'pos'      => get_option( 'woocommerce_currency_pos', 'right_space' ),
				'thousand' => wc_get_price_thousand_separator(),
				'decimal'  => wc_get_price_decimal_separator(),
			),
			'i18n'     => array(
				'chooseCommune'  => DZCOD_Settings::field( 'commune' )['placeholder'],
				'loading'        => __( 'Loading…', 'dz-cod-order-form' ),
				'free'           => __( 'Free', 'dz-cod-order-form' ),
				'sending'        => __( 'Sending…', 'dz-cod-order-form' ),
				'error'          => __( 'Something went wrong. Please try again.', 'dz-cod-order-form' ),
				'communesError'  => __( 'Could not load the communes. Please refresh the page.', 'dz-cod-order-form' ),
				'chooseWilaya'   => __( 'Please choose your wilaya.', 'dz-cod-order-form' ),
				'chooseCommune2' => __( 'Please choose your commune.', 'dz-cod-order-form' ),
				'chooseOption'   => __( 'Please choose an option.', 'dz-cod-order-form' ),
				'invalidName'    => __( 'Please enter your full name.', 'dz-cod-order-form' ),
				'invalidPhone'   => __( 'Please enter a valid Algerian phone number (e.g. 0555 12 34 56).', 'dz-cod-order-form' ),
				'invalidAddress' => __( 'Please enter your delivery address.', 'dz-cod-order-form' ),
				'requiredField'  => __( 'Please fill in this field.', 'dz-cod-order-form' ),
				'invalidEmail'   => __( 'Please enter a valid email address.', 'dz-cod-order-form' ),
			),
		);
	}

	/**
	 * Label HTML with an optional "(optional)" hint.
	 *
	 * @param string $for      Input id.
	 * @param string $label    Label.
	 * @param bool   $required Required.
	 * @return string
	 */
	private static function label( $for, $label, $required ) {
		$hint = $required ? '' : ' <small>(' . esc_html__( 'optional', 'dz-cod-order-form' ) . ')</small>';
		return '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . $hint . '</label>';
	}

	/**
	 * Simple text-like input row for a built-in field.
	 *
	 * @param string $uid   Form id.
	 * @param string $key   Field key.
	 * @param string $type  Input type.
	 * @param string $attrs Extra attributes (already escaped).
	 * @return string
	 */
	private static function input_row( $uid, $key, $type, $attrs = '' ) {
		$f = DZCOD_Settings::field( $key );
		if ( ! $f['show'] ) {
			return '';
		}
		$id = $uid . '-' . $key;
		return '<div class="dzcod-field dzcod-f-' . esc_attr( $key ) . '">' . self::label( $id, $f['label'], (bool) $f['required'] )
			. '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" placeholder="' . esc_attr( $f['placeholder'] ) . '"'
			. ( $f['required'] ? ' required' : '' ) . ' ' . $attrs . '></div>';
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
		self::register_assets(); // Covers renders that happen before wp_enqueue_scripts (page builders, AJAX).
		wp_enqueue_style( 'dzcod-form' );
		wp_enqueue_script( 'dzcod-form' );

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

		$price   = $product->is_type( 'variable' ) ? $variations[0]['price'] : (float) wc_get_price_to_display( $product );
		$uid     = 'dzcod-' . $product->get_id() . '-' . wp_rand( 100, 999 );
		$ar      = 'ar' === $s['name_lang'];
		$wil     = DZCOD_Settings::field( 'wilaya' );
		$com     = DZCOD_Settings::field( 'commune' );
		$address = DZCOD_Settings::field( 'address' );
		$note    = DZCOD_Settings::field( 'note' );
		$qty     = DZCOD_Settings::field( 'qty' );

		ob_start();
		?>
		<form class="dzcod-form" id="<?php echo esc_attr( $uid ); ?>" style="<?php echo esc_attr( '--dzcod-primary:' . $s['primary_color'] . ';' ); ?>" novalidate
			data-product="<?php echo esc_attr( $product->get_id() ); ?>"
			data-price="<?php echo esc_attr( $price ); ?>"
			data-config="<?php echo esc_attr( wp_json_encode( self::config() ) ); ?>">
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
				<?php
				echo self::input_row( $uid, 'name', 'text', 'autocomplete="name" minlength="3" maxlength="100"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in input_row().
				echo self::input_row( $uid, 'phone', 'tel', 'inputmode="tel" autocomplete="tel" dir="ltr" maxlength="20"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
			<?php if ( DZCOD_Settings::field( 'phone2' )['show'] || DZCOD_Settings::field( 'email' )['show'] ) : ?>
				<div class="dzcod-row">
					<?php
					echo self::input_row( $uid, 'phone2', 'tel', 'inputmode="tel" dir="ltr" maxlength="20"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::input_row( $uid, 'email', 'email', 'autocomplete="email" dir="ltr" maxlength="100"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
			<?php endif; ?>

			<div class="dzcod-row">
				<div class="dzcod-field">
					<?php echo self::label( $uid . '-wilaya', $wil['label'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<select id="<?php echo esc_attr( $uid ); ?>-wilaya" name="wilaya" required>
						<option value=""><?php echo esc_html( $wil['placeholder'] ); ?></option>
						<?php
						// Rendered on the server: the list is there even if JavaScript or the data file fails.
						foreach ( DZCOD_Locations::all() as $code => $w ) {
							if ( empty( $s['prices'][ $code ]['enabled'] ) ) {
								continue;
							}
							echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $code . ' - ' . ( $ar ? $w['ar'] : $w['n'] ) ) . '</option>';
						}
						?>
					</select>
				</div>
				<div class="dzcod-field">
					<?php echo self::label( $uid . '-commune', $com['label'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<select id="<?php echo esc_attr( $uid ); ?>-commune" name="commune" required disabled><option value=""><?php echo esc_html( $com['placeholder'] ); ?></option></select>
				</div>
			</div>

			<?php if ( $s['stopdesk_enabled'] ) : ?>
				<fieldset class="dzcod-field dzcod-delivery">
					<legend><?php esc_html_e( 'Delivery', 'dz-cod-order-form' ); ?></legend>
					<div class="dzcod-choices">
						<label class="dzcod-choice"><input type="radio" name="delivery_type" value="home" checked> <span class="dzcod-choice-icon" aria-hidden="true">🏠</span> <span><?php esc_html_e( 'Home delivery', 'dz-cod-order-form' ); ?></span> <b class="dzcod-price-home"></b></label>
						<label class="dzcod-choice"><input type="radio" name="delivery_type" value="desk"> <span class="dzcod-choice-icon" aria-hidden="true">🏢</span> <span><?php esc_html_e( 'Stop desk (office pickup)', 'dz-cod-order-form' ); ?></span> <b class="dzcod-price-desk"></b></label>
					</div>
				</fieldset>
			<?php else : ?>
				<input type="hidden" name="delivery_type" value="home">
			<?php endif; ?>

			<?php if ( $address['show'] ) : ?>
				<div class="dzcod-field dzcod-address">
					<?php echo self::label( $uid . '-address', $address['label'], (bool) $address['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input id="<?php echo esc_attr( $uid ); ?>-address" name="address" type="text" autocomplete="street-address" maxlength="200" placeholder="<?php echo esc_attr( $address['placeholder'] ); ?>">
				</div>
			<?php endif; ?>

			<?php foreach ( DZCOD_Settings::custom_fields() as $cf ) : ?>
				<?php $id = $uid . '-' . $cf['key']; ?>
				<div class="dzcod-field dzcod-custom" data-required="<?php echo $cf['required'] ? '1' : ''; ?>">
					<?php if ( 'checkbox' === $cf['type'] ) : ?>
						<label class="dzcod-check"><input type="checkbox" name="<?php echo esc_attr( $cf['key'] ); ?>" value="1" <?php echo $cf['required'] ? 'required' : ''; ?>> <span><?php echo esc_html( $cf['label'] ); ?></span></label>
					<?php else : ?>
						<?php echo self::label( $id, $cf['label'], $cf['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( 'select' === $cf['type'] ) : ?>
							<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $cf['key'] ); ?>" <?php echo $cf['required'] ? 'required' : ''; ?>>
								<option value=""><?php echo esc_html( '' !== $cf['placeholder'] ? $cf['placeholder'] : '—' ); ?></option>
								<?php foreach ( $cf['options'] as $opt ) : ?>
									<option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php elseif ( 'textarea' === $cf['type'] ) : ?>
							<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $cf['key'] ); ?>" rows="2" maxlength="500" placeholder="<?php echo esc_attr( $cf['placeholder'] ); ?>" <?php echo $cf['required'] ? 'required' : ''; ?>></textarea>
						<?php else : ?>
							<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $cf['key'] ); ?>" type="text" maxlength="200" placeholder="<?php echo esc_attr( $cf['placeholder'] ); ?>" <?php echo $cf['required'] ? 'required' : ''; ?>>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( $note['show'] ) : ?>
				<div class="dzcod-field dzcod-f-note">
					<?php echo self::label( $uid . '-note', $note['label'], (bool) $note['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<textarea id="<?php echo esc_attr( $uid ); ?>-note" name="note" rows="2" maxlength="500" placeholder="<?php echo esc_attr( $note['placeholder'] ); ?>" <?php echo $note['required'] ? 'required' : ''; ?>></textarea>
				</div>
			<?php endif; ?>

			<?php if ( $qty['show'] && ! $product->is_sold_individually() ) : ?>
				<div class="dzcod-field dzcod-qty">
					<label for="<?php echo esc_attr( $uid ); ?>-qty"><?php echo esc_html( $qty['label'] ); ?></label>
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
			<p class="dzcod-trust"><span aria-hidden="true">🔒</span> <?php esc_html_e( 'Pay on delivery — nothing to pay now', 'dz-cod-order-form' ); ?></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}

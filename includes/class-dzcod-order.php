<?php
/**
 * AJAX order placement.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates WooCommerce orders from the COD form.
 */
class DZCOD_Order {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_ajax_dzcod_place_order', array( __CLASS__, 'ajax_place_order' ) );
		add_action( 'wp_ajax_nopriv_dzcod_place_order', array( __CLASS__, 'ajax_place_order' ) );
		// Fresh nonce for pages served from a full-page cache.
		add_action( 'wp_ajax_dzcod_nonce', array( __CLASS__, 'ajax_nonce' ) );
		add_action( 'wp_ajax_nopriv_dzcod_nonce', array( __CLASS__, 'ajax_nonce' ) );
	}

	/**
	 * Return a fresh nonce.
	 */
	public static function ajax_nonce() {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'dzcod_order' ) ) );
	}

	/**
	 * Read a POST field as plain text.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function field( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in ajax_place_order().
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Send a JSON error.
	 *
	 * @param string $message Message.
	 * @param string $code    Machine code.
	 * @param string $field   Offending field name.
	 */
	private static function fail( $message, $code = 'error', $field = '' ) {
		wp_send_json_error(
			array(
				'message' => $message,
				'code'    => $code,
				'field'   => $field,
			)
		);
	}

	/**
	 * Handle the form submission.
	 */
	public static function ajax_place_order() {
		if ( ! check_ajax_referer( 'dzcod_order', 'nonce', false ) ) {
			self::fail( __( 'Your session expired. Please try again.', 'dz-cod-order-form' ), 'nonce' );
		}

		// Honeypot: real visitors never fill this hidden field.
		if ( '' !== self::field( 'dzcod_website' ) ) {
			self::fail( __( 'Your order could not be submitted.', 'dz-cod-order-form' ), 'spam' );
		}

		$s            = DZCOD_Settings::get();
		$product_id   = absint( self::field( 'product_id' ) );
		$variation_id = absint( self::field( 'variation_id' ) );
		$qty          = max( 1, min( 99, absint( self::field( 'qty' ) ) ) );
		$name         = self::field( 'name' );
		$phone        = DZCOD_Antifraud::normalize_phone( self::field( 'phone' ) );
		$wilaya       = self::field( 'wilaya' );
		$commune      = self::field( 'commune' );
		$address      = self::field( 'address' );
		$type         = ( 'desk' === self::field( 'delivery_type' ) && $s['stopdesk_enabled'] ) ? 'desk' : 'home';

		// Length caps keep garbage out of orders, sheets and courier exports.
		$name    = mb_substr( $name, 0, 100 );
		$address = mb_substr( $address, 0, 200 );

		// Validate fields.
		if ( mb_strlen( $name ) < 3 ) {
			self::fail( __( 'Please enter your full name.', 'dz-cod-order-form' ), 'invalid', 'name' );
		}
		if ( ! DZCOD_Antifraud::is_valid_phone( $phone ) ) {
			self::fail( __( 'Please enter a valid Algerian phone number (e.g. 0555 12 34 56).', 'dz-cod-order-form' ), 'invalid', 'phone' );
		}
		$all = DZCOD_Locations::all();
		if ( ! isset( $all[ $wilaya ] ) ) {
			self::fail( __( 'Please choose your wilaya.', 'dz-cod-order-form' ), 'invalid', 'wilaya' );
		}
		if ( ! DZCOD_Locations::commune_exists( $wilaya, $commune ) ) {
			self::fail( __( 'Please choose your commune.', 'dz-cod-order-form' ), 'invalid', 'commune' );
		}
		$fa = DZCOD_Settings::field( 'address' );
		if ( ! $fa['show'] ) {
			$address = '';
		} elseif ( $fa['required'] && 'home' === $type && mb_strlen( $address ) < 4 ) {
			self::fail( __( 'Please enter your delivery address.', 'dz-cod-order-form' ), 'invalid', 'address' );
		}
		$extras   = self::read_extras();
		$shipping = DZCOD_Settings::delivery_price( $wilaya, $type );
		if ( null === $shipping ) {
			self::fail( __( 'Sorry, we do not deliver to this wilaya yet.', 'dz-cod-order-form' ), 'invalid', 'wilaya' );
		}

		// Product.
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product || ( $variation_id && $product->get_parent_id() !== $product_id ) ) {
			self::fail( __( 'This product is not available.', 'dz-cod-order-form' ), 'product' );
		}
		$parent = $variation_id ? wc_get_product( $product_id ) : $product;
		if ( ! $parent || 'publish' !== $parent->get_status() || post_password_required( $parent->get_id() ) ) {
			self::fail( __( 'This product is not available.', 'dz-cod-order-form' ), 'product' );
		}
		if ( $product->is_type( 'variable' ) ) {
			self::fail( __( 'Please choose an option.', 'dz-cod-order-form' ), 'invalid', 'variation_id' );
		}
		if ( $product->is_sold_individually() ) {
			$qty = 1;
		}
		// Quantity offers: the pack decides the quantity and the price, never the browser.
		// Free delivery chosen by the shop for this product (and wilaya / delivery type).
		if ( DZCOD_Offers::free_delivery_applies( DZCOD_Offers::free_delivery( $product_id ), $wilaya, $type ) ) {
			$extras['free_shipping'] = true;
		}

		$offers = $product->is_sold_individually() ? array() : DZCOD_Offers::get( $product_id );
		if ( $offers ) {
			$idx = self::field( 'offer' );
			if ( '' === $idx || ! ctype_digit( $idx ) || ! isset( $offers[ (int) $idx ] ) ) {
				self::fail( __( 'Please choose a pack.', 'dz-cod-order-form' ), 'invalid', 'offer' );
			}
			$offer                   = $offers[ (int) $idx ];
			$qty                     = $offer['qty'];
			$extras['line_total']    = DZCOD_Offers::total( $offer, (float) $product->get_price() );
			$extras['free_shipping'] = ! empty( $extras['free_shipping'] ) || $offer['free_shipping'];
			/* translators: %d: quantity */
			$extras['offer'] = '' !== $offer['label'] ? $offer['label'] : sprintf( _n( '%d piece', '%d pieces', $qty, 'dz-cod-order-form' ), $qty );
		}
		if ( ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->has_enough_stock( $qty ) ) {
			self::fail( __( 'Sorry, this product is out of stock.', 'dz-cod-order-form' ), 'stock' );
		}

		// Anti-fraud.
		$check = DZCOD_Antifraud::check( $phone );
		if ( ! $check['ok'] ) {
			self::fail( $check['error'], 'fraud', 'phone' );
		}

		$extras['risky'] = ! empty( $check['risky'] );
		$order           = self::create_order( $product, $qty, $name, $phone, $wilaya, $commune, $address, $type, (float) $shipping, (int) $check['duplicate_of'], $extras );
		if ( is_wp_error( $order ) ) {
			self::fail( __( 'Your order could not be saved. Please try again.', 'dz-cod-order-form' ), 'save' );
		}

		DZCOD_Antifraud::record_order();
		do_action( 'dzcod_order_created', $order );

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'id'       => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
			);
		}

		wp_send_json_success(
			array(
				'message'  => $s['success_message'],
				'redirect' => $s['redirect_thankyou'] ? $order->get_checkout_order_received_url() : '',
				'upsell'   => DZCOD_Upsell::for_response( $order ),
				'order'    => array(
					'id'       => $order->get_id(),
					'total'    => (float) $order->get_total(),
					'currency' => $order->get_currency(),
					'items'    => $items,
				),
			)
		);
	}

	/**
	 * Read and validate the optional built-in fields and the custom fields.
	 * Sends a JSON error and stops on invalid input.
	 *
	 * @return array{phone2:string,email:string,note:string,custom:array}
	 */
	private static function read_extras() {
		$out = array(
			'phone2' => '',
			'email'  => '',
			'note'   => '',
			'custom' => array(),
		);

		$f = DZCOD_Settings::field( 'phone2' );
		if ( $f['show'] ) {
			$p2 = DZCOD_Antifraud::normalize_phone( self::field( 'phone2' ) );
			if ( ( '' !== $p2 || $f['required'] ) && ! DZCOD_Antifraud::is_valid_phone( $p2 ) ) {
				self::fail( __( 'Please enter a valid Algerian phone number (e.g. 0555 12 34 56).', 'dz-cod-order-form' ), 'invalid', 'phone2' );
			}
			$out['phone2'] = $p2;
		}

		$f = DZCOD_Settings::field( 'email' );
		if ( $f['show'] ) {
			$email = sanitize_email( self::field( 'email' ) );
			if ( ( '' !== self::field( 'email' ) || $f['required'] ) && ! is_email( $email ) ) {
				self::fail( __( 'Please enter a valid email address.', 'dz-cod-order-form' ), 'invalid', 'email' );
			}
			$out['email'] = $email;
		}

		$f = DZCOD_Settings::field( 'note' );
		if ( $f['show'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in ajax_place_order().
			$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
			$note = mb_substr( $note, 0, 500 );
			if ( $f['required'] && '' === trim( $note ) ) {
				self::fail( __( 'Please fill in this field.', 'dz-cod-order-form' ), 'invalid', 'note' );
			}
			$out['note'] = $note;
		}

		foreach ( DZCOD_Settings::custom_fields() as $cf ) {
			$key = $cf['key'];
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in ajax_place_order().
			$raw = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
			if ( 'checkbox' === $cf['type'] ) {
				$value = '' !== $raw ? __( 'Yes', 'dz-cod-order-form' ) : '';
			} elseif ( 'textarea' === $cf['type'] ) {
				$value = mb_substr( $raw, 0, 500 );
			} else {
				$value = mb_substr( sanitize_text_field( $raw ), 0, 200 );
			}
			// A dropdown only accepts one of its own options.
			if ( 'select' === $cf['type'] && '' !== $value && ! in_array( $value, $cf['options'], true ) ) {
				$value = '';
			}
			if ( $cf['required'] && '' === trim( $value ) ) {
				self::fail( __( 'Please fill in this field.', 'dz-cod-order-form' ), 'invalid', $key );
			}
			if ( '' !== $value ) {
				$out['custom'][ $cf['label'] ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Build and save the WooCommerce order.
	 *
	 * @param WC_Product $product      Product or variation.
	 * @param int        $qty          Quantity.
	 * @param string     $name         Customer name.
	 * @param string     $phone        Normalized phone.
	 * @param string     $wilaya       Wilaya code.
	 * @param string     $commune      Commune.
	 * @param string     $address      Address.
	 * @param string     $type         home|desk.
	 * @param float      $shipping     Delivery price.
	 * @param int        $duplicate_of Earlier order id from the same phone, 0 if none.
	 * @param array      $extras       phone2, email, note, custom (label => value).
	 * @return WC_Order|WP_Error
	 */
	public static function create_order( $product, $qty, $name, $phone, $wilaya, $commune, $address, $type, $shipping, $duplicate_of, $extras = array() ) {
		$extras = wp_parse_args(
			$extras,
			array(
				'phone2'        => '',
				'email'         => '',
				'note'          => '',
				'custom'        => array(),
				'line_total'    => null,
				'free_shipping' => false,
				'offer'         => '',
				'from_admin'    => false,
				'risky'         => false,
			)
		);
		$s      = DZCOD_Settings::get();

		try {
			$order = wc_create_order( array( 'created_via' => 'dzcod' ) );
			if ( is_wp_error( $order ) ) {
				return $order;
			}

			if ( null !== $extras['line_total'] ) {
				$order->add_product(
					$product,
					$qty,
					array(
						'subtotal' => $extras['line_total'],
						'total'    => $extras['line_total'],
					)
				);
			} else {
				$order->add_product( $product, $qty );
			}

			$label  = DZCOD_Locations::wilaya_label( $wilaya, 'latin' );
			$states = WC()->countries->get_states( 'DZ' );
			$state  = ( is_array( $states ) && isset( $states[ 'DZ-' . $wilaya ] ) ) ? 'DZ-' . $wilaya : '';

			$address_fields = array(
				'first_name' => $name,
				'phone'      => $phone,
				'country'    => 'DZ',
				'state'      => $state,
				'city'       => $commune,
				'address_1'  => ( '' !== $address ) ? $address : $commune,
			);
			if ( '' !== $extras['email'] ) {
				$address_fields['email'] = $extras['email'];
			}
			$order->set_address( $address_fields, 'billing' );
			unset( $address_fields['email'] );
			unset( $address_fields['phone'] );
			$order->set_address( $address_fields, 'shipping' );

			// Delivery.
			$subtotal = (float) $order->get_subtotal();
			$free     = (float) $s['free_shipping_over'];
			if ( $extras['free_shipping'] || ( $free > 0 && $subtotal >= $free ) ) {
				$shipping = 0;
			}
			$ship = new WC_Order_Item_Shipping();
			$ship->set_method_id( 'dzcod_' . $type );
			$ship->set_method_title(
				'desk' === $type
					/* translators: %s: wilaya */
					? sprintf( __( 'Stop desk delivery – %s', 'dz-cod-order-form' ), $label )
					/* translators: %s: wilaya */
					: sprintf( __( 'Home delivery – %s', 'dz-cod-order-form' ), $label )
			);
			$ship->set_total( wc_format_decimal( $shipping ) );
			$order->add_item( $ship );

			// Payment.
			$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
			$order->set_payment_method( 'cod' );
			$order->set_payment_method_title( isset( $gateways['cod'] ) ? $gateways['cod']->get_title() : __( 'Cash on delivery', 'dz-cod-order-form' ) );

			// Orders created by staff (recovered leads) must not carry the staff member's IP or account.
			if ( ! $extras['from_admin'] ) {
				$order->set_customer_ip_address( DZCOD_Antifraud::client_ip() );
				$order->set_customer_user_agent( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );
				if ( get_current_user_id() ) {
					$order->set_customer_id( get_current_user_id() );
				}
			}

			$order->update_meta_data( '_dzcod', 1 );
			$order->update_meta_data( '_dzcod_wilaya', $wilaya );
			$order->update_meta_data( '_dzcod_wilaya_label', $label );
			$order->update_meta_data( '_dzcod_delivery_type', $type );
			if ( '' !== $extras['phone2'] ) {
				$order->update_meta_data( '_dzcod_phone2', $extras['phone2'] );
			}
			if ( '' !== $extras['note'] ) {
				$order->set_customer_note( $extras['note'] );
			}
			if ( '' !== $extras['offer'] ) {
				$order->update_meta_data( '_dzcod_offer', $extras['offer'] );
			}
			if ( $extras['custom'] ) {
				$order->update_meta_data( '_dzcod_extra', $extras['custom'] );
			}
			if ( $duplicate_of ) {
				$order->update_meta_data( '_dzcod_duplicate_of', $duplicate_of );
			}
			if ( $extras['risky'] ) {
				$order->update_meta_data( '_dzcod_risky', 1 );
			}

			$order->calculate_totals();
			do_action( 'dzcod_before_order_save', $order );
			$order->save();

			if ( $duplicate_of ) {
				$order->update_status(
					'on-hold',
					/* translators: %d: earlier order id */
					sprintf( __( 'Possible duplicate: the same phone number placed order #%d recently.', 'dz-cod-order-form' ), $duplicate_of )
				);
			} elseif ( $extras['risky'] ) {
				$order->update_status( 'on-hold', __( 'This phone number refused more parcels than it accepted. Confirm before shipping.', 'dz-cod-order-form' ) );
			} else {
				$order->update_status( $s['order_status'], __( 'Order placed from the COD form.', 'dz-cod-order-form' ) );
			}

			return $order;
		} catch ( Exception $e ) {
			return new WP_Error( 'dzcod_order', $e->getMessage() );
		}
	}
}

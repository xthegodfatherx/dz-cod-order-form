<?php
/**
 * One-click upsell: add a discounted product to the order just placed.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post-order upsell.
 */
class DZCOD_Upsell {

	const WINDOW = 30; // Minutes during which the offer can be accepted.

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_ajax_dzcod_upsell', array( __CLASS__, 'ajax_accept' ) );
		add_action( 'wp_ajax_nopriv_dzcod_upsell', array( __CLASS__, 'ajax_accept' ) );
	}

	/**
	 * Signature that proves the browser received this offer for this order.
	 *
	 * @param WC_Order $order      Order.
	 * @param int      $product_id Upsell product.
	 * @return string
	 */
	private static function token( $order, $product_id ) {
		return hash_hmac( 'sha256', $order->get_id() . '|' . $order->get_order_key() . '|' . $product_id, wp_salt( 'nonce' ) );
	}

	/**
	 * The upsell product and price for an order, or null.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	public static function offer_for( $order ) {
		$items = $order->get_items();
		$first = reset( $items );
		if ( ! $first ) {
			return null;
		}
		$cfg = DZCOD_Offers::upsell_settings( $first->get_product_id() );
		if ( ! $cfg['product'] ) {
			return null;
		}
		$p = wc_get_product( $cfg['product'] );
		if ( ! $p || ! $p->is_type( 'simple' ) || 'publish' !== $p->get_status() || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
			return null;
		}
		if ( $order->get_meta( '_dzcod_upsold' ) ) {
			return null;
		}
		$was   = (float) $p->get_price();
		$price = round( $was * ( 1 - $cfg['discount'] / 100 ), wc_get_price_decimals() );
		return array(
			'product' => $p,
			'price'   => $price,
			'was'     => $was,
			'title'   => '' !== $cfg['title'] ? $cfg['title'] : __( 'Add this to your order at a special price?', 'dz-cod-order-form' ),
		);
	}

	/**
	 * Data sent to the browser right after ordering (or null when there is no offer).
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	public static function for_response( $order ) {
		$o = self::offer_for( $order );
		if ( ! $o ) {
			return null;
		}
		$img = wp_get_attachment_image_url( $o['product']->get_image_id(), 'thumbnail' );
		return array(
			'order'   => $order->get_id(),
			'product' => $o['product']->get_id(),
			'token'   => self::token( $order, $o['product']->get_id() ),
			'name'    => $o['product']->get_name(),
			'title'   => $o['title'],
			'price'   => html_entity_decode( wp_strip_all_tags( wc_price( $o['price'] ) ), ENT_QUOTES, 'UTF-8' ),
			'was'     => $o['was'] > $o['price'] ? html_entity_decode( wp_strip_all_tags( wc_price( $o['was'] ) ), ENT_QUOTES, 'UTF-8' ) : '',
			'image'   => $img ? $img : '',
			'yes'     => __( 'Yes, add it', 'dz-cod-order-form' ),
			'no'      => __( 'No thanks', 'dz-cod-order-form' ),
		);
	}

	/**
	 * Customer clicked "Yes, add it".
	 */
	public static function ajax_accept() {
		$order_id   = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorised by the HMAC token below.
		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order      = wc_get_order( $order_id );
		$fail       = function () {
			wp_send_json_error( array( 'message' => __( 'This offer is no longer available.', 'dz-cod-order-form' ) ) );
		};
		if ( ! $order || ! $order->get_meta( '_dzcod' ) || ! hash_equals( self::token( $order, $product_id ), $token ) ) {
			$fail();
		}
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
		if ( $order->get_meta( '_dzcod_upsold' ) || time() - $created > self::WINDOW * MINUTE_IN_SECONDS
			|| '' !== (string) $order->get_meta( '_dzcod_tracking' ) || in_array( $order->get_status(), array( 'cancelled', 'completed', 'refunded', 'failed', DZCOD_Tracking::RETURNED ), true ) ) {
			$fail();
		}
		$offer = self::offer_for( $order );
		if ( ! $offer || $offer['product']->get_id() !== $product_id || ! $offer['product']->has_enough_stock( 1 ) ) {
			$fail();
		}
		// Lock: one upsell per order even with a double tap.
		$lock = 'dzcod_upsell_' . $order_id;
		if ( get_transient( $lock ) ) {
			$fail();
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );

		$order->add_product(
			$offer['product'],
			1,
			array(
				'subtotal' => $offer['price'],
				'total'    => $offer['price'],
			)
		);
		$order->update_meta_data( '_dzcod_upsold', $product_id );
		$order->calculate_totals();
		/* translators: 1: product, 2: price */
		$order->add_order_note( sprintf( __( 'Customer accepted the upsell: %1$s at %2$s.', 'dz-cod-order-form' ), $offer['product']->get_name(), wp_strip_all_tags( wc_price( $offer['price'] ) ) ) );
		$order->save();
		// Reduce stock for the added line if the order already reduced stock for the others.
		if ( $order->get_data_store()->get_stock_reduced( $order->get_id() ) ) {
			wc_update_product_stock( $offer['product'], 1, 'decrease' );
		}
		delete_transient( $lock );
		do_action( 'dzcod_upsell_accepted', $order, $offer['product'] );

		wp_send_json_success(
			array(
				'message' => __( 'Added to your order!', 'dz-cod-order-form' ),
				'total'   => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total() ) ), ENT_QUOTES, 'UTF-8' ),
			)
		);
	}
}

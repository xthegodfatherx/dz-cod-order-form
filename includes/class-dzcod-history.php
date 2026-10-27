<?php
/**
 * Customer delivery history by phone: how many parcels were delivered or refused.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return-rate check.
 */
class DZCOD_History {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'dzcod_parcel_status', array( __CLASS__, 'forget' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'forget_by_id' ) );
	}

	/**
	 * Delivery history for a phone number (cached 10 minutes).
	 *
	 * @param string $phone       Normalized phone.
	 * @param int    $exclude_id  Order to leave out (the one being looked at).
	 * @return array{orders:int,delivered:int,returned:int,rate:float|null}
	 */
	public static function for_phone( $phone, $exclude_id = 0 ) {
		$phone = DZCOD_Antifraud::normalize_phone( $phone );
		$empty = array(
			'orders'    => 0,
			'delivered' => 0,
			'returned'  => 0,
			'rate'      => null,
		);
		if ( '' === $phone ) {
			return $empty;
		}
		$key  = 'dzcod_hist_' . md5( $phone );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data   = array();
			$orders = wc_get_orders(
				array(
					'billing_phone' => $phone,
					'limit'         => 100,
					'orderby'       => 'date',
					'order'         => 'DESC',
				)
			);
			foreach ( $orders as $o ) {
				$ps = (string) $o->get_meta( '_dzcod_parcel_status' );
				if ( 'delivered' === $ps || ( '' === $ps && 'completed' === $o->get_status() ) ) {
					$state = 'delivered';
				} elseif ( 'returned' === $ps || DZCOD_Tracking::RETURNED === $o->get_status() ) {
					$state = 'returned';
				} else {
					$state = 'other';
				}
				$data[ $o->get_id() ] = $state;
			}
			set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		}
		unset( $data[ $exclude_id ] );
		$out              = $empty;
		$out['orders']    = count( $data );
		$counts           = array_count_values( $data );
		$out['delivered'] = isset( $counts['delivered'] ) ? $counts['delivered'] : 0;
		$out['returned']  = isset( $counts['returned'] ) ? $counts['returned'] : 0;
		$done             = $out['delivered'] + $out['returned'];
		$out['rate']      = $done ? $out['returned'] / $done : null;
		return $out;
	}

	/**
	 * Is this customer a repeat refuser according to the settings?
	 *
	 * @param array $h for_phone() result.
	 * @return bool
	 */
	public static function is_risky( $h ) {
		$limit = (int) DZCOD_Settings::get( 'refusals_limit' );
		return $limit > 0 && $h['returned'] >= $limit && $h['returned'] > $h['delivered'];
	}

	/**
	 * Clear the cache for an order's phone.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function forget( $order ) {
		if ( $order instanceof WC_Order ) {
			delete_transient( 'dzcod_hist_' . md5( DZCOD_Antifraud::normalize_phone( $order->get_billing_phone() ) ) );
		}
	}

	/**
	 * Clear cache on status change.
	 *
	 * @param int $order_id Order id.
	 */
	public static function forget_by_id( $order_id ) {
		self::forget( wc_get_order( $order_id ) );
	}

	/**
	 * Short HTML badge, e.g. "2 ✓ · 1 ✗".
	 *
	 * @param array $h History.
	 * @return string
	 */
	public static function badge( $h ) {
		if ( ! $h['orders'] ) {
			return '<span class="dzcod-hist dzcod-hist-new">' . esc_html__( 'New customer', 'dz-cod-order-form' ) . '</span>';
		}
		$class = self::is_risky( $h ) ? 'bad' : ( $h['returned'] ? 'warn' : 'good' );
		/* translators: 1: delivered count, 2: returned count */
		$text = sprintf( __( '%1$d delivered · %2$d returned', 'dz-cod-order-form' ), $h['delivered'], $h['returned'] );
		return '<span class="dzcod-hist dzcod-hist-' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Order screen meta box.
	 */
	public static function meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box( 'dzcod-history', __( 'Customer history', 'dz-cod-order-form' ), array( __CLASS__, 'render' ), $screen, 'side', 'high' );
	}

	/**
	 * Meta box content.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function render( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order ) {
			return;
		}
		$h = self::for_phone( $order->get_billing_phone(), $order->get_id() );
		echo '<p>' . wp_kses_post( self::badge( $h ) ) . '</p>';
		if ( $h['orders'] ) {
			/* translators: %d: number of orders */
			echo '<p class="description">' . esc_html( sprintf( _n( '%d other order with this phone number.', '%d other orders with this phone number.', $h['orders'], 'dz-cod-order-form' ), $h['orders'] ) ) . '</p>';
		}
		if ( self::is_risky( $h ) ) {
			echo '<p style="color:#b32d2e"><strong>' . esc_html__( 'This customer refused more parcels than they accepted. Confirm carefully before shipping.', 'dz-cod-order-form' ) . '</strong></p>';
		}
	}
}

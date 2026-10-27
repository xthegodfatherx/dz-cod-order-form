<?php
/**
 * Parcel status sync: delivered / returned / in transit, pulled back from the delivery company.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parcel tracking and order status updates.
 */
class DZCOD_Tracking {

	const HOOK     = 'dzcod_sync_parcels';
	const RETURNED = 'dzcod-returned';
	const FINAL    = array( 'delivered', 'returned', 'cancelled' );

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
		add_action( self::HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_post_dzcod_refresh_parcel', array( __CLASS__, 'admin_refresh' ) );
	}

	/**
	 * Labels for the normalized statuses.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'pending'            => __( 'Waiting for pickup', 'dz-cod-order-form' ),
			'picked_up'          => __( 'Picked up', 'dz-cod-order-form' ),
			'in_transit'         => __( 'In transit', 'dz-cod-order-form' ),
			'out_for_delivery'   => __( 'Out for delivery', 'dz-cod-order-form' ),
			'delivery_attempted' => __( 'Delivery attempted — call the customer', 'dz-cod-order-form' ),
			'delivered'          => __( 'Delivered', 'dz-cod-order-form' ),
			'return_in_transit'  => __( 'Return in progress', 'dz-cod-order-form' ),
			'returned'           => __( 'Returned', 'dz-cod-order-form' ),
			'cancelled'          => __( 'Cancelled', 'dz-cod-order-form' ),
			'unknown'            => __( 'Unknown', 'dz-cod-order-form' ),
		);
	}

	/**
	 * "Returned" order status, so refused parcels are not mixed with cancelled orders.
	 */
	public static function register_status() {
		register_post_status(
			'wc-' . self::RETURNED,
			array(
				'label'                     => _x( 'Returned (refused)', 'Order status', 'dz-cod-order-form' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Returned <span class="count">(%s)</span>', 'Returned <span class="count">(%s)</span>', 'dz-cod-order-form' ),
			)
		);
	}

	/**
	 * Add the status to WooCommerce's list (after Cancelled).
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function add_status( $statuses ) {
		$out = array();
		foreach ( $statuses as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wc-cancelled' === $k ) {
				$out[ 'wc-' . self::RETURNED ] = _x( 'Returned (refused)', 'Order status', 'dz-cod-order-form' );
			}
		}
		if ( ! isset( $out[ 'wc-' . self::RETURNED ] ) ) {
			$out[ 'wc-' . self::RETURNED ] = _x( 'Returned (refused)', 'Order status', 'dz-cod-order-form' );
		}
		return $out;
	}

	/**
	 * Schedule the sync every 3 hours while a delivery company is connected.
	 */
	public static function schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$on   = '' !== DZCOD_Couriers::settings()['provider'] && ! empty( DZCOD_Couriers::settings()['sync_enabled'] );
		$next = as_has_scheduled_action( self::HOOK, array(), 'dzcod' );
		if ( $on && ! $next ) {
			as_schedule_recurring_action( time() + 10 * MINUTE_IN_SECONDS, 3 * HOUR_IN_SECONDS, self::HOOK, array(), 'dzcod' );
		} elseif ( ! $on && $next ) {
			as_unschedule_all_actions( self::HOOK, array(), 'dzcod' );
		}
	}

	/**
	 * Orders whose parcel is still moving, least recently checked first.
	 *
	 * @param int $limit Max orders.
	 * @return WC_Order[]
	 */
	private static function open_orders( $limit ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 200,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'date_created' => '>' . ( time() - 60 * DAY_IN_SECONDS ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_dzcod_tracking',
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'key'     => '_dzcod_parcel_final',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$min = time() - 2 * HOUR_IN_SECONDS;
		$due = array_filter(
			$orders,
			function ( $o ) use ( $min ) {
				return (int) $o->get_meta( '_dzcod_parcel_checked' ) < $min;
			}
		);
		usort(
			$due,
			function ( $a, $b ) {
				return (int) $a->get_meta( '_dzcod_parcel_checked' ) - (int) $b->get_meta( '_dzcod_parcel_checked' );
			}
		);
		return array_slice( $due, 0, $limit );
	}

	/**
	 * Background sync: up to 100 parcels per run.
	 */
	public static function sync() {
		$s = DZCOD_Couriers::settings();
		if ( '' === $s['provider'] || empty( $s['sync_enabled'] ) ) {
			return;
		}
		foreach ( array_chunk( self::open_orders( 100 ), 25 ) as $batch ) {
			self::sync_orders( $batch );
		}
	}

	/**
	 * Check a batch of orders sent to the configured company.
	 *
	 * @param WC_Order[] $orders Orders.
	 * @return int Orders updated.
	 */
	public static function sync_orders( $orders ) {
		$s   = DZCOD_Couriers::settings();
		$map = array();
		foreach ( $orders as $o ) {
			if ( (string) $o->get_meta( '_dzcod_courier' ) === $s['provider'] ) {
				$map[ (string) $o->get_meta( '_dzcod_tracking' ) ] = $o;
			}
		}
		if ( ! $map ) {
			return 0;
		}
		$res = DZCOD_Courier_API::track( $s['provider'], $s, array_keys( $map ) );
		if ( ! $res['ok'] ) {
			return 0;
		}
		$n = 0;
		foreach ( $map as $tracking => $order ) {
			$order->update_meta_data( '_dzcod_parcel_checked', time() );
			if ( isset( $res['status'][ $tracking ] ) && '' !== $res['status'][ $tracking ] ) {
				$n += self::apply( $order, $res['status'][ $tracking ] ) ? 1 : 0;
			}
			$order->save();
		}
		return $n;
	}

	/**
	 * Record a raw courier status on the order and move the order when final.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $raw   Raw status text.
	 * @return bool Whether the status changed.
	 */
	public static function apply( $order, $raw ) {
		$status = DZCOD_Courier_API::normalize_status( $raw );
		$old    = (string) $order->get_meta( '_dzcod_parcel_status' );
		$order->update_meta_data( '_dzcod_parcel_raw', mb_substr( $raw, 0, 200 ) );
		if ( $status === $old ) {
			return false;
		}
		$order->update_meta_data( '_dzcod_parcel_status', $status );
		$labels = self::labels();
		/* translators: 1: status, 2: courier wording */
		$order->add_order_note( sprintf( __( 'Parcel: %1$s (%2$s)', 'dz-cod-order-form' ), $labels[ $status ], $raw ) );

		if ( in_array( $status, self::FINAL, true ) ) {
			$order->update_meta_data( '_dzcod_parcel_final', 1 );
		}
		$s = DZCOD_Couriers::settings();
		if ( 'delivered' === $status && '' !== $s['delivered_status'] && $order->get_status() !== $s['delivered_status'] ) {
			$order->set_status( $s['delivered_status'], __( 'Delivered and paid on delivery.', 'dz-cod-order-form' ) );
		} elseif ( 'returned' === $status && '' !== $s['returned_status'] && $order->get_status() !== $s['returned_status'] ) {
			$order->set_status( $s['returned_status'], __( 'Parcel returned by the delivery company.', 'dz-cod-order-form' ) );
		}
		do_action( 'dzcod_parcel_status', $order, $status, $raw );
		return true;
	}

	/**
	 * "Refresh status" button on the order screen.
	 */
	public static function admin_refresh() {
		$id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dz-cod-order-form' ) );
		}
		check_admin_referer( 'dzcod_refresh_parcel_' . $id );
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete_meta_data( '_dzcod_parcel_final' );
			self::sync_orders( array( $order ) );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}
}

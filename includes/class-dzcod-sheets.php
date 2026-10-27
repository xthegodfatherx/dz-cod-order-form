<?php
/**
 * Google Sheets export through a Google Apps Script web app (no OAuth needed).
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pushes new orders and status changes to a Google Sheet.
 */
class DZCOD_Sheets {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'dzcod_order_created', array( __CLASS__, 'on_order_created' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'admin_post_dzcod_sheets_test', array( __CLASS__, 'send_test' ) );
	}

	/**
	 * Whether a webhook URL is configured.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '' !== (string) DZCOD_Settings::get( 'sheets_url' );
	}

	/**
	 * Flatten an order into a sheet row.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function order_row( $order ) {
		$products = array();
		foreach ( $order->get_items() as $item ) {
			$products[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		$row = array(
			'order_id'      => $order->get_id(),
			'date'          => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
			'name'          => $order->get_billing_first_name(),
			'phone'         => $order->get_billing_phone(),
			'wilaya'        => (string) $order->get_meta( '_dzcod_wilaya_label' ),
			'commune'       => $order->get_billing_city(),
			'address'       => $order->get_billing_address_1(),
			'delivery_type' => (string) $order->get_meta( '_dzcod_delivery_type' ),
			'products'      => implode( ', ', $products ),
			'shipping'      => (float) $order->get_shipping_total(),
			'total'         => (float) $order->get_total(),
			'status'        => $order->get_status(),
			'duplicate_of'  => (int) $order->get_meta( '_dzcod_duplicate_of' ),
		);
		return (array) apply_filters( 'dzcod_sheets_row', $row, $order );
	}

	/**
	 * Send a payload to the Apps Script endpoint.
	 *
	 * @param array $payload  Data.
	 * @param bool  $blocking Wait for the response.
	 * @return array|WP_Error
	 */
	public static function send( $payload, $blocking = false ) {
		$payload['secret'] = (string) DZCOD_Settings::get( 'sheets_secret' );
		return wp_safe_remote_post(
			DZCOD_Settings::get( 'sheets_url' ),
			array(
				'timeout'     => $blocking ? 15 : 3,
				'blocking'    => $blocking,
				'redirection' => 5,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * New order → append a row.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function on_order_created( $order ) {
		if ( self::enabled() ) {
			self::send(
				array(
					'event' => 'order',
					'row'   => self::order_row( $order ),
				)
			);
		}
	}

	/**
	 * Status change on one of our orders → update the status column.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( self::enabled() && $order && $order->get_meta( '_dzcod' ) ) {
			self::send(
				array(
					'event'    => 'status',
					'order_id' => (int) $order_id,
					'status'   => $to,
				)
			);
		}
	}

	/**
	 * Admin "send test row" button.
	 */
	public static function send_test() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dz-cod-order-form' ) );
		}
		check_admin_referer( 'dzcod_sheets_test' );
		$ok = false;
		if ( self::enabled() ) {
			$res = self::send(
				array(
					'event' => 'test',
					'row'   => array(
						'order_id' => 0,
						'date'     => current_time( 'Y-m-d H:i' ),
						'name'     => 'Test',
						'phone'    => '0555000000',
						'wilaya'   => '16 - Alger',
						'commune'  => 'Alger Centre',
						'status'   => 'test',
					),
				),
				true
			);
			$ok = ! is_wp_error( $res ) && false !== strpos( (string) wp_remote_retrieve_body( $res ), '"ok":true' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=dzcod&tab=sheets&dzcod_test=' . ( $ok ? 'ok' : 'fail' ) ) );
		exit;
	}
}

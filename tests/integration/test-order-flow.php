<?php
/**
 * End-to-end tests of the order form AJAX flow inside real WordPress + WooCommerce.
 *
 * @package DZCOD
 */

/**
 * Order flow.
 */
class Test_Order_Flow extends WP_Ajax_UnitTestCase {

	/**
	 * Simple product at 3,500 DA.
	 *
	 * @var WC_Product_Simple
	 */
	private $product;

	/**
	 * Fresh product, settings and empty POST for every test.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'woocommerce_currency', 'DZD' );
		$this->product = new WC_Product_Simple();
		$this->product->set_name( 'Miel Bilobee 1kg' );
		$this->product->set_regular_price( '3500' );
		$this->product->set_manage_stock( true );
		$this->product->set_stock_quantity( 50 );
		$this->product->save();

		$prices = array();
		foreach ( array_keys( DZCOD_Locations::all() ) as $code ) {
			$prices[ $code ] = array( 'home' => 600, 'desk' => 400, 'enabled' => 1 );
		}
		$prices['16'] = array( 'home' => 500, 'desk' => 300, 'enabled' => 1 );
		$prices['33'] = array( 'home' => 900, 'desk' => 700, 'enabled' => 0 );
		update_option(
			DZCOD_Settings::OPTION,
			array_merge(
				DZCOD_Settings::defaults(),
				array(
					'prices'      => $prices,
					'rate_limit'  => 0,
					'dup_hours'   => 24,
					'dup_action'  => 'block',
					'leads_enabled' => 0,
				)
			)
		);
		DZCOD_Settings::flush();
		$_POST = array();
	}

	/**
	 * Submit the form through admin-ajax and return the decoded JSON.
	 *
	 * @param array $fields Form fields.
	 * @return array
	 */
	private function submit( $fields ) {
		$_POST = array_merge(
			array(
				'action'        => 'dzcod_place_order',
				'nonce'         => wp_create_nonce( 'dzcod_order' ),
				'product_id'    => $this->product->get_id(),
				'qty'           => 1,
				'name'          => 'Amine Benali',
				'phone'         => '0555 12 34 56',
				'wilaya'        => '16',
				'commune'       => 'Bab El Oued',
				'address'       => 'Rue 1',
				'delivery_type' => 'home',
			),
			$fields
		);
		if ( ! has_action( 'wp_ajax_dzcod_place_order' ) ) {
			DZCOD_Order::init();
		}
		$this->_last_response = '';
		$note                 = '';
		try {
			$this->_handleAjax( 'dzcod_place_order' );
		} catch ( WPAjaxDieContinueException $e ) {
			$note = 'continue:' . $e->getMessage();
		} catch ( WPAjaxDieStopException $e ) {
			$note = 'stop:' . $e->getMessage();
		}
		$this->assertNotSame( '', $this->_last_response, 'Empty AJAX response (' . $note . ')' );
		$res = json_decode( $this->_last_response, true );
		$this->assertIsArray( $res, 'Non-JSON AJAX response (' . $note . '): ' . substr( $this->_last_response, 0, 600 ) );
		return $res;
	}

	/**
	 * A valid order is created with server-side prices and delivery.
	 */
	public function test_order_is_created_with_server_prices() {
		$res = $this->submit( array( 'price' => '1', 'total' => '1' ) ); // Fake price fields are ignored.
		$this->assertTrue( $res['success'], wp_json_encode( $res ) );
		$order = wc_get_order( $res['data']['order']['id'] );
		$this->assertEquals( 4000, (float) $order->get_total() );
		$this->assertEquals( 500, (float) $order->get_shipping_total() );
		$this->assertSame( '0555123456', $order->get_billing_phone() );
		$this->assertSame( '16', (string) $order->get_meta( '_dzcod_wilaya' ) );
		$this->assertSame( 'cod', $order->get_payment_method() );
		$this->assertSame( 49, wc_get_product( $this->product->get_id() )->get_stock_quantity() );
	}

	/**
	 * Same phone twice within the window is blocked.
	 */
	public function test_duplicate_is_blocked() {
		$this->assertTrue( $this->submit( array() )['success'] );
		$res = $this->submit( array( 'phone' => '+213 555 12 34 56' ) );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'fraud', $res['data']['code'] );
	}

	/**
	 * Honeypot, invalid phone, wrong commune and disabled wilaya are refused.
	 */
	public function test_invalid_submissions_are_refused() {
		$this->assertSame( 'spam', $this->submit( array( 'dzcod_website' => 'http://spam' ) )['data']['code'] );
		$this->assertSame( 'phone', $this->submit( array( 'phone' => '0812345678' ) )['data']['field'] );
		$this->assertSame( 'commune', $this->submit( array( 'commune' => 'Oran' ) )['data']['field'] );
		$this->assertSame( 'wilaya', $this->submit( array( 'wilaya' => '33', 'commune' => 'Illizi' ) )['data']['field'] );
		$this->assertSame( 'nonce', $this->submit( array( 'nonce' => 'bad' ) )['data']['code'] );
	}

	/**
	 * Quantity offers: the pack price is applied on the server, free delivery honoured.
	 */
	public function test_quantity_offer_pricing() {
		update_post_meta(
			$this->product->get_id(),
			'_dzcod_offers',
			array(
				array( 'qty' => 1, 'price' => '', 'percent' => '', 'label' => '', 'badge' => '', 'free_shipping' => 0 ),
				array( 'qty' => 3, 'price' => '8000', 'percent' => '', 'label' => '', 'badge' => 'Best', 'free_shipping' => 1 ),
			)
		);
		$res   = $this->submit( array( 'offer' => '1', 'qty' => '1' ) ); // Browser qty is ignored; the pack decides.
		$order = wc_get_order( $res['data']['order']['id'] );
		$items = $order->get_items();
		$this->assertSame( 3, reset( $items )->get_quantity() );
		$this->assertEquals( 8000, (float) $order->get_total() );
		$this->assertEquals( 0, (float) $order->get_shipping_total() );
		$this->assertSame( 'offer', $this->submit( array( 'offer' => '9', 'phone' => '0661000000' ) )['data']['field'] );
	}

	/**
	 * Free delivery set on the product: free only where and how the shop decided.
	 */
	public function test_product_free_delivery() {
		update_post_meta( $this->product->get_id(), '_dzcod_free_delivery', array( 'mode' => 'desk', 'wilayas' => array( '16' ) ) );
		$desk = wc_get_order( $this->submit( array( 'delivery_type' => 'desk', 'phone' => '0770000010' ) )['data']['order']['id'] );
		$this->assertEquals( 0, (float) $desk->get_shipping_total() );
		$home = wc_get_order( $this->submit( array( 'delivery_type' => 'home', 'phone' => '0770000011' ) )['data']['order']['id'] );
		$this->assertEquals( 500, (float) $home->get_shipping_total() );
		$other = wc_get_order( $this->submit( array( 'delivery_type' => 'desk', 'wilaya' => '09', 'commune' => 'Blida', 'phone' => '0770000012' ) )['data']['order']['id'] );
		$this->assertEquals( 400, (float) $other->get_shipping_total() );
	}

	/**
	 * Orders from a new wilaya are sent to couriers under the former wilaya code.
	 */
	public function test_new_wilaya_maps_to_legacy_code_for_couriers() {
		$res    = $this->submit( array( 'wilaya' => '68', 'commune' => 'Bou Saada', 'phone' => '0770000001' ) );
		$order  = wc_get_order( $res['data']['order']['id'] );
		$parcel = DZCOD_Couriers::parcel_from_order( $order );
		$this->assertSame( '28', (string) $parcel['wilaya_code'] );
		$this->assertSame( "M'Sila", $parcel['wilaya_name'] );
	}

	/**
	 * The upsell endpoint refuses a forged token.
	 */
	public function test_upsell_requires_valid_token() {
		$res   = $this->submit( array( 'phone' => '0770000002' ) );
		$_POST = array(
			'action'  => 'dzcod_upsell',
			'order'   => $res['data']['order']['id'],
			'product' => $this->product->get_id(),
			'token'   => str_repeat( 'a', 64 ),
		);
		$this->_last_response = '';
		try {
			$this->_handleAjax( 'dzcod_upsell' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
		$this->assertFalse( json_decode( $this->_last_response, true )['success'] );
	}
}

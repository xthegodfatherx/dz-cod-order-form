<?php
/**
 * Settings storage and admin page.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * All plugin settings live in one option array.
 */
class DZCOD_Settings {

	const OPTION = 'dzcod_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$prices = array();
		foreach ( array_keys( DZCOD_Locations::all() ) as $code ) {
			$prices[ $code ] = array(
				'home'    => 600,
				'desk'    => 400,
				'enabled' => 1,
			);
		}
		return array(
			// General.
			'auto_insert'        => 1,
			'hide_add_to_cart'   => 1,
			'form_title'         => __( 'Order now – pay on delivery', 'dz-cod-order-form' ),
			'button_text'        => __( 'Confirm order', 'dz-cod-order-form' ),
			'success_message'    => __( 'Thank you! Your order has been received. We will call you shortly to confirm it.', 'dz-cod-order-form' ),
			'name_lang'          => 'ar',
			'show_address'       => 1,
			'require_address'    => 0,
			'show_quantity'      => 1,
			'stopdesk_enabled'   => 1,
			'order_status'       => 'processing',
			'redirect_thankyou'  => 0,
			'primary_color'      => '#16a34a',
			// Delivery.
			'prices'             => $prices,
			'free_shipping_over' => 0,
			// Anti-fraud.
			'dup_hours'          => 24,
			'dup_action'         => 'block',
			'blocked_phones'     => '',
			'rate_limit'         => 5,
			// Google Sheets.
			'sheets_url'         => '',
			'sheets_secret'      => '',
		);
	}

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Get all settings merged with defaults, or one key.
	 *
	 * @param string|null $key Optional key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		static $merged = null;
		if ( null === $merged ) {
			$saved  = get_option( self::OPTION, array() );
			$merged = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
			// Make sure every wilaya has a price row (new wilayas, partial saves).
			$merged['prices'] = wp_parse_args( (array) $merged['prices'], self::defaults()['prices'] );
		}
		if ( null === $key ) {
			return $merged;
		}
		return isset( $merged[ $key ] ) ? $merged[ $key ] : null;
	}

	/**
	 * Delivery price for a wilaya, or null when delivery there is disabled.
	 *
	 * @param string $code Wilaya code.
	 * @param string $type 'home' or 'desk'.
	 * @return float|null
	 */
	public static function delivery_price( $code, $type ) {
		$prices = self::get( 'prices' );
		if ( empty( $prices[ $code ] ) || empty( $prices[ $code ]['enabled'] ) ) {
			return null;
		}
		$type = ( 'desk' === $type ) ? 'desk' : 'home';
		return (float) $prices[ $code ][ $type ];
	}

	/**
	 * Register option with sanitizer.
	 */
	public static function register() {
		register_setting(
			'dzcod',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * Sanitize a save from one tab, merging into existing settings so other tabs are kept.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::get();
		$input   = is_array( $input ) ? $input : array();
		$tab     = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
		$out     = $current;

		// Already-sanitized full array (e.g. add_option on activation): keep it.
		if ( '' === $tab ) {
			return wp_parse_args( $input, $current );
		}

		if ( 'general' === $tab ) {
			foreach ( array( 'auto_insert', 'hide_add_to_cart', 'show_address', 'require_address', 'show_quantity', 'stopdesk_enabled', 'redirect_thankyou' ) as $k ) {
				$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
			}
			foreach ( array( 'form_title', 'button_text' ) as $k ) {
				$out[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : $current[ $k ];
			}
			$out['success_message'] = isset( $input['success_message'] ) ? sanitize_textarea_field( $input['success_message'] ) : $current['success_message'];
			$out['name_lang']       = ( isset( $input['name_lang'] ) && 'latin' === $input['name_lang'] ) ? 'latin' : 'ar';
			$status                 = isset( $input['order_status'] ) ? sanitize_key( $input['order_status'] ) : 'processing';
			$out['order_status']    = in_array( $status, array( 'processing', 'pending', 'on-hold' ), true ) ? $status : 'processing';
			$color                  = isset( $input['primary_color'] ) ? sanitize_hex_color( $input['primary_color'] ) : '';
			$out['primary_color']   = $color ? $color : '#16a34a';
		}

		if ( 'delivery' === $tab ) {
			$prices = array();
			foreach ( array_keys( DZCOD_Locations::all() ) as $code ) {
				$row             = isset( $input['prices'][ $code ] ) ? (array) $input['prices'][ $code ] : array();
				$prices[ $code ] = array(
					'home'    => isset( $row['home'] ) ? max( 0, (float) $row['home'] ) : 0,
					'desk'    => isset( $row['desk'] ) ? max( 0, (float) $row['desk'] ) : 0,
					'enabled' => empty( $row['enabled'] ) ? 0 : 1,
				);
			}
			$out['prices']             = $prices;
			$out['free_shipping_over'] = isset( $input['free_shipping_over'] ) ? max( 0, (float) $input['free_shipping_over'] ) : 0;
		}

		if ( 'antifraud' === $tab ) {
			$out['dup_hours']      = isset( $input['dup_hours'] ) ? max( 0, absint( $input['dup_hours'] ) ) : 24;
			$out['dup_action']     = ( isset( $input['dup_action'] ) && 'flag' === $input['dup_action'] ) ? 'flag' : 'block';
			$out['rate_limit']     = isset( $input['rate_limit'] ) ? absint( $input['rate_limit'] ) : 5;
			$phones                = isset( $input['blocked_phones'] ) ? explode( "\n", (string) $input['blocked_phones'] ) : array();
			$clean                 = array_filter( array_map( array( 'DZCOD_Antifraud', 'normalize_phone' ), $phones ) );
			$out['blocked_phones'] = implode( "\n", array_unique( $clean ) );
		}

		if ( 'sheets' === $tab ) {
			$out['sheets_url']    = isset( $input['sheets_url'] ) ? esc_url_raw( trim( $input['sheets_url'] ), array( 'https' ) ) : '';
			$out['sheets_secret'] = isset( $input['sheets_secret'] ) ? sanitize_text_field( $input['sheets_secret'] ) : '';
		}

		unset( $out['_tab'] );
		return $out;
	}
}

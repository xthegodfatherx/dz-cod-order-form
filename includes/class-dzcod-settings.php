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
	 * Merged settings cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Fields that are always shown and required (orders cannot work without them).
	 */
	const LOCKED_FIELDS = array( 'name', 'phone', 'wilaya', 'commune' );

	/**
	 * Default configuration of the built-in form fields.
	 *
	 * @return array
	 */
	public static function default_fields() {
		return array(
			'name'    => array( 'label' => __( 'Full name', 'dz-cod-order-form' ), 'placeholder' => '', 'show' => 1, 'required' => 1 ),
			'phone'   => array( 'label' => __( 'Phone number', 'dz-cod-order-form' ), 'placeholder' => '05XX XX XX XX', 'show' => 1, 'required' => 1 ),
			'phone2'  => array( 'label' => __( 'Second phone', 'dz-cod-order-form' ), 'placeholder' => '', 'show' => 0, 'required' => 0 ),
			'email'   => array( 'label' => __( 'Email', 'dz-cod-order-form' ), 'placeholder' => '', 'show' => 0, 'required' => 0 ),
			'wilaya'  => array( 'label' => __( 'Wilaya', 'dz-cod-order-form' ), 'placeholder' => __( 'Choose your wilaya', 'dz-cod-order-form' ), 'show' => 1, 'required' => 1 ),
			'commune' => array( 'label' => __( 'Commune', 'dz-cod-order-form' ), 'placeholder' => __( 'Choose your commune', 'dz-cod-order-form' ), 'show' => 1, 'required' => 1 ),
			'address' => array( 'label' => __( 'Address', 'dz-cod-order-form' ), 'placeholder' => '', 'show' => 1, 'required' => 0 ),
			'note'    => array( 'label' => __( 'Note', 'dz-cod-order-form' ), 'placeholder' => '', 'show' => 0, 'required' => 0 ),
			'qty'     => array( 'label' => __( 'Quantity', 'dz-cod-order-form' ), 'placeholder' => '', 'show' => 1, 'required' => 0 ),
		);
	}

	/**
	 * Configuration of one built-in field.
	 *
	 * @param string $key Field key.
	 * @return array{label:string,placeholder:string,show:int,required:int}
	 */
	public static function field( $key ) {
		$fields = (array) self::get( 'fields' );
		$def    = self::default_fields();
		$f      = isset( $fields[ $key ] ) ? wp_parse_args( (array) $fields[ $key ], $def[ $key ] ) : $def[ $key ];
		if ( in_array( $key, self::LOCKED_FIELDS, true ) ) {
			$f['show']     = 1;
			$f['required'] = 1;
		}
		if ( '' === trim( (string) $f['label'] ) ) {
			$f['label'] = $def[ $key ]['label'];
		}
		return $f;
	}

	/**
	 * Custom fields: list of [ key, label, type, options[], required, placeholder ].
	 *
	 * @return array
	 */
	public static function custom_fields() {
		$out = array();
		foreach ( (array) self::get( 'custom_fields' ) as $i => $cf ) {
			if ( empty( $cf['label'] ) ) {
				continue;
			}
			$opts  = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( isset( $cf['options'] ) ? $cf['options'] : '' ) ) ), 'strlen' ) );
			$type  = isset( $cf['type'] ) ? $cf['type'] : 'text';
			$out[] = array(
				'key'         => 'dzcod_cf_' . (int) $i,
				'label'       => (string) $cf['label'],
				'type'        => ( 'select' === $type && ! $opts ) ? 'text' : $type,
				'options'     => $opts,
				'required'    => ! empty( $cf['required'] ),
				'placeholder' => isset( $cf['placeholder'] ) ? (string) $cf['placeholder'] : '',
			);
		}
		return $out;
	}

	/**
	 * Forget cached settings (after programmatic updates).
	 */
	public static function flush() {
		self::$cache = null;
	}

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
			'fields'             => self::default_fields(),
			'custom_fields'      => array(),
			'stopdesk_enabled'   => 1,
			'order_status'       => 'processing',
			'redirect_thankyou'  => 0,
			'primary_color'      => '#16a34a',
			'leads_enabled'      => 1,
			'leads_retention'    => 30,
			'leads_notice'       => __( 'Your number is only used to contact you about this order.', 'dz-cod-order-form' ),
			// Delivery.
			'prices'             => $prices,
			'free_shipping_over' => 0,
			'prices_sheet_url'   => '',
			'prices_sheet_auto'  => 0,
			// Anti-fraud.
			'dup_hours'          => 24,
			'dup_action'         => 'block',
			'blocked_phones'     => '',
			'rate_limit'         => 5,
			'ip_source'          => 'remote',
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
		add_filter(
			'option_page_capability_dzcod',
			function () {
				return 'manage_woocommerce';
			}
		);
	}

	/**
	 * Get all settings merged with defaults, or one key.
	 *
	 * @param string|null $key Optional key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			// 1.1 and earlier: address / quantity switches lived in the General tab.
			if ( ! isset( $saved['fields'] ) && ( isset( $saved['show_address'] ) || isset( $saved['show_quantity'] ) ) ) {
				$fields                        = self::default_fields();
				$fields['address']['show']     = empty( $saved['show_address'] ) ? 0 : 1;
				$fields['address']['required'] = empty( $saved['require_address'] ) ? 0 : 1;
				$fields['qty']['show']         = ( isset( $saved['show_quantity'] ) && empty( $saved['show_quantity'] ) ) ? 0 : 1;
				$saved['fields']               = $fields;
			}
			$defaults = self::defaults();
			$merged   = wp_parse_args( $saved, $defaults );
			// Make sure every wilaya has a price row (new wilayas, partial saves).
			// "+" and not wp_parse_args(): array_merge() renumbers numeric keys such as "16".
			$merged['prices'] = (array) $merged['prices'] + $defaults['prices'];
			self::$cache      = $merged;
		}
		$merged = self::$cache;
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
			foreach ( array( 'auto_insert', 'hide_add_to_cart', 'stopdesk_enabled', 'redirect_thankyou', 'leads_enabled' ) as $k ) {
				$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
			}
			foreach ( array( 'form_title', 'button_text' ) as $k ) {
				$out[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : $current[ $k ];
			}
			$out['success_message'] = isset( $input['success_message'] ) ? sanitize_textarea_field( $input['success_message'] ) : $current['success_message'];
			$out['leads_retention'] = isset( $input['leads_retention'] ) ? min( 365, max( 1, absint( $input['leads_retention'] ) ) ) : 30;
			$out['leads_notice']    = isset( $input['leads_notice'] ) ? sanitize_text_field( $input['leads_notice'] ) : $current['leads_notice'];
			$out['name_lang']       = ( isset( $input['name_lang'] ) && 'latin' === $input['name_lang'] ) ? 'latin' : 'ar';
			$status                 = isset( $input['order_status'] ) ? sanitize_key( $input['order_status'] ) : 'processing';
			$out['order_status']    = in_array( $status, array( 'processing', 'pending', 'on-hold' ), true ) ? $status : 'processing';
			$color                  = isset( $input['primary_color'] ) ? sanitize_hex_color( $input['primary_color'] ) : '';
			$out['primary_color']   = $color ? $color : '#16a34a';
		}

		if ( 'fields' === $tab ) {
			$fields = array();
			foreach ( self::default_fields() as $key => $def ) {
				$row            = isset( $input['fields'][ $key ] ) ? (array) $input['fields'][ $key ] : array();
				$fields[ $key ] = array(
					'label'       => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : $def['label'],
					'placeholder' => isset( $row['placeholder'] ) ? sanitize_text_field( $row['placeholder'] ) : $def['placeholder'],
					'show'        => in_array( $key, self::LOCKED_FIELDS, true ) || ! empty( $row['show'] ) ? 1 : 0,
					'required'    => in_array( $key, self::LOCKED_FIELDS, true ) || ! empty( $row['required'] ) ? 1 : 0,
				);
			}
			$fields['qty']['required'] = 0;
			$out['fields']             = $fields;

			$custom = array();
			$rows   = isset( $input['custom_fields'] ) ? (array) $input['custom_fields'] : array();
			foreach ( array_slice( $rows, 0, 10, true ) as $i => $row ) {
				$row   = (array) $row;
				$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
				$type  = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'text';
				// Keep the row index stable so saved order data keeps matching its field.
				$custom[ absint( $i ) ] = array(
					'label'       => $label,
					'type'        => in_array( $type, array( 'text', 'textarea', 'select', 'checkbox' ), true ) ? $type : 'text',
					'options'     => isset( $row['options'] ) ? sanitize_text_field( $row['options'] ) : '',
					'placeholder' => isset( $row['placeholder'] ) ? sanitize_text_field( $row['placeholder'] ) : '',
					'required'    => empty( $row['required'] ) ? 0 : 1,
				);
			}
			$out['custom_fields'] = $custom;
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
			$out['prices_sheet_url']   = isset( $input['prices_sheet_url'] ) ? esc_url_raw( trim( $input['prices_sheet_url'] ), array( 'https' ) ) : '';
			$out['prices_sheet_auto']  = empty( $input['prices_sheet_auto'] ) ? 0 : 1;
		}

		if ( 'antifraud' === $tab ) {
			$out['dup_hours']      = isset( $input['dup_hours'] ) ? max( 0, absint( $input['dup_hours'] ) ) : 24;
			$out['dup_action']     = ( isset( $input['dup_action'] ) && 'flag' === $input['dup_action'] ) ? 'flag' : 'block';
			$out['rate_limit']     = isset( $input['rate_limit'] ) ? absint( $input['rate_limit'] ) : 5;
			$src                   = isset( $input['ip_source'] ) ? sanitize_key( $input['ip_source'] ) : 'remote';
			$out['ip_source']      = in_array( $src, array( 'remote', 'cloudflare', 'proxy' ), true ) ? $src : 'remote';
			$phones                = isset( $input['blocked_phones'] ) ? explode( "\n", (string) $input['blocked_phones'] ) : array();
			$clean                 = array_filter( array_map( array( 'DZCOD_Antifraud', 'normalize_phone' ), $phones ) );
			$out['blocked_phones'] = implode( "\n", array_unique( $clean ) );
		}

		if ( 'sheets' === $tab ) {
			$out['sheets_url']    = isset( $input['sheets_url'] ) ? esc_url_raw( trim( $input['sheets_url'] ), array( 'https' ) ) : '';
			$secret = isset( $input['sheets_secret'] ) ? sanitize_text_field( $input['sheets_secret'] ) : '';
			if ( '' !== $out['sheets_url'] && strlen( $secret ) < 12 ) {
				// Generate a strong secret rather than leave the sheet writable by anyone.
				$secret = wp_generate_password( 32, false, false );
				add_settings_error( self::OPTION, 'dzcod_secret', __( 'A secret of at least 12 characters is required. A random one was generated — copy it into your Apps Script (SECRET).', 'dz-cod-order-form' ), 'warning' );
			}
			$out['sheets_secret'] = $secret;
		}

		unset( $out['_tab'], $out['show_address'], $out['require_address'], $out['show_quantity'] );
		self::flush();
		return $out;
	}
}

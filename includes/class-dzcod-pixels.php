<?php
/**
 * Server-side conversions: Meta Conversions API and TikTok Events API.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends Purchase events from the server, deduplicated with the browser pixels.
 */
class DZCOD_Pixels {

	const HOOK = 'dzcod_send_conversions';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'dzcod_order_created', array( __CLASS__, 'on_created' ), 20 );
		add_action( 'dzcod_order_confirmed', array( __CLASS__, 'on_confirmed' ) );
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
		add_action( 'admin_post_dzcod_pixels_test', array( __CLASS__, 'admin_test' ) );
	}

	/**
	 * Any server-side destination configured?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = DZCOD_Settings::get();
		return ( '' !== $s['meta_pixel_id'] && '' !== $s['meta_token'] ) || ( '' !== $s['tiktok_pixel'] && '' !== $s['tiktok_token'] );
	}

	/**
	 * Remember the browser identifiers at order time (they are gone once the visitor leaves).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function capture_browser( $order ) {
		$cookie = function ( $k ) {
			return isset( $_COOKIE[ $k ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $k ] ) ) : '';
		};
		$url = wp_get_referer() ? wp_get_referer() : '';
		$fbc = $cookie( '_fbc' );
		if ( '' === $fbc && $url ) {
			$q = array();
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			if ( ! empty( $q['fbclid'] ) ) {
				$fbc = 'fb.1.' . ( time() * 1000 ) . '.' . sanitize_text_field( $q['fbclid'] );
			}
		}
		$order->update_meta_data(
			'_dzcod_track',
			array(
				'fbp' => mb_substr( $cookie( '_fbp' ), 0, 200 ),
				'fbc' => mb_substr( $fbc, 0, 300 ),
				'ttp' => mb_substr( $cookie( '_ttp' ), 0, 200 ),
				'url' => esc_url_raw( mb_substr( $url, 0, 500 ) ),
				'ip'  => DZCOD_Antifraud::client_ip(),
				'ua'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '',
			)
		);
	}

	/**
	 * New order from the form.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function on_created( $order ) {
		if ( ! $order->get_meta( '_dzcod_recovered' ) ) {
			self::capture_browser( $order );
			$order->save();
		}
		if ( self::enabled() && 'order' === DZCOD_Settings::get( 'purchase_on' ) ) {
			self::queue( $order->get_id() );
		}
	}

	/**
	 * Order confirmed by phone.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function on_confirmed( $order ) {
		if ( self::enabled() && 'confirmed' === DZCOD_Settings::get( 'purchase_on' ) ) {
			self::queue( $order->get_id() );
		}
	}

	/**
	 * Send in the background.
	 *
	 * @param int $order_id Order id.
	 */
	public static function queue( $order_id ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( 'order_id' => (int) $order_id ), 'dzcod' );
		} else {
			self::send( $order_id );
		}
	}

	/**
	 * SHA-256 of a normalized value (empty stays empty).
	 *
	 * @param string $v Value.
	 * @return string
	 */
	private static function hash( $v ) {
		$v = strtolower( trim( (string) $v ) );
		return '' === $v ? '' : hash( 'sha256', $v );
	}

	/**
	 * Common event data from an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function event_data( $order ) {
		$phone    = DZCOD_Antifraud::normalize_phone( $order->get_billing_phone() );
		$intl     = '' !== $phone ? '213' . substr( $phone, 1 ) : '';
		$track    = (array) $order->get_meta( '_dzcod_track' );
		$contents = array();
		foreach ( $order->get_items() as $item ) {
			$contents[] = array(
				'id'       => (string) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() ),
				'quantity' => (int) $item->get_quantity(),
				'name'     => $item->get_name(),
			);
		}
		return array(
			'event_id' => 'dzcod-' . $order->get_id(),
			'time'     => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time(),
			'phone'    => $intl,
			'name'     => $order->get_billing_first_name(),
			'city'     => preg_replace( '/[^a-z]/', '', strtolower( remove_accents( $order->get_billing_city() ) ) ),
			'value'    => (float) $order->get_total(),
			'currency' => $order->get_currency(),
			'contents' => $contents,
			'url'      => isset( $track['url'] ) ? (string) $track['url'] : home_url( '/' ),
			'ip'       => isset( $track['ip'] ) ? (string) $track['ip'] : '',
			'ua'       => isset( $track['ua'] ) ? (string) $track['ua'] : '',
			'fbp'      => isset( $track['fbp'] ) ? (string) $track['fbp'] : '',
			'fbc'      => isset( $track['fbc'] ) ? (string) $track['fbc'] : '',
			'ttp'      => isset( $track['ttp'] ) ? (string) $track['ttp'] : '',
			'order_id' => (string) $order->get_order_number(),
		);
	}

	/**
	 * Meta Conversions API payload.
	 *
	 * @param array $e event_data().
	 * @return array
	 */
	public static function meta_payload( $e ) {
		$user = array_filter(
			array(
				'ph'                => $e['phone'] ? array( self::hash( $e['phone'] ) ) : null,
				'external_id'       => $e['phone'] ? array( self::hash( $e['phone'] ) ) : null,
				'fn'                => '' !== $e['name'] ? array( self::hash( $e['name'] ) ) : null,
				'ct'                => '' !== $e['city'] ? array( self::hash( $e['city'] ) ) : null,
				'country'           => array( self::hash( 'dz' ) ),
				'client_ip_address' => $e['ip'] ? $e['ip'] : null,
				'client_user_agent' => $e['ua'] ? $e['ua'] : null,
				'fbp'               => $e['fbp'] ? $e['fbp'] : null,
				'fbc'               => $e['fbc'] ? $e['fbc'] : null,
			)
		);
		return array(
			'event_name'       => 'Purchase',
			'event_time'       => (int) $e['time'],
			'event_id'         => $e['event_id'],
			'action_source'    => 'website',
			'event_source_url' => $e['url'],
			'user_data'        => $user,
			'custom_data'      => array(
				'currency'     => $e['currency'],
				'value'        => $e['value'],
				'content_type' => 'product',
				'content_ids'  => wp_list_pluck( $e['contents'], 'id' ),
				'contents'     => array_map(
					function ( $c ) {
						return array(
							'id'       => $c['id'],
							'quantity' => $c['quantity'],
						);
					},
					$e['contents']
				),
				'order_id'     => $e['order_id'],
			),
		);
	}

	/**
	 * TikTok Events API payload.
	 *
	 * @param array $e event_data().
	 * @return array
	 */
	public static function tiktok_payload( $e ) {
		return array(
			'event'      => 'CompletePayment',
			'event_time' => (int) $e['time'],
			'event_id'   => $e['event_id'],
			'user'       => array_filter(
				array(
					'phone'       => $e['phone'] ? self::hash( '+' . $e['phone'] ) : null,
					'external_id' => $e['phone'] ? self::hash( $e['phone'] ) : null,
					'ip'          => $e['ip'] ? $e['ip'] : null,
					'user_agent'  => $e['ua'] ? $e['ua'] : null,
					'ttp'         => $e['ttp'] ? $e['ttp'] : null,
				)
			),
			'properties' => array(
				'currency'     => $e['currency'],
				'value'        => $e['value'],
				'content_type' => 'product',
				'contents'     => array_map(
					function ( $c ) {
						return array(
							'content_id'   => $c['id'],
							'content_name' => $c['name'],
							'quantity'     => $c['quantity'],
						);
					},
					$e['contents']
				),
				'order_id'     => $e['order_id'],
			),
			'page'       => array( 'url' => $e['url'] ),
		);
	}

	/**
	 * Send the Purchase event for an order to every configured destination (once).
	 *
	 * @param int  $order_id Order id.
	 * @param bool $test     Test mode: only destinations that have a test code, nothing recorded.
	 * @return array Results per destination.
	 */
	public static function send( $order_id, $test = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ( ! $test && $order->get_meta( '_dzcod_capi_sent' ) ) ) {
			return array();
		}
		$s   = DZCOD_Settings::get();
		$e   = self::event_data( $order );
		$out = array();

		if ( '' !== $s['meta_pixel_id'] && '' !== $s['meta_token'] && ( ! $test || '' !== $s['meta_test_code'] ) ) {
			$body = array(
				'data'         => wp_json_encode( array( self::meta_payload( $e ) ) ),
				'access_token' => $s['meta_token'],
			);
			if ( '' !== $s['meta_test_code'] ) {
				$body['test_event_code'] = $s['meta_test_code'];
			}
			$version     = (string) apply_filters( 'dzcod_meta_api_version', 'v26.0' );
			$out['meta'] = self::post( 'https://graph.facebook.com/' . rawurlencode( $version ) . '/' . rawurlencode( $s['meta_pixel_id'] ) . '/events', $body, array() );
		}
		if ( '' !== $s['tiktok_pixel'] && '' !== $s['tiktok_token'] && ( ! $test || '' !== $s['tiktok_test_code'] ) ) {
			$body = array(
				'event_source'    => 'web',
				'event_source_id' => $s['tiktok_pixel'],
				'data'            => array( self::tiktok_payload( $e ) ),
			);
			if ( '' !== $s['tiktok_test_code'] ) {
				$body['test_event_code'] = $s['tiktok_test_code'];
			}
			$out['tiktok'] = self::post(
				'https://business-api.tiktok.com/open_api/v1.3/event/track/',
				wp_json_encode( $body ),
				array(
					'Access-Token' => $s['tiktok_token'],
					'Content-Type' => 'application/json',
				)
			);
		}

		if ( $test ) {
			return $out;
		}
		$errors = array();
		foreach ( $out as $dest => $r ) {
			if ( ! $r['ok'] ) {
				$errors[] = ( 'meta' === $dest ? 'Meta' : 'TikTok' ) . ': ' . $r['error'];
			}
		}
		if ( $out && ! $errors ) {
			$order->update_meta_data( '_dzcod_capi_sent', time() );
		}
		if ( $errors ) {
			/* translators: %s: errors */
			$order->add_order_note( sprintf( __( 'Server-side conversion not accepted: %s', 'dz-cod-order-form' ), implode( ' | ', $errors ) ) );
		}
		$order->save();
		return $out;
	}

	/**
	 * POST helper.
	 *
	 * @param string       $url     URL.
	 * @param array|string $body    Body.
	 * @param array        $headers Headers.
	 * @return array{ok:bool,error:string}
	 */
	private static function post( $url, $body, $headers ) {
		$res = wp_safe_remote_post(
			$url,
			array(
				'timeout'             => 15,
				'headers'             => $headers,
				'body'                => $body,
				'limit_response_size' => MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'error' => $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		// TikTok answers HTTP 200 with a non-zero "code" on errors.
		if ( 200 !== $code || ( is_array( $json ) && isset( $json['code'] ) && 0 !== (int) $json['code'] ) || ( is_array( $json ) && isset( $json['error'] ) ) ) {
			$msg = '';
			if ( is_array( $json ) ) {
				$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( isset( $json['message'] ) ? $json['message'] : '' );
			}
			return array( 'ok' => false, 'error' => 'HTTP ' . $code . ( $msg ? ' — ' . mb_substr( (string) $msg, 0, 200 ) : '' ) );
		}
		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * "Send a test event" button: uses the most recent form order with the test codes.
	 */
	public static function admin_test() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dz-cod-order-form' ) );
		}
		check_admin_referer( 'dzcod_pixels_test' );
		$ids = wc_get_orders(
			array(
				'limit'       => 1,
				'return'      => 'ids',
				'created_via' => 'dzcod',
			)
		);
		$res = $ids ? self::send( $ids[0], true ) : null;
		set_transient( 'dzcod_pixels_test_' . get_current_user_id(), null === $res ? 'noorder' : $res, MINUTE_IN_SECONDS );
		$msg = 1;
		wp_safe_redirect( admin_url( 'admin.php?page=dzcod&tab=tracking&tested=' . rawurlencode( $msg ) ) );
		exit;
	}
}

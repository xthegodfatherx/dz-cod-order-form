<?php
/**
 * Phone validation, blocklist, rate limiting and duplicate detection.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fake / duplicate order protection.
 */
class DZCOD_Antifraud {

	/**
	 * Normalize an Algerian phone number to the local 0XXXXXXXXX format.
	 * Accepts +213, 00213, spaces, dashes and dots.
	 *
	 * @param string $phone Raw phone.
	 * @return string Normalized digits, or '' when empty.
	 */
	public static function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 0 === strpos( $digits, '00213' ) ) {
			$digits = '0' . substr( $digits, 5 );
		} elseif ( 0 === strpos( $digits, '213' ) && strlen( $digits ) >= 11 ) {
			$digits = '0' . substr( $digits, 3 );
		} elseif ( 9 === strlen( $digits ) && preg_match( '/^[567]/', $digits ) ) {
			$digits = '0' . $digits;
		}
		return $digits;
	}

	/**
	 * Valid Algerian mobile (05/06/07 + 8 digits) or landline (02x/03x/04x + 7 digits).
	 *
	 * @param string $phone Normalized phone.
	 * @return bool
	 */
	public static function is_valid_phone( $phone ) {
		$valid = (bool) preg_match( '/^0(?:[567]\d{8}|[234]\d{7})$/', $phone );
		return (bool) apply_filters( 'dzcod_is_valid_phone', $valid, $phone );
	}

	/**
	 * Client IP. Uses REMOTE_ADDR unless the admin says the site is behind
	 * Cloudflare or a reverse proxy (those headers are spoofable otherwise).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$source = (string) DZCOD_Settings::get( 'ip_source' );
		if ( 'cloudflare' === $source && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( 'proxy' === $source && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$list = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip   = trim( $list[0] );
		}
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		return (string) apply_filters( 'dzcod_client_ip', $ip );
	}

	/**
	 * Run all checks before creating an order.
	 *
	 * @param string $phone Normalized phone.
	 * @return array{ok:bool,error:string,duplicate_of:int}
	 */
	public static function check( $phone ) {
		$s      = DZCOD_Settings::get();
		$result = array(
			'ok'           => true,
			'error'        => '',
			'duplicate_of' => 0,
		);

		if ( ! self::is_valid_phone( $phone ) ) {
			$result['ok']    = false;
			$result['error'] = __( 'Please enter a valid Algerian phone number (e.g. 0555 12 34 56).', 'dz-cod-order-form' );
			return $result;
		}

		$blocked = array_filter( array_map( 'trim', explode( "\n", (string) $s['blocked_phones'] ) ) );
		if ( in_array( $phone, $blocked, true ) ) {
			$result['ok']    = false;
			$result['error'] = __( 'We could not accept this order. Please contact us directly.', 'dz-cod-order-form' );
			return $result;
		}

		$limit = (int) $s['rate_limit'];
		if ( $limit > 0 && '' !== self::client_ip() ) {
			$key   = 'dzcod_rl_' . md5( self::client_ip() );
			$count = (int) get_transient( $key );
			if ( $count >= $limit ) {
				$result['ok']    = false;
				$result['error'] = __( 'Too many orders from your connection. Please try again later.', 'dz-cod-order-form' );
				return $result;
			}
		}

		$hours = (int) $s['dup_hours'];
		if ( $hours > 0 ) {
			$statuses = array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed', 'wc-refunded' ) );
			$existing = wc_get_orders(
				array(
					'billing_phone' => $phone,
					'date_created'  => '>' . ( time() - $hours * HOUR_IN_SECONDS ),
					'status'        => $statuses,
					'limit'         => 1,
					'return'        => 'ids',
				)
			);
			if ( ! empty( $existing ) ) {
				$result['duplicate_of'] = (int) $existing[0];
				if ( 'block' === $s['dup_action'] ) {
					$result['ok']    = false;
					$result['error'] = __( 'You already placed an order recently. We will call you to confirm it — no need to order again.', 'dz-cod-order-form' );
				}
			}
		}

		return apply_filters( 'dzcod_antifraud_result', $result, $phone );
	}

	/**
	 * Count a successful order against the IP rate limit.
	 */
	public static function record_order() {
		$limit = (int) DZCOD_Settings::get( 'rate_limit' );
		if ( $limit <= 0 || '' === self::client_ip() ) {
			return;
		}
		$key = 'dzcod_rl_' . md5( self::client_ip() );
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}
}

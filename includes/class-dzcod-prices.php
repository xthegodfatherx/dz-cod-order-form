<?php
/**
 * Import delivery prices from the delivery company's API or from a Google Sheet / CSV.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delivery price import.
 */
class DZCOD_Prices {

	const CRON = 'dzcod_sync_sheet_prices';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_ajax_dzcod_import_prices', array( __CLASS__, 'ajax_import' ) );
		add_action( 'admin_post_dzcod_export_prices', array( __CLASS__, 'export_csv' ) );
		add_action( self::CRON, array( __CLASS__, 'scheduled_sheet_sync' ) );
		add_action( 'update_option_' . DZCOD_Settings::OPTION, array( __CLASS__, 'schedule' ) );
		add_action( 'add_option_' . DZCOD_Settings::OPTION, array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Keep the daily Google Sheet sync scheduled only while it is switched on.
	 */
	public static function schedule() {
		DZCOD_Settings::flush();
		$on = ! empty( DZCOD_Settings::get( 'prices_sheet_auto' ) ) && '' !== (string) DZCOD_Settings::get( 'prices_sheet_url' );
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$next = as_next_scheduled_action( self::CRON, array(), 'dzcod' );
		if ( $on && ! $next ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CRON, array(), 'dzcod' );
		} elseif ( ! $on && $next ) {
			as_unschedule_all_actions( self::CRON, array(), 'dzcod' );
		}
	}

	/**
	 * Daily sync callback.
	 */
	public static function scheduled_sheet_sync() {
		$url = (string) DZCOD_Settings::get( 'prices_sheet_url' );
		if ( '' !== $url ) {
			self::import_sheet( $url );
		}
	}

	/**
	 * Pick the first numeric value among several possible keys (APIs name fields differently).
	 *
	 * @param array $row  Row.
	 * @param array $keys Candidate keys.
	 * @return float|null
	 */
	private static function pick( $row, $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $row[ $k ] ) && '' !== $row[ $k ] && is_numeric( $row[ $k ] ) ) {
				return (float) $row[ $k ];
			}
		}
		return null;
	}

	/**
	 * Wilaya code ("1", "01", "16 - Alger") → two-digit code, or ''.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	private static function code( $raw ) {
		if ( ! preg_match( '/\d{1,2}/', (string) $raw, $m ) ) {
			return '';
		}
		$code = str_pad( $m[0], 2, '0', STR_PAD_LEFT );
		return isset( DZCOD_Locations::all()[ $code ] ) ? $code : '';
	}

	/**
	 * Write prices into the settings.
	 *
	 * @param array $rows   code => [ home, desk, enabled|null ].
	 * @param bool  $legacy Codes are the 58 courier codes: also apply them to the wilayas created from them.
	 * @return int Number of wilayas updated.
	 */
	public static function apply( $rows, $legacy ) {
		$opt    = get_option( DZCOD_Settings::OPTION, array() );
		$opt    = is_array( $opt ) ? $opt : array();
		$prices = (array) DZCOD_Settings::get( 'prices' );
		$count  = 0;
		foreach ( DZCOD_Locations::all() as $code => $w ) {
			$src = $legacy ? $w['l'] : $code;
			if ( ! isset( $rows[ $src ] ) && ! $legacy && isset( $rows[ $w['l'] ] ) ) {
				$src = $w['l']; // Sheet without the new wilayas: use the parent wilaya's price.
			}
			if ( ! isset( $rows[ $src ] ) ) {
				continue;
			}
			$r      = $rows[ $src ];
			$cur    = isset( $prices[ $code ] ) ? (array) $prices[ $code ] : array(
				'home'    => 0,
				'desk'    => 0,
				'enabled' => 1,
			);
			$home   = null !== $r[0] ? max( 0, (float) $r[0] ) : (float) $cur['home'];
			$desk   = null !== $r[1] ? max( 0, (float) $r[1] ) : (float) $cur['desk'];
			$enable = isset( $r[2] ) && null !== $r[2] ? (int) (bool) $r[2] : (int) ( $home > 0 || $desk > 0 || ! empty( $cur['enabled'] ) );

			$prices[ $code ] = array(
				'home'    => $home,
				'desk'    => $desk,
				'enabled' => $enable,
			);
			++$count;
		}
		$opt['prices'] = $prices;
		// No "_tab" key: the sanitizer keeps this already-clean array as it is.
		update_option( DZCOD_Settings::OPTION, wp_parse_args( $opt, DZCOD_Settings::get() ) );
		DZCOD_Settings::flush();
		return $count;
	}

	/**
	 * Turn any Google Sheets link into a CSV download link.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function csv_url( $url ) {
		$url = trim( $url );
		if ( preg_match( '#docs\.google\.com/spreadsheets/d/e/([^/]+)/pub(html)?#', $url, $m ) ) {
			$gid = preg_match( '/[?&#]gid=(\d+)/', $url, $g ) ? '&gid=' . $g[1] : '';
			return 'https://docs.google.com/spreadsheets/d/e/' . $m[1] . '/pub?output=csv' . $gid;
		}
		if ( preg_match( '#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m ) && false === strpos( $url, 'format=csv' ) ) {
			$gid = preg_match( '/[?&#]gid=(\d+)/', $url, $g ) ? $g[1] : '0';
			return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
		}
		return $url;
	}

	/**
	 * Parse CSV text: columns wilaya_code, home, desk, enabled (names are flexible).
	 *
	 * @param string $csv CSV text.
	 * @return array code => [ home, desk, enabled|null ]
	 */
	public static function parse_csv( $csv ) {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $csv );
		$lines = preg_split( '/\r\n|\n|\r/', trim( $csv ) );
		if ( count( $lines ) < 2 ) {
			return array();
		}
		$sep    = substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ? ';' : ',';
		$header = array_map(
			function ( $h ) {
				return strtolower( trim( remove_accents( $h ) ) );
			},
			str_getcsv( $lines[0], $sep, '"', '\\' )
		);
		$find   = function ( $names ) use ( $header ) {
			foreach ( $header as $i => $h ) {
				if ( in_array( $h, $names, true ) ) {
					return $i;
				}
			}
			return null;
		};
		$c_code = $find( array( 'wilaya_code', 'code', 'wilaya', 'code_wilaya', 'id' ) );
		$c_home = $find( array( 'home', 'domicile', 'home_price', 'a domicile', 'prix_domicile' ) );
		$c_desk = $find( array( 'desk', 'stopdesk', 'stop_desk', 'stop desk', 'bureau', 'desk_price', 'prix_stopdesk' ) );
		$c_on   = $find( array( 'enabled', 'active', 'actif', 'livraison' ) );
		if ( null === $c_code || ( null === $c_home && null === $c_desk ) ) {
			return array();
		}
		$rows = array();
		foreach ( array_slice( $lines, 1 ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cols = str_getcsv( $line, $sep, '"', '\\' );
			$code = self::code( isset( $cols[ $c_code ] ) ? $cols[ $c_code ] : '' );
			if ( '' === $code ) {
				continue;
			}
			$num = function ( $i ) use ( $cols ) {
				if ( null === $i || ! isset( $cols[ $i ] ) ) {
					return null;
				}
				$v = str_replace( array( ' ', "\xC2\xA0", 'DA', 'DZD', 'da' ), '', $cols[ $i ] );
				$v = str_replace( ',', '.', $v );
				return is_numeric( $v ) ? (float) $v : null;
			};
			$on  = null;
			if ( null !== $c_on && isset( $cols[ $c_on ] ) && '' !== trim( $cols[ $c_on ] ) ) {
				$on = in_array( strtolower( trim( $cols[ $c_on ] ) ), array( '1', 'yes', 'oui', 'true', 'x', 'نعم' ), true ) ? 1 : 0;
			}
			$rows[ $code ] = array( $num( $c_home ), $num( $c_desk ), $on );
		}
		return $rows;
	}

	/**
	 * Import from a Google Sheet / CSV URL.
	 *
	 * @param string $url URL.
	 * @return array{ok:bool,message:string}
	 */
	public static function import_sheet( $url ) {
		$csv_url = self::csv_url( $url );
		if ( ! wp_http_validate_url( $csv_url ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This link is not valid.', 'dz-cod-order-form' ),
			);
		}
		$res = wp_safe_remote_get(
			$csv_url,
			array(
				'timeout'             => 20,
				'redirection'         => 5,
				'limit_response_size' => 2 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Could not download the sheet. In Google Sheets use Share → “Anyone with the link can view”, or File → Share → Publish to web → CSV.', 'dz-cod-order-form' ),
			);
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( false !== stripos( substr( $body, 0, 200 ), '<html' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Google returned a web page instead of the data: the sheet is not shared publicly.', 'dz-cod-order-form' ),
			);
		}
		$rows = self::parse_csv( $body );
		if ( ! $rows ) {
			return array(
				'ok'      => false,
				'message' => __( 'No prices found. The first row must have the columns wilaya_code, home, desk (see the template).', 'dz-cod-order-form' ),
			);
		}
		$n = self::apply( $rows, false );
		return array(
			'ok'      => true,
			/* translators: %d: number of wilayas */
			'message' => sprintf( __( 'Prices updated for %d wilayas.', 'dz-cod-order-form' ), $n ),
		);
	}

	/**
	 * Import from the configured delivery company. Yalidine needs one request per wilaya,
	 * so it runs in chunks driven by the admin page.
	 *
	 * @param int $offset Position in the 58 legacy wilaya codes.
	 * @return array{ok:bool,message:string,done:bool,next:int}
	 */
	public static function import_courier( $offset ) {
		$s    = DZCOD_Couriers::settings();
		$prov = DZCOD_Courier_API::providers();
		$key  = $s['provider'];
		$fail = function ( $msg ) {
			return array(
				'ok'      => false,
				'message' => $msg,
				'done'    => true,
				'next'    => 0,
			);
		};
		if ( ! isset( $prov[ $key ] ) ) {
			return $fail( __( 'Connect a delivery company first (Delivery company tab).', 'dz-cod-order-form' ) );
		}
		$platform = $prov[ $key ][1];
		$base     = DZCOD_Courier_API::base_url( $key, $s );
		$title    = $prov[ $key ][0];

		if ( 'yalidine' === $platform ) {
			return self::import_yalidine( $base, $s, $offset, $title );
		}

		$fetch = DZCOD_Courier_API::fees( $key, $s );
		if ( ! $fetch['ok'] ) {
			return $fail( $fetch['error'] );
		}
		$rows = array();
		foreach ( (array) $fetch['data'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$code = self::code( isset( $row['wilaya_id'] ) ? $row['wilaya_id'] : ( isset( $row['IDWilaya'] ) ? $row['IDWilaya'] : ( isset( $row['code_wilaya'] ) ? $row['code_wilaya'] : ( isset( $row['id'] ) ? $row['id'] : '' ) ) ) );
			if ( '' === $code ) {
				continue;
			}
			$home = self::pick( $row, array( 'tarif', 'tarif_domicile', 'Domicile', 'domicile', 'home', 'prix_domicile', 'Tarif' ) );
			$desk = self::pick( $row, array( 'tarif_stopdesk', 'tarif_desk', 'Stopdesk', 'StopDesk', 'stopdesk', 'stop_desk', 'desk', 'prix_stopdesk', 'Bureau' ) );
			if ( null === $home && null === $desk ) {
				continue;
			}
			$rows[ $code ] = array( $home, $desk, null );
		}
		if ( ! $rows ) {
			$sample = is_array( $fetch['data'] ) && $fetch['data'] ? wp_json_encode( array_slice( (array) reset( $fetch['data'] ), 0, 8 ) ) : '';
			/* translators: 1: company, 2: sample data */
			return $fail( sprintf( __( '%1$s answered, but the price format was not recognised. Please report it on GitHub with this sample: %2$s', 'dz-cod-order-form' ), $title, $sample ) );
		}
		$n = self::apply( $rows, true );
		return array(
			'ok'      => true,
			/* translators: 1: number, 2: company */
			'message' => sprintf( __( 'Prices updated for %1$d wilayas from %2$s.', 'dz-cod-order-form' ), $n, $title ),
			'done'    => true,
			'next'    => 0,
		);
	}

	/**
	 * Yalidine: GET /v1/fees per destination; the wilaya price is the most common commune price.
	 *
	 * @param string $base   Base URL.
	 * @param array  $s      Courier settings.
	 * @param int    $offset Offset.
	 * @param string $title  Company name.
	 * @return array
	 */
	private static function import_yalidine( $base, $s, $offset, $title ) {
		$legacy = array();
		$from   = '';
		foreach ( DZCOD_Locations::all() as $code => $w ) {
			if ( (string) $w['l'] === (string) $code ) {
				$legacy[] = $code;
				if ( $w['n'] === $s['yalidine_from_wilaya'] ) {
					$from = $code;
				}
			}
		}
		if ( '' === $from ) {
			return array(
				'ok'      => false,
				'message' => __( 'Choose the “Ship from wilaya” in the Delivery company tab first.', 'dz-cod-order-form' ),
				'done'    => true,
				'next'    => 0,
			);
		}
		$chunk = array_slice( $legacy, $offset, 6 );
		$rows  = array();
		$errs  = array();
		foreach ( $chunk as $i => $to ) {
			if ( $i > 0 ) {
				usleep( 400000 ); // Stay under Yalidine's per-second limit.
			}
			$r = DZCOD_Courier_API::yalidine_fees( $base, $s, (int) $from, (int) $to );
			if ( ! $r['ok'] ) {
				$errs[] = $to . ': ' . $r['error'];
				continue;
			}
			$home = array();
			$desk = array();
			foreach ( (array) ( isset( $r['data']['per_commune'] ) ? $r['data']['per_commune'] : array() ) as $c ) {
				if ( isset( $c['express_home'] ) && is_numeric( $c['express_home'] ) ) {
					$home[] = (string) (float) $c['express_home'];
				}
				if ( isset( $c['express_desk'] ) && is_numeric( $c['express_desk'] ) ) {
					$desk[] = (string) (float) $c['express_desk'];
				}
			}
			$mode        = function ( $values ) {
				if ( ! $values ) {
					return null;
				}
				$counts = array_count_values( $values );
				arsort( $counts );
				return (float) key( $counts );
			};
			$rows[ $to ] = array( $mode( $home ), $mode( $desk ), ( $home || $desk ) ? 1 : 0 );
		}
		if ( $rows ) {
			self::apply( $rows, true );
		}
		$next = $offset + count( $chunk );
		$done = $next >= count( $legacy );
		return array(
			'ok'      => empty( $errs ) || ! empty( $rows ),
			/* translators: 1: done, 2: total, 3: company */
			'message' => sprintf( __( '%1$d / %2$d wilayas imported from %3$s', 'dz-cod-order-form' ), min( $next, count( $legacy ) ), count( $legacy ), $title ) . ( $errs ? ' — ' . implode( ' | ', $errs ) : '' ),
			'done'    => $done || ( empty( $rows ) && ! empty( $errs ) ),
			'next'    => $next,
		);
	}

	/**
	 * AJAX import endpoint used by the Delivery prices tab.
	 */
	public static function ajax_import() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dz-cod-order-form' ) ) );
		}
		check_ajax_referer( 'dzcod_import_prices', 'nonce' );
		$source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
		if ( 'sheet' === $source ) {
			$url         = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'https' ) ) : '';
			$res         = self::import_sheet( $url );
			$res['done'] = true;
		} else {
			$res = self::import_courier( isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * Download current prices as CSV (same format as the template).
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dz-cod-order-form' ) );
		}
		check_admin_referer( 'dzcod_export_prices' );
		$prices = DZCOD_Settings::get( 'prices' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=delivery-prices.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array( 'wilaya_code', 'wilaya_name', 'wilaya_name_ar', 'home', 'desk', 'enabled' ), ',', '"', '\\' );
		foreach ( DZCOD_Locations::all() as $code => $w ) {
			$p = isset( $prices[ $code ] ) ? $prices[ $code ] : array(
				'home'    => 0,
				'desk'    => 0,
				'enabled' => 0,
			);
			fputcsv( $out, array( $code, $w['n'], $w['ar'], $p['home'], $p['desk'], empty( $p['enabled'] ) ? 0 : 1 ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}

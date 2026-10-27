<?php
/**
 * Algerian delivery company APIs: Yalidine / Yalitec, ZR Express (Procolis), Ecotrack couriers.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless API calls. Every call returns
 * [ 'ok' => bool, 'tracking' => string, 'label' => string, 'error' => string ].
 */
class DZCOD_Courier_API {

	/**
	 * Supported companies: key => [ title, platform, base URL ].
	 *
	 * @return array
	 */
	public static function providers() {
		$list = array(
			'yalidine'      => array( 'Yalidine', 'yalidine', 'https://api.yalidine.app' ),
			'yalitec'       => array( 'Yalitec (Guepex)', 'yalidine', 'https://api.yalitec.me' ),
			'zrexpress'     => array( 'ZR Express', 'procolis', 'https://procolis.com/api_v1' ),
			'dhd'           => array( 'DHD', 'ecotrack', 'https://dhd.ecotrack.dz' ),
			'conexlog'      => array( 'Conexlog / UPS', 'ecotrack', 'https://conexlog.ecotrack.dz' ),
			'msmgo'         => array( 'MSM Go', 'ecotrack', 'https://msmgo.ecotrack.dz' ),
			'rex'           => array( 'Rex Livraison', 'ecotrack', 'https://rex.ecotrack.dz' ),
			'rblivraison'   => array( 'RB Livraison', 'ecotrack', 'https://rblivraison.ecotrack.dz' ),
			'speeddelivery' => array( 'Speed Delivery', 'ecotrack', 'https://speeddelivery.ecotrack.dz' ),
			'areex'         => array( 'Areex', 'ecotrack', 'https://areex.ecotrack.dz' ),
			'prest'         => array( 'Prest', 'ecotrack', 'https://prest.ecotrack.dz' ),
			'rocket'        => array( 'Rocket Delivery', 'ecotrack', 'https://rocket.ecotrack.dz' ),
			'worldexpress'  => array( 'World Express', 'ecotrack', 'https://worldexpress.ecotrack.dz' ),
			'baconsult'     => array( 'BA Consult', 'ecotrack', 'https://baconsult.ecotrack.dz' ),
			'packers'       => array( 'Packers', 'ecotrack', 'https://packers.ecotrack.dz' ),
			'48hr'          => array( '48hr Livraison', 'ecotrack', 'https://48hr.ecotrack.dz' ),
			'mono'          => array( 'Mono Hub', 'ecotrack', 'https://mono.ecotrack.dz' ),
			'anderson'      => array( 'Anderson Delivery', 'ecotrack', 'https://anderson.ecotrack.dz' ),
			'golivri'       => array( 'Golivri', 'ecotrack', 'https://golivri.ecotrack.dz' ),
			'coyote'        => array( 'Coyote Express', 'ecotrack', 'https://coyote.ecotrack.dz' ),
			'salvadelivery' => array( 'Salva Delivery', 'ecotrack', 'https://salvadelivery.ecotrack.dz' ),
			'distazero'     => array( 'Distazero', 'ecotrack', 'https://distazero.ecotrack.dz' ),
			'fretdirect'    => array( 'Fret Direct', 'ecotrack', 'https://fretdirect.ecotrack.dz' ),
			'tsl'           => array( 'TSL Express', 'ecotrack', 'https://tsl.ecotrack.dz' ),
			'negmar'        => array( 'Negmar Express', 'ecotrack', 'https://negmar.ecotrack.dz' ),
			'ecorapide'     => array( 'EcoRapide Express', 'ecotrack', 'https://ecorapide-express.ecotrack.dz' ),
			'ecotrack'      => array( __( 'Other Ecotrack courier (custom address)', 'dz-cod-order-form' ), 'ecotrack', '' ),
		);
		return (array) apply_filters( 'dzcod_courier_providers', $list );
	}

	/**
	 * Result helper.
	 *
	 * @param bool   $ok       Success.
	 * @param string $error    Error message.
	 * @param string $tracking Tracking number.
	 * @param string $label    Label URL.
	 * @return array
	 */
	private static function result( $ok, $error = '', $tracking = '', $label = '' ) {
		return array(
			'ok'       => (bool) $ok,
			'error'    => (string) $error,
			'tracking' => (string) $tracking,
			'label'    => (string) $label,
		);
	}

	/**
	 * JSON HTTP request. Only public hosts are allowed (wp_safe_remote_request).
	 *
	 * @param string     $method  GET|POST.
	 * @param string     $url     URL.
	 * @param array      $headers Headers.
	 * @param array|null $body    JSON body.
	 * @return array{status:int,json:mixed,raw:string,error:string}
	 */
	private static function http( $method, $url, $headers, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array_merge(
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array(
				'status' => 0,
				'json'   => null,
				'raw'    => '',
				'error'  => $res->get_error_message(),
			);
		}
		$raw = (string) wp_remote_retrieve_body( $res );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $res ),
			'json'   => json_decode( $raw, true ),
			'raw'    => $raw,
			'error'  => '',
		);
	}

	/**
	 * Short readable error from a response.
	 *
	 * @param array $r http() result.
	 * @return string
	 */
	private static function http_error( $r ) {
		if ( '' !== $r['error'] ) {
			return $r['error'];
		}
		$msg = '';
		if ( is_array( $r['json'] ) ) {
			foreach ( array( 'message', 'error', 'detail' ) as $k ) {
				if ( ! empty( $r['json'][ $k ] ) && is_scalar( $r['json'][ $k ] ) ) {
					$msg = (string) $r['json'][ $k ];
					break;
				}
			}
			if ( ! empty( $r['json']['errors'] ) ) {
				$msg = trim( $msg . ' ' . wp_json_encode( $r['json']['errors'] ) );
			}
		}
		if ( '' === $msg ) {
			$msg = mb_substr( wp_strip_all_tags( $r['raw'] ), 0, 200 );
		}
		/* translators: 1: HTTP status, 2: message */
		return sprintf( __( 'HTTP %1$d: %2$s', 'dz-cod-order-form' ), $r['status'], $msg );
	}

	/**
	 * Base URL of a provider, honouring the custom Ecotrack address.
	 *
	 * @param string $key      Provider key.
	 * @param array  $settings Courier settings.
	 * @return string
	 */
	public static function base_url( $key, $settings ) {
		$p = self::providers();
		if ( ! isset( $p[ $key ] ) ) {
			return '';
		}
		$base = $p[ $key ][2];
		if ( 'ecotrack' === $key ) {
			$base = (string) $settings['ecotrack_url'];
		}
		return untrailingslashit( $base );
	}

	/**
	 * Check that the credentials work.
	 *
	 * @param string $key      Provider key.
	 * @param array  $settings Courier settings.
	 * @return array
	 */
	public static function test( $key, $settings ) {
		$p    = self::providers();
		$base = self::base_url( $key, $settings );
		if ( ! isset( $p[ $key ] ) || '' === $base ) {
			return self::result( false, __( 'Choose a delivery company and fill in its address.', 'dz-cod-order-form' ) );
		}
		switch ( $p[ $key ][1] ) {
			case 'yalidine':
				$r = self::http( 'GET', $base . '/v1/wilayas/?page_size=1', self::yalidine_headers( $settings ) );
				break;
			case 'procolis':
				$r = self::http( 'GET', $base . '/token', self::procolis_headers( $settings ) );
				if ( 200 === $r['status'] && is_array( $r['json'] ) && isset( $r['json']['Statut'] ) && false === stripos( (string) $r['json']['Statut'], 'activ' ) ) {
					return self::result( false, (string) $r['json']['Statut'] );
				}
				break;
			default:
				$r = self::http( 'GET', $base . '/api/v1/get/wilayas', self::ecotrack_headers( $settings ) );
		}
		return 200 === $r['status'] ? self::result( true ) : self::result( false, self::http_error( $r ) );
	}

	/**
	 * Create a parcel.
	 *
	 * @param string $key      Provider key.
	 * @param array  $settings Courier settings.
	 * @param array  $parcel   Normalized parcel, see DZCOD_Couriers::parcel_from_order().
	 * @return array
	 */
	public static function create( $key, $settings, $parcel ) {
		$p    = self::providers();
		$base = self::base_url( $key, $settings );
		if ( ! isset( $p[ $key ] ) || '' === $base ) {
			return self::result( false, __( 'No delivery company configured.', 'dz-cod-order-form' ) );
		}
		switch ( $p[ $key ][1] ) {
			case 'yalidine':
				return self::create_yalidine( $base, $settings, $parcel );
			case 'procolis':
				return self::create_procolis( $base, $settings, $parcel );
			default:
				return self::create_ecotrack( $base, $settings, $parcel );
		}
	}

	/* ---------------------------------------------------------------- Yalidine */

	/**
	 * Yalidine auth headers.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	private static function yalidine_headers( $s ) {
		return array(
			'X-API-ID'    => (string) $s['yalidine_id'],
			'X-API-TOKEN' => (string) $s['yalidine_token'],
		);
	}

	/**
	 * Pick the Yalidine stop desk for a commune (or the first one in the wilaya).
	 *
	 * @param string $base   Base URL.
	 * @param array  $s      Settings.
	 * @param array  $parcel Parcel.
	 * @return array{id:string,error:string}
	 */
	private static function yalidine_stopdesk( $base, $s, $parcel ) {
		$r = self::http( 'GET', $base . '/v1/centers/?wilaya_id=' . (int) $parcel['wilaya_code'] . '&page_size=100', self::yalidine_headers( $s ) );
		if ( 200 !== $r['status'] || ! is_array( $r['json'] ) ) {
			return array(
				'id'    => '',
				'error' => self::http_error( $r ),
			);
		}
		$centers = isset( $r['json']['data'] ) ? (array) $r['json']['data'] : (array) $r['json'];
		$first   = '';
		foreach ( $centers as $c ) {
			if ( empty( $c['center_id'] ) ) {
				continue;
			}
			if ( '' === $first ) {
				$first = (string) $c['center_id'];
			}
			if ( isset( $c['commune_name'] ) && 0 === strcasecmp( remove_accents( (string) $c['commune_name'] ), remove_accents( $parcel['commune'] ) ) ) {
				return array(
					'id'    => (string) $c['center_id'],
					'error' => '',
				);
			}
		}
		return array(
			'id'    => $first,
			'error' => '' === $first ? __( 'No Yalidine stop desk found in this wilaya.', 'dz-cod-order-form' ) : '',
		);
	}

	/**
	 * Create a Yalidine / Yalitec parcel.
	 *
	 * @param string $base   Base URL.
	 * @param array  $s      Settings.
	 * @param array  $parcel Parcel.
	 * @return array
	 */
	private static function create_yalidine( $base, $s, $parcel ) {
		if ( $parcel['amount'] > 150000 ) {
			return self::result( false, __( 'Yalidine refuses amounts above 150,000 DA.', 'dz-cod-order-form' ) );
		}
		$stopdesk = '';
		if ( $parcel['stopdesk'] ) {
			$desk = self::yalidine_stopdesk( $base, $s, $parcel );
			if ( '' === $desk['id'] ) {
				return self::result( false, $desk['error'] );
			}
			$stopdesk = $desk['id'];
		}
		$names = preg_split( '/\s+/', trim( $parcel['name'] ), 2 );
		$data  = array(
			'order_id'         => $parcel['reference'],
			'from_wilaya_name' => (string) $s['yalidine_from_wilaya'],
			'firstname'        => $names[0],
			'familyname'       => isset( $names[1] ) ? $names[1] : $names[0],
			'contact_phone'    => $parcel['phone'],
			'address'          => $parcel['address'],
			'to_commune_name'  => $parcel['commune'],
			'to_wilaya_name'   => $parcel['wilaya_name'],
			'product_list'     => mb_substr( $parcel['products'], 0, 150 ),
			'price'            => (int) round( $parcel['amount'] ),
			'do_insurance'     => false,
			'declared_value'   => (int) round( $parcel['amount'] ),
			'length'           => 0,
			'width'            => 0,
			'height'           => 0,
			'weight'           => 0,
			'freeshipping'     => false,
			'is_stopdesk'      => (bool) $parcel['stopdesk'],
			'has_exchange'     => false,
		);
		if ( $stopdesk ) {
			$data['stopdesk_id'] = $stopdesk;
		}
		$data = (array) apply_filters( 'dzcod_courier_payload', $data, 'yalidine', $parcel );

		$r = self::http( 'POST', $base . '/v1/parcels/', self::yalidine_headers( $s ), array( $data ) );
		if ( ! in_array( $r['status'], array( 200, 201 ), true ) || ! is_array( $r['json'] ) ) {
			return self::result( false, self::http_error( $r ) );
		}
		$row = isset( $r['json'][ $parcel['reference'] ] ) ? $r['json'][ $parcel['reference'] ] : reset( $r['json'] );
		$success = is_array( $row ) && isset( $row['success'] ) && filter_var( $row['success'], FILTER_VALIDATE_BOOLEAN );
		if ( ! $success ) {
			$msg = is_array( $row ) && ! empty( $row['message'] ) ? (string) $row['message'] : __( 'Parcel refused.', 'dz-cod-order-form' );
			return self::result( false, $msg );
		}
		return self::result( true, '', isset( $row['tracking'] ) ? (string) $row['tracking'] : '', isset( $row['label'] ) ? (string) $row['label'] : '' );
	}

	/* ---------------------------------------------------- ZR Express (Procolis) */

	/**
	 * Procolis auth headers.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	private static function procolis_headers( $s ) {
		return array(
			'token' => (string) $s['procolis_token'],
			'key'   => (string) $s['procolis_key'],
		);
	}

	/**
	 * Create a ZR Express parcel.
	 *
	 * @param string $base   Base URL.
	 * @param array  $s      Settings.
	 * @param array  $parcel Parcel.
	 * @return array
	 */
	private static function create_procolis( $base, $s, $parcel ) {
		$colis = array(
			'Tracking'      => '',
			'TypeLivraison' => $parcel['stopdesk'] ? '1' : '0',
			'TypeColis'     => '0',
			'Confrimee'     => empty( $s['procolis_confirmed'] ) ? '' : '1',
			'Client'        => $parcel['name'],
			'MobileA'       => $parcel['phone'],
			'MobileB'       => '',
			'Adresse'       => $parcel['address'],
			'IDWilaya'      => (string) (int) $parcel['wilaya_code'],
			'Commune'       => $parcel['commune'],
			'Total'         => (string) round( $parcel['amount'] ),
			'Note'          => $parcel['note'],
			'TProduit'      => mb_substr( $parcel['products'], 0, 250 ),
			'id_Externe'    => $parcel['reference'],
			'Source'        => '',
		);
		$colis = (array) apply_filters( 'dzcod_courier_payload', $colis, 'procolis', $parcel );

		$r = self::http( 'POST', $base . '/add_colis', self::procolis_headers( $s ), array( 'Colis' => array( $colis ) ) );
		if ( 200 !== $r['status'] || ! is_array( $r['json'] ) || empty( $r['json']['Colis'][0] ) ) {
			return self::result( false, self::http_error( $r ) );
		}
		$row = $r['json']['Colis'][0];
		$msg = isset( $row['MessageRetour'] ) ? (string) $row['MessageRetour'] : '';
		if ( 'Good' !== $msg ) {
			return self::result( false, '' !== $msg ? $msg : __( 'Parcel refused.', 'dz-cod-order-form' ) );
		}
		return self::result( true, '', isset( $row['Tracking'] ) ? (string) $row['Tracking'] : '' );
	}

	/* ------------------------------------------------------------- Ecotrack */

	/**
	 * Ecotrack auth headers.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	private static function ecotrack_headers( $s ) {
		return array( 'Authorization' => 'Bearer ' . (string) $s['ecotrack_token'] );
	}

	/**
	 * Create a parcel on an Ecotrack-based courier.
	 *
	 * @param string $base   Base URL.
	 * @param array  $s      Settings.
	 * @param array  $parcel Parcel.
	 * @return array
	 */
	private static function create_ecotrack( $base, $s, $parcel ) {
		$data = array(
			'reference'   => $parcel['reference'],
			'nom_client'  => mb_substr( $parcel['name'], 0, 255 ),
			'telephone'   => $parcel['phone'],
			'adresse'     => mb_substr( $parcel['address'], 0, 255 ),
			'commune'     => $parcel['commune'],
			'code_wilaya' => (int) $parcel['wilaya_code'],
			'montant'     => round( $parcel['amount'] ),
			'remarque'    => mb_substr( $parcel['note'], 0, 255 ),
			'produit'     => mb_substr( $parcel['products'], 0, 255 ),
			'type'        => 1,
			'stop_desk'   => $parcel['stopdesk'] ? 1 : 0,
		);
		$data = (array) apply_filters( 'dzcod_courier_payload', $data, 'ecotrack', $parcel );

		$r = self::http( 'POST', $base . '/api/v1/create/order', self::ecotrack_headers( $s ), $data );
		if ( ! in_array( $r['status'], array( 200, 201 ), true ) || ! is_array( $r['json'] ) || ( isset( $r['json']['success'] ) && false === $r['json']['success'] ) ) {
			return self::result( false, self::http_error( $r ) );
		}
		$tracking = isset( $r['json']['tracking'] ) ? (string) $r['json']['tracking'] : '';
		if ( '' === $tracking ) {
			return self::result( false, self::http_error( $r ) );
		}
		return self::result( true, '', $tracking );
	}
}

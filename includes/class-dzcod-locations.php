<?php
/**
 * Algerian wilayas and communes.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the bundled location dataset (69 wilayas, 1541 communes; source: geoalgeria, MIT).
 */
class DZCOD_Locations {

	/**
	 * Cached dataset.
	 *
	 * @var array|null
	 */
	private static $data = null;

	/**
	 * Full dataset: code => [ n => latin name, ar => arabic name, c => [ [latin, arabic], ... ] ].
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$data ) {
			$raw        = file_get_contents( DZCOD_DIR . 'data/algeria.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.
			self::$data = json_decode( (string) $raw, true );
			if ( ! is_array( self::$data ) ) {
				self::$data = array();
			}
		}
		return (array) apply_filters( 'dzcod_locations', self::$data );
	}

	/**
	 * Note for callers: PHP stores keys such as "16" as the integer 16, so always
	 * compare wilaya codes as strings ( (string) $a === (string) $b ) and never merge
	 * code-keyed arrays with array_merge() / wp_parse_args(), which renumber them.
	 */

	/**
	 * Display label of a wilaya, e.g. "16 - Alger".
	 *
	 * @param string $code Two-digit wilaya code.
	 * @param string $lang 'ar' or 'latin'.
	 * @return string
	 */
	public static function wilaya_label( $code, $lang = 'latin' ) {
		$all = self::all();
		if ( ! isset( $all[ $code ] ) ) {
			return '';
		}
		$name = ( 'ar' === $lang ) ? $all[ $code ]['ar'] : $all[ $code ]['n'];
		return $code . ' - ' . $name;
	}

	/**
	 * Whether a commune belongs to a wilaya. Accepts latin or arabic name.
	 *
	 * @param string $code    Wilaya code.
	 * @param string $commune Commune name.
	 * @return bool
	 */
	public static function commune_exists( $code, $commune ) {
		$all = self::all();
		if ( ! isset( $all[ $code ] ) ) {
			return false;
		}
		foreach ( $all[ $code ]['c'] as $c ) {
			if ( $c[0] === $commune || $c[1] === $commune ) {
				return true;
			}
		}
		return false;
	}
}

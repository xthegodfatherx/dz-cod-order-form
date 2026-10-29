<?php
/**
 * Atomic locks and counters.
 *
 * Transients are read-then-write, so two parallel requests can both pass a check.
 * These helpers rely on operations the storage performs atomically instead:
 * wp_cache_add() / wp_cache_incr() with a persistent object cache, otherwise a
 * single INSERT on the options table, whose option_name column is unique.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lock and counter helpers.
 */
class DZCOD_Lock {

	const PREFIX = 'dzcod_lk_';
	const GROUP  = 'dzcod_lock';

	/**
	 * Take a lock. Returns false when someone else holds it.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds before a forgotten lock expires.
	 * @return bool
	 */
	public static function acquire( $name, $ttl ) {
		$key = self::PREFIX . md5( $name );
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $key, 1, self::GROUP, $ttl );
		}
		global $wpdb;
		$expires = time() + (int) $ttl;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic insert is the point; options API is check-then-write.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, (string) $expires ) );
		if ( 1 === (int) $inserted ) {
			return true;
		}
		// Take over an expired lock; the WHERE clause makes this a single atomic step.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", (string) $expires, $key, time() ) );
		return 1 === (int) $updated;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( $name ) {
		$key = self::PREFIX . md5( $name );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::GROUP );
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Row written by acquire().
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) );
	}

	/**
	 * Add one to an hourly counter and return the new value.
	 *
	 * @param string $name Counter name.
	 * @return int
	 */
	public static function incr( $name ) {
		$key = self::counter_key( $name );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::GROUP, 2 * HOUR_IN_SECONDS );
			return (int) wp_cache_incr( $key, 1, self::GROUP );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic increment.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1", $key ) );
		return self::count( $name );
	}

	/**
	 * Current value of an hourly counter.
	 *
	 * @param string $name Counter name.
	 * @return int
	 */
	public static function count( $name ) {
		$key = self::counter_key( $name );
		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $key, self::GROUP );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must read the live value, not a cached one.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
	}

	/**
	 * Delete counters from past hours and expired locks (runs with the daily cleanup).
	 */
	public static function cleanup() {
		if ( wp_using_ext_object_cache() ) {
			return;
		}
		global $wpdb;
		$current = '_h' . (int) floor( time() / HOUR_IN_SECONDS );
		$like    = $wpdb->esc_like( self::PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Housekeeping of rows this class writes.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND ( ( option_name LIKE %s AND option_name NOT LIKE %s ) OR ( option_name NOT LIKE %s AND CAST(option_value AS UNSIGNED) < %d ) )", $like, '%\_h%', '%' . $wpdb->esc_like( $current ), '%\_h%', time() ) );
	}

	/**
	 * Option name of a counter for the current hour.
	 *
	 * @param string $name Counter name.
	 * @return string
	 */
	private static function counter_key( $name ) {
		return self::PREFIX . md5( $name ) . '_h' . (int) floor( time() / HOUR_IN_SECONDS );
	}
}

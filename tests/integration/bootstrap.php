<?php
/**
 * Integration test bootstrap: real WordPress + WooCommerce + this plugin.
 * Requires WP_TESTS_DIR (bin/install-wp-tests.sh) and WC_DIR (path to the woocommerce plugin folder).
 *
 * @package DZCOD
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
$_wc_dir    = getenv( 'WC_DIR' ) ? getenv( 'WC_DIR' ) : dirname( __DIR__, 3 ) . '/woocommerce';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test suite not found in $_tests_dir. Run bin/install-wp-tests.sh first.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $_wc_dir ) {
		require $_wc_dir . '/woocommerce.php';
		require dirname( __DIR__, 2 ) . '/dz-cod-order-form.php';
	}
);
tests_add_filter(
	'setup_theme',
	function () {
		define( 'WC_TAX_ROUNDING_MODE', 'auto' );
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
	}
);

require $_tests_dir . '/includes/bootstrap.php';

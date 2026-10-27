<?php
/**
 * Plugin Name:       DZ COD Order Form
 * Plugin URI:        https://github.com/xthegodfatherx/dz-cod-order-form
 * Description:       One-page cash-on-delivery order form for Algerian WooCommerce stores: all 69 wilayas and 1541 communes, per-wilaya delivery pricing (home / stop desk), duplicate and fake-order protection, Google Sheets export, and sending to Yalidine, ZR Express and Ecotrack couriers.
 * Version:           1.3.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Nedjai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dz-cod-order-form
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   10.3
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

define( 'DZCOD_VERSION', '1.3.1' );
define( 'DZCOD_FILE', __FILE__ );
define( 'DZCOD_DIR', plugin_dir_path( __FILE__ ) );
define( 'DZCOD_URL', plugin_dir_url( __FILE__ ) );

require_once DZCOD_DIR . 'includes/class-dzcod-locations.php';
require_once DZCOD_DIR . 'includes/class-dzcod-settings.php';
require_once DZCOD_DIR . 'includes/class-dzcod-antifraud.php';
require_once DZCOD_DIR . 'includes/class-dzcod-sheets.php';
require_once DZCOD_DIR . 'includes/class-dzcod-order.php';
require_once DZCOD_DIR . 'includes/class-dzcod-form.php';
require_once DZCOD_DIR . 'includes/class-dzcod-admin.php';
require_once DZCOD_DIR . 'includes/class-dzcod-courier-api.php';
require_once DZCOD_DIR . 'includes/class-dzcod-couriers.php';
require_once DZCOD_DIR . 'includes/class-dzcod-prices.php';
require_once DZCOD_DIR . 'includes/class-dzcod-offers.php';
require_once DZCOD_DIR . 'includes/class-dzcod-leads.php';

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'DZ COD Order Form requires WooCommerce to be installed and active.', 'dz-cod-order-form' ) . '</p></div>';
				}
			);
			return;
		}
		DZCOD_Settings::init();
		DZCOD_Form::init();
		DZCOD_Order::init();
		DZCOD_Sheets::init();
		DZCOD_Admin::init();
		DZCOD_Couriers::init();
		DZCOD_Prices::init();
		DZCOD_Offers::init();
		DZCOD_Leads::init();

		add_action(
			'elementor/widgets/register',
			function ( $widgets_manager ) {
				require_once DZCOD_DIR . 'includes/class-dzcod-elementor-widget.php';
				$widgets_manager->register( new DZCOD_Elementor_Widget() );
			}
		);
	}
);

register_activation_hook(
	__FILE__,
	function () {
		if ( false === get_option( DZCOD_Settings::OPTION ) ) {
			add_option( DZCOD_Settings::OPTION, DZCOD_Settings::defaults() );
		}
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=dzcod' ) ) . '">' . esc_html__( 'Settings', 'dz-cod-order-form' ) . '</a>' );
		return $links;
	}
);

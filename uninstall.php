<?php
/**
 * Remove plugin settings on uninstall. Orders are kept.
 *
 * @package DZCOD
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'dzcod_settings' );
delete_option( 'dzcod_couriers' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'dzcod_sync_sheet_prices', array(), 'dzcod' );
}

<?php
/**
 * Remove everything the plugin stored on uninstall: settings, call log, scheduled jobs and
 * abandoned-order records. WooCommerce orders are kept.
 *
 * @package DZCOD
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'dzcod_settings' );
delete_option( 'dzcod_couriers' );
delete_option( 'dzcod_agent_log' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'dzcod_leads_cleanup', array(), 'dzcod' );
	as_unschedule_all_actions( 'dzcod_sync_sheet_prices', array(), 'dzcod' );
	as_unschedule_all_actions( 'dzcod_sync_parcels', array(), 'dzcod' );
	as_unschedule_all_actions( '', array(), 'dzcod' ); // Any queued parcel / conversion jobs of this plugin.
}

// Abandoned-order records hold personal data: remove them all.
$dzcod_leads = get_posts(
	array(
		'post_type'      => 'dzcod_lead',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $dzcod_leads as $dzcod_lead ) {
	wp_delete_post( $dzcod_lead, true );
}

// Rate-limit counters and locks (see includes/class-dzcod-lock.php).
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup on uninstall.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dzcod_lk_' ) . '%' ) );

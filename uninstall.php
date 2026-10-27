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
	as_unschedule_all_actions( 'dzcod_leads_cleanup', array(), 'dzcod' );
	as_unschedule_all_actions( 'dzcod_sync_sheet_prices', array(), 'dzcod' );
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

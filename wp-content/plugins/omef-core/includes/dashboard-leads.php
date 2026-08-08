<?php
/**
 * Contact-form leads list for the Omef dashboard.
 */

defined( 'ABSPATH' ) || exit;

function omef_leads_list_ajax(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . omef_leads_table() . ' ORDER BY created_at DESC LIMIT 200' );

	$leads = array_map(
		static fn( $row ): array => array(
			'name'      => trim( $row->first_name . ' ' . $row->last_name ),
			'phone'     => $row->phone,
			'interest'  => $row->interest,
			'createdAt' => mysql2date( 'd/m/Y H:i', $row->created_at ),
		),
		(array) $rows
	);

	wp_send_json_success( array( 'leads' => $leads ) );
}
add_action( 'wp_ajax_omef_leads_list', 'omef_leads_list_ajax' );

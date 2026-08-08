<?php
/**
 * Abandoned-cart tracking and reminder email.
 */

defined( 'ABSPATH' ) || exit;

function omef_abandoned_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'omef_abandoned';
}

function omef_abandoned_create_table(): void {
	global $wpdb;
	$table = omef_abandoned_table();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		"CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(191) NOT NULL,
			cart_key VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			reminded_at DATETIME NULL,
			converted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cart_key (cart_key),
			KEY email (email)
		) {$wpdb->get_charset_collate()};"
	);
}

function omef_abandoned_upsert( string $email, string $key ): void {
	global $wpdb;
	$table = omef_abandoned_table();
	if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE cart_key = %s", $key ) ) ) {
		$wpdb->insert( $table, array( 'email' => $email, 'cart_key' => $key, 'created_at' => current_time( 'mysql' ) ) );
		return;
	}
	$wpdb->update( $table, array( 'created_at' => current_time( 'mysql' ) ), array( 'cart_key' => $key ) );
}

function omef_capture_abandoned_cart(): void {
	if ( ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}
	$user_id = get_current_user_id();
	if ( $user_id ) {
		omef_abandoned_upsert( wp_get_current_user()->user_email, 'user_' . $user_id );
	}
}
add_action( 'woocommerce_cart_updated', 'omef_capture_abandoned_cart' );

function omef_capture_checkout_email( array $posted ): void {
	if ( empty( $posted['billing_email'] ) || ! is_email( $posted['billing_email'] ) ) {
		return;
	}
	omef_abandoned_upsert( $posted['billing_email'], 'guest_' . md5( $posted['billing_email'] ) );
}
add_action( 'woocommerce_checkout_update_order_review', 'omef_capture_checkout_email' );

function omef_mark_cart_converted( int $order_id ): void {
	global $wpdb;
	$table = omef_abandoned_table();
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$keys = array();
	if ( $order->get_user_id() ) {
		$keys[] = 'user_' . $order->get_user_id();
	}
	$email = $order->get_billing_email();
	if ( $email ) {
		$keys[] = 'guest_' . md5( $email );
	}
	foreach ( $keys as $key ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET converted_at = %s WHERE cart_key = %s AND converted_at IS NULL",
				current_time( 'mysql' ),
				$key
			)
		);
	}
}
add_action( 'woocommerce_checkout_order_processed', 'omef_mark_cart_converted', 10, 1 );

function omef_send_abandoned_reminders(): void {
	global $wpdb;
	$table = omef_abandoned_table();
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table WHERE created_at < %s AND reminded_at IS NULL AND converted_at IS NULL",
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		)
	);
	foreach ( (array) $rows as $row ) {
		wp_mail(
			$row->email,
			'הפריטים מחכים לך בעגלה — חנות הוויסקי',
			sprintf(
				"שלום,\n\nהשארת פריטים בעגלה בחנות הוויסקי שלנו. שלמי את הרכישה כאן:\n\n%s\n\nהמלאי של חביות בודדות מוגבל ואין לדעת מתי יחזור.\n\n— אומף ויסקי",
				esc_url_raw( home_url( '/cart/' ) )
			)
		);
		$wpdb->update( $table, array( 'reminded_at' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
	}
}
add_action( 'omef_abandoned_reminder_cron', 'omef_send_abandoned_reminders' );


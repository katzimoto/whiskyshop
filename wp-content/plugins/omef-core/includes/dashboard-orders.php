<?php
/**
 * Order management for the Omef dashboard: list/get/update-status/resend-email
 * over AJAX, built on WooCommerce's own order objects. Refund issuance is
 * intentionally not implemented here — the order detail view links out to
 * WooCommerce's native order screen for that financial action.
 */

defined( 'ABSPATH' ) || exit;

function omef_admin_shape_order_summary( WC_Order $order ): array {
	return array(
		'id'            => $order->get_id(),
		'orderNumber'   => $order->get_order_number(),
		'date'          => wc_format_datetime( $order->get_date_created() ),
		'customerName'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		'customerEmail' => $order->get_billing_email(),
		'status'        => $order->get_status(),
		'statusLabel'   => wc_get_order_status_name( $order->get_status() ),
		'total'         => wp_strip_all_tags( $order->get_formatted_order_total() ),
		'paymentMethod' => $order->get_payment_method_title(),
	);
}

function omef_orders_list(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$page     = max( 1, absint( $_GET['page'] ?? 1 ) );
	$per_page = 20;

	$args = array(
		'limit'    => $per_page,
		'page'     => $page,
		'paginate' => true,
		'orderby'  => 'date',
		'order'    => 'DESC',
		'type'     => 'shop_order',
	);

	$status = sanitize_key( $_GET['status'] ?? '' );
	if ( $status !== '' && array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
		$args['status'] = $status;
	}

	$search = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
	if ( $search !== '' ) {
		$args['s'] = $search;
	}

	$result = wc_get_orders( $args );
	$orders = array_map(
		static fn( $order ) => omef_admin_shape_order_summary( $order ),
		is_array( $result->orders ?? null ) ? $result->orders : array()
	);

	wp_send_json_success(
		array(
			'orders'     => $orders,
			'page'       => $page,
			'totalPages' => max( 1, (int) ( $result->max_num_pages ?? 1 ) ),
			'total'      => (int) ( $result->total ?? count( $orders ) ),
			'statuses'   => wc_get_order_statuses(),
		)
	);
}
add_action( 'wp_ajax_omef_orders_list', 'omef_orders_list' );

function omef_orders_get(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id    = absint( $_GET['id'] ?? 0 );
	$order = $id ? wc_get_order( $id ) : null;
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => 'ההזמנה לא נמצאה.' ), 404 );
	}

	$items = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = array(
			'name'     => $item->get_name(),
			'quantity' => $item->get_quantity(),
			'total'    => wc_price( (float) $item->get_total() + (float) $item->get_total_tax(), array( 'currency' => $order->get_currency() ) ),
		);
	}

	wp_send_json_success(
		array(
			'id'              => $order->get_id(),
			'orderNumber'     => $order->get_order_number(),
			'date'            => wc_format_datetime( $order->get_date_created() ),
			'status'          => $order->get_status(),
			'statuses'        => wc_get_order_statuses(),
			'items'           => $items,
			'subtotal'        => wc_price( (float) $order->get_subtotal(), array( 'currency' => $order->get_currency() ) ),
			'shippingTotal'   => wc_price( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), array( 'currency' => $order->get_currency() ) ),
			'total'           => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'paymentMethod'   => $order->get_payment_method_title() ?: '—',
			'customerName'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customerEmail'   => $order->get_billing_email(),
			'customerPhone'   => $order->get_billing_phone(),
			'billingAddress'  => nl2br( esc_html( omef_email_address_lines( $order, 'billing' ) ) ),
			'shippingAddress' => nl2br( esc_html( omef_email_address_lines( $order, 'shipping' ) ) ),
			'customerNote'    => $order->get_customer_note(),
			'editUrl'         => admin_url( 'post.php?action=edit&post=' . $order->get_id() ),
		)
	);
}
add_action( 'wp_ajax_omef_orders_get', 'omef_orders_get' );

function omef_orders_update_status(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id    = absint( $_POST['id'] ?? 0 );
	$order = $id ? wc_get_order( $id ) : null;
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => 'ההזמנה לא נמצאה.' ), 404 );
	}

	$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
	if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
		wp_send_json_error( array( 'message' => 'סטטוס לא תקין.' ) );
	}

	$order->set_status( $status );
	$order->save();

	wp_send_json_success( array( 'order' => omef_admin_shape_order_summary( $order ) ) );
}
add_action( 'wp_ajax_omef_orders_update_status', 'omef_orders_update_status' );

function omef_orders_resend_email(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id    = absint( $_POST['id'] ?? 0 );
	$order = $id ? wc_get_order( $id ) : null;
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => 'ההזמנה לא נמצאה.' ), 404 );
	}

	$email = $order->get_billing_email();
	if ( ! $email || ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => 'אין כתובת דוא"ל תקינה בהזמנה זו.' ) );
	}

	$sent = omef_send_purchase_email( $order, array( $email ) );
	if ( ! $sent ) {
		wp_send_json_error( array( 'message' => 'שליחת ההודעה נכשלה.' ) );
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_omef_orders_resend_email', 'omef_orders_resend_email' );

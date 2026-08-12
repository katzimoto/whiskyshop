<?php
/**
 * Order emails: an admin notification for every purchase sent to a
 * configurable address, and a detailed customer confirmation built from an
 * admin-editable email template.
 */

defined( 'ABSPATH' ) || exit;

function omef_email_admin_recipients(): array {
	$raw        = trim( (string) get_option( 'omef_admin_notification_to', '' ) );
	$recipients = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'is_email' ) );
	return $recipients ?: array( (string) get_option( 'admin_email' ) );
}

function omef_email_address_lines( WC_Order $order, string $type ): string {
	$lines = array();
	foreach ( array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
		$getter = 'get_' . $type . '_' . $field;
		if ( method_exists( $order, $getter ) ) {
			$value = trim( (string) call_user_func( array( $order, $getter ) ) );
			if ( $value !== '' ) {
				$lines[] = $value;
			}
		}
	}
	return implode( "\n", $lines );
}

function omef_email_placeholders( WC_Order $order ): array {
	$items_html = '';
	foreach ( $order->get_items() as $item ) {
		$price      = wc_price( (float) $item->get_total() + (float) $item->get_total_tax(), array( 'currency' => $order->get_currency() ) );
		$items_html .= '<tr><td style="padding:6px 12px;border-bottom:1px solid #eee">' . esc_html( $item->get_name() ) . ' &times; ' . esc_html( (string) $item->get_quantity() ) . '</td><td style="padding:6px 12px;border-bottom:1px solid #eee;text-align:right">' . $price . '</td></tr>';
	}
	if ( $items_html ) {
		$items_html = '<table style="border-collapse:collapse;width:100%"><tbody>' . $items_html . '</tbody></table>';
	}

	$shipping_lines = omef_email_address_lines( $order, 'shipping' );
	$has_shipping = $shipping_lines !== '';

	return array(
		'{customer_name}'    => esc_html( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ),
		'{order_number}'     => (string) $order->get_order_number(),
		'{order_date}'       => wc_format_datetime( $order->get_date_created() ),
		'{order_status}'     => wc_get_order_status_name( $order->get_status() ),
		'{items}'            => $items_html,
		'{subtotal}'         => wc_price( (float) $order->get_subtotal() + (float) $order->get_total_tax() - (float) $order->get_total_shipping() - (float) $order->get_shipping_tax(), array( 'currency' => $order->get_currency() ) ),
		'{shipping_total}'   => $has_shipping ? wc_price( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), array( 'currency' => $order->get_currency() ) ) : '—',
		'{total}'            => $order->get_formatted_order_total(),
		'{payment_method}'   => $order->get_payment_method_title() ?: '—',
		'{billing_address}'  => nl2br( esc_html( omef_email_address_lines( $order, 'billing' ) ) ),
		'{shipping_address}' => nl2br( esc_html( $has_shipping ? $shipping_lines : omef_email_address_lines( $order, 'billing' ) ) ),
	);
}

function omef_email_template_default(): string {
	return '<p>שלום {customer_name},</p>' . "\n"
		. '<p>תודה שביצעתם הזמנה בחנות הוויסקי שלנו. להלן סיכום ההזמנה:</p>' . "\n"
		. '<p><strong>מספר הזמנה:</strong> {order_number}<br>' . "\n"
		. '<strong>תאריך:</strong> {order_date}<br>' . "\n"
		. '<strong>סטטוס:</strong> {order_status}</p>' . "\n"
		. '{items}' . "\n"
		. '<p><strong>סה"כ:</strong> {total}<br>' . "\n"
		. '<strong>אמצעי תשלום:</strong> {payment_method}</p>' . "\n"
		. '{shipping_address}' . "\n"
		. '<p>נשמח לעמוד לשירותכם בכל שאלה.</p>' . "\n"
		. '<p>— חנות הוויסקי</p>';
}

function omef_email_wrap( string $html ): string {
	return '<!DOCTYPE html><html dir="rtl" lang="he"><head><meta charset="utf-8"><title>חנות הוויסקי</title></head>'
		. '<body style="margin:0;padding:24px;background:#f4f1ec;font-family:Helvetica,Arial,sans-serif;color:#332a22">'
		. '<div id="omef-order-email" style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e4ded5;border-radius:8px;padding:24px 28px">'
		. $html
		. '</div></body></html>';
}

function omef_email_render( WC_Order $order ): array {
	$template = trim( (string) get_option( 'omef_customer_email_template', '' ) );
	if ( $template === '' ) {
		$template = omef_email_template_default();
	}

	$subject = trim( (string) get_option( 'omef_customer_email_subject', '' ) );
	if ( $subject === '' ) {
		$subject = 'הזמנה #{order_number} — אתר הוויסקי';
	}

	$values = omef_email_placeholders( $order );
	return array(
		'subject' => str_replace( array_keys( $values ), array_values( $values ), $subject ),
		'body'    => str_replace( array_keys( $values ), array_values( $values ), $template ),
	);
}

function omef_send_purchase_email( WC_Order $order, array $recipients ): bool {
	$html = omef_email_render( $order );
	return wp_mail( $recipients, $html['subject'], $html['body'], array( 'Content-Type: text/html; charset=UTF-8' ) );
}

function omef_send_admin_new_order_notification( int $order_id ): void {
	if ( get_option( 'omef_admin_notification_enabled', 'yes' ) !== 'yes' ) {
		return;
	}

	// woocommerce_new_order fires inside wc_create_order(), before line items
	// exist, so it can't be used here. Instead this runs on the status
	// transition into a paid state (processing/completed), where the order is
	// fully built. Send only once per order.
	if ( get_post_meta( $order_id, '_omef_admin_notified', true ) ) {
		return;
	}
	update_post_meta( $order_id, '_omef_admin_notified', '1' );

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	omef_send_purchase_email( $order, omef_email_admin_recipients() );
}
add_action( 'woocommerce_order_status_processing', 'omef_send_admin_new_order_notification', 20, 1 );
add_action( 'woocommerce_order_status_completed', 'omef_send_admin_new_order_notification', 20, 1 );

add_filter( 'woocommerce_email_recipient_new_order', '__return_empty_string' );

function omef_intercept_customer_email( $phpmailer ): void {
	if ( ! ( $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) || get_option( 'omef_customer_email_enabled', 'yes' ) !== 'yes' ) {
		return;
	}

	$subject = (string) $phpmailer->Subject;
	if ( ! preg_match( '/#(\d+)/u', $subject, $matches ) ) {
		return;
	}

	if ( strpos( (string) $phpmailer->Body, 'omef-order-email' ) !== false ) {
		return;
	}

	$order = wc_get_order( (int) $matches[1] );
	if ( ! $order ) {
		return;
	}

	$html = omef_email_wrap( omef_email_render( $order )['body'] );
	$phpmailer->Body = $html;
	$phpmailer->AltBody = wp_strip_all_tags( omef_email_render( $order )['body'] );
}
add_action( 'phpmailer_init', 'omef_intercept_customer_email', 5 );

function omef_customer_email_subject( $subject, $order ) {
	if ( $order instanceof WC_Order ) {
		return omef_email_render( $order )['subject'];
	}
	return $subject;
}
add_filter( 'woocommerce_email_subject_customer_processing_order', 'omef_customer_email_subject', 10, 2 );
add_filter( 'woocommerce_email_subject_customer_completed_order', 'omef_customer_email_subject', 10, 2 );
add_filter( 'woocommerce_email_subject_customer_on_hold_order', 'omef_customer_email_subject', 10, 2 );

function omef_sanitize_email_list( string $value ): string {
	$emails = array();
	foreach ( explode( ',', $value ) as $email ) {
		$email = trim( $email );
		if ( is_email( $email ) ) {
			$emails[] = $email;
		}
	}
	return implode( ', ', $emails );
}

function omef_email_settings_get(): void {
	omef_dashboard_guard( 'manage_options' );

	wp_send_json_success(
		array(
			'adminNotificationEnabled' => get_option( 'omef_admin_notification_enabled', 'yes' ) === 'yes',
			'adminNotificationTo'      => get_option( 'omef_admin_notification_to', '' ),
			'customerEmailEnabled'     => get_option( 'omef_customer_email_enabled', 'yes' ) === 'yes',
			'customerEmailSubject'     => get_option( 'omef_customer_email_subject', '' ),
			'customerEmailTemplate'    => get_option( 'omef_customer_email_template', omef_email_template_default() ),
			'placeholders'             => array( '{customer_name}', '{order_number}', '{order_date}', '{order_status}', '{items}', '{subtotal}', '{shipping_total}', '{total}', '{payment_method}', '{billing_address}', '{shipping_address}' ),
		)
	);
}
add_action( 'wp_ajax_omef_email_settings_get', 'omef_email_settings_get' );

function omef_email_settings_save(): void {
	omef_dashboard_guard( 'manage_options' );

	update_option( 'omef_admin_notification_enabled', empty( $_POST['admin_notification_enabled'] ) ? 'no' : 'yes' );
	update_option( 'omef_admin_notification_to', omef_sanitize_email_list( sanitize_text_field( wp_unslash( $_POST['admin_notification_to'] ?? '' ) ) ) );
	update_option( 'omef_customer_email_enabled', empty( $_POST['customer_email_enabled'] ) ? 'no' : 'yes' );
	update_option( 'omef_customer_email_subject', sanitize_text_field( wp_unslash( $_POST['customer_email_subject'] ?? '' ) ) );
	update_option( 'omef_customer_email_template', wp_kses_post( wp_unslash( $_POST['customer_email_template'] ?? '' ) ) );

	wp_send_json_success();
}
add_action( 'wp_ajax_omef_email_settings_save', 'omef_email_settings_save' );
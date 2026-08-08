<?php
/**
 * Fast single-screen mobile checkout: trimmed classic form and payment defaults.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_checkout_fields', static function ( array $fields ): array {
	unset( $fields['billing']['billing_company'] );
	unset( $fields['billing']['billing_address_2'] );
	unset( $fields['billing']['billing_country'] );
	unset( $fields['billing']['billing_state'] );
	unset( $fields['shipping'] );

	if ( isset( $fields['billing']['billing_postcode'] ) ) {
		$fields['billing']['billing_postcode']['required'] = false;
		$fields['billing']['billing_postcode']['priority']  = 30;
	}

	if ( isset( $fields['billing']['billing_phone'] ) ) {
		$fields['billing']['billing_phone']['priority'] = 110;
	}

	if ( ! empty( $fields['order']['order_comments'] ) ) {
		$fields['order']['order_comments']['priority'] = 200;
	}

	return $fields;
} );

add_action( 'woocommerce_before_checkout_form', static function (): void {
	if ( ! is_checkout() || is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}
	echo '<script>document.addEventListener("DOMContentLoaded",function(){var r=document.querySelector("#payment .wc_payment_method input[type=radio]");if(r&&!document.querySelector("#payment .wc_payment_method input[type=radio]:checked"))r.checked=true;});</script>';
} );
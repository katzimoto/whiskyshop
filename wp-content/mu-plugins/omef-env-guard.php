<?php
/**
 * Plugin Name: Omef Environment Guard
 * Description: Refuses to boot if a mock integration driver is active in production.
 *
 * @package Omef
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function (): void {
		if ( 'production' !== wp_get_environment_type() ) {
			return;
		}

		$drivers = array(
			'PAYMENT_DRIVER' => getenv( 'PAYMENT_DRIVER' ),
			'INVOICE_DRIVER' => getenv( 'INVOICE_DRIVER' ),
		);

		$mock_drivers = array();
		foreach ( $drivers as $name => $value ) {
			if ( 'mock' === ( $value ? $value : 'mock' ) ) {
				$mock_drivers[] = $name;
			}
		}

		if ( array() !== $mock_drivers ) {
			wp_die(
				esc_html(
					'Refusing to boot: mock driver(s) active in production: ' . implode( ', ', $mock_drivers )
				)
			);
		}
	},
	0
);

add_action(
	'admin_notices',
	function (): void {
		$payment_driver = getenv( 'PAYMENT_DRIVER' );
		$invoice_driver = getenv( 'INVOICE_DRIVER' );
		$payment_mock   = 'mock' === ( $payment_driver ? $payment_driver : 'mock' );
		$invoice_mock   = 'mock' === ( $invoice_driver ? $invoice_driver : 'mock' );

		if ( 'production' === wp_get_environment_type() || ( ! $payment_mock && ! $invoice_mock ) ) {
			return;
		}

		echo '<div class="notice notice-error" style="border-inline-start-color:#d63638;"><p><strong>'
			. esc_html__( 'מצב בדיקה: תשלומים אינם אמיתיים', 'omef' )
			. '</strong></p></div>';
	}
);

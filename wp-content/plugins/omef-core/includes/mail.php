<?php
/**
 * Outgoing mail sender configuration.
 */

defined( 'ABSPATH' ) || exit;

function omef_mail_from(): string {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $host || str_contains( $host, 'localhost' ) ) {
		$host = 'omef.test';
	}
	return 'noreply@' . $host;
}
add_filter( 'wp_mail_from', 'omef_mail_from' );
add_filter( 'wp_mail_from_name', static fn() => 'חנות הוויסקי — אומף' );

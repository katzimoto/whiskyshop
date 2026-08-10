<?php
/**
 * Plugin Name: Omef – Scope Airwallex Currency-Switching Modal To Checkout
 * Description: The Airwallex Online Payments Gateway plugin is installed directly on the
 * server (not part of this repo — see wp-content/README or the deploy workflow, neither
 * installs it). Every Airwallex gateway class it registers (Card, Afterpay, Klarna, WeChat,
 * POS, ExpressCheckout, Main) is instantiated by WooCommerce regardless of whether it is the
 * enabled/selected gateway, and its constructor unconditionally hooks
 * AirwallexGatewayLocalPaymentMethod::renderQuoteExpireHtml() to wp_footer with no is_checkout()
 * guard — unlike its own sibling enqueueScripts(), which correctly checks is_checkout() before
 * loading the JS that would ever toggle this markup visible. The result: the "quote expired /
 * amount to pay updated / Back to checkout / Place Order" currency-conversion modal (hidden only
 * via inline style="display:none") is echoed into every single page's HTML, including the age
 * gate and homepage, on every request — one copy per registered gateway instance.
 *
 * Since that markup's own companion JS never loads outside checkout, it is always inert there;
 * this only removes its dead DOM presence on non-checkout requests, by exact class match, so it
 * never becomes a visible full-page leak if any future CSS/JS conflict ever flips its display.
 * On checkout the buffer is never started, so the modal keeps working exactly as designed the
 * moment a real currency-conversion quote expires mid-payment.
 *
 * If the Airwallex plugin ships a fix for this upstream, or is removed, this file is a no-op:
 * it only touches wp_footer output that actually contains the leaked markup.
 */

defined( 'ABSPATH' ) || exit;

function omef_airwallex_leaked_classes(): array {
	return array(
		'wc-airwallex-currency-switching-quote-expire-mask',
		'wc-airwallex-currency-switching-quote-expire',
	);
}

/**
 * Matches exactly the pages Airwallex's own enqueueScripts() already restricts itself to —
 * this fix does nothing more and nothing less than apply that same boundary to the sibling
 * hook the plugin forgot to guard.
 */
function omef_airwallex_footer_needs_scoping(): bool {
	return function_exists( 'is_checkout' ) && ! is_checkout();
}

function omef_airwallex_start_footer_buffer(): void {
	if ( omef_airwallex_footer_needs_scoping() ) {
		ob_start();
	}
}
add_action( 'wp_footer', 'omef_airwallex_start_footer_buffer', 1 );

function omef_airwallex_end_footer_buffer(): void {
	if ( ! omef_airwallex_footer_needs_scoping() ) {
		return;
	}

	$html = ob_get_clean();
	if ( ! is_string( $html ) || $html === '' ) {
		return;
	}

	if ( strpos( $html, 'wc-airwallex-currency-switching-quote-expire' ) === false ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- passthrough of already-rendered wp_footer output.
		return;
	}

	echo omef_airwallex_strip_leaked_markup( $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- passthrough, minus a stripped fragment.
}
add_action( 'wp_footer', 'omef_airwallex_end_footer_buffer', PHP_INT_MAX );

/**
 * Removes just the two Airwallex wrapper elements (matched by exact class token, so their own
 * differently-named child elements — "-inner", "-header", "-footer", etc. — are never touched)
 * from a wp_footer HTML fragment, leaving every other plugin's/theme's footer output untouched.
 * Falls back to returning the original string untouched on any parse failure, so this can never
 * take down a page.
 */
function omef_airwallex_strip_leaked_markup( string $html ): string {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return $html;
	}

	$wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
		. '<body><div id="omef-airwallex-scope-root">' . $html . '</div></body></html>';

	$doc = new DOMDocument();
	$previous_setting = libxml_use_internal_errors( true );
	$loaded = $doc->loadHTML( $wrapped, LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_use_internal_errors( $previous_setting );

	if ( ! $loaded ) {
		return $html;
	}

	$xpath   = new DOMXPath( $doc );
	$removed = 0;
	foreach ( omef_airwallex_leaked_classes() as $class ) {
		$nodes = $xpath->query(
			sprintf( '//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $class )
		);
		foreach ( $nodes as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
				$removed++;
			}
		}
	}

	if ( 0 === $removed ) {
		return $html;
	}

	$root_nodes = $xpath->query( '//div[@id="omef-airwallex-scope-root"]' );
	if ( 0 === $root_nodes->length ) {
		return $html;
	}

	$output = '';
	foreach ( $root_nodes->item( 0 )->childNodes as $child ) {
		$output .= $doc->saveHTML( $child );
	}

	return $output;
}

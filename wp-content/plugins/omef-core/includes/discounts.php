<?php
/**
 * Editorial price discounts: strikethrough price, discounted price and a reason note.
 */

defined( 'ABSPATH' ) || exit;

function omef_discount( int $product_id ): ?array {
	$sale = (float) get_post_meta( $product_id, '_omef_sale_price', true );
	if ( $sale <= 0 ) {
		return null;
	}

	$base = (float) get_post_meta( $product_id, '_omef_full_price', true );
	if ( ! $base ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}
		$base = (float) ( $product->is_type( 'variable' ) ? $product->get_variation_regular_price( 'min', true ) : $product->get_regular_price( 'edit' ) );
	}

	if ( $base <= 0 || $sale >= $base ) {
		return null;
	}

	return array(
		'base' => $base,
		'sale' => $sale,
		'note' => trim( (string) get_post_meta( $product_id, '_omef_sale_note', true ) ),
	);
}

function omef_price( float $amount ): string {
	return number_format_i18n( $amount, 0 ) . ' ₪';
}

function omef_render_discount_price(): void {
	global $product;
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$discount = omef_discount( $product->get_id() );
	if ( ! $discount ) {
		return;
	}

	echo '<p class="omef-discount"><s>' . esc_html( omef_price( $discount['base'] ) ) . '</s> <strong>' . esc_html( omef_price( $discount['sale'] ) ) . '</strong>';
	if ( $discount['note'] ) {
		echo ' <span class="omef-discount-note">' . esc_html( $discount['note'] ) . '</span>';
	}
	echo '</p>';
}
add_action( 'woocommerce_single_product_summary', 'omef_render_discount_price', 11 );

function omef_render_card_discount(): void {
	global $product;
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$discount = omef_discount( $product->get_id() );
	if ( ! $discount ) {
		return;
	}

	echo '<p class="omef-discount omef-discount-card"><s>' . esc_html( omef_price( $discount['base'] ) ) . '</s> <strong>' . esc_html( omef_price( $discount['sale'] ) ) . '</strong></p>';
}
add_action( 'woocommerce_after_shop_loop_item_title', 'omef_render_card_discount', 8 );

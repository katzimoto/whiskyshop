<?php
/**
 * Minimal, behavior-matching stand-ins for the WordPress/WooCommerce
 * functions the tested files call, so plugin logic can be unit tested
 * without booting a full WordPress + database environment. These are not
 * a WordPress test suite — they exist only to let PHPUnit exercise our own
 * business logic (sanitizers, discount math, etc.) in isolation.
 */

declare( strict_types=1 );

function add_action( ...$args ): true {
	return true;
}

function add_filter( ...$args ): true {
	return true;
}

function absint( $maybeint ): int {
	return abs( (int) $maybeint );
}

function sanitize_text_field( string $str ): string {
	return trim( strip_tags( $str ) );
}

function sanitize_textarea_field( string $str ): string {
	return trim( strip_tags( $str ) );
}

$GLOBALS['omef_test_post_types'] = array();
function get_post_type( int $post_id ) {
	return $GLOBALS['omef_test_post_types'][ $post_id ] ?? false;
}

$GLOBALS['omef_test_post_meta'] = array();
function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	return $GLOBALS['omef_test_post_meta'][ $post_id ][ $key ] ?? '';
}

$GLOBALS['omef_test_products'] = array();
function wc_get_product( int $product_id ) {
	return $GLOBALS['omef_test_products'][ $product_id ] ?? false;
}

function sanitize_title( string $title ): string {
	return strtolower( trim( $title ) );
}

$GLOBALS['omef_test_is_robots'] = false;
function is_robots(): bool {
	return $GLOBALS['omef_test_is_robots'];
}

$GLOBALS['omef_test_query_vars'] = array();
function get_query_var( string $var, $default = '' ) {
	return $GLOBALS['omef_test_query_vars'][ $var ] ?? $default;
}

/**
 * Stands in for WC_Product for tests that need is_type()/get_regular_price()/
 * get_variation_regular_price() without loading WooCommerce.
 */
class Omef_Test_Product {
	public function __construct( private array $data = array() ) {}

	public function is_type( string $type ): bool {
		return ( $this->data['type'] ?? 'simple' ) === $type;
	}

	public function get_id(): int {
		return $this->data['id'] ?? 0;
	}

	public function get_parent_id(): int {
		return $this->data['parent_id'] ?? 0;
	}

	public function get_attributes(): array {
		return $this->data['attributes'] ?? array();
	}

	public function get_regular_price( string $context = 'view' ) {
		return $this->data['regular_price'] ?? 0;
	}

	public function get_variation_regular_price( string $min_or_max = 'min', bool $display = false ) {
		return $this->data['variation_regular_price'][ $min_or_max ] ?? 0;
	}
}

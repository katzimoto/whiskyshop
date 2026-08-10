<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['omef_test_post_types'] = array();
	}

	public function test_integer_type_absints_the_value(): void {
		$this->assertSame( '5', omef_sanitize_value( '5.9', 'integer' ) );
		$this->assertSame( '5', omef_sanitize_value( -5, 'integer' ) );
	}

	public function test_decimal_type_accepts_up_to_two_places(): void {
		$this->assertSame( '19.99', omef_sanitize_value( '19.99', 'decimal' ) );
		$this->assertSame( '19.99', omef_sanitize_value( '19,99', 'decimal' ) );
		$this->assertSame( '19', omef_sanitize_value( '19', 'decimal' ) );
	}

	public function test_decimal_type_rejects_malformed_input(): void {
		$this->assertSame( '', omef_sanitize_value( '19.999', 'decimal' ) );
		$this->assertSame( '', omef_sanitize_value( 'abc', 'decimal' ) );
		$this->assertSame( '', omef_sanitize_value( '-5', 'decimal' ) );
	}

	public function test_boolean_type_casts_to_bool(): void {
		$this->assertTrue( omef_sanitize_value( '1', 'boolean' ) );
		$this->assertFalse( omef_sanitize_value( '', 'boolean' ) );
	}

	public function test_datetime_type_validates_format(): void {
		$this->assertSame( '2026-08-10T18:30', omef_sanitize_value( '2026-08-10T18:30', 'datetime' ) );
		$this->assertSame( '', omef_sanitize_value( '10/08/2026', 'datetime' ) );
	}

	public function test_sanitize_product_ids_dedupes_and_filters_by_post_type(): void {
		$GLOBALS['omef_test_post_types'] = array( 1 => 'product', 2 => 'product', 3 => 'post' );

		$this->assertSame( array( 1, 2 ), omef_sanitize_product_ids( array( '1', 1, 2, 3, 0, -4 ) ) );
	}

	public function test_sanitize_episode_ids_filters_by_episode_post_type(): void {
		$GLOBALS['omef_test_post_types'] = array( 1 => 'omef_episode', 2 => 'product' );

		$this->assertSame( array( 1 ), omef_sanitize_episode_ids( array( 1, 2 ) ) );
	}
}

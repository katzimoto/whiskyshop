<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class DiscountsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['omef_test_post_meta'] = array();
		$GLOBALS['omef_test_products']  = array();
	}

	public function test_returns_null_without_a_sale_price(): void {
		$this->assertNull( omef_discount( 10 ) );
	}

	public function test_returns_null_when_sale_is_not_below_base(): void {
		$GLOBALS['omef_test_post_meta'][10] = array(
			'_omef_sale_price' => 200,
			'_omef_full_price' => 150,
		);

		$this->assertNull( omef_discount( 10 ) );
	}

	public function test_returns_null_when_sale_equals_base(): void {
		$GLOBALS['omef_test_post_meta'][10] = array(
			'_omef_sale_price' => 150,
			'_omef_full_price' => 150,
		);

		$this->assertNull( omef_discount( 10 ) );
	}

	public function test_computes_discount_from_full_price_meta(): void {
		$GLOBALS['omef_test_post_meta'][10] = array(
			'_omef_sale_price' => 150,
			'_omef_full_price' => 200,
			'_omef_sale_note'  => 'מבצע השקה',
		);

		$this->assertSame(
			array( 'base' => 200.0, 'sale' => 150.0, 'note' => 'מבצע השקה' ),
			omef_discount( 10 )
		);
	}

	public function test_falls_back_to_the_product_price_when_full_price_meta_is_missing(): void {
		$GLOBALS['omef_test_post_meta'][10] = array( '_omef_sale_price' => 150 );
		$GLOBALS['omef_test_products'][10]  = new Omef_Test_Product( array( 'regular_price' => 220 ) );

		$discount = omef_discount( 10 );

		$this->assertNotNull( $discount );
		$this->assertSame( 220.0, $discount['base'] );
	}

	public function test_falls_back_to_the_minimum_variation_price_for_variable_products(): void {
		$GLOBALS['omef_test_post_meta'][10] = array( '_omef_sale_price' => 100 );
		$GLOBALS['omef_test_products'][10]  = new Omef_Test_Product(
			array(
				'type'                    => 'variable',
				'variation_regular_price' => array( 'min' => 180 ),
			)
		);

		$discount = omef_discount( 10 );

		$this->assertNotNull( $discount );
		$this->assertSame( 180.0, $discount['base'] );
	}

	public function test_returns_null_when_the_product_cannot_be_found(): void {
		$GLOBALS['omef_test_post_meta'][10] = array( '_omef_sale_price' => 100 );

		$this->assertNull( omef_discount( 10 ) );
	}

	public function test_price_filter_charges_the_editorial_sale_price_for_a_simple_product(): void {
		$GLOBALS['omef_test_post_meta'][10] = array(
			'_omef_sale_price' => 150,
			'_omef_full_price' => 200,
		);
		$product = new Omef_Test_Product( array( 'id' => 10 ) );

		$this->assertSame( '150', omef_apply_discount_to_price( '200', $product ) );
	}

	public function test_price_filter_leaves_price_untouched_without_an_active_discount(): void {
		$product = new Omef_Test_Product( array( 'id' => 10 ) );

		$this->assertSame( '200', omef_apply_discount_to_price( '200', $product ) );
	}

	public function test_price_filter_charges_the_discount_on_the_full_bottle_variation(): void {
		$GLOBALS['omef_test_post_meta'][10] = array(
			'_omef_sale_price' => 400,
			'_omef_full_price' => 500,
		);
		$variation = new Omef_Test_Product(
			array(
				'id'         => 11,
				'type'       => 'variation',
				'parent_id'  => 10,
				'attributes' => array( sanitize_title( 'גודל דגימה' ) => 'בקבוק מלא (700 מ"ל)' ),
			)
		);

		$this->assertSame( '400', omef_apply_discount_to_price( '500', $variation ) );
	}

	public function test_price_filter_leaves_the_sample_variation_at_its_own_price(): void {
		$GLOBALS['omef_test_post_meta'][10] = array(
			'_omef_sale_price' => 400,
			'_omef_full_price' => 500,
		);
		$sample = new Omef_Test_Product(
			array(
				'id'         => 12,
				'type'       => 'variation',
				'parent_id'  => 10,
				'attributes' => array( sanitize_title( 'גודל דגימה' ) => 'דגימה (30 מ"ל)' ),
			)
		);

		$this->assertSame( '80', omef_apply_discount_to_price( '80', $sample ) );
	}
}

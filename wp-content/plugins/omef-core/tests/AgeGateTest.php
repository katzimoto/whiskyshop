<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AgeGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['omef_test_is_robots']  = false;
		$GLOBALS['omef_test_query_vars'] = array();
	}

	public function test_a_normal_page_request_is_not_bypassed(): void {
		$this->assertFalse( omef_age_gate_bypassed_request() );
	}

	public function test_robots_txt_bypasses_the_gate(): void {
		$GLOBALS['omef_test_is_robots'] = true;

		$this->assertTrue( omef_age_gate_bypassed_request() );
	}

	public function test_sitemap_index_bypasses_the_gate(): void {
		$GLOBALS['omef_test_query_vars']['sitemap'] = 'index';

		$this->assertTrue( omef_age_gate_bypassed_request() );
	}

	public function test_sitemap_stylesheet_bypasses_the_gate(): void {
		$GLOBALS['omef_test_query_vars']['sitemap-stylesheet'] = 'sitemap';

		$this->assertTrue( omef_age_gate_bypassed_request() );
	}
}

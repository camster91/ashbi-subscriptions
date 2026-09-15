<?php

use PHPUnit\Framework\TestCase;

final class ToolingSmokeTest extends TestCase {
	public function test_test_environment_is_bootstrapped(): void {
		$this->assertTrue( defined( 'PHP_VERSION' ) );
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/plugin/subscription.php' );
	}

	public function test_no_charge_apply_tool_has_no_gateway_or_order_dispatch_calls(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/apply-overdue-dispositions.php' );
		$this->assertIsString( $tool );
		$this->assertStringNotContainsString( 'create_renewal_order(', $tool );
		$this->assertStringNotContainsString( 'process_payment(', $tool );
		$this->assertStringNotContainsString( 'wc_create_order(', $tool );
		$this->assertStringNotContainsString( 'wp_update_post(', $tool );
		$this->assertStringContainsString( "'apply-no-charge'", $tool );
		$this->assertStringContainsString( 'ASHBI_DISPOSITION_CONFIRM', $tool );
		$this->assertStringContainsString( 'array_diff( $active, $claim, $overdue )', $tool );
		$this->assertStringContainsString( 'array_diff( $advanced_ids, $preexisting_operator, $unowned )', $tool );
	}

	public function test_fingerprint_report_never_emits_source_rows_or_key(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/site-state-fingerprint.php' );
		$this->assertIsString( $tool );
		$this->assertStringContainsString( 'SiteFingerprint::digest_rows', $tool );
		$this->assertStringContainsString( 'SiteFingerprint::seal_report', $tool );
		$this->assertStringNotContainsString( "'rows' =>", $tool );
		$this->assertStringNotContainsString( "'key' =>", $tool );
	}
}

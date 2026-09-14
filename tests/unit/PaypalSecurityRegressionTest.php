<?php

use PHPUnit\Framework\TestCase;

final class PaypalSecurityRegressionTest extends TestCase {
	private string $source;

	protected function setUp(): void {
		$this->source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Gateways/Paypal/Paypal.php'
		);
	}

	public function test_webhooks_fail_closed_without_downloading_request_selected_certificates(): void {
		$this->assertStringNotContainsString( 'verify_paypal_webhook_manual', $this->source );
		$this->assertStringNotContainsString( 'wp_remote_get( $cert_url', $this->source );
		$this->assertStringContainsString( "empty( \$this->webhook_id )", $this->source );
		$this->assertStringContainsString( 'verify_paypal_webhook_rest_api', $this->source );
		$this->assertStringContainsString( "'response' => 503", $this->source );
		$this->assertStringContainsString( 'is_wp_error( $response )', $this->source );
	}

	public function test_return_handler_uses_the_order_bound_subscription_identifier(): void {
		$this->assertStringContainsString( '$returned_subscription_id', $this->source );
		$this->assertStringContainsString( 'hash_equals( (string) $paypal_subscription_id, (string) $returned_subscription_id )', $this->source );
		$this->assertStringNotContainsString( '$paypal_subscription_id = isset( $_GET', $this->source );
		$this->assertStringNotContainsString( '$this->get_paypal_order( $paypal_token )', $this->source );
	}

	public function test_lifecycle_webhooks_reconcile_authoritative_remote_state(): void {
		$this->assertStringContainsString( '$this->get_paypal_subscription( $paypal_subscription_id )', $this->source );
		$this->assertStringContainsString( "'_subscrpt_paypal_last_event_time_precise'", $this->source );
		$this->assertStringContainsString( 'PayPal subscription state has not converged yet.', $this->source );
		$this->assertStringContainsString( 'status_update_time', $this->source );
		$this->assertStringContainsString( 'Stale PayPal subscription event ignored.', $this->source );
		$this->assertStringNotContainsString( '$event_time <= $last_event_time', $this->source );
	}
}

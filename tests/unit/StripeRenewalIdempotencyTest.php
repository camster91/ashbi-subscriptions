<?php

use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Illuminate\Gateways\Stripe\RenewalPaymentTerminalException;
use SpringDevs\Subscription\Illuminate\Gateways\Stripe\Stripe;

if ( ! class_exists( 'WC_Stripe_Payment_Gateway' ) ) {
	class WC_Stripe_Payment_Gateway {
	}
}

if ( ! class_exists( 'WC_Stripe_Exception' ) ) {
	class WC_Stripe_Exception extends Exception {
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Gateways/Stripe/Stripe.php';

final class StripeRenewalIdempotencyTest extends TestCase {
	private Stripe $gateway;

	protected function setUp(): void {
		$reflection    = new ReflectionClass( Stripe::class );
		$this->gateway = $reflection->newInstanceWithoutConstructor();
	}

	public function test_same_canonical_request_gets_the_same_key(): void {
		$request = array(
			'amount'   => 2500,
			'currency' => 'cad',
			'metadata' => array( 'ashbi_renewal_identity' => str_repeat( 'a', 64 ) ),
		);

		$first  = $this->gateway->renewal_idempotency_key( 'random-one', $request );
		$second = $this->gateway->renewal_idempotency_key( 'random-two', $request );

		$this->assertSame( $first, $second );
		$this->assertStringStartsWith( 'ashbi-renewal-', $first );
	}

	public function test_level_three_fallback_gets_a_distinct_stable_key(): void {
		$request = array(
			'amount'   => 2500,
			'currency' => 'cad',
			'metadata' => array( 'ashbi_renewal_identity' => str_repeat( 'a', 64 ) ),
		);

		$with_level_three           = $request;
		$with_level_three['level3'] = array( 'merchant_reference' => 'order-501' );

		$this->assertNotSame(
			$this->gateway->renewal_idempotency_key( null, $request ),
			$this->gateway->renewal_idempotency_key( null, $with_level_three )
		);
	}

	public function test_changed_base_request_cannot_silently_get_a_new_attempt_key(): void {
		$request = array(
			'amount'   => 2500,
			'currency' => 'cad',
			'metadata' => array( 'ashbi_renewal_identity' => str_repeat( 'a', 64 ) ),
		);
		$changed           = $request;
		$changed['amount'] = 2600;

		$this->assertSame(
			$this->gateway->renewal_idempotency_key( null, $request ),
			$this->gateway->renewal_idempotency_key( null, $changed )
		);
	}

	public function test_non_renewal_request_keeps_gateway_key(): void {
		$this->assertSame(
			'gateway-key',
			$this->gateway->renewal_idempotency_key( 'gateway-key', array( 'amount' => 2500 ) )
		);
	}

	public function test_uncertain_pending_exception_is_retried_without_becoming_terminal(): void {
		$this->assertSame(
			Stripe::RENEWAL_EXCEPTION_RETRY,
			Stripe::classify_renewal_exception( new WC_Stripe_Exception( 'transport timeout' ), true, false )
		);
	}

	public function test_local_reconciliation_exception_is_terminal(): void {
		$this->assertSame(
			Stripe::RENEWAL_EXCEPTION_TERMINAL,
			Stripe::classify_renewal_exception( new RenewalPaymentTerminalException( 'identity mismatch' ), true, false )
		);
	}

	public function test_explicit_gateway_decline_is_terminal(): void {
		$this->assertSame(
			Stripe::RENEWAL_EXCEPTION_TERMINAL,
			Stripe::classify_renewal_exception( new WC_Stripe_Exception( 'card declined' ), true, true )
		);
	}

	public function test_pre_dispatch_exception_uses_the_ordinary_order_failure_path(): void {
		$this->assertSame(
			Stripe::RENEWAL_EXCEPTION_ORDER_FAILURE,
			Stripe::classify_renewal_exception( new WC_Stripe_Exception( 'minimum amount' ), false, false )
		);
	}
}

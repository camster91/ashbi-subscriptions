<?php

use PHPUnit\Framework\TestCase;
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
}

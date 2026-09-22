<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit fixture intentionally retains its historical test filename.
/**
 * Verify Stripe renewal idempotency and exception classification contracts.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Illuminate\Gateways\Stripe\RenewalPaymentTerminalException;
use SpringDevs\Subscription\Illuminate\Gateways\Stripe\Stripe;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound -- This fixture intentionally combines gateway stubs, a JSON stub, and PHPUnit tests.

if ( ! class_exists( 'WC_Stripe_Payment_Gateway' ) ) {
	/** Provide the gateway base class required by the Stripe source. */
	class WC_Stripe_Payment_Gateway {
	}
}

if ( ! class_exists( 'WC_Stripe_Exception' ) ) {
	/** Provide the gateway exception required by the Stripe source. */
	class WC_Stripe_Exception extends Exception {
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Provide the WordPress JSON helper required by the Stripe source.
	 *
	 * @param mixed $value Value to encode.
	 */
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Record an action dispatched by the isolated Stripe fixture.
	 *
	 * @param string $hook Action name.
	 * @param mixed  ...$args Action arguments.
	 */
	function do_action( $hook, ...$args ) {
		$GLOBALS['ashbi_actions'][] = array( $hook, $args );
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Helper.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Gateways/Stripe/Stripe.php';

/** Verify Stripe renewal idempotency and error classification. */
final class StripeRenewalIdempotencyTest extends TestCase {
	/**
	 * Stripe gateway instance without constructor dependencies.
	 *
	 * @var Stripe
	 */
	private Stripe $gateway;

	/** Create a reflection-based gateway test double. */
	protected function setUp(): void {
		$reflection               = new ReflectionClass( Stripe::class );
		$this->gateway            = $reflection->newInstanceWithoutConstructor();
		$GLOBALS['ashbi_actions'] = array();
		$GLOBALS['wpdb']          = new class() {
			/** WordPress table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Return a deterministic query string for the fixture.
			 *
			 * @param string $query SQL query.
			 * @param mixed  $args Query arguments.
			 * @return string Query string.
			 */
			public function prepare( $query, $args ) {
				return $query;
			}

			/**
			 * Return the fabricated subscription relation rows.
			 *
			 * @param string $query SQL query.
			 * @return array<int, object> Relation rows.
			 */
			public function get_results( $query ) {
				return $GLOBALS['ashbi_stripe_relations'] ?? array();
			}
		};
	}

	/** Remove the isolated database fixture after each test. */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['ashbi_stripe_relations'], $GLOBALS['ashbi_actions'] );
	}

	/** Verify the same canonical request gets the same key. */
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

	/** Verify the level-three fallback gets a distinct stable key. */
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

	/** Verify a changed base request cannot silently get a new attempt key. */
	public function test_changed_base_request_cannot_silently_get_a_new_attempt_key(): void {
		$request           = array(
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

	/** Verify non-renewal requests retain the gateway key. */
	public function test_non_renewal_request_keeps_gateway_key(): void {
		$this->assertSame(
			'gateway-key',
			$this->gateway->renewal_idempotency_key( 'gateway-key', array( 'amount' => 2500 ) )
		);
	}

	/** Verify uncertain pending exceptions are retried without becoming terminal. */
	public function test_uncertain_pending_exception_is_retried_without_becoming_terminal(): void {
		$this->assertSame(
			Stripe::RENEWAL_EXCEPTION_RETRY,
			Stripe::classify_renewal_exception( new WC_Stripe_Exception( 'transport timeout' ), true, false )
		);
	}

	/** Verify local reconciliation exceptions are terminal. */
	public function test_local_reconciliation_exception_is_terminal(): void {
		$this->assertSame(
			Stripe::RENEWAL_EXCEPTION_TERMINAL,
			Stripe::classify_renewal_exception( new RenewalPaymentTerminalException( 'identity mismatch' ), true, false )
		);
	}

	/** Verify explicit gateway declines are terminal. */
	public function test_explicit_gateway_decline_is_terminal(): void {
		$this->assertSame(
			Stripe::RENEWAL_EXCEPTION_TERMINAL,
			Stripe::classify_renewal_exception( new WC_Stripe_Exception( 'card declined' ), true, true )
		);
	}

	/** Verify pre-dispatch exceptions use the ordinary order failure path. */
	public function test_pre_dispatch_exception_uses_the_ordinary_order_failure_path(): void {
		$this->assertSame(
			Stripe::RENEWAL_EXCEPTION_ORDER_FAILURE,
			Stripe::classify_renewal_exception( new WC_Stripe_Exception( 'minimum amount' ), false, false )
		);
	}

	/** Verify a missing subscription relation does not emit a failure hook for ID zero. */
	public function test_payment_failure_hook_fails_closed_without_a_subscription_relation(): void {
		$GLOBALS['ashbi_stripe_relations'] = array();
		$order                             = new class() {
			/** Return the fabricated renewal order ID. */
			public function get_id() {
				return 701;
			}
		};
		$method                            = new ReflectionMethod( Stripe::class, 'trigger_renewal_payment_failed' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $this->gateway, $order );

		$this->assertSame( array(), $GLOBALS['ashbi_actions'] );
	}

	/** Verify a valid relation still emits the canonical subscription failure hook. */
	public function test_payment_failure_hook_emits_the_canonical_subscription_id(): void {
		$GLOBALS['ashbi_stripe_relations'] = array( (object) array( 'subscription_id' => 702 ) );
		$order                             = new class() {
			/** Return the fabricated renewal order ID. */
			public function get_id() {
				return 702;
			}
		};
		$method                            = new ReflectionMethod( Stripe::class, 'trigger_renewal_payment_failed' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $this->gateway, $order );

		$this->assertSame(
			array( array( 'subscrpt_subscription_payment_failed', array( 702 ) ) ),
			$GLOBALS['ashbi_actions']
		);
	}
}

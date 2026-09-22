<?php // phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound -- PHPUnit fixture retains the historical test filename and combines an order double with the gateway tests.
/**
 * PayPal security and retry regression tests.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Illuminate\Gateways\Paypal\Paypal;

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Minimal order double for PayPal event-ledger tests.
	 *
	 * @package AshbiSubscriptions\Tests
	 */
	class WC_Order {
		/** @var array<string,mixed> */
		private array $meta = array();

		/**
		 * Read a fabricated order meta value.
		 *
		 * @param string $key    Meta key.
		 * @param bool   $single Return one value.
		 * @return mixed
		 */
		public function get_meta( $key, $single = true ) {
			return $this->meta[ $key ] ?? '';
		}

		/**
		 * Persist a fabricated order meta value.
		 *
		 * @param string $key   Meta key.
		 * @param mixed  $value Meta value.
		 * @return void
		 */
		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Provide the WordPress sanitizer required by the isolated gateway method.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string
	 */
	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Gateways/Paypal/Paypal.php';


/** Verify PayPal gateway hardening and retry identity behavior. */
final class PaypalSecurityRegressionTest extends TestCase {
	/**
	 * PayPal gateway source under test.
	 *
	 * @var string
	 */
	private string $source;

	/** Load the PayPal gateway source. */
	protected function setUp(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local source fixture under test.
		$this->source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Gateways/Paypal/Paypal.php'
		);
	}

	/** Verify webhook verification fails closed. */
	public function test_webhooks_fail_closed_without_downloading_request_selected_certificates(): void {
		$this->assertStringNotContainsString( 'verify_paypal_webhook_manual', $this->source );
		$this->assertStringNotContainsString( 'use PHPUnit\\', $this->source );
		$this->assertStringNotContainsString( 'wp_remote_get( $cert_url', $this->source );
		$this->assertStringContainsString( 'empty( $this->webhook_id )', $this->source );
		$this->assertStringContainsString( 'verify_paypal_webhook_rest_api', $this->source );
		$this->assertStringContainsString( "'response' => 503", $this->source );
		$this->assertStringContainsString( 'is_wp_error( $response )', $this->source );
	}

	/** Verify PayPal returns use the order-bound subscription. */
	public function test_return_handler_uses_the_order_bound_subscription_identifier(): void {
		$this->assertStringContainsString( '$returned_subscription_id', $this->source );
		$this->assertStringContainsString( 'hash_equals( (string) $paypal_subscription_id, (string) $returned_subscription_id )', $this->source );
		$this->assertStringNotContainsString( '$paypal_subscription_id = isset( $_GET', $this->source );
		$this->assertStringNotContainsString( '$this->get_paypal_order( $paypal_token )', $this->source );
	}

	/** Verify unmapped PayPal webhooks query relations with the order ID. */
	public function test_unmapped_subscription_webhooks_resolve_relations_by_order_id(): void {
		$start = strpos( $this->source, 'public function handle_subscription_event' );
		$end   = strpos( $this->source, "\n\t/**\n\t * Handle subscription cancellation.", (int) $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );

		$handler = substr( $this->source, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString( 'Helper::get_subscriptions_from_order( $order->get_id() )', $handler );
		$this->assertStringNotContainsString( 'Helper::get_subscriptions_from_order( $order );', $handler );
		$this->assertStringContainsString( '$subscription = ! empty( $subscription ) ? reset( $subscription ) : null;', $handler );
	}

	/** Verify malformed but signed webhook bodies fail closed before field access. */
	public function test_malformed_webhooks_fail_closed_with_a_bad_request(): void {
		$start = strpos( $this->source, 'public function process_webhook' );
		$end   = strpos( $this->source, "\n\t/**\n\t * Return minimal webhook context", (int) $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );

		$handler = substr( $this->source, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString( '$webhook_data = json_decode( $raw_body, true );', $handler );
		$this->assertStringContainsString( 'if ( ! is_array( $webhook_data ) ) {', $handler );
		$this->assertStringContainsString( "'400 Bad Request'", $handler );
	}

	/** Verify webhook replay protection requires an event identifier. */
	public function test_webhooks_without_event_ids_fail_closed_before_dispatch(): void {
		$start = strpos( $this->source, 'public function process_webhook' );
		$end   = strpos( $this->source, "\n\t/**\n\t * Return minimal webhook context", (int) $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );

		$handler = substr( $this->source, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString( '$event_id = sanitize_text_field( (string) ( $webhook_data[\'id\'] ?? \'\' ) );', $handler );
		$this->assertStringContainsString( "'PayPal webhook event ID is missing.'", $handler );
		$this->assertStringContainsString( "'400 Bad Request'", $handler );
	}

	/** Verify transaction webhooks have a serialized, identified delivery boundary. */
	public function test_transaction_webhooks_are_replay_safe_and_require_transaction_ids(): void {
		$start = strpos( $this->source, 'public function process_webhook' );
		$end   = strpos( $this->source, "\n\t/**\n\t * Return minimal webhook context", (int) $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );

		$handler = substr( $this->source, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString( "in_array( \$event, \$transaction_events, true ) && '' === \$transaction_id", $handler );
		$this->assertStringContainsString( "'PayPal transaction identifier is missing.'", $handler );
		$this->assertStringContainsString( "'ashbi_paypal_transaction_'", $this->source );
		$this->assertStringContainsString( 'SELECT GET_LOCK(%s, 5)', $this->source );
		$this->assertStringContainsString( 'SELECT RELEASE_LOCK(%s)', $this->source );
		$this->assertStringContainsString( 'handle_transaction_event_locked', $this->source );
		$this->assertStringContainsString( "'_subscrpt_paypal_transaction_event_ids'", $this->source );
		$this->assertStringContainsString( 'has_processed_transaction_event', $this->source );
		$this->assertStringContainsString( 'record_transaction_event', $this->source );
		$this->assertStringContainsString( "array( 'refunded', 'cancelled' )", $this->source );
		$this->assertStringContainsString( '$event_id', $handler );
	}

	/** Verify lifecycle events reconcile authoritative remote state. */
	public function test_lifecycle_webhooks_reconcile_authoritative_remote_state(): void {
		$this->assertStringContainsString( '$this->get_paypal_subscription( $paypal_subscription_id )', $this->source );
		$this->assertStringContainsString( "'_subscrpt_paypal_last_event_time_precise'", $this->source );
		$this->assertStringContainsString( 'PayPal subscription state has not converged yet.', $this->source );
		$this->assertStringContainsString( 'status_update_time', $this->source );
		$this->assertStringContainsString( 'Stale PayPal subscription event ignored.', $this->source );
		$this->assertStringNotContainsString( '$event_time <= $last_event_time', $this->source );
	}

	/** Verify mapped PayPal catalog products are reused safely. */
	public function test_catalog_products_are_reused_when_local_mapping_is_missing(): void {
		$this->assertStringContainsString( 'find_paypal_product( $product_data, $access_token )', $this->source );
		$this->assertStringContainsString( "'/v1/catalogs/products'", $this->source );
		$this->assertStringContainsString( "'page_size'", $this->source );
		$this->assertStringContainsString( "'total_required' => 'true'", $this->source );
		$this->assertStringContainsString( 'false === $paypal_product', $this->source );
		$this->assertStringContainsString( 'untrailingslashit', $this->source );
	}

	/** Verify PayPal creation requests have stable retry identities. */
	public function test_paypal_creation_requests_have_stable_retry_identities(): void {
		$this->assertStringContainsString( 'get_paypal_request_id( \'product\'', $this->source );
		$this->assertStringContainsString( 'get_paypal_request_id( \'plan\'', $this->source );
		$this->assertStringContainsString( 'get_paypal_request_id( \'subscription\'', $this->source );
		$this->assertStringNotContainsString( "uniqid( 'wp-subs-paypal-', true )", $this->source );
		$this->assertStringContainsString( 'hash( \'sha256\'', $this->source );
	}

	/** Verify gateway logs exclude full webhook and response payloads. */
	public function test_webhook_and_gateway_debug_logs_do_not_dump_payloads(): void {
		$this->assertStringContainsString( 'webhook_debug_context', $this->source );
		$this->assertStringNotContainsString( 'wp_json_encode( $webhook_data )', $this->source );
		$this->assertStringNotContainsString( 'sanitize_text_field( $raw_body )', $this->source );
		$this->assertStringNotContainsString( 'wp_json_encode( $response_data )', $this->source );
	}

	/** Verify refund webhook event IDs provide replay defense. */
	public function test_refund_webhooks_have_event_id_replay_defense(): void {
		$this->assertStringContainsString( "'_subscrpt_paypal_refund_event_ids'", $this->source );
		$this->assertStringContainsString( 'in_array( $event_id, $seen_event_ids, true )', $this->source );
		$this->assertStringContainsString( '$seen_event_ids[] = $event_id', $this->source );
	}

	/** Verify refund requests have deterministic retry identities. */
	public function test_refund_requests_have_deterministic_retry_identity(): void {
		$this->assertStringContainsString( 'get_paypal_request_id(', $this->source );
		$this->assertStringContainsString( "'refund',", $this->source );
		$this->assertStringNotContainsString( "uniqid( 'wp-subs-refund-', true )", $this->source );
		$this->assertStringContainsString( 'refund_sequence', $this->source );
	}

	/** Verify request identities are stable and sandbox/live scoped. */
	public function test_paypal_request_identity_is_stable_and_mode_scoped(): void {
		$gateway  = ( new ReflectionClass( Paypal::class ) )->newInstanceWithoutConstructor();
		$identity = new ReflectionMethod( Paypal::class, 'get_paypal_request_id' );
		if ( PHP_VERSION_ID < 80100 ) {
			$identity->setAccessible( true );
		}

		$gateway->sandbox_mode = true;
		$sandbox_first         = $identity->invoke( $gateway, 'subscription', 'order-501' );
		$sandbox_repeat        = $identity->invoke( $gateway, 'subscription', 'order-501' );
		$gateway->sandbox_mode = false;
		$live_identity         = $identity->invoke( $gateway, 'subscription', 'order-501' );

		$this->assertSame( $sandbox_first, $sandbox_repeat );
		$this->assertNotSame( $sandbox_first, $live_identity );
		$this->assertStringStartsWith( 'ashbi-subscription-', $sandbox_first );
	}

	/** Verify completed-sale event IDs are durable and deduplicated per order. */
	public function test_completed_sale_event_ledger_is_durable_and_deduplicated(): void {
		$gateway = ( new ReflectionClass( Paypal::class ) )->newInstanceWithoutConstructor();
		$order   = new WC_Order();
		$record  = new ReflectionMethod( Paypal::class, 'record_transaction_event' );
		$seen    = new ReflectionMethod( Paypal::class, 'has_processed_transaction_event' );
		if ( PHP_VERSION_ID < 80100 ) {
			$record->setAccessible( true );
			$seen->setAccessible( true );
		}

		$record->invoke( $gateway, $order, 'WH-EVENT-1' );
		$record->invoke( $gateway, $order, 'WH-EVENT-1' );
		$record->invoke( $gateway, $order, 'WH-EVENT-2' );

		$this->assertSame(
			array( 'WH-EVENT-1', 'WH-EVENT-2' ),
			$order->get_meta( '_subscrpt_paypal_transaction_event_ids', true )
		);
		$this->assertTrue( $seen->invoke( $gateway, $order, 'WH-EVENT-1' ) );
		$this->assertFalse( $seen->invoke( $gateway, $order, 'WH-EVENT-3' ) );
	}
}

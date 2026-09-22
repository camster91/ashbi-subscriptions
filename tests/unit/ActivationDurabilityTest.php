<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit fixture intentionally retains its historical test filename.
/**
 * Verify lifecycle activation effects are durable and replay-safe.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Illuminate\Action;
use SpringDevs\Subscription\Illuminate\Order as SubscriptionOrder;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound -- This fixture intentionally combines WordPress stubs, doubles, and PHPUnit tests.

if ( ! function_exists( 'wp_insert_comment' ) ) {
	/**
	 * Insert a comment into the isolated fixture.
	 *
	 * @param mixed $data Comment data.
	 * @return mixed Fixture comment identifier.
	 */
	function wp_insert_comment( $data ) {
		$GLOBALS['ashbi_comment_data'][] = $data;
		return $GLOBALS['ashbi_comment_insert_result'];
	}
}

if ( ! function_exists( 'update_comment_meta' ) ) {
	/**
	 * Store comment metadata in the isolated fixture.
	 *
	 * @param mixed $comment_id Comment identifier.
	 * @param mixed $key Metadata key.
	 * @param mixed $value Metadata value.
	 * @return mixed Fixture update result.
	 */
	function update_comment_meta( $comment_id, $key, $value ) {
		$GLOBALS['ashbi_comment_meta'][] = array( $comment_id, $key, $value );
		return $GLOBALS['ashbi_comment_meta_result'];
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Record an action dispatched by the isolated fixture.
	 *
	 * @param mixed $hook Hook name.
	 * @param mixed ...$args Hook arguments.
	 */
	function do_action( $hook, ...$args ) {
		$GLOBALS['ashbi_actions'][] = array( $hook, $args );
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	/**
	 * Report whether the isolated fixture has a scheduled action.
	 *
	 * @param mixed $hook Hook name.
	 * @param mixed $args Action arguments.
	 * @param mixed $group Action group.
	 * @return bool Whether the action is scheduled.
	 */
	function as_has_scheduled_action( $hook, $args, $group ) {
		return $GLOBALS['ashbi_has_scheduled_action'] ?? false;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Return an untranslated fixture string.
	 *
	 * @param mixed $text Text to translate.
	 * @param mixed $domain Translation domain.
	 * @return mixed Original text.
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Action.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Order.php';

/** Provide subscription item metadata for the lifecycle fixture. */
final class ActivationDurabilityItemFake {
	/**
	 * Return fixture item metadata.
	 *
	 * @param mixed $key Metadata key.
	 * @param mixed $single Whether to return one value.
	 * @return mixed Fixture metadata.
	 */
	public function get_meta( $key, $single = false ) {
		return '_subscrpt_meta' === $key ? array( 'trial' => '7 days' ) : null;
	}
}

/** Provide the order behavior needed by trial completion checks. */
final class ActivationDurabilityOrderFake {
	/** Return the fixture order status. */
	public function get_status(): string {
		return 'processing';
	}

	/** Return the fixture order items. */
	public function get_items(): array {
		return array( new ActivationDurabilityItemFake() );
	}
}

/** Provide a trial order whose status persistence can be controlled. */
final class ActivationDurabilityTrialWorkerOrderFake {
	/**
	 * Whether status writes persist.
	 *
	 * @var bool
	 */
	private bool $persists;
	/**
	 * Current fixture order status.
	 *
	 * @var string
	 */
	private string $status = 'processing';

	/**
	 * Create a controllable trial order double.
	 *
	 * @param bool $persists Whether status writes persist.
	 */
	public function __construct( bool $persists ) {
		$this->persists = $persists;
	}

	/**
	 * Attempt to update the fixture order status.
	 *
	 * @param mixed $status New status.
	 * @param mixed $note Order note.
	 */
	public function update_status( $status, $note ): void {
		if ( $this->persists ) {
			$this->status = $status;
		}
	}

	/**
	 * Check the fixture order status.
	 *
	 * @param mixed $status Expected status.
	 * @return bool Whether the status matches.
	 */
	public function has_status( $status ): bool {
		return $this->status === $status;
	}
}

/** Provide the subscription-order hooks needed by trial activation tests. */
final class ActivationDurabilityOrderServiceFake extends SubscriptionOrder {
	/**
	 * Deferred activation claims.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $deferred = array();

	/** Create the service without parent dependencies. */
	public function __construct() {
	}

	/**
	 * Return renewal history records for the fixture order.
	 *
	 * @param int $order_id Order identifier.
	 * @return array<int,object> Renewal history records.
	 */
	protected function renewal_histories_for_order( int $order_id ): array {
		return array(
			(object) array(
				'subscription_id' => 7,
				'type'            => 'renew',
			),
		);
	}

	/**
	 * Report that the fixture order is a claimed renewal.
	 *
	 * @param int $subscription_id Subscription identifier.
	 * @param int $order_id Order identifier.
	 * @return bool Whether the order is claimed.
	 */
	protected function is_claimed_renewal_order( int $subscription_id, int $order_id ): bool {
		return true;
	}

	/**
	 * Record a deferred renewal activation.
	 *
	 * @param int    $subscription_id Subscription identifier.
	 * @param int    $order_id Order identifier.
	 * @param string $reason Deferral reason.
	 */
	protected function defer_renewal_activation( int $subscription_id, int $order_id, string $reason ): void {
		$this->deferred[] = array( $subscription_id, $order_id, $reason );
	}
}

/** Verify activation effects are durable before claims complete. */
final class ActivationDurabilityTest extends TestCase {
	/** Reset isolated lifecycle fixture state. */
	protected function setUp(): void {
		$GLOBALS['ashbi_comment_data']          = array();
		$GLOBALS['ashbi_comment_meta']          = array();
		$GLOBALS['ashbi_actions']               = array();
		$GLOBALS['ashbi_orders']                = array();
		$GLOBALS['ashbi_has_scheduled_action']  = false;
		$GLOBALS['ashbi_comment_insert_result'] = 101;
		$GLOBALS['ashbi_comment_meta_result']   = 1;
	}

	/** Verify comment insertion failure is reported. */
	public function test_lifecycle_effect_reports_failure_when_comment_insert_fails(): void {
		$GLOBALS['ashbi_comment_insert_result'] = 0;

		$this->assertFalse( Action::write_comment( 'active', 42 ) );
		$this->assertSame( array(), $GLOBALS['ashbi_actions'] );
	}

	/** Verify completion is recorded only after comment and hook effects. */
	public function test_lifecycle_effect_is_complete_only_after_comment_and_hook(): void {
		$this->assertTrue( Action::write_comment( 'completed', 42 ) );
		$this->assertCount( 2, $GLOBALS['ashbi_comment_meta'] );
		$this->assertSame( 'subscrpt_subscription_completed', $GLOBALS['ashbi_actions'][0][0] );
	}

	/** Verify an already queued trial remains pending until verified. */
	public function test_already_queued_trial_completion_remains_pending_until_verified(): void {
		$GLOBALS['ashbi_orders'][42]           = new ActivationDurabilityOrderFake();
		$GLOBALS['ashbi_has_scheduled_action'] = true;

		$reflection = new ReflectionClass( SubscriptionOrder::class );
		$order      = $reflection->newInstanceWithoutConstructor();
		$this->assertNull( $order->maybe_trigger_auto_complete_trial_order( 42, 7 ) );
	}

	/** Verify failed trial persistence defers the canonical claim. */
	public function test_failed_trial_worker_persistence_defers_the_canonical_claim(): void {
		$GLOBALS['ashbi_orders'][42] = new ActivationDurabilityTrialWorkerOrderFake( false );
		$service                     = new ActivationDurabilityOrderServiceFake();

		$service->auto_complete_subscription_trial_order( 42 );

		$this->assertSame( 7, $service->deferred[0][0] );
		$this->assertSame( 42, $service->deferred[0][1] );
		$this->assertStringContainsString( 'did not persist', $service->deferred[0][2] );
	}

	/** Verify successful trial persistence does not defer the claim. */
	public function test_successful_trial_worker_persistence_does_not_defer(): void {
		$GLOBALS['ashbi_orders'][42] = new ActivationDurabilityTrialWorkerOrderFake( true );
		$service                     = new ActivationDurabilityOrderServiceFake();

		$service->auto_complete_subscription_trial_order( 42 );

		$this->assertSame( array(), $service->deferred );
	}
}

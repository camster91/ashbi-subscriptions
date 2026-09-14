<?php

use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Illuminate\Action;
use SpringDevs\Subscription\Illuminate\Order as SubscriptionOrder;

if ( ! function_exists( 'wp_insert_comment' ) ) {
	function wp_insert_comment( $data ) {
		$GLOBALS['ashbi_comment_data'][] = $data;
		return $GLOBALS['ashbi_comment_insert_result'];
	}
}

if ( ! function_exists( 'update_comment_meta' ) ) {
	function update_comment_meta( $comment_id, $key, $value ) {
		$GLOBALS['ashbi_comment_meta'][] = array( $comment_id, $key, $value );
		return $GLOBALS['ashbi_comment_meta_result'];
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['ashbi_actions'][] = array( $hook, $args );
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( $hook, $args, $group ) {
		return $GLOBALS['ashbi_has_scheduled_action'] ?? false;
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Action.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Order.php';

final class ActivationDurabilityItemFake {
	public function get_meta( $key, $single = false ) {
		return '_subscrpt_meta' === $key ? array( 'trial' => '7 days' ) : null;
	}
}

final class ActivationDurabilityOrderFake {
	public function get_status(): string {
		return 'processing';
	}

	public function get_items(): array {
		return array( new ActivationDurabilityItemFake() );
	}
}

final class ActivationDurabilityTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ashbi_comment_data']        = array();
		$GLOBALS['ashbi_comment_meta']        = array();
		$GLOBALS['ashbi_actions']             = array();
		$GLOBALS['ashbi_orders']              = array();
		$GLOBALS['ashbi_has_scheduled_action'] = false;
		$GLOBALS['ashbi_comment_insert_result'] = 101;
		$GLOBALS['ashbi_comment_meta_result']   = 1;
	}

	public function test_lifecycle_effect_reports_failure_when_comment_insert_fails(): void {
		$GLOBALS['ashbi_comment_insert_result'] = 0;

		$this->assertFalse( Action::write_comment( 'active', 42 ) );
		$this->assertSame( array(), $GLOBALS['ashbi_actions'] );
	}

	public function test_lifecycle_effect_is_complete_only_after_comment_and_hook(): void {
		$this->assertTrue( Action::write_comment( 'completed', 42 ) );
		$this->assertCount( 2, $GLOBALS['ashbi_comment_meta'] );
		$this->assertSame( 'subscrpt_subscription_completed', $GLOBALS['ashbi_actions'][0][0] );
	}

	public function test_already_queued_trial_completion_remains_pending_until_verified(): void {
		$GLOBALS['ashbi_orders'][42]            = new ActivationDurabilityOrderFake();
		$GLOBALS['ashbi_has_scheduled_action'] = true;

		$reflection = new ReflectionClass( SubscriptionOrder::class );
		$order      = $reflection->newInstanceWithoutConstructor();
		$this->assertNull( $order->maybe_trigger_auto_complete_trial_order( 42, 7 ) );
	}
}

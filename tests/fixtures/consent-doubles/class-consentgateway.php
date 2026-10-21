<?php
/**
 * Offline ConsentGateway double for consent regression tests.
 *
 * @package AshbiSubscriptions\Tests
 */

/** Isolated local test double; no provider requests. */
class ConsentGateway extends SpringDevs\Subscription\Illuminate\Gateways\Stripe\Stripe {
	/** Dispatch count.
	 *
	 * @var int
	 */
	public $dispatches = 0;
	/** Do not register WordPress hooks. */
	public function __construct() {}
	/** Record dispatch only; this is not a live charge or sandbox.
	 *
	 * @param mixed $order Order.
	 * @param int   $subscription_id ID.
	 */
	public function pay_renew_order( $order, int $subscription_id = 0 ) {
		++$this->dispatches; }
}

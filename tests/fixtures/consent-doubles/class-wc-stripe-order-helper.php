<?php
/**
 * Offline WC_Stripe_Order_Helper double for consent regression tests.
 *
 * @package AshbiSubscriptions\Tests
 */

/** Isolated local test double; no provider requests. */
class WC_Stripe_Order_Helper {
	/** Stop when the real payment entry reaches provider work.
	 *
	 * @param mixed $order Order.
	 * @throws RuntimeException Boundary reached.
	 */
	public function validate_minimum_order_amount( $order ) {
		$GLOBALS['fixture_boundary_orders'][] = $order->get_id();
		++$GLOBALS['provider_boundaries'];
		throw new RuntimeException( 'local-provider-boundary' );
	}
}

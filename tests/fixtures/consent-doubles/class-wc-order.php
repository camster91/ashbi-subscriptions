<?php
/**
 * Offline pending order for the real canonical-resume helper.
 *
 * @package AshbiSubscriptions\Tests
 */

/** Saved pending order double; it never contacts a payment provider. */
class WC_Order extends ConsentOrder {
	/** Pending canonical order.
	 *
	 * @param string $status Requested status.
	 * @return bool
	 */
	public function has_status( $status ) {
		return 'pending' === $status;
	}
	/** This offline order still needs payment.
	 *
	 * @return bool
	 */
	public function needs_payment() {
		return true;
	}
}

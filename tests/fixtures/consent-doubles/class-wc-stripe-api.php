<?php
/**
 * Offline WC_Stripe_API double for consent regression tests.
 *
 * @package AshbiSubscriptions\Tests
 */

/** Isolated local test double; no provider requests. */
class WC_Stripe_API {
	/** Record, never send, a lookup.
	 *
	 * @param string $endpoint Endpoint.
	 * @return object
	 */
	public static function retrieve( $endpoint ) {
		$GLOBALS['fixture_endpoints'][] = $endpoint;
		++$GLOBALS['provider_lookups'];
		return (object) array( 'data' => array() );
	}
}

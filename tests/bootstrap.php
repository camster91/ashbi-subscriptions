<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName,Universal.Files.SeparateFunctionsFromOO.Mixed -- Shared PHPUnit bootstrap intentionally hosts test-only WooCommerce fixtures.
/**
 * Unit-test bootstrap.
 *
 * @package AshbiSubscriptions\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	/**
	 * Minimal WooCommerce gateway base for isolated unit tests.
	 */
	class WC_Payment_Gateway {
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * Return an order fixture from the isolated unit harness.
	 *
	 * @param int $order_id Order fixture identifier.
	 * @return mixed Order fixture or false when it is not registered.
	 */
	function wc_get_order( $order_id ) {
		return $GLOBALS['ashbi_orders'][ $order_id ] ?? $GLOBALS['ashbi_counting_orders'][ $order_id ] ?? false;
	}
}

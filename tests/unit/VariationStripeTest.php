<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit path.
/**
 * Stripe force-save must recognize an order line before subscription relations exist.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Illuminate\Gateways\Stripe\Stripe;

/** Exercise order-based payment saving without a cart or current product lookup. */
final class VariationStripeTest extends TestCase {
	/** A plan snapshot forces saving the Stripe payment method for off-session renewals. */
	public function test_plan_order_line_forces_saving_without_cart_or_relation(): void {
		$prior_db = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
		$GLOBALS['wpdb']              = new class() {
			/** Prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';
			/**
			 * Prepared SQL double.
			 *
			 * @param string $sql Query.
			 * @return string
			 */
			public function prepare( $sql ) {
				return $sql; }
			/**
			 * No relation yet.
			 *
			 * @param string $sql Query.
			 * @return array
			 */
			public function get_results( $sql ) {
				return $sql ? array() : array(); }
		};
		$GLOBALS['ashbi_orders'][501] = new class() {
			/** Fabricated mapped order line.
			 *
			 * @return array
			 */
			public function get_items() {
				return array(
					new class() {
						/**
						 * Immutable plan marker.
						 *
						 * @param string $key Key.
						 * @return int
						 */
						public function get_meta( $key ) {
							return '_subscrpt_plan_id' === $key ? 8 : 0; }
					},
				);
			}
		};
		$gateway                      = ( new ReflectionClass( Stripe::class ) )->newInstanceWithoutConstructor();
		try {
			self::assertTrue( $gateway->force_save_payment_method_for_subscriptions( false, 501 ) );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore isolated database double.
			$GLOBALS['wpdb'] = $prior_db;
			unset( $GLOBALS['ashbi_orders'][501] );
		}
	}
}

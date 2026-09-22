<?php
/**
 * Renewal cron and subscription admin regression contracts.
 *
 * @package AshbiSubscriptions
 */

use PHPUnit\Framework\TestCase;

/** Verify renewal-cron and admin-detail safety contracts. */
final class RenewalCronContractTest extends TestCase {

	/** Verify no legitimate due renewals are silently omitted after five rows. */
	public function test_due_renewal_query_is_not_limited_to_wordpress_default_batch(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$cron = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Cron.php' );

		$this->assertIsString( $cron );
		$this->assertStringContainsString( "'posts_per_page' => -1", $cron );
		$this->assertStringContainsString( "'no_found_rows'  => true", $cron );
	}

	/** Verify a deleted or migrated source item cannot fatal the admin details view. */
	public function test_missing_source_order_item_returns_a_safe_empty_info_state(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$subscriptions = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/Subscriptions.php' );

		$this->assertIsString( $subscriptions );
		$this->assertStringContainsString( 'if ( ! $order_item instanceof \\WC_Order_Item_Product )', $subscriptions );
		$this->assertStringContainsString( 'return null;', $subscriptions );
	}

	/** Verify both standard WooCommerce paid states can seed a renewal. */
	public function test_renewal_source_accepts_processing_and_completed_paid_orders(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$helper = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Helper.php' );

		$this->assertIsString( $helper );
		$this->assertStringContainsString( "has_status( array( 'processing', 'completed' ) )", $helper );
	}

	/** Verify a paid processing renewal advances lifecycle effects immediately. */
	public function test_paid_processing_renewals_are_not_skipped_by_lifecycle_handler(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$order = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Order.php' );

		$this->assertIsString( $order );
		$this->assertStringNotContainsString( "'renew' === \$history->type && 'processing' === \$order->get_status()", $order );
		$this->assertStringContainsString( "'renew' === \$history->type && \$order->is_paid()", $order );
	}

	/** Verify paid ordinary renewals use the gateway callback lifecycle fallback. */
	public function test_payment_complete_fallback_handles_ordinary_renewals(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$order = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Order.php' );

		$this->assertIsString( $order );
		$this->assertStringContainsString( "array( \$table, (int) \$order_id, 'early-renew', 'renew' )", $order );
		$this->assertStringContainsString( 'IN (%s, %s)', $order );
	}
}

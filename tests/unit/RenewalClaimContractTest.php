<?php
/**
 * Contract tests for durable renewal claims and payment recovery.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;

/** Verify source contracts for renewal claims and recovery. */
final class RenewalClaimContractTest extends TestCase {
	/** Installer source.
	 *
	 * @var string
	 */
	private string $installer;
	/** Renewal claim source.
	 *
	 * @var string
	 */
	private string $claim;
	/** Renewal helper source.
	 *
	 * @var string
	 */
	private string $helper;
	/** Checkout source.
	 *
	 * @var string
	 */
	private string $checkout;
	/** Plan checkout source.
	 *
	 * @var string
	 */
	private string $plan_checkout;
	/** Action controller source.
	 *
	 * @var string
	 */
	private string $action_controller;
	/** Cart source.
	 *
	 * @var string
	 */
	private string $cart;
	/** Order source.
	 *
	 * @var string
	 */
	private string $order;
	/** Stripe gateway source.
	 *
	 * @var string
	 */
	private string $stripe;
	/** Shared functions source.
	 *
	 * @var string
	 */
	private string $functions;

	/** Load the production source contracts under test. */
	protected function setUp(): void {
		$root                    = dirname( __DIR__, 2 );
		$this->installer         = (string) file_get_contents( $root . '/plugin/includes/Installer.php' );
		$this->claim             = (string) @file_get_contents( $root . '/plugin/includes/Illuminate/RenewalClaim.php' );
		$this->helper            = (string) file_get_contents( $root . '/plugin/includes/Illuminate/Helper.php' );
		$this->checkout          = (string) file_get_contents( $root . '/plugin/includes/Frontend/Checkout.php' );
		$this->plan_checkout     = (string) file_get_contents( $root . '/plugin/includes/Frontend/PlanCheckout.php' );
		$this->action_controller = (string) file_get_contents( $root . '/plugin/includes/Frontend/ActionController.php' );
		$this->cart              = (string) file_get_contents( $root . '/plugin/includes/Frontend/Cart.php' );
		$this->order             = (string) file_get_contents( $root . '/plugin/includes/Illuminate/Order.php' );
		$this->stripe            = (string) file_get_contents( $root . '/plugin/includes/Illuminate/Gateways/Stripe/Stripe.php' );
		$this->functions         = (string) file_get_contents( $root . '/plugin/includes/functions.php' );
	}

	/** Verify the schema has a dedicated atomic subscription-period claim. */
	public function test_schema_has_a_dedicated_atomic_subscription_period_claim(): void {
		$this->assertStringContainsString( 'subscrpt_renewal_claim', $this->installer );
		$this->assertStringContainsString( 'UNIQUE KEY `subscription_period` (`subscription_id`,`period_key`)', $this->installer );
		$this->assertStringContainsString( '`lease_expires_at` DATETIME NOT NULL', $this->installer );
		$this->assertStringContainsString( '`schedule_state` VARCHAR(20) NOT NULL', $this->installer );
		$this->assertStringContainsString( '`schedule_next_attempt` DATETIME NULL', $this->installer );
		$this->assertStringContainsString( '`payment_state` VARCHAR(20) NOT NULL', $this->installer );
		$this->assertStringContainsString( '`payment_next_attempt` DATETIME NULL', $this->installer );
		$this->assertStringContainsString( "const DB_VERSION = '1.5.0'", $this->installer );
	}

	/** Verify automated and checkout renewals share the claim boundary. */
	public function test_automated_and_checkout_renewals_share_the_claim_boundary(): void {
		$this->assertStringContainsString( 'RenewalClaim::acquire( (int) $subscription_id, 0, $period_anchor )', $this->helper );
		$this->assertStringContainsString( 'RenewalClaim::finalize', $this->helper );
		$this->assertStringContainsString( 'RenewalClaim::acquire( (int) $subscription_id, 0, $period_anchor )', $this->helper );
		$this->assertStringContainsString( 'if ( ! Helper::process_order_renewal', $this->checkout );
		$this->assertStringContainsString( 'resolve_checkout_renewal_subscription', $this->checkout );
		$this->assertStringContainsString( 'Helper::process_order_renewal', $this->plan_checkout );
		$this->assertStringContainsString( "array( 'renew_subscrpt' => (int) \$subscrpt_id )", $this->action_controller );
		$this->assertStringContainsString( "\$cart_item_data['renew_subscrpt'] = (int) \$expired", $this->cart );
		$this->assertStringContainsString( '$current_anchor !== $period_anchor', $this->helper );
	}

	/** Verify upgrades backfill and quarantine open legacy renewal orders. */
	public function test_upgrade_backfills_and_quarantines_open_legacy_renewal_orders(): void {
		$this->assertStringContainsString( 'backfill_open_renewal_claims', $this->installer );
		$this->assertStringContainsString( "array( 'pending', 'failed', 'on-hold' )", $this->installer );
		$this->assertStringContainsString( 'RenewalClaim::claim_order', $this->installer );
		$this->assertStringContainsString( '_subscrpt_renewal_quarantined', $this->installer );
		$this->assertStringContainsString( '_subscrpt_stripe_renewal_customer', $this->installer );
		$this->assertStringContainsString( "0 !== strpos( \$stripe_intent, 'pi_' )", $this->installer );
		$this->assertStringContainsString( '$order->save_meta_data();', $this->installer );
		$this->assertStringContainsString( '$open_order->save_meta_data();', $this->installer );
		$this->assertStringNotContainsString( '$open_order->save();', $this->installer );
		$this->assertStringContainsString( "get_meta( '_subscrpt_renewal_quarantined' )", $this->order );
		$this->assertStringContainsString( 'woocommerce_order_needs_payment', $this->order );
		$this->assertStringContainsString( 'backfill_overdue_renewal_quarantine', $this->installer );
		$this->assertStringContainsString( 'subscrpt_overdue_renewal_quarantine_1', $this->installer );
		$this->assertStringContainsString( 'subscrpt_renewal_is_migration_blocked', $this->helper );
		$this->assertStringContainsString( 'function subscrpt_renewal_is_migration_blocked', $this->functions );
	}

	/** Verify overdue quarantine binds its metadata and timestamp once. */
	public function test_overdue_quarantine_binds_the_meta_key_post_type_and_timestamp_once(): void {
		$query_start = strpos( $this->installer, 'AND CAST(next_date.meta_value AS UNSIGNED) <= %d' );
		$this->assertNotFalse( $query_start );

		$query_tail = substr( $this->installer, (int) $query_start, 420 );
		$this->assertStringContainsString(
			"'_subscrpt_next_date',\n\t\t\t\t'subscrpt_order',\n\t\t\t\ttime()",
			$query_tail
		);
		$this->assertStringNotContainsString(
			"'_subscrpt_next_date',\n\t\t\t\t'_subscrpt_next_date',",
			$query_tail
		);
	}

	/** Verify Stripe rejects an order outside the period claim. */
	public function test_stripe_rejects_an_order_outside_the_period_claim(): void {
		$this->assertStringContainsString( 'RenewalClaim::is_claimed_order', $this->stripe );
		$this->assertStringContainsString( 'Renewal order is not the canonical order for its subscription period.', $this->stripe );
		$this->assertStringContainsString( 'wc_stripe_idempotency_key', $this->stripe );
		$this->assertStringContainsString( 'ashbi_renewal_claim', $this->stripe );
		$this->assertStringContainsString( 'ashbi_renewal_identity', $this->stripe );
		$this->assertStringContainsString( 'find_existing_renewal_intent', $this->stripe );
		$this->assertStringContainsString( 'prepare_renewal_dispatch_identity', $this->stripe );
		$this->assertStringContainsString( '_subscrpt_stripe_renewal_customer', $this->stripe );
		$this->assertStringContainsString( "get_meta( '_stripe_intent_id' )", $this->stripe );
		$this->assertStringNotContainsString( 'get_stripe_customer_id', $this->stripe );
		$this->assertStringNotContainsString( 'get_stripe_source_id', $this->stripe );
		$this->assertStringContainsString( 'mark_payment_pending', $this->stripe );
		$this->assertStringContainsString( 'due_payment_retries', $this->stripe );
		$this->assertStringContainsString( "'ashbi-renewal-' . \$identity", $this->stripe );
	}

	/** Verify claims use atomic inserts and bounded lease takeover. */
	public function test_claim_uses_atomic_insert_and_bounded_lease_takeover(): void {
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $this->claim );
		$this->assertStringContainsString( 'lease_expires_at < UTC_TIMESTAMP()', $this->claim );
		$this->assertStringContainsString( "get_post_meta( \$subscription_id, '_subscrpt_next_date', true )", $this->claim );
		$this->assertStringContainsString( 'SELECT GET_LOCK(%s, 5)', $this->order );
		$this->assertStringContainsString( 'false === $inserted', $this->helper );
	}

	/** Verify recovered orders resume payment and failed schedule writes retry. */
	public function test_recovered_orders_resume_payment_and_failed_schedule_writes_retry(): void {
		$this->assertStringContainsString( 'resume_canonical_renewal_order', $this->helper );
		$this->assertStringContainsString( 'ashbi_subscrpt_dispatch_', $this->helper );
		$this->assertStringContainsString( "has_status( 'pending' )", $this->helper );
		$this->assertStringContainsString( 'subscrpt_retry_subscription_schedule', $this->order );
		$this->assertStringContainsString( 'as_schedule_single_action', $this->order );
		$this->assertStringContainsString( 'wp_schedule_single_event', $this->order );
		$this->assertStringContainsString( 'mark_schedule_pending', $this->order );
		$this->assertStringContainsString( 'due_schedule_repairs', $this->order );
		$this->assertStringContainsString( '_subscrpt_next_date_set_by_order', $this->order );
	}

	/** Verify uncertain Stripe errors remain recoverable and honor backoff. */
	public function test_uncertain_stripe_errors_remain_recoverable_and_retries_honor_backoff(): void {
		$catch_start = strpos( $this->stripe, '} catch ( \\WC_Stripe_Exception $e ) {' );
		$this->assertNotFalse( $catch_start );
		$catch = substr( $this->stripe, (int) $catch_start, 1800 );

		$this->assertStringContainsString( 'schedule_renewal_payment_retry', $catch );
		$this->assertStringNotContainsString( 'mark_payment_complete', $catch );
		$this->assertStringContainsString( 'payment_next_attempt', $this->stripe );
		$this->assertStringContainsString( '$next_attempt > time()', $this->stripe );
		$this->assertStringContainsString( 'schedule_next_attempt', $this->order );
		$this->assertStringContainsString( '$next_attempt > time()', $this->order );
	}

	/** Verify installment limits count only paid orders without side effects. */
	public function test_installment_limit_is_pure_and_counts_only_paid_orders(): void {
		$count_start = strpos( $this->functions, 'function subscrpt_count_payments_made' );
		$limit_start = strpos( $this->functions, 'function subscrpt_is_max_payments_reached' );
		$final_start = strpos( $this->functions, 'function subscrpt_finalize_split_payment_completion' );
		$this->assertNotFalse( $count_start );
		$this->assertNotFalse( $limit_start );
		$this->assertNotFalse( $final_start );

		$count = substr( $this->functions, (int) $count_start, (int) $limit_start - (int) $count_start );
		$limit = substr( $this->functions, (int) $limit_start, (int) $final_start - (int) $limit_start );
		$this->assertStringContainsString( '$order->is_paid()', $count );
		$this->assertStringNotContainsString( "'on-hold'", $count );
		$this->assertStringNotContainsString( 'wp_update_post', $limit );
		$this->assertStringNotContainsString( 'do_action', $limit );
		$this->assertStringContainsString( 'subscrpt_finalize_split_payment_completion', $this->order );
	}

	/** Verify activation effects are marked only after the effect runs. */
	public function test_activation_effects_are_never_marked_before_the_effect_runs(): void {
		$this->assertStringNotContainsString( '_subscrpt_renewal_pro_count_started', $this->order );
		$this->assertStringNotContainsString( '_subscrpt_renewal_activity_note_started', $this->order );
		$this->assertStringNotContainsString( '_subscrpt_renewal_comment_started', $this->order );
		$this->assertStringContainsString( '_subscrpt_renewal_activity_note_done', $this->order );
		$this->assertStringContainsString( '_subscrpt_renewal_comment_done', $this->order );
	}

	/** Verify terminal subscription states fail closed for payment requirements. */
	public function test_subscription_payment_requirement_fails_closed_for_terminal_states(): void {
		$this->assertStringContainsString( '$status = self::get_subscription_status( (int) $subscription_id );', $this->helper );
		$this->assertStringContainsString( "if ( ! is_string( \$status ) || '' === \$status ) {", $this->helper );
		$this->assertStringContainsString(
			"return ! in_array( \$status, array( 'cancelled', 'completed', 'trash', 'draft' ), true );",
			$this->helper
		);
		$this->assertStringNotContainsString( 'return true; // Always true for now', $this->helper );
	}
}

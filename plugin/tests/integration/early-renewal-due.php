<?php
/**
 * Unpaid early invoices crossing their billing date in real WooCommerce.
 *
 * @package AshbiSubscriptions\Tests
 */

/**
 * Advance only fabricated fixture dates; never operate on store subscriptions.
 *
 * @param callable $check Assertion collector.
 * @param int      $subscription_id Fabricated subscription.
 * @param WC_Order $early_order Fabricated unpaid early invoice.
 */
function ashbi_check_early_renewal_due( callable $check, int $subscription_id, WC_Order $early_order ): void {
	global $wpdb;
	$anchor = time() - 60;
	update_post_meta( $subscription_id, '_subscrpt_next_date', $anchor );
	update_post_meta( $subscription_id, '_subscrpt_early_renewal_anchor', $anchor );
	$early_order->update_meta_data( '_subscrpt_early_renewal_anchor', $anchor );
	$early_order->update_meta_data( '_subscrpt_renewal_period_key', 'early-' . $subscription_id . '-' . $anchor );
	$early_order->save();
	$total       = $early_order->get_total();
	$period_key  = $early_order->get_meta( '_subscrpt_renewal_period_key' );
	$helper      = \SpringDevs\Subscription\Illuminate\Helper::class;
	$claim_class = \SpringDevs\Subscription\Illuminate\RenewalClaim::class;
	$duplicate   = wc_create_order();
	$duplicate->update_meta_data( '_subscrpt_early_renewal_anchor', $anchor );
	$duplicate->save();
	$wpdb->insert(
		$wpdb->prefix . 'subscrpt_order_relation',
		array(
			'subscription_id' => $subscription_id,
			'order_id'        => $duplicate->get_id(),
			'order_item_id'   => 0,
			'type'            => 'early-renew',
		)
	);
	$duplicate_relation = $wpdb->insert_id;
	$check( false === $helper::create_renewal_order( $subscription_id ), 'Ambiguous early invoices did not stop due-period creation.' );
	$wpdb->delete( $wpdb->prefix . 'subscrpt_order_relation', array( 'id' => $duplicate_relation ), array( '%d' ) );
	$claim_class::claim_order( $subscription_id, $duplicate->get_id(), $anchor );
	$check( false === $helper::create_renewal_order( $subscription_id ), 'A competing regular claim allowed an early invoice to dispatch.' );
	$wpdb->delete( $wpdb->prefix . 'subscrpt_renewal_claim', array( 'subscription_id' => $subscription_id ), array( '%d' ) );
	$duplicate->delete( true );
	$unavailable = static function ( $order, $source, $id ) use ( $subscription_id ) {
		if ( $subscription_id === (int) $id ) {
			throw new RuntimeException( 'fabricated-early-provider-unavailable' );
		}
	};
	add_action( 'subscrpt_after_create_renew_order', $unavailable, -999, 3 );
	$outage = false;
	try {
		$helper::create_renewal_order( $subscription_id );
	} catch ( RuntimeException $error ) {
		$outage = 'fabricated-early-provider-unavailable' === $error->getMessage();
	} finally {
		remove_action( 'subscrpt_after_create_renew_order', $unavailable, -999 );
	}
	$check( $outage && $claim_class::is_claimed_order( $subscription_id, $early_order->get_id() ), 'A dispatch outage lost the canonical early invoice.' );
	$due_order = $helper::create_renewal_order( $subscription_id );
	$check( $due_order instanceof WC_Order && $due_order->get_id() === $early_order->get_id(), 'Due renewal created a second invoice instead of reusing the unpaid early invoice.' );
	$check( wc_get_order( $early_order->get_id() )->get_total() === $total && wc_get_order( $early_order->get_id() )->get_meta( '_subscrpt_renewal_period_key' ) === $period_key, 'Due renewal changed the existing early invoice amount or payment identity.' );
	$check( $claim_class::is_claimed_order( $subscription_id, $early_order->get_id() ), 'The early invoice did not become the canonical due-period order.' );
	foreach ( array( 'pending', 'failed', 'on-hold' ) as $status ) {
		$early_order = wc_get_order( $early_order->get_id() );
		$early_order->set_status( $status );
		$early_order->save();
		$repeated = $helper::create_renewal_order( $subscription_id );
		$check( $repeated instanceof WC_Order && $repeated->get_id() === $early_order->get_id(), 'A repeated due job replaced the ' . $status . ' early invoice.' );
		$phase = $wpdb->get_var( $wpdb->prepare( 'SELECT payment_state FROM %i WHERE subscription_id = %d AND order_id = %d', $wpdb->prefix . 'subscrpt_renewal_claim', $subscription_id, $early_order->get_id() ) );
		$check( 'complete' !== $phase, 'An unpaid ' . $status . ' invoice was incorrectly marked paid.' );
	}
	wp_update_post(
		array(
			'ID'          => $subscription_id,
			'post_status' => 'expired',
		)
	);
	$early_order = wc_get_order( $early_order->get_id() );
	$early_order->payment_complete( 'fabricated-local-early-due-payment' );
	$next_date = (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
	$check( 'active' === get_post_status( $subscription_id ) && $next_date > time(), 'Payment of the due early invoice did not restore expired access and advance its date.' );
	$check( $early_order->get_id() === (int) get_post_meta( $subscription_id, '_subscrpt_next_date_set_by_order', true ), 'The early due payment did not persist the schedule replay marker.' );
	do_action( 'woocommerce_payment_complete', $early_order->get_id() );
	do_action( 'subscrpt_subscription_activated', $subscription_id );
	$check( (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true ) === $next_date, 'Repeated payment or activation advanced the early due invoice twice.' );
	$relation_type = $wpdb->get_var( $wpdb->prepare( 'SELECT type FROM %i WHERE subscription_id = %d AND order_id = %d LIMIT 1', $wpdb->prefix . 'subscrpt_order_relation', $subscription_id, $early_order->get_id() ) );
	$check( 'early-renew' === $relation_type, 'Reusing the invoice rewrote its historical relation type.' );
	$early_order = wc_get_order( $early_order->get_id() );
	$early_order->update_status( 'completed' );
	ashbi_check_paid_early_invoice_due( $check, $subscription_id, $early_order );
	$actor = get_current_user_id();
	wp_set_current_user( 1 );
	$cancellation = \SpringDevs\Subscription\Illuminate\CancellationEvidence::request_admin( $subscription_id, get_current_user_id(), 'fabricated-early-due-cancel', 'cancelled' );
	wp_set_current_user( $actor );
	$check( ! empty( $cancellation['barrier'] ) && false === $helper::create_renewal_order( $subscription_id ) && false === $helper::create_early_renewal_order( $subscription_id ), 'Cancellation did not block renewal after an early invoice was reused.' );
	if ( $due_order instanceof WC_Order && $due_order->get_id() !== $early_order->get_id() ) {
		$due_order->delete( true );
	}
	$wpdb->delete( $wpdb->prefix . 'subscrpt_renewal_claim', array( 'subscription_id' => $subscription_id ), array( '%d' ) );
	$wpdb->delete( $wpdb->prefix . 'subscrpt_cancellation_barrier', array( 'subscription_id' => $subscription_id ), array( '%d' ) );
}

/**
 * Recover a late payment received just before the due worker claims its invoice.
 *
 * @param callable $check Assertion collector.
 * @param int      $source_subscription Fabricated source subscription.
 * @param WC_Order $source Fabricated completed source order.
 * @throws RuntimeException When a fabricated early invoice cannot be created.
 */
function ashbi_check_paid_early_invoice_due( callable $check, int $source_subscription, WC_Order $source ): void {
	global $wpdb;
	$subscription_id = wp_insert_post(
		array(
			'post_type'   => 'subscrpt_order',
			'post_status' => 'active',
			'post_author' => get_current_user_id(),
			'post_title'  => 'Fabricated late early-invoice payment',
		)
	);
	$order           = null;
	try {
		foreach ( array( '_subscrpt_product_id', '_subscrpt_variation_id', '_subscrpt_price', '_subscrpt_order_id', '_subscrpt_order_item_id' ) as $key ) {
			update_post_meta( $subscription_id, $key, get_post_meta( $source_subscription, $key, true ) );
		}
		update_post_meta( $subscription_id, '_subscrpt_next_date', time() + DAY_IN_SECONDS );
		$wpdb->insert(
			$wpdb->prefix . 'subscrpt_order_relation',
			array(
				'subscription_id' => $subscription_id,
				'order_id'        => $source->get_id(),
				'order_item_id'   => (int) get_post_meta( $source_subscription, '_subscrpt_order_item_id', true ),
				'type'            => 'new',
			)
		);
		$helper = \SpringDevs\Subscription\Illuminate\Helper::class;
		$order  = $helper::create_early_renewal_order( $subscription_id );
		if ( ! $order instanceof WC_Order ) {
			throw new RuntimeException( 'Could not create fabricated late-payment early invoice.' );
		}
		$anchor = time() - 60;
		update_post_meta( $subscription_id, '_subscrpt_next_date', $anchor );
		$order->update_meta_data( '_subscrpt_early_renewal_anchor', $anchor );
		$order->update_meta_data( '_subscrpt_renewal_period_key', 'early-' . $subscription_id . '-' . $anchor );
		$order->save();
		wp_update_post(
			array(
				'ID'          => $subscription_id,
				'post_status' => 'expired',
			)
		);
		$order->payment_complete( 'fabricated-local-payment-before-due-worker' );
		$check( 'expired' === get_post_status( $subscription_id ) && wc_get_order( $order->get_id() )->is_paid(), 'The paid-before-claim fixture did not reach its crash-window state.' );
		$recovered = $helper::create_renewal_order( $subscription_id );
		$check( $recovered instanceof WC_Order && $recovered->get_id() === $order->get_id() && 'active' === get_post_status( $subscription_id ) && (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true ) > time(), 'An already-paid early invoice was charged again or left expired after claiming.' );
	} finally {
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
		$wpdb->delete( $wpdb->prefix . 'subscrpt_order_relation', array( 'subscription_id' => $subscription_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'subscrpt_renewal_claim', array( 'subscription_id' => $subscription_id ), array( '%d' ) );
		wp_delete_post( $subscription_id, true );
	}
}

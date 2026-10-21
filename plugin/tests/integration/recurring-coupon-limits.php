<?php
/**
 * Real WooCommerce coupon limits and immutable pending invoice checks.
 *
 * @package AshbiSubscriptions\Tests
 */

/**
 * Exercise additional coupon history within the guarded source integration harness.
 *
 * @param callable              $check Assertion collector.
 * @param int                   $subscription_id Fabricated subscription.
 * @param WC_Order              $source Completed source order.
 * @param WC_Order_Item_Product $source_item Original discounted item.
 */
function ashbi_check_recurring_coupon_limits( callable $check, int $subscription_id, WC_Order $source, WC_Order_Item_Product $source_item ): void {
	global $wpdb;
	$helper = \SpringDevs\Subscription\Illuminate\Helper::class;
	$orders = array();
	$rows   = array();
	$price  = get_post_meta( $subscription_id, '_subscrpt_price', true );
	$link   = static function ( $order_id, $type ) use ( $wpdb, $subscription_id, &$rows ) {
		$wpdb->insert(
			$wpdb->prefix . 'subscrpt_order_relation',
			array(
				'subscription_id' => $subscription_id,
				'order_id'        => $order_id,
				'order_item_id'   => 0,
				'type'            => $type,
			)
		);
		$rows[] = $wpdb->insert_id;
	};
	$build  = static function ( $amount ) use ( $helper, $source, $source_item, $subscription_id, &$orders ) {
		$data  = $helper::create_new_order_for_renewal(
			$source,
			$source_item,
			array(
				'subtotal' => $amount,
				'total'    => $amount,
			),
			$subscription_id
		);
		$order = is_array( $data ) ? $data['order'] : null;
		if ( ! $order instanceof WC_Order ) {
			throw new RuntimeException( 'Fabricated coupon renewal could not be created.' );
		}
		$orders[] = $order;
		$order->calculate_totals( false );
		$order->save();
		return $order;
	};
	try {
		$link( $source->get_id(), 'renew' );
		$unpaid = array();
		foreach ( array( 'pending', 'failed', 'refunded' ) as $status ) {
			$order    = wc_create_order();
			$orders[] = $order;
			$order->set_status( $status );
			$order->save();
			$unpaid[ $status ] = $order;
			$link( $order->get_id(), 'pending' === $status ? 'early-renew' : 'renew' );
		}
		$discount = $helper::get_subscription_recurring_discount( $subscription_id, $source, $source_item );
		$check( 1 === subscrpt_count_payments_made( $subscription_id ) && 5.0 === $discount['amount'] && ! $discount['exhausted'], 'Unpaid or duplicate orders exhausted the recurring discount in the account price.' );
		$invoice = $build( 39 );
		$link( $invoice->get_id(), 'early-renew' );
		$check( 34.0 === (float) $invoice->get_total() && 1 === count( $invoice->get_items( 'coupon' ) ), 'Unpaid history removed the promised coupon from a new renewal.' );
		update_post_meta( $subscription_id, '_subscrpt_price', 78 );
		$changed_price = $helper::get_subscription_recurring_discount( $subscription_id, $source, $source_item );
		$check( 10.0 === $changed_price['amount'], 'Request cache hid a changed recurring price from the discount display.' );
		$repriced = $build( 78 );
		$check( 68.0 === (float) $repriced->get_total(), 'New renewal did not use the displayed proportional discount after a price change.' );
		update_post_meta( $subscription_id, '_subscrpt_price', $price );
		$unpaid['pending']->set_status( 'processing' );
		$unpaid['pending']->save();
		$unpaid['failed']->set_status( 'completed' );
		$unpaid['failed']->save();
		$exhausted = $helper::get_subscription_recurring_discount( $subscription_id, $source, $source_item );
		$check( 3 === subscrpt_count_payments_made( $subscription_id ) && 0.0 === $exhausted['amount'] && $exhausted['exhausted'], 'Discount display stayed eligible after the successful-payment limit.' );
		$full_price = $build( 39 );
		$check( 39.0 === (float) $full_price->get_total() && ! $full_price->get_items( 'coupon' ), 'Renewal exceeded the finite coupon payment limit.' );
		$saved_invoice = wc_get_order( $invoice->get_id() );
		$check( 'pending' === $saved_invoice->get_status() && 34.0 === (float) $saved_invoice->get_total() && 1 === count( $saved_invoice->get_items( 'coupon' ) ), 'Price/payment changes rewrote an existing pending invoice.' );
	} finally {
		update_post_meta( $subscription_id, '_subscrpt_price', $price );
		foreach ( $rows as $row_id ) {
			$wpdb->delete( $wpdb->prefix . 'subscrpt_order_relation', array( 'id' => $row_id ), array( '%d' ) );
		}
		foreach ( $orders as $order ) {
			$order->delete( true );
		}
	}
}

<?php
/** Shared, read-only evidence collection for overdue disposition tools. */

use Ashbi\Subscriptions\Tools\OverdueDispositionPlan;

/**
 * @return array<string,mixed>|null
 */
function ashbi_overdue_evidence_record( int $subscription_id, int $now ): ?array {
	global $wpdb;
	$post = get_post( $subscription_id );
	if ( ! $post || 'subscrpt_order' !== $post->post_type ) {
		return null;
	}

	$next_date      = (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
	$relation_table = $wpdb->prefix . 'subscrpt_order_relation';
	$table_exists   = $relation_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relation_table ) );
	$order_refs     = array();
	if ( $table_exists ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed, prefixed table name.
		$relations = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, type FROM {$relation_table} WHERE subscription_id = %d ORDER BY id DESC", $subscription_id ), ARRAY_A );
		foreach ( (array) $relations as $relation ) {
			$order_id = (int) $relation['order_id'];
			if ( $order_id > 0 ) {
				$order_refs[ $order_id ] = (string) $relation['type'];
			}
		}
	}
	$parent_order_id = (int) get_post_meta( $subscription_id, '_subscrpt_order_id', true );
	if ( $parent_order_id > 0 && ! isset( $order_refs[ $parent_order_id ] ) ) {
		$order_refs[ $parent_order_id ] = 'new';
	}

	$latest_paid = null;
	$open_orders = array();
	foreach ( $order_refs as $order_id => $relation_type ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			continue;
		}
		if ( in_array( $relation_type, array( 'renew', 'early-renew' ), true ) && ! $order->is_paid() ) {
			$open_orders[] = array( 'order_id' => (int) $order_id, 'status' => (string) $order->get_status() );
		}
		if ( ! $order->is_paid() ) {
			continue;
		}
		$date_paid = $order->get_date_paid();
		$paid_at   = $date_paid ? (int) $date_paid->getTimestamp() : 0;
		if ( null === $latest_paid || $paid_at >= $latest_paid['timestamp'] ) {
			$latest_paid = array( 'order_id' => (int) $order_id, 'timestamp' => $paid_at, 'gateway' => (string) $order->get_payment_method() );
		}
	}
	usort( $open_orders, static function ( array $left, array $right ): int { return $left['order_id'] <=> $right['order_id']; } );

	$order_item_id = (int) get_post_meta( $subscription_id, '_subscrpt_order_item_id', true );
	$item_meta     = $order_item_id > 0 ? wc_get_order_item_meta( $order_item_id, '_subscrpt_meta' ) : null;
	$interval      = is_array( $item_meta ) ? (int) ( $item_meta['time'] ?? 0 ) : 0;
	$period        = is_array( $item_meta ) ? rtrim( strtolower( (string) ( $item_meta['type'] ?? '' ) ), 's' ) : '';
	$recurrence    = $interval > 0 && in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ? array( 'interval' => $interval, 'period' => $period ) : null;
	$auto_renew    = get_post_meta( $subscription_id, '_subscrpt_auto_renew', true );
	$record        = array(
		'subscription_id'       => $subscription_id,
		'status'                => (string) $post->post_status,
		'next_date_utc'         => $next_date > 0 ? gmdate( 'c', $next_date ) : null,
		'overdue_days'          => $next_date > 0 ? max( 0, (int) floor( ( $now - $next_date ) / DAY_IN_SECONDS ) ) : null,
		'auto_renew'            => '' === (string) $auto_renew ? 'unset' : ( in_array( $auto_renew, array( 1, '1', 'yes', true ), true ) ? 'enabled' : 'disabled' ),
		'payment_failure_count' => (int) get_post_meta( $subscription_id, '_subscrpt_payment_failure_count', true ),
		'latest_paid_order_id'  => $latest_paid ? $latest_paid['order_id'] : null,
		'latest_paid_date_utc'  => $latest_paid && $latest_paid['timestamp'] > 0 ? gmdate( 'c', $latest_paid['timestamp'] ) : null,
		'latest_paid_gateway'   => $latest_paid ? $latest_paid['gateway'] : null,
		'open_renewal_orders'   => $open_orders,
		'recurrence'            => $recurrence,
		'max_payments_reached'  => function_exists( 'subscrpt_is_max_payments_reached' ) && subscrpt_is_max_payments_reached( $subscription_id ),
		'operator_disposition'  => null,
		'operator_note'         => null,
	);
	$record['evidence_checksum'] = OverdueDispositionPlan::evidence_checksum( $record );
	return $record;
}

/** @return array<int,array<string,mixed>> */
function ashbi_overdue_evidence_records( int $now ): array {
	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} next_date ON next_date.post_id = p.ID AND next_date.meta_key = %s WHERE p.post_type = %s AND p.post_status IN ('active','pe_cancelled') AND CAST(next_date.meta_value AS UNSIGNED) <= %d ORDER BY CAST(next_date.meta_value AS UNSIGNED) ASC, p.ID ASC",
			'_subscrpt_next_date', 'subscrpt_order', $now
		)
	);
	$records = array();
	foreach ( (array) $ids as $id ) {
		$record = ashbi_overdue_evidence_record( (int) $id, $now );
		if ( null !== $record ) {
			$records[] = $record;
		}
	}
	return $records;
}

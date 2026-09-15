<?php
/**
 * Read-only, server-local worksheet for overdue renewal disposition.
 *
 * Usage:
 *   wp eval-file tools/overdue-disposition-worksheet.php > /secure/path/worksheet.json
 *
 * The output intentionally contains subscription and order IDs so an operator
 * can reconcile each record. Keep it on the authorized server, restrict its
 * permissions, and do not commit or attach it to tickets. It never emits names,
 * email addresses, postal details, token values, gateway secrets, or order
 * contents. The script performs no writes and never calls a payment gateway.
 *
 * @package AshbiSubscriptions
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run this file with WP-CLI.\n" );
	exit( 1 );
}

global $wpdb;

$now            = time();
$relation_table = $wpdb->prefix . 'subscrpt_order_relation';
$table_exists   = $relation_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relation_table ) );
$subscriptions  = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT p.ID, p.post_status, CAST(next_date.meta_value AS UNSIGNED) AS next_date
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} next_date
		   ON next_date.post_id = p.ID AND next_date.meta_key = %s
		 WHERE p.post_type = %s
		   AND p.post_status IN ('active','pe_cancelled')
		   AND CAST(next_date.meta_value AS UNSIGNED) <= %d
		 ORDER BY next_date ASC, p.ID ASC",
		'_subscrpt_next_date',
		'subscrpt_order',
		$now
	),
	ARRAY_A
);

$records = array();
foreach ( (array) $subscriptions as $subscription ) {
	$subscription_id = (int) $subscription['ID'];
	$order_refs       = array();
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
			$open_orders[] = array(
				'order_id' => (int) $order_id,
				'status'   => (string) $order->get_status(),
			);
		}
		if ( ! $order->is_paid() ) {
			continue;
		}
		$date_paid = $order->get_date_paid();
		$paid_at   = $date_paid ? (int) $date_paid->getTimestamp() : 0;
		if ( null === $latest_paid || $paid_at >= $latest_paid['timestamp'] ) {
			$latest_paid = array(
				'order_id'  => (int) $order_id,
				'timestamp' => $paid_at,
				'gateway'   => (string) $order->get_payment_method(),
			);
		}
	}

	$auto_renew = get_post_meta( $subscription_id, '_subscrpt_auto_renew', true );
	$record     = array(
		'subscription_id'        => $subscription_id,
		'status'                 => (string) $subscription['post_status'],
		'next_date_utc'          => gmdate( 'c', (int) $subscription['next_date'] ),
		'overdue_days'           => max( 0, (int) floor( ( $now - (int) $subscription['next_date'] ) / DAY_IN_SECONDS ) ),
		'auto_renew'             => '' === (string) $auto_renew ? 'unset' : ( in_array( $auto_renew, array( 1, '1', 'yes', true ), true ) ? 'enabled' : 'disabled' ),
		'payment_failure_count'  => (int) get_post_meta( $subscription_id, '_subscrpt_payment_failure_count', true ),
		'latest_paid_order_id'   => $latest_paid ? $latest_paid['order_id'] : null,
		'latest_paid_date_utc'   => $latest_paid && $latest_paid['timestamp'] > 0 ? gmdate( 'c', $latest_paid['timestamp'] ) : null,
		'latest_paid_gateway'    => $latest_paid ? $latest_paid['gateway'] : null,
		'open_renewal_orders'    => $open_orders,
		'operator_disposition'   => null,
		'operator_note'          => null,
	);
	$record['evidence_checksum'] = hash( 'sha256', wp_json_encode( $record ) );
	$records[]                   = $record;
}

$worksheet = array(
	'schema_version' => 1,
	'generated_at'   => gmdate( 'c', $now ),
	'site_url'       => site_url(),
	'record_count'   => count( $records ),
	'allowed_dispositions' => array(
		'advance_without_charge',
		'controlled_retry',
		'manual_recovery',
		'cancel',
		'retain_for_investigation',
	),
	'records'        => $records,
);

WP_CLI::line( wp_json_encode( $worksheet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

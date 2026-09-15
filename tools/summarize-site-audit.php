<?php
/**
 * Reduce a site-audit JSON document to its fleet-safe reconciliation fields.
 *
 * Usage: php tools/summarize-site-audit.php < site-audit.json
 *
 * @package AshbiSubscriptions
 */

$raw   = stream_get_contents( STDIN );
$start = strpos( $raw, '{' );
$data  = false === $start ? null : json_decode( substr( $raw, $start ), true );

if ( ! is_array( $data ) ) {
	fwrite( STDERR, "Invalid site-audit JSON.\n" );
	exit( 1 );
}

if ( 2 !== (int) ( $data['schema_version'] ?? 0 ) ) {
	fwrite( STDERR, "Unsupported site-audit schema; expected version 2.\n" );
	exit( 1 );
}

/**
 * Project a count map onto a fixed allowlist.
 *
 * Unexpected keys are either discarded or combined into a non-identifying
 * fallback bucket. This prevents record-level or operator-injected fields from
 * crossing the fleet-summary boundary.
 *
 * @param mixed       $input Raw bucket map.
 * @param string[]    $allowed Allowed bucket names.
 * @param string|null $fallback Optional aggregate bucket for unknown keys.
 * @return array<string,int>
 */
function ashbi_project_audit_buckets( $input, $allowed, $fallback = null ) {
	$output = array_fill_keys( $allowed, 0 );
	if ( null !== $fallback ) {
		$output[ $fallback ] = 0;
	}

	foreach ( is_array( $input ) ? $input : array() as $key => $value ) {
		$count = max( 0, (int) $value );
		if ( in_array( (string) $key, $allowed, true ) ) {
			$output[ (string) $key ] += $count;
		} elseif ( null !== $fallback ) {
			$output[ $fallback ] += $count;
		}
	}

	return $output;
}

$reconciliation = isset( $data['subscriptions']['overdue_reconciliation'] ) && is_array( $data['subscriptions']['overdue_reconciliation'] )
	? $data['subscriptions']['overdue_reconciliation']
	: array();

$age_buckets = array( '0_7_days', '8_30_days', '31_90_days', '91_365_days', 'over_365_days' );
$safe_reconciliation = array(
	'total'                       => max( 0, (int) ( $reconciliation['total'] ?? 0 ) ),
	'due_age_buckets'             => ashbi_project_audit_buckets( $reconciliation['due_age_buckets'] ?? array(), $age_buckets ),
	'auto_renew'                  => ashbi_project_audit_buckets( $reconciliation['auto_renew'] ?? array(), array( 'enabled', 'disabled', 'unset' ) ),
	'payment_failure_history'     => ashbi_project_audit_buckets( $reconciliation['payment_failure_history'] ?? array(), array( 'recorded', 'none_recorded' ) ),
	'latest_paid_gateway'         => ashbi_project_audit_buckets(
		$reconciliation['latest_paid_gateway'] ?? array(),
		array( 'stripe', 'stripe_ideal', 'stripe_sepa', 'sepa_debit', 'stripe_bancontact', 'stripe_klarna', 'stripe_affirm', 'yith-stripe', 'paypal', 'ppec_paypal', 'no_paid_order' ),
		'other'
	),
	'last_paid_age_buckets'       => ashbi_project_audit_buckets( $reconciliation['last_paid_age_buckets'] ?? array(), array_merge( $age_buckets, array( 'no_paid_order' ) ) ),
	'open_renewal_order_statuses' => ashbi_project_audit_buckets(
		$reconciliation['open_renewal_order_statuses'] ?? array(),
		array( 'pending', 'failed', 'on-hold', 'cancelled', 'refunded', 'checkout-draft' ),
		'other'
	),
);

$summary = array(
	'schema_version'         => 2,
	'site'                   => isset( $data['site']['url'] ) ? (string) $data['site']['url'] : 'unknown',
	'overdue_active'         => isset( $data['subscriptions']['overdue_active'] ) ? (int) $data['subscriptions']['overdue_active'] : 0,
	'overdue_reconciliation' => $safe_reconciliation,
);

fwrite( STDOUT, json_encode( $summary, JSON_UNESCAPED_SLASHES ) . PHP_EOL );

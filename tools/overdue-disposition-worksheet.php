<?php
/**
 * Read-only, server-local worksheet for overdue renewal disposition.
 *
 * Usage:
 *   wp eval-file tools/overdue-disposition-worksheet.php > /secure/path/worksheet.json
 *
 * Keep the output on the authorized server and never commit it. It contains
 * subscription and order IDs, but no customer PII, token values, gateway
 * secrets, or order contents. This script performs no writes or gateway calls.
 *
 * @package AshbiSubscriptions
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run this file with WP-CLI.\n" );
	exit( 1 );
}

require_once __DIR__ . '/lib/OverdueDispositionPlan.php';
require_once __DIR__ . '/lib/overdue-evidence.php';

use Ashbi\Subscriptions\Tools\OverdueDispositionPlan;

$now     = time();
$records = ashbi_overdue_evidence_records( $now );

$worksheet = array(
	'schema_version'       => OverdueDispositionPlan::SCHEMA_VERSION,
	'generated_at'         => gmdate( 'c', $now ),
	'site_url'             => site_url(),
	'record_count'         => count( $records ),
	'allowed_dispositions' => OverdueDispositionPlan::dispositions(),
	'records'              => $records,
);

WP_CLI::line( wp_json_encode( $worksheet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

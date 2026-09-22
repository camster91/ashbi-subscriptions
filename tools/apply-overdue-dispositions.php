<?php
/**
 * Fail-closed, no-charge application of a reviewed overdue worksheet.
 *
 * Dry-run (default):
 *   ASHBI_DISPOSITION_WORKSHEET=/secure/worksheet.json \
 *   ASHBI_EXPECTED_SITE_URL=https://store.example \
 *   wp eval-file tools/apply-overdue-dispositions.php
 *
 * Apply the exact reviewed plan using the digest printed by dry-run:
 *   ASHBI_DISPOSITION_MODE=apply-no-charge \
 *   ASHBI_DISPOSITION_CONFIRM=<digest> ... wp eval-file ...
 *
 * This command never creates an order, calls a gateway, changes an order, or
 * cancels a subscription. Only `advance_without_charge` moves an unchanged due
 * date forward by whole billing periods. Every other disposition remains held
 * for its separately approved workflow.
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

/**
 * Normalize candidate subscription IDs.
 *
 * @param mixed $ids Candidate IDs.
 * @return int[] Normalized IDs.
 */
function ashbi_disposition_ids( $ids ): array {
	if ( ! is_array( $ids ) ) {
		return array();
	}
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	sort( $ids, SORT_NUMERIC );
	return $ids;
}

/**
 * Persist and verify an exact option ID list.
 *
 * @param string $option Option name.
 * @param int[]  $ids    Exact option value.
 * @return bool Whether the stored value matches.
 */
function ashbi_disposition_write_ids( string $option, array $ids ): bool {
	$ids = ashbi_disposition_ids( $ids );
	if ( empty( $ids ) ) {
		delete_option( $option );
		return false === get_option( $option, false );
	}
	update_option( $option, $ids, false );
	return ashbi_disposition_ids( get_option( $option, array() ) ) === $ids;
}

/** Rebuild the fail-closed active union from source-owned inventories. */
function ashbi_disposition_rebuild_active(): bool {
	$claim    = ashbi_disposition_ids( get_option( 'subscrpt_renewal_claim_quarantine_1', array() ) );
	$overdue  = ashbi_disposition_ids( get_option( 'subscrpt_overdue_renewal_quarantine_1', array() ) );
	$operator = ashbi_disposition_ids( get_option( 'subscrpt_renewal_operator_hold_1', array() ) );
	return ashbi_disposition_write_ids( 'subscrpt_renewal_migration_blocked', array_merge( $claim, $overdue, $operator ) );
}

try {
	$worksheet_path = (string) getenv( 'ASHBI_DISPOSITION_WORKSHEET' );
	$expected_site  = (string) getenv( 'ASHBI_EXPECTED_SITE_URL' );
	if ( '' === $worksheet_path || ! is_file( $worksheet_path ) || ! is_readable( $worksheet_path ) ) {
		throw new RuntimeException( 'ASHBI_DISPOSITION_WORKSHEET must name a readable server-local file.' );
	}
	if ( '' === $expected_site || site_url() !== $expected_site ) {
		throw new RuntimeException( 'ASHBI_EXPECTED_SITE_URL must exactly match the current WordPress site URL.' );
	}
	$raw       = file_get_contents( $worksheet_path );
	$worksheet = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( ! is_array( $worksheet ) ) {
		throw new RuntimeException( 'Worksheet is not valid JSON.' );
	}
	$digest = OverdueDispositionPlan::validate( $worksheet, $expected_site );
	$now    = time();
	$active = get_option( 'subscrpt_renewal_migration_blocked', array() );
	if ( ! is_array( $active ) && ! empty( $active ) ) {
		throw new RuntimeException( 'A legacy global migration block is active; refusing a scoped apply.' );
	}
	$overdue = ashbi_disposition_ids( get_option( 'subscrpt_overdue_renewal_quarantine_1', array() ) );
	$advance = array();
	$held    = array();
	foreach ( $worksheet['records'] as $record ) {
		$subscription_id = (int) $record['subscription_id'];
		if ( ! in_array( $subscription_id, $overdue, true ) ) {
			throw new RuntimeException( "Subscription #{$subscription_id} is not in the source-owned overdue quarantine." );
		}
		$current = ashbi_overdue_evidence_record( $subscription_id, $now );
		if ( null === $current ) {
			throw new RuntimeException( "Subscription #{$subscription_id} no longer exists." );
		}
		OverdueDispositionPlan::assert_current( $record, $current );
		if ( 'advance_without_charge' === $record['operator_disposition'] ) {
			$recurrence     = OverdueDispositionPlan::recurrence_string( $record );
			$anchor         = strtotime( (string) $record['next_date_utc'] );
			$next           = OverdueDispositionPlan::future_anchor(
				(int) $anchor,
				$now,
				static function ( int $from ) use ( $recurrence ): int {
					return (int) sdevs_wp_strtotime( $recurrence, $from );
				}
			);
			$advance[ $subscription_id ] = $next;
		} else {
			$held[] = $subscription_id;
		}
	}

	WP_CLI::line( 'Validated worksheet for ' . count( $worksheet['records'] ) . ' subscriptions.' );
	WP_CLI::line( 'Will advance without charge: ' . count( $advance ) . '.' );
	WP_CLI::line( 'Will remain quarantined: ' . count( $held ) . '.' );
	WP_CLI::line( 'Confirmation digest: ' . $digest );

	$disposition_mode = (string) getenv( 'ASHBI_DISPOSITION_MODE' );
	if ( 'apply-no-charge' !== $disposition_mode ) {
		WP_CLI::success( 'Dry-run only; no data changed.' );
		return;
	}
	$confirmation = (string) getenv( 'ASHBI_DISPOSITION_CONFIRM' );
	if ( ! hash_equals( $digest, $confirmation ) ) {
		throw new RuntimeException( 'ASHBI_DISPOSITION_CONFIRM does not match this exact reviewed plan.' );
	}

	// Establish an explicit hold before the first schedule write. Any interruption
	// therefore remains fail-closed and cannot dispatch a renewal.
	$preexisting_operator = ashbi_disposition_ids( get_option( 'subscrpt_renewal_operator_hold_1', array() ) );
	$claim                = ashbi_disposition_ids( get_option( 'subscrpt_renewal_claim_quarantine_1', array() ) );
	$active               = ashbi_disposition_ids( $active );
	$unowned              = array_diff( $active, $claim, $overdue );
	$all_ids              = ashbi_disposition_ids( array_column( $worksheet['records'], 'subscription_id' ) );
	if ( ! ashbi_disposition_write_ids( 'subscrpt_renewal_operator_hold_1', array_merge( $preexisting_operator, $unowned, $all_ids ) ) || ! ashbi_disposition_rebuild_active() ) {
		throw new RuntimeException( 'Could not establish and verify the pre-apply operator hold.' );
	}

	foreach ( $advance as $subscription_id => $next ) {
		update_post_meta( $subscription_id, '_subscrpt_next_date', $next );
		if ( (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true ) !== $next ) {
			throw new RuntimeException( "Could not verify the advanced date for subscription #{$subscription_id}; all records remain held." );
		}
		update_post_meta( $subscription_id, '_subscrpt_no_charge_disposition_digest', $digest );
		if ( ! hash_equals( $digest, (string) get_post_meta( $subscription_id, '_subscrpt_no_charge_disposition_digest', true ) ) ) {
			throw new RuntimeException( "Could not verify the disposition audit marker for subscription #{$subscription_id}; all records remain held." );
		}
	}

	$advanced_ids = array_keys( $advance );
	$overdue      = array_values( array_diff( $overdue, $advanced_ids ) );
	$operator     = ashbi_disposition_ids( get_option( 'subscrpt_renewal_operator_hold_1', array() ) );
	$temporary    = array_diff( $advanced_ids, $preexisting_operator, $unowned );
	$operator     = array_values( array_diff( $operator, $temporary ) );
	if ( ! ashbi_disposition_write_ids( 'subscrpt_overdue_renewal_quarantine_1', $overdue ) ) {
		throw new RuntimeException( 'Could not verify the updated overdue inventory; operator holds remain active.' );
	}
	if ( ! ashbi_disposition_write_ids( 'subscrpt_renewal_operator_hold_1', $operator ) || ! ashbi_disposition_rebuild_active() ) {
		throw new RuntimeException( 'Could not verify the final quarantine union; inspect holds before retrying.' );
	}

	WP_CLI::success( 'Applied reviewed no-charge advances. All other dispositions remain quarantined.' );
} catch ( Throwable $error ) {
	WP_CLI::error( $error->getMessage() );
}

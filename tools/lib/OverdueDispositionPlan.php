<?php
/**
 * Pure validation and planning helpers for overdue renewal disposition.
 *
 * @package AshbiSubscriptions
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- The CLI tooling uses this stable library path.

namespace Ashbi\Subscriptions\Tools;

use InvalidArgumentException;

/**
 * Validates operator worksheets without performing WordPress or gateway work.
 */
final class OverdueDispositionPlan {
	public const SCHEMA_VERSION = 2;

	/**
	 * Operator dispositions supported by the worksheet.
	 *
	 * @var string[]
	 */
	private const DISPOSITIONS = array(
		'advance_without_charge',
		'controlled_retry',
		'manual_recovery',
		'cancel',
		'retain_for_investigation',
	);

	/**
	 * Evidence fields protected by the source checksum.
	 *
	 * @var string[]
	 */
	private const EVIDENCE_FIELDS = array(
		'subscription_id',
		'status',
		'next_date_utc',
		'auto_renew',
		'payment_failure_count',
		'latest_paid_order_id',
		'latest_paid_date_utc',
		'latest_paid_gateway',
		'open_renewal_orders',
		'recurrence',
		'max_payments_reached',
	);

	/**
	 * Return the supported operator dispositions.
	 *
	 * @return string[]
	 */
	public static function dispositions(): array {
		return self::DISPOSITIONS;
	}

	/**
	 * Hash source evidence only; operator fields and derived age are editable.
	 *
	 * @param array<string,mixed> $record Worksheet record.
	 * @throws InvalidArgumentException When source evidence is incomplete.
	 */
	public static function evidence_checksum( array $record ): string {
		$evidence = array();
		foreach ( self::EVIDENCE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $record ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is a CLI/operator exception message, not HTML output.
				throw new InvalidArgumentException( "Missing evidence field: {$field}." );
			}
			$evidence[ $field ] = $record[ $field ];
		}

		return hash( 'sha256', self::encode( $evidence ) );
	}

	/**
	 * Validate a complete worksheet and return its exact-plan confirmation hash.
	 *
	 * @param array<string,mixed> $worksheet          Worksheet payload.
	 * @param string              $expected_site_url Explicitly expected site URL.
	 * @throws InvalidArgumentException When the worksheet is invalid.
	 */
	public static function validate( array $worksheet, string $expected_site_url ): string {
		if ( self::SCHEMA_VERSION !== ( $worksheet['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet schema must be exactly version 2.' );
		}
		if ( '' === $expected_site_url || ( $worksheet['site_url'] ?? null ) !== $expected_site_url ) {
			throw new InvalidArgumentException( 'Worksheet site URL does not match the explicitly expected site URL.' );
		}
		if ( self::DISPOSITIONS !== ( $worksheet['allowed_dispositions'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet disposition vocabulary is not the expected version.' );
		}
		if ( ! isset( $worksheet['records'] ) || ! is_array( $worksheet['records'] ) ) {
			throw new InvalidArgumentException( 'Worksheet records must be an array.' );
		}
		if ( count( $worksheet['records'] ) !== ( $worksheet['record_count'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet record count does not match its records.' );
		}

		$seen = array();
		foreach ( $worksheet['records'] as $record ) {
			if ( ! is_array( $record ) ) {
				throw new InvalidArgumentException( 'Every worksheet record must be an object.' );
			}
			$id = (int) ( $record['subscription_id'] ?? 0 );
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				throw new InvalidArgumentException( 'Worksheet contains a missing or duplicate subscription ID.' );
			}
			$seen[ $id ] = true;
			$checksum = $record['evidence_checksum'] ?? null;
			if ( ! is_string( $checksum ) || ! hash_equals( self::evidence_checksum( $record ), $checksum ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is a CLI/operator exception message, not HTML output.
					throw new InvalidArgumentException( "Evidence checksum failed for subscription #{$id}." );
			}
			$disposition = $record['operator_disposition'] ?? null;
			if ( ! is_string( $disposition ) || ! in_array( $disposition, self::DISPOSITIONS, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is a CLI/operator exception message, not HTML output.
					throw new InvalidArgumentException( "Subscription #{$id} needs one allowed operator disposition." );
			}
			if ( 'advance_without_charge' === $disposition ) {
				if ( ! empty( $record['open_renewal_orders'] ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is a CLI/operator exception message, not HTML output.
						throw new InvalidArgumentException( "Subscription #{$id} has an open renewal order and cannot be advanced." );
				}
				if ( ! empty( $record['max_payments_reached'] ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is a CLI/operator exception message, not HTML output.
						throw new InvalidArgumentException( "Subscription #{$id} reached its payment limit and cannot be advanced." );
				}
				self::recurrence_string( $record );
			}
		}

		return hash( 'sha256', self::encode( $worksheet ) );
	}

	/**
	 * Confirm that source evidence still matches the reviewed worksheet.
	 *
	 * @param array<string,mixed> $worksheet_record Reviewed record.
	 * @param array<string,mixed> $current_record   Current server evidence.
	 * @throws InvalidArgumentException When source evidence has changed.
	 */
	public static function assert_current( array $worksheet_record, array $current_record ): void {
		$id = (int) ( $worksheet_record['subscription_id'] ?? 0 );
		if ( ! hash_equals( self::evidence_checksum( $worksheet_record ), self::evidence_checksum( $current_record ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is a CLI/operator exception message, not HTML output.
			throw new InvalidArgumentException( "Source evidence changed for subscription #{$id}; generate and review a new worksheet." );
		}
	}

	/**
	 * Return the validated recurrence string for a worksheet record.
	 *
	 * @param array<string,mixed> $record Worksheet record.
	 * @throws InvalidArgumentException When recurrence evidence is invalid.
	 */
	public static function recurrence_string( array $record ): string {
		$recurrence = $record['recurrence'] ?? null;
		if ( ! is_array( $recurrence ) ) {
			throw new InvalidArgumentException( 'Recurrence evidence is unavailable.' );
		}
		$interval = (int) ( $recurrence['interval'] ?? 0 );
		$period   = $recurrence['period'] ?? null;
		if ( $interval < 1 || $interval > 100 || ! is_string( $period ) || ! in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ) {
			throw new InvalidArgumentException( 'Recurrence evidence is invalid.' );
		}

		return $interval . ' ' . $period;
	}

	/**
	 * Step an old anchor forward without creating an order or invoking a gateway.
	 *
	 * @param int      $anchor  Initial billing anchor.
	 * @param int      $now     Current timestamp.
	 * @param callable $advance Receives current anchor and returns one later anchor.
	 * @throws InvalidArgumentException When the recurrence does not advance safely.
	 */
	public static function future_anchor( int $anchor, int $now, callable $advance ): int {
		if ( $anchor <= 0 ) {
			throw new InvalidArgumentException( 'Billing anchor is invalid.' );
		}
		$current = $anchor;
		for ( $guard = 0; $guard < 1000 && $current <= $now; ++$guard ) {
			$next = (int) $advance( $current );
			if ( $next <= $current ) {
				throw new InvalidArgumentException( 'Recurrence did not advance the billing anchor.' );
			}
			$current = $next;
		}
		if ( $current <= $now ) {
			throw new InvalidArgumentException( 'Recurrence exceeded the 1000-period safety limit.' );
		}

		return $current;
	}

	/**
	 * Encode worksheet data deterministically.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 * @throws InvalidArgumentException When the value cannot be encoded.
	 */
	private static function encode( $value ): string {
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			throw new InvalidArgumentException( 'Worksheet data could not be encoded.' );
		}

		return $encoded;
	}
}

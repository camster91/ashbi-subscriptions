<?php
/**
 * Privacy-preserving site-state fingerprint helpers.
 *
 * @package AshbiSubscriptions
 */

namespace Ashbi\Subscriptions\Tools;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Canonicalizes sensitive rows and compares only keyed digests and counts.
 */
final class SiteFingerprint {
	public const SCHEMA_VERSION = 1;

	/** @var string[] */
	private const ACTIVATION_ADDITIVE_TABLES = array(
		'subscrpt_stats_snapshot',
		'subscrpt_cancellation_feedback',
		'subscrpt_plan_group',
		'subscrpt_plan',
		'subscrpt_plan_relation',
		'subscrpt_renewal_claim',
		'subscrpt_order_relation',
		'subscrpt_paypal_map',
	);

	/**
	 * Produce a stable keyed digest without exposing source values.
	 *
	 * @param array<int,mixed> $rows Sensitive rows.
	 * @return array{count:int,digest:string,row_digests:string[]}
	 */
	public static function digest_rows( array $rows, string $key ): array {
		self::assert_key( $key );
		$encoded = array();
		foreach ( $rows as $row ) {
			$json = json_encode( self::canonicalize( $row ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $json ) ) {
				throw new InvalidArgumentException( 'A fingerprint row could not be encoded.' );
			}
			$encoded[] = $json;
		}
		sort( $encoded, SORT_STRING );

		return array(
			'count'       => count( $encoded ),
			'digest'      => hash_hmac( 'sha256', implode( "\n", $encoded ), $key ),
			'row_digests' => array_map(
				static function ( string $row ) use ( $key ): string {
					return hash_hmac( 'sha256', $row, $key );
				},
				$encoded
			),
		);
	}

	/**
	 * Authenticate the complete report so file replacement cannot pass silently.
	 *
	 * @param array<string,mixed> $report Report with or without report_mac.
	 */
	public static function seal_report( array $report, string $key ): string {
		self::assert_key( $key );
		unset( $report['report_mac'] );
		$encoded = json_encode( self::canonicalize( $report ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			throw new InvalidArgumentException( 'The fingerprint report could not be authenticated.' );
		}

		return hash_hmac( 'sha256', $encoded, $key );
	}

	/**
	 * Compare two reports for activation or exact rollback equivalence.
	 *
	 * @param array<string,mixed> $before Pre-operation report.
	 * @param array<string,mixed> $after Post-operation report.
	 * @return string[] Human-readable mismatches.
	 */
	public static function compare( array $before, array $after, string $mode, string $key ): array {
		self::assert_key( $key );
		if ( ! in_array( $mode, array( 'activation', 'exact' ), true ) ) {
			throw new InvalidArgumentException( 'Comparison mode must be activation or exact.' );
		}
		$errors = array();
		foreach ( array( 'before' => $before, 'after' => $after ) as $label => $report ) {
			$errors = array_merge( $errors, self::validate_report( $report, ucfirst( $label ) ) );
		}
		if ( $errors ) {
			return $errors;
		}
		$expected_key_id = substr( hash( 'sha256', $key ), 0, 16 );
		foreach ( array( 'Before' => $before, 'After' => $after ) as $label => $report ) {
			if ( ! hash_equals( $expected_key_id, $report['key_id'] ) ) {
				$errors[] = "{$label} fingerprint key identifier does not match the supplied key.";
			}
			if ( ! hash_equals( self::seal_report( $report, $key ), $report['report_mac'] ) ) {
				$errors[] = "{$label} fingerprint authentication failed.";
			}
		}
		if ( $errors ) {
			return $errors;
		}
		if ( ( $before['site_url'] ?? null ) !== ( $after['site_url'] ?? null ) ) {
			$errors[] = 'Site URL changed.';
		}
		if ( ( $before['key_id'] ?? null ) !== ( $after['key_id'] ?? null ) ) {
			$errors[] = 'Fingerprints were generated with different keys.';
		}

		$before_state = isset( $before['state'] ) && is_array( $before['state'] ) ? $before['state'] : array();
		$after_state  = isset( $after['state'] ) && is_array( $after['state'] ) ? $after['state'] : array();
		$protected_sections = array( 'subscription_posts', 'subscription_meta', 'related_orders', 'payment_tokens', 'payment_token_meta', 'stable_options' );
		if ( 'exact' === $mode ) {
			$protected_sections[] = 'related_orders_full';
		}
		foreach ( $protected_sections as $section ) {
			if ( ( $before_state[ $section ] ?? null ) !== ( $after_state[ $section ] ?? null ) ) {
				$errors[] = "Protected state changed: {$section}.";
			}
		}

		$before_tables = isset( $before_state['custom_tables'] ) && is_array( $before_state['custom_tables'] ) ? $before_state['custom_tables'] : array();
		$after_tables  = isset( $after_state['custom_tables'] ) && is_array( $after_state['custom_tables'] ) ? $after_state['custom_tables'] : array();
		foreach ( $before_tables as $table => $fingerprint ) {
			if ( ! array_key_exists( $table, $after_tables ) ) {
				$errors[] = "Protected custom table disappeared: {$table}.";
			} elseif (
				'activation' === $mode
				&& in_array( $table, self::ACTIVATION_ADDITIVE_TABLES, true )
				&& self::is_additive( $fingerprint, $after_tables[ $table ] )
			) {
				continue;
			} elseif ( $fingerprint !== $after_tables[ $table ] ) {
				$errors[] = "Protected custom table changed: {$table}.";
			}
		}
		foreach ( array_diff( array_keys( $after_tables ), array_keys( $before_tables ) ) as $table ) {
			if ( 'exact' === $mode || ! in_array( $table, self::ACTIVATION_ADDITIVE_TABLES, true ) ) {
				$errors[] = "Unexpected custom table appeared: {$table}.";
			}
		}
		if ( 'exact' === $mode && $before_state['custom_table_schemas'] !== $after_state['custom_table_schemas'] ) {
			$errors[] = 'Protected custom table schemas changed.';
		}

		return $errors;
	}

	/**
	 * Reject incomplete or structurally invalid reports before comparison.
	 *
	 * @param array<string,mixed> $report Fingerprint report.
	 * @return string[] Validation errors.
	 */
	private static function validate_report( array $report, string $label ): array {
		if ( self::SCHEMA_VERSION !== ( $report['schema_version'] ?? null ) ) {
			return array( "{$label} fingerprint schema is unsupported." );
		}
		$errors = array();
		if ( ! isset( $report['site_url'] ) || ! is_string( $report['site_url'] ) || '' === $report['site_url'] ) {
			$errors[] = "{$label} fingerprint has no site URL.";
		}
		if ( ! isset( $report['key_id'] ) || ! is_string( $report['key_id'] ) || ! preg_match( '/^[a-f0-9]{16}$/', $report['key_id'] ) ) {
			$errors[] = "{$label} fingerprint has an invalid key identifier.";
		}
		if ( ! isset( $report['report_mac'] ) || ! self::is_digest_string( $report['report_mac'] ) ) {
			$errors[] = "{$label} fingerprint has an invalid report authentication code.";
		}
		if ( ! isset( $report['state'] ) || ! is_array( $report['state'] ) ) {
			$errors[] = "{$label} fingerprint has no state map.";
			return $errors;
		}

		$state = $report['state'];
		foreach ( array( 'subscription_posts', 'subscription_meta', 'related_orders', 'related_orders_full', 'payment_tokens', 'payment_token_meta', 'stable_options' ) as $section ) {
			if ( ! isset( $state[ $section ] ) || ! self::is_valid_digest( $state[ $section ] ) ) {
				$errors[] = "{$label} fingerprint has an invalid {$section} section.";
			}
		}
		if ( ! isset( $state['custom_tables'] ) || ! is_array( $state['custom_tables'] ) ) {
			$errors[] = "{$label} fingerprint has no custom table map.";
			return $errors;
		}
		foreach ( $state['custom_tables'] as $table => $fingerprint ) {
			if ( ! is_string( $table ) || ! preg_match( '/^subscrpt_[a-z0-9_]+$/', $table ) || ! self::is_valid_digest( $fingerprint ) ) {
				$errors[] = "{$label} fingerprint has an invalid custom table entry.";
			}
		}
		if ( ! isset( $state['custom_table_schemas'] ) || ! is_array( $state['custom_table_schemas'] ) ) {
			$errors[] = "{$label} fingerprint has no custom table schema map.";
			return $errors;
		}
		foreach ( $state['custom_table_schemas'] as $table => $fingerprint ) {
			if ( ! is_string( $table ) || ! preg_match( '/^subscrpt_[a-z0-9_]+$/', $table ) || ! self::is_valid_digest( $fingerprint ) ) {
				$errors[] = "{$label} fingerprint has an invalid custom table schema entry.";
			}
		}
		if ( array_keys( $state['custom_tables'] ) !== array_keys( $state['custom_table_schemas'] ) ) {
			$errors[] = "{$label} fingerprint table and schema maps do not match.";
		}

		return $errors;
	}

	/** @param mixed $value Potential count/digest pair. */
	private static function is_valid_digest( $value ): bool {
		return is_array( $value )
			&& isset( $value['count'], $value['digest'], $value['row_digests'] )
			&& is_int( $value['count'] )
			&& 0 <= $value['count']
			&& is_string( $value['digest'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $value['digest'] )
			&& is_array( $value['row_digests'] )
			&& $value['count'] === count( $value['row_digests'] )
			&& count( $value['row_digests'] ) === count( array_filter( $value['row_digests'], array( self::class, 'is_digest_string' ) ) );
	}

	/** @param mixed $value Potential SHA-256 digest. */
	private static function is_digest_string( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
	}

	/**
	 * Verify that every old row digest remains in a potentially larger multiset.
	 *
	 * @param mixed $before Before-table fingerprint.
	 * @param mixed $after  After-table fingerprint.
	 */
	private static function is_additive( $before, $after ): bool {
		if ( ! self::is_valid_digest( $before ) || ! self::is_valid_digest( $after ) || $after['count'] < $before['count'] ) {
			return false;
		}
		$remaining = array_count_values( $after['row_digests'] );
		foreach ( $before['row_digests'] as $digest ) {
			if ( empty( $remaining[ $digest ] ) ) {
				return false;
			}
			--$remaining[ $digest ];
		}

		return true;
	}

	/** Require a comparison key with an adequate security margin. */
	private static function assert_key( string $key ): void {
		if ( strlen( $key ) < 32 ) {
			throw new InvalidArgumentException( 'Fingerprint key must contain at least 32 bytes.' );
		}
	}

	/** @param mixed $value Arbitrary scalar, array, or data object. @return mixed */
	private static function canonicalize( $value ) {
		if ( $value instanceof DateTimeInterface ) {
			return $value->format( 'Y-m-d\TH:i:s.uP' );
		}
		if ( is_object( $value ) ) {
			if ( method_exists( $value, 'get_data' ) ) {
				return self::canonicalize( $value->get_data() );
			}
			return self::canonicalize( get_object_vars( $value ) );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}

		return $value;
	}
}

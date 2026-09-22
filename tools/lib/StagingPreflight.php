<?php
/**
 * Fail-closed staging rehearsal preflight checks.
 *
 * @package AshbiSubscriptions
 */

// PSR-4 class filenames are retained for the staging tool loader.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace Ashbi\Subscriptions\Tools;

use InvalidArgumentException;

/**
 * Evaluates privacy-safe staging state without mutating WordPress.
 */
final class StagingPreflight {
	/** @var string[] */
	private const OFFLINE_GATEWAYS = array( 'bacs', 'cheque', 'cod' );

	/**
	 * Build a privacy-safe inventory from registered WooCommerce gateways.
	 *
	 * @param array<string,array<string,mixed>> $gateway_maps Settings keyed by
	 *                                                        registered gateway ID.
	 * @return array<string,array{enabled:?bool,mode:string}>
	 */
	public static function gateway_inventory( array $gateway_maps ): array {
		$inventory = array();
		foreach ( $gateway_maps as $id => $settings ) {
			$inventory[ (string) $id ] = self::classify_gateway( (string) $id, $settings );
		}
		ksort( $inventory, SORT_STRING );

		return $inventory;
	}

	/**
	 * Reduce gateway settings to enabled and sandbox/offline/live/unknown.
	 *
	 * @param array<string,mixed> $settings Gateway settings; never returned.
	 * @return array{enabled:?bool,mode:string}
	 */
	public static function classify_gateway( string $id, array $settings ): array {
		$enabled = self::boolean_setting( $settings['enabled'] ?? null );
		if ( false === $enabled ) {
			return array(
				'enabled' => false,
				'mode' => 'disabled',
			);
		}
		if ( null === $enabled ) {
			return array(
				'enabled' => null,
				'mode' => 'unknown',
			);
		}
		if ( in_array( $id, self::OFFLINE_GATEWAYS, true ) ) {
			return array(
				'enabled' => true,
				'mode' => 'offline',
			);
		}

		foreach ( array( 'testmode', 'test_mode', 'sandbox', 'sandbox_mode' ) as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$test_mode = self::boolean_setting( $settings[ $key ] );
			if ( null !== $test_mode ) {
				return array(
					'enabled' => true,
					'mode' => $test_mode ? 'sandbox' : 'live',
				);
			}
			return array(
				'enabled' => true,
				'mode' => 'unknown',
			);
		}
		foreach ( array( 'environment', 'mode' ) as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$mode = strtolower( trim( (string) $settings[ $key ] ) );
			if ( in_array( $mode, array( 'test', 'testing', 'sandbox' ), true ) ) {
				return array(
					'enabled' => true,
					'mode' => 'sandbox',
				);
			}
			if ( in_array( $mode, array( 'live', 'production' ), true ) ) {
				return array(
					'enabled' => true,
					'mode' => 'live',
				);
			}
		}

		return array(
			'enabled' => true,
			'mode' => 'unknown',
		);
	}

	/**
	 * Evaluate all required rehearsal controls.
	 *
	 * @param array<string,mixed> $state Collected staging state.
	 * @return array{passed:bool,checks:array<int,array{id:string,passed:bool,message:string}>}
	 */
	public static function evaluate( array $state ): array {
		$required = array(
			'expected_site_url',
			'site_url',
			'home_url',
			'environment_type',
			'wp_cron_disabled',
			'action_scheduler_runner_disabled',
			'active_webhook_count',
			'gateway_discovery_complete',
			'gateways',
			'attestations',
		);
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $state ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception is consumed by WP-CLI/test tooling, not rendered.
				throw new InvalidArgumentException( "Missing staging preflight field: {$field}." );
			}
		}

		$expected = self::normalize_url( $state['expected_site_url'] );
		$site     = self::normalize_url( $state['site_url'] );
		$home     = self::normalize_url( $state['home_url'] );
		$checks   = array();

		self::add_check(
			$checks,
			'expected_site_url',
			'' !== $expected && hash_equals( $expected, $site ),
			'Site URL exactly matches ASHBI_EXPECTED_SITE_URL.'
		);
		self::add_check(
			$checks,
			'home_host',
			'' !== $expected && self::url_origin( $expected ) === self::url_origin( $home ),
			'Home URL uses the expected staging origin.'
		);
		self::add_check(
			$checks,
			'environment_type',
			in_array( (string) $state['environment_type'], array( 'staging', 'development', 'local' ), true ),
			'WordPress environment type is non-production.'
		);
		self::add_check(
			$checks,
			'wp_cron_disabled',
			true === $state['wp_cron_disabled'],
			'DISABLE_WP_CRON is explicitly true.'
		);
		self::add_check(
			$checks,
			'action_scheduler_runner_disabled',
			true === $state['action_scheduler_runner_disabled'],
			'ACTION_SCHEDULER_DISABLE_DEFAULT_QUEUE_RUNNER is explicitly true.'
		);
		self::add_check(
			$checks,
			'gateway_discovery',
			true === $state['gateway_discovery_complete'] && is_array( $state['gateways'] ),
			'WooCommerce registered gateway discovery completed.'
		);
		self::add_check(
			$checks,
			'active_webhooks',
			is_int( $state['active_webhook_count'] ) && 0 === $state['active_webhook_count'],
			'WooCommerce has no active outgoing webhooks.'
		);

		$gateways = is_array( $state['gateways'] ) ? $state['gateways'] : array();
		foreach ( $gateways as $gateway_id => $gateway ) {
			$gateway = is_array( $gateway ) ? $gateway : array();
			$enabled = $gateway['enabled'] ?? null;
			$mode    = (string) ( $gateway['mode'] ?? 'unknown' );
			self::add_check(
				$checks,
				'gateway_' . preg_replace( '/[^a-z0-9_\-]/', '_', (string) $gateway_id ),
				false === $enabled || ( true === $enabled && in_array( $mode, array( 'sandbox', 'offline' ), true ) ),
				"Enabled gateway {$gateway_id} is sandboxed or offline."
			);
		}

		$expected_gateway_ids = is_array( $state['expected_gateway_ids'] ?? null )
			? array_values( array_unique( array_filter( array_map( 'strval', $state['expected_gateway_ids'] ) ) ) )
			: array();
		foreach ( $expected_gateway_ids as $gateway_id ) {
			$gateway = $gateways[ $gateway_id ] ?? array();
			$gateway = is_array( $gateway ) ? $gateway : array();
			$enabled = $gateway['enabled'] ?? null;
			$mode    = (string) ( $gateway['mode'] ?? 'unknown' );
			self::add_check(
				$checks,
				'expected_gateway_' . preg_replace( '/[^a-z0-9_\-]/', '_', $gateway_id ),
				true === $enabled && in_array( $mode, array( 'sandbox', 'offline' ), true ),
				"Expected gateway {$gateway_id} is registered and enabled in sandbox or offline mode."
			);
		}

		$attestations = is_array( $state['attestations'] ) ? $state['attestations'] : array();
		foreach ( array( 'host_cron_disabled', 'outbound_email_disabled', 'production_callbacks_disabled' ) as $attestation ) {
			self::add_check(
				$checks,
				$attestation,
				true === ( $attestations[ $attestation ] ?? false ),
				str_replace( '_', ' ', ucfirst( $attestation ) ) . ' was explicitly attested.'
			);
		}

		return array(
			'passed' => ! in_array( false, array_column( $checks, 'passed' ), true ),
			'checks' => $checks,
		);
	}

	/**
	 * Add a stable check result.
	 *
	 * @param array<int,array{id:string,passed:bool,message:string}> $checks Result list.
	 * @param string $id Check identifier.
	 * @param bool $passed Whether the check passed.
	 * @param string $message Human-readable check message.
	 */
	private static function add_check( array &$checks, string $id, bool $passed, string $message ): void {
		$checks[] = array(
			'id'      => $id,
			'passed'  => $passed,
			'message' => $message,
		);
	}

	/**
	 * Parse a strict WordPress-style boolean setting.
	 *
	 * @param mixed $value Setting value.
	 */
	private static function boolean_setting( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) && in_array( $value, array( 0, 1 ), true ) ) {
			return 1 === $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = strtolower( trim( $value ) );
		if ( in_array( $value, array( '1', 'yes', 'on', 'true', 'test', 'sandbox' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( '0', 'no', 'off', 'false', 'live', 'production' ), true ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Normalize a URL-like value for exact comparison.
	 *
	 * @param mixed $url URL-like value.
	 * @return string
	 */
	private static function normalize_url( $url ): string {
		return rtrim( trim( (string) $url ), '/' );
	}

	/**
	 * Return only scheme and host so WordPress-in-subdirectory installs work.
	 *
	 * @param string $url URL to reduce to its origin.
	 * @return string
	 */
	private static function url_origin( string $url ): string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return strtolower( (string) $parts['scheme'] . '://' . (string) $parts['host'] . $port );
	}
}

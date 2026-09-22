<?php
/**
 * Verify keyed site-state fingerprint comparison.
 *
 * @package AshbiSubscriptions\Tests
 */

use Ashbi\Subscriptions\Tools\SiteFingerprint;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/tools/lib/SiteFingerprint.php';

/** Verify activation and exact rollback comparison semantics. */
final class SiteFingerprintTest extends TestCase {
	/** Fingerprint key used only by fabricated test reports. */
	private const KEY = '0123456789abcdef0123456789abcdef';

	/**
	 * Invoke the standalone comparator as an operator would.
	 *
	 * @param array<string,mixed> $before Before report.
	 * @param array<string,mixed> $after  After report.
	 * @param string              $mode   Comparison mode.
	 * @return array{exit:int,stdout:string,stderr:string}
	 */
	private function run_comparator( array $before, array $after, string $mode ): array {
		$before_path = tempnam( sys_get_temp_dir(), 'ashbi-before-' );
		$after_path  = tempnam( sys_get_temp_dir(), 'ashbi-after-' );
		self::assertIsString( $before_path );
		self::assertIsString( $after_path );
		file_put_contents( $before_path, json_encode( $before ) );
		file_put_contents( $after_path, json_encode( $after ) );
		$command = implode(
			' ',
			array_map(
				'escapeshellarg',
				array( PHP_BINARY, dirname( __DIR__, 2 ) . '/tools/compare-site-fingerprints.php', $before_path, $after_path, $mode )
			)
		);
		$pipes   = array();
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array( 'ASHBI_FINGERPRINT_KEY' => self::KEY )
		);
		self::assertIsResource( $process );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		unlink( $before_path );
		unlink( $after_path );

		return array(
			'exit'   => $exit,
			'stdout' => (string) $stdout,
			'stderr' => (string) $stderr,
		);
	}

	/**
	 * Build a signed fabricated report.
	 *
	 * @return array<string,mixed>
	 */
	private function report(): array {
		$fingerprint          = SiteFingerprint::digest_rows( array( array( 'id' => 1 ) ), self::KEY );
		$report               = array(
			'schema_version' => 1,
			'site_url'       => 'https://client.example',
			'key_id'         => substr( hash( 'sha256', self::KEY ), 0, 16 ),
			'state'          => array(
				'subscription_posts'   => $fingerprint,
				'subscription_meta'    => $fingerprint,
				'related_orders'       => $fingerprint,
				'related_orders_full'  => $fingerprint,
				'payment_tokens'       => $fingerprint,
				'payment_token_meta'   => $fingerprint,
				'stable_options'       => $fingerprint,
				'custom_tables'        => array( 'subscrpt_order_relation' => $fingerprint ),
				'custom_table_schemas' => array( 'subscrpt_order_relation' => $fingerprint ),
			),
		);
		$report['report_mac'] = SiteFingerprint::seal_report( $report, self::KEY );
		return $report;
	}

	/** Verify row and map ordering cannot change a digest. */
	public function test_digest_is_stable_across_row_and_map_order(): void {
		$first  = SiteFingerprint::digest_rows(
			array(
				array(
					'b' => 2,
					'a' => 1,
				),
				array( 'id' => 2 ),
			),
			self::KEY
		);
		$second = SiteFingerprint::digest_rows(
			array(
				array( 'id' => 2 ),
				array(
					'a' => 1,
					'b' => 2,
				),
			),
			self::KEY
		);
		$this->assertSame( $first, $second );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first['digest'] );
	}

	/** Verify MySQL's redundant default column charset syntax is canonicalized. */
	public function test_schema_canonicalization_ignores_redundant_default_column_charset(): void {
		$before = 'CREATE TABLE `x` (`name` varchar(10) COLLATE utf8mb4_unicode_520_ci) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
		$after  = 'CREATE TABLE `x` (`name` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

		$this->assertSame( $before, SiteFingerprint::canonicalize_schema( $after ) );
	}

	/** Verify short fingerprint keys are rejected. */
	public function test_short_key_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		SiteFingerprint::digest_rows( array(), 'short' );
	}

	/** Verify identical reports pass exact comparison. */
	public function test_identical_reports_pass_exact_comparison(): void {
		$report = $this->report();
		$this->assertSame( array(), SiteFingerprint::compare( $report, $report, 'exact', self::KEY ) );
	}

	/** Verify activation allows only known additive tables. */
	public function test_activation_allows_only_known_additive_table(): void {
		$before = $this->report();
		$after  = $before;
		$after['state']['custom_tables']['subscrpt_renewal_claim']         = SiteFingerprint::digest_rows( array(), self::KEY );
		$after['state']['custom_table_schemas']['subscrpt_renewal_claim']  = SiteFingerprint::digest_rows( array( 'schema' ), self::KEY );
		$after['state']['custom_tables']['subscrpt_recovery_event']        = SiteFingerprint::digest_rows( array(), self::KEY );
		$after['state']['custom_table_schemas']['subscrpt_recovery_event'] = SiteFingerprint::digest_rows( array( 'schema' ), self::KEY );
		$after['report_mac'] = SiteFingerprint::seal_report( $after, self::KEY );
		$this->assertSame( array(), SiteFingerprint::compare( $before, $after, 'activation', self::KEY ) );
		$this->assertStringContainsString( 'Unexpected custom table appeared', implode( ' ', SiteFingerprint::compare( $before, $after, 'exact', self::KEY ) ) );

		$after['state']['custom_tables']['subscrpt_attacker_table']        = SiteFingerprint::digest_rows( array(), self::KEY );
		$after['state']['custom_table_schemas']['subscrpt_attacker_table'] = SiteFingerprint::digest_rows( array( 'schema' ), self::KEY );
		$after['report_mac'] = SiteFingerprint::seal_report( $after, self::KEY );
		$this->assertStringContainsString( 'subscrpt_attacker_table', implode( ' ', SiteFingerprint::compare( $before, $after, 'activation', self::KEY ) ) );
	}

	/** Verify activation permits additive rows but rejects rewrites. */
	public function test_activation_allows_additive_rows_but_not_rewrites(): void {
		$before = $this->report();
		$after  = $before;
		$after['state']['custom_tables']['subscrpt_order_relation'] = SiteFingerprint::digest_rows(
			array( array( 'id' => 1 ), array( 'id' => 2 ) ),
			self::KEY
		);
		$after['report_mac']                                        = SiteFingerprint::seal_report( $after, self::KEY );
		$this->assertSame( array(), SiteFingerprint::compare( $before, $after, 'activation', self::KEY ) );

		$after['state']['custom_tables']['subscrpt_order_relation'] = SiteFingerprint::digest_rows( array( array( 'id' => 2 ) ), self::KEY );
		$after['report_mac']                                        = SiteFingerprint::seal_report( $after, self::KEY );
		$this->assertStringContainsString( 'changed', implode( ' ', SiteFingerprint::compare( $before, $after, 'activation', self::KEY ) ) );
	}

	/** Verify activation migration deltas remain forbidden in exact mode. */
	public function test_activation_ignores_full_order_migration_delta_but_exact_does_not(): void {
		$before                                = $this->report();
		$after                                 = $before;
		$after['state']['related_orders_full'] = SiteFingerprint::digest_rows( array( array( 'migration_meta' => true ) ), self::KEY );
		$after['report_mac']                   = SiteFingerprint::seal_report( $after, self::KEY );

		$this->assertSame( array(), SiteFingerprint::compare( $before, $after, 'activation', self::KEY ) );
		$this->assertStringContainsString( 'related_orders_full', implode( ' ', SiteFingerprint::compare( $before, $after, 'exact', self::KEY ) ) );
	}

	/** Verify protected-state and missing-table changes are reported. */
	public function test_protected_state_and_missing_table_are_reported(): void {
		$before                           = $this->report();
		$after                            = $before;
		$after['state']['payment_tokens'] = SiteFingerprint::digest_rows( array(), self::KEY );
		unset( $after['state']['custom_tables']['subscrpt_order_relation'] );
		unset( $after['state']['custom_table_schemas']['subscrpt_order_relation'] );
		$after['report_mac'] = SiteFingerprint::seal_report( $after, self::KEY );
		$errors              = implode( ' ', SiteFingerprint::compare( $before, $after, 'activation', self::KEY ) );
		$this->assertStringContainsString( 'payment_tokens', $errors );
		$this->assertStringContainsString( 'subscrpt_order_relation', $errors );
	}

	/** Verify incomplete reports fail closed. */
	public function test_incomplete_reports_fail_closed(): void {
		$before = $this->report();
		$after  = $before;
		unset( $after['state']['payment_token_meta'] );

		$after['report_mac'] = SiteFingerprint::seal_report( $after, self::KEY );
		$errors              = implode( ' ', SiteFingerprint::compare( $before, $after, 'activation', self::KEY ) );
		$this->assertStringContainsString( 'invalid payment_token_meta section', $errors );
	}

	/** Verify invalid digests and key identifiers fail closed. */
	public function test_invalid_digest_and_key_identifier_fail_closed(): void {
		$before                           = $this->report();
		$after                            = $before;
		$after['key_id']                  = 'not-a-key-id';
		$after['state']['related_orders'] = array(
			'count'  => -1,
			'digest' => 'bad',
		);
		$after['report_mac']              = SiteFingerprint::seal_report( $after, self::KEY );

		$errors = implode( ' ', SiteFingerprint::compare( $before, $after, 'exact', self::KEY ) );
		$this->assertStringContainsString( 'invalid key identifier', $errors );
		$this->assertStringContainsString( 'invalid related_orders section', $errors );
	}

	/** Verify the standalone comparator returns automation-safe exit codes. */
	public function test_standalone_comparator_exit_codes_are_automation_safe(): void {
		$before = $this->report();
		$passed = $this->run_comparator( $before, $before, 'exact' );
		$this->assertSame( 0, $passed['exit'], $passed['stderr'] );
		$this->assertStringContainsString( 'passed (exact)', $passed['stdout'] );

		$after                            = $before;
		$after['state']['payment_tokens'] = SiteFingerprint::digest_rows( array(), self::KEY );
		$after['report_mac']              = SiteFingerprint::seal_report( $after, self::KEY );
		$failed                           = $this->run_comparator( $before, $after, 'activation' );
		$this->assertSame( 1, $failed['exit'] );
		$this->assertStringContainsString( 'payment_tokens', $failed['stderr'] );
	}

	/** Verify report authentication and exact schema comparison. */
	public function test_report_authentication_and_exact_schema_comparison(): void {
		$before               = $this->report();
		$tampered             = $before;
		$tampered['site_url'] = 'https://tampered.example';
		$this->assertStringContainsString( 'authentication failed', implode( ' ', SiteFingerprint::compare( $before, $tampered, 'exact', self::KEY ) ) );

		$after = $before;
		$after['state']['custom_table_schemas']['subscrpt_order_relation'] = SiteFingerprint::digest_rows( array( 'changed schema' ), self::KEY );
		$after['report_mac'] = SiteFingerprint::seal_report( $after, self::KEY );
		$this->assertStringContainsString( 'schemas changed', implode( ' ', SiteFingerprint::compare( $before, $after, 'exact', self::KEY ) ) );
	}
}

<?php

use PHPUnit\Framework\TestCase;

final class OverdueDispositionApplyTest extends TestCase {
	/** @return array<string,mixed> */
	private function runFixture( string $mode, string $scenario = 'normal' ): array {
		$command = sprintf(
			'ASHBI_FIXTURE_MODE=%s ASHBI_FIXTURE_SCENARIO=%s %s %s',
			escapeshellarg( $mode ),
			escapeshellarg( $scenario ),
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( dirname( __DIR__ ) . '/fixtures/run-overdue-apply.php' )
		);
		$output = array();
		$exit   = 0;
		exec( $command, $output, $exit );
		$this->assertSame( 0, $exit );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	public function test_dry_run_changes_nothing(): void {
		$result = $this->runFixture( 'dry-run' );
		$this->assertNull( $result['error'] );
		$this->assertSame( strtotime( '2024-01-15T12:00:00+00:00' ), $result['meta']['_subscrpt_next_date'] );
		$this->assertSame( array( 42 ), $result['options']['subscrpt_overdue_renewal_quarantine_1'] );
		$this->assertArrayNotHasKey( '_subscrpt_no_charge_disposition_digest', $result['meta'] );
	}

	public function test_apply_advances_without_order_and_preserves_unrelated_hold(): void {
		$result = $this->runFixture( 'apply' );
		$this->assertNull( $result['error'] );
		$this->assertGreaterThan( time(), $result['meta']['_subscrpt_next_date'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['meta']['_subscrpt_no_charge_disposition_digest'] );
		$this->assertArrayNotHasKey( 'subscrpt_overdue_renewal_quarantine_1', $result['options'] );
		$this->assertSame( array( 99 ), $result['options']['subscrpt_renewal_operator_hold_1'] );
		$this->assertSame( array( 99 ), $result['options']['subscrpt_renewal_migration_blocked'] );
	}

	public function test_apply_does_not_remove_a_preexisting_hold_on_advanced_record(): void {
		$result = $this->runFixture( 'apply', 'preexisting' );
		$this->assertNull( $result['error'] );
		$this->assertSame( array( 42, 99 ), $result['options']['subscrpt_renewal_operator_hold_1'] );
		$this->assertSame( array( 42, 99 ), $result['options']['subscrpt_renewal_migration_blocked'] );
	}
}

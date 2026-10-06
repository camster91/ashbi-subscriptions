<?php
/**
 * Verify the overdue-disposition CLI fixture contract.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;

/** Verify the no-charge overdue disposition workflow. */
final class OverdueDispositionApplyTest extends TestCase {
	/**
	 * Run the isolated overdue-disposition fixture.
	 *
	 * @param string $mode     Fixture execution mode.
	 * @param string $scenario Fixture data scenario.
	 * @return array<string,mixed>
	 */
	private function runFixture( string $mode, string $scenario = 'normal' ): array {
		$environment = 'Windows' === PHP_OS_FAMILY
			? 'set ' . escapeshellarg( 'ASHBI_FIXTURE_MODE=' . $mode ) . ' && set ' . escapeshellarg( 'ASHBI_FIXTURE_SCENARIO=' . $scenario ) . ' && '
			: 'ASHBI_FIXTURE_MODE=' . escapeshellarg( $mode ) . ' ASHBI_FIXTURE_SCENARIO=' . escapeshellarg( $scenario ) . ' ';
		$command     = $environment . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/run-overdue-apply.php' );
		$output      = array();
		$exit        = 0;
		exec( $command, $output, $exit );
		$this->assertSame( 0, $exit );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	/** Verify dry-run mode does not mutate the fixture state. */
	public function test_dry_run_changes_nothing(): void {
		$result = $this->runFixture( 'dry-run' );
		$this->assertNull( $result['error'] );
		$this->assertSame( strtotime( '2024-01-15T12:00:00+00:00' ), $result['meta']['_subscrpt_next_date'] );
		$this->assertSame( array( 42 ), $result['options']['subscrpt_overdue_renewal_quarantine_1'] );
		$this->assertArrayNotHasKey( '_subscrpt_no_charge_disposition_digest', $result['meta'] );
	}

	/** Verify applying a disposition advances only the selected record. */
	public function test_apply_advances_without_order_and_preserves_unrelated_hold(): void {
		$result = $this->runFixture( 'apply' );
		$this->assertNull( $result['error'] );
		$this->assertGreaterThan( time(), $result['meta']['_subscrpt_next_date'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['meta']['_subscrpt_no_charge_disposition_digest'] );
		$this->assertArrayNotHasKey( 'subscrpt_overdue_renewal_quarantine_1', $result['options'] );
		$this->assertSame( array( 99 ), $result['options']['subscrpt_renewal_operator_hold_1'] );
		$this->assertSame( array( 99 ), $result['options']['subscrpt_renewal_migration_blocked'] );
	}

	/** Verify an existing operator hold remains attached after application. */
	public function test_apply_does_not_remove_a_preexisting_hold_on_advanced_record(): void {
		$result = $this->runFixture( 'apply', 'preexisting' );
		$this->assertNull( $result['error'] );
		$this->assertSame( array( 42, 99 ), $result['options']['subscrpt_renewal_operator_hold_1'] );
		$this->assertSame( array( 42, 99 ), $result['options']['subscrpt_renewal_migration_blocked'] );
	}
}

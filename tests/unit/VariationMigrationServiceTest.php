<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Exercise locked migration writes with an isolated transactional runtime double.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;

/** Prove dry run, persistence failures, idempotency and guarded rollback. */
final class VariationMigrationServiceTest extends TestCase {
	/**
	 * Execute a no-network subprocess fixture.
	 *
	 * @param string $scenario Scenario.
	 * @return array
	 */
	private function fixture( $scenario ) {
		$command   = 'ASHBI_VARIATION_SCENARIO=' . escapeshellarg( $scenario ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/run-variation-migration.php' );
		$output    = array();
		$exit_code = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated local unit fixture only.
		exec( $command, $output, $exit_code );
		self::assertSame( 0, $exit_code );
		$result = json_decode( implode( "\n", $output ), true );
		self::assertIsArray( $result );
		return $result;
	}

	/** Dry run writes only a non-autoload report, never product data. */
	public function test_dry_run_is_default_and_product_safe(): void {
		$result = $this->fixture( 'dry' );
		self::assertNull( $result['error'] );
		self::assertTrue( $result['same_as_before'] );
		self::assertSame( 'dry_run', $result['mode'] );
		self::assertFalse( $result['autoload']['subscrpt_variation_migration_report'] );
	}

	/** Applying twice creates only one group, term and exact relation. */
	public function test_apply_idempotency_and_untouched_subscription(): void {
		$result = $this->fixture( 'apply' );
		self::assertNull( $result['error'] );
		self::assertTrue( $result['idempotent'] );
		self::assertTrue( $result['dry_rollback_unchanged'] );
		self::assertCount( 1, $result['catalogue']['relations'] );
		self::assertSame( 11, $result['catalogue']['relations'][1]['vid'] );
		self::assertSame( array( 'price_source' => 'variation' ), $result['catalogue']['relations'][1]['data'] );
		self::assertSame( 'yes', $result['catalogue']['meta'][10]['_subscrpt_variation_term_mode'] );
		self::assertSame( array(), $result['catalogue']['meta'][12] );
		self::assertSame(
			array(
				'_subscrpt_price'     => 'unchanged',
				'_subscrpt_next_date' => 'unchanged',
			),
			$result['catalogue']['meta'][99]
		);
	}

	/** Classic simple opt-in controls writes and is bound to the reviewed fingerprint. */
	public function test_simple_fleet_safety_and_fingerprint(): void {
		$default = $this->fixture( 'simple_default' );
		self::assertNull( $default['error'] );
		self::assertTrue( $default['same_as_before'] );
		self::assertSame( array( 'classic_simple' ), $default['actions'] );
		self::assertSame( array( 'classic_simple' => 1 ), $default['totals'] );
		$ordinary = $this->fixture( 'ordinary_simple' );
		self::assertNull( $ordinary['error'] );
		self::assertTrue( $ordinary['same_as_before'] );
		self::assertSame( array(), $ordinary['totals'] );
		$included = $this->fixture( 'simple_include' );
		self::assertNull( $included['error'] );
		self::assertCount( 1, $included['catalogue']['relations'] );
		self::assertSame( 0, $included['catalogue']['relations'][1]['vid'] );
		self::assertSame( array( 'price_source' => 'product' ), $included['catalogue']['relations'][1]['data'] );
		$stale = $this->fixture( 'simple_stale' );
		self::assertNotNull( $stale['error'] );
		self::assertTrue( $stale['same_as_before'] );
	}

	/** A failed relation insert rolls back newly created group and term too. */
	public function test_failed_write_rolls_back_all_data(): void {
		$result = $this->fixture( 'failure' );
		self::assertNotNull( $result['error'] );
		self::assertTrue( $result['same_as_before'] );
	}

	/** Rollback restores exact prior rows and meta, including removed stopgaps. */
	public function test_rollback_is_exact_and_restores_stopgaps(): void {
		foreach ( array( 'rollback', 'stopgap' ) as $scenario ) {
			$result = $this->fixture( $scenario );
			self::assertNull( $result['error'] );
			self::assertSame( array(), $result['rollback_errors'] );
			self::assertTrue( $result['same_as_before'] );
			self::assertSame( 'rollback_apply', $result['mode'] );
		}
	}

	/** Later edits prevent a destructive rollback. */
	public function test_rollback_refuses_modified_owned_rows(): void {
		$result = $this->fixture( 'changed' );
		self::assertNotEmpty( $result['rollback_errors'] );
		self::assertCount( 1, $result['catalogue']['relations'] );
		self::assertSame( 'rollback_dry_run', $result['mode'] );
	}

	/** Stale review, concurrent workers and nontransactional tables fail closed. */
	public function test_safety_preflights(): void {
		foreach ( array( 'stale', 'busy', 'engine' ) as $scenario ) {
			$result = $this->fixture( $scenario );
			self::assertNotNull( $result['error'] );
			self::assertSame( array(), $result['catalogue']['groups'] );
			self::assertSame( array(), $result['catalogue']['relations'] );
		}
	}
}

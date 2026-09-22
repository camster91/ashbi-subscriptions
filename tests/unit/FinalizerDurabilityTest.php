<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit fixture intentionally retains its historical test filename.
/**
 * Verify split-payment finalization and migration quarantine durability.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound -- This fixture intentionally combines WordPress stubs, doubles, and PHPUnit tests.

/** Provide the database behavior needed by finalizer tests. */
final class FinalizerWpdbFake {
	/**
	 * Database table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';
	/**
	 * Posts table name.
	 *
	 * @var string
	 */
	public string $posts = 'wp_posts';
	/**
	 * Postmeta table name.
	 *
	 * @var string
	 */
	public string $postmeta = 'wp_postmeta';
	/**
	 * Overdue subscription identifiers.
	 *
	 * @var array<int,string>
	 */
	public array $overdue_rows = array();

	/**
	 * Return the prepared SQL unchanged.
	 *
	 * @param mixed $sql SQL template.
	 * @param mixed ...$args Query arguments.
	 * @return mixed SQL template.
	 */
	public function prepare( $sql, ...$args ) {
		return $sql;
	}

	/**
	 * Return migration relation rows from the fixture.
	 *
	 * @param mixed $sql SQL statement.
	 * @return array<int,object> Relation rows.
	 */
	public function get_results( $sql ): array {
		return $GLOBALS['ashbi_finalizer_relations'];
	}

	/**
	 * Return overdue identifiers from the fixture.
	 *
	 * @param mixed $sql SQL statement.
	 * @return array<int,string> Overdue identifiers.
	 */
	public function get_col( $sql ): array {
		return $this->overdue_rows;
	}
}

/** Provide paid-order behavior for finalizer tests. */
final class FinalizerOrderFake {
	/** Report that the fixture order is paid. */
	public function is_paid(): bool {
		return true;
	}
}

/** Capture finalizer logger messages. */
final class FinalizerLoggerFake {
	/**
	 * Record a logger message.
	 *
	 * @param mixed $source Log source.
	 * @param mixed $message Log message.
	 */
	public function add( $source, $message ): void {
		$GLOBALS['ashbi_finalizer_logs'][] = array( $source, $message );
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Return post metadata from the isolated finalizer fixture.
	 *
	 * @param mixed $post_id Post identifier.
	 * @param mixed $key Metadata key.
	 * @param mixed $single Whether to return one value.
	 * @return mixed Fixture metadata.
	 */
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['ashbi_finalizer_meta'][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Return an option from the isolated finalizer fixture.
	 *
	 * @param mixed $key Option key.
	 * @param mixed $default Default value.
	 * @return mixed Fixture option value.
	 */
	function get_option( $key, $default = false ) {
		return $GLOBALS['ashbi_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Update an option in the isolated finalizer fixture.
	 *
	 * @param mixed $key Option key.
	 * @param mixed $value Option value.
	 * @param mixed $autoload Autoload setting.
	 * @return bool Whether the write succeeded.
	 */
	function update_option( $key, $value, $autoload = null ) {
		if ( in_array( $key, $GLOBALS['ashbi_fail_option_writes'] ?? array(), true ) ) {
			return false;
		}
		$GLOBALS['ashbi_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Delete an option from the isolated finalizer fixture.
	 *
	 * @param mixed $key Option key.
	 * @return bool Whether the delete succeeded.
	 */
	function delete_option( $key ) {
		if ( in_array( $key, $GLOBALS['ashbi_fail_option_deletes'] ?? array(), true ) ) {
			return false;
		}
		unset( $GLOBALS['ashbi_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Return the fixed finalizer fixture time.
	 *
	 * @param mixed $type Time format.
	 * @param mixed $gmt Whether to use GMT.
	 * @return string Fixture timestamp.
	 */
	function current_time( $type, $gmt = false ) {
		return '2026-09-14 23:15:00';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Update post metadata in the isolated finalizer fixture.
	 *
	 * @param mixed $post_id Post identifier.
	 * @param mixed $key Metadata key.
	 * @param mixed $value Metadata value.
	 * @return bool Whether the write succeeded.
	 */
	function update_post_meta( $post_id, $key, $value ) {
		if ( '_subscrpt_split_payment_completed_fired' === $key && ! empty( $GLOBALS['ashbi_finalizer_fail_marker'] ) ) {
			return false;
		}
		$GLOBALS['ashbi_finalizer_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * Delete post metadata from the isolated finalizer fixture.
	 *
	 * @param mixed $post_id Post identifier.
	 * @param mixed $key Metadata key.
	 * @return bool Whether the delete succeeded.
	 */
	function delete_post_meta( $post_id, $key ) {
		if ( '_subscrpt_next_date' === $key && ! empty( $GLOBALS['ashbi_finalizer_fail_delete'] ) ) {
			return false;
		}
		unset( $GLOBALS['ashbi_finalizer_meta'][ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	/**
	 * Return a fixture post status.
	 *
	 * @param mixed $post_id Post identifier.
	 * @return mixed Fixture post status.
	 */
	function get_post_status( $post_id ) {
		return $GLOBALS['ashbi_finalizer_status'][ $post_id ] ?? false;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * Update a fixture post status.
	 *
	 * @param mixed $postarr Post fields.
	 * @param mixed $wp_error Whether to return a WP error.
	 * @return mixed Updated post identifier.
	 */
	function wp_update_post( $postarr, $wp_error = false ) {
		if ( ! empty( $GLOBALS['ashbi_finalizer_fail_status'] ) ) {
			return 0;
		}
		$GLOBALS['ashbi_finalizer_status'][ $postarr['ID'] ] = $postarr['post_status'];
		return $postarr['ID'];
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Report whether a fixture value is a WordPress error.
	 *
	 * @param mixed $thing Value to inspect.
	 * @return bool Whether the value is an error.
	 */
	function is_wp_error( $thing ): bool {
		return false;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Return the unfiltered fixture value.
	 *
	 * @param mixed $hook Filter name.
	 * @param mixed $value Filter value.
	 * @param mixed ...$args Additional filter arguments.
	 * @return mixed Unfiltered value.
	 */
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Record an action dispatched by the fixture.
	 *
	 * @param mixed $hook Hook name.
	 * @param mixed ...$args Hook arguments.
	 */
	function do_action( $hook, ...$args ) {
		$GLOBALS['ashbi_actions'][] = array( $hook, $args );
	}
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	/** Return the isolated finalizer logger. */
	function wc_get_logger() {
		return new FinalizerLoggerFake();
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Return an escaped fixture value.
	 *
	 * @param mixed $value Value to escape.
	 * @return mixed Escaped value.
	 */
	function esc_html( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encode a fixture value as JSON.
	 *
	 * @param mixed $value Value to encode.
	 * @return string JSON value.
	 */
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/functions.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Installer.php';

/** Verify finalization and migration quarantine remain retry-safe. */
final class FinalizerDurabilityTest extends TestCase {
	/** Reset isolated finalizer state. */
	protected function setUp(): void {
		$GLOBALS['wpdb']                        = new FinalizerWpdbFake();
		$GLOBALS['ashbi_orders']                = array( 10 => new FinalizerOrderFake() );
		$GLOBALS['ashbi_finalizer_relations']   = array(
			(object) array(
				'order_id' => 10,
				'type'     => 'new',
			),
		);
		$GLOBALS['ashbi_finalizer_meta']        = array(
			42 => array(
				'_subscrpt_product_id' => 5,
				'_subscrpt_next_date'  => 2000000000,
			),
			5  => array( '_subscrpt_max_no_payment' => 1 ),
		);
		$GLOBALS['ashbi_finalizer_status']      = array( 42 => 'active' );
		$GLOBALS['ashbi_finalizer_fail_status'] = false;
		$GLOBALS['ashbi_finalizer_fail_delete'] = false;
		$GLOBALS['ashbi_finalizer_fail_marker'] = false;
		$GLOBALS['ashbi_finalizer_logs']        = array();
		$GLOBALS['ashbi_actions']               = array();
		$GLOBALS['ashbi_options']               = array();
		$GLOBALS['ashbi_fail_option_writes']    = array();
		$GLOBALS['ashbi_fail_option_deletes']   = array();
	}

	/**
	 * Invoke the installer's source-owned block persistence helper.
	 *
	 * @param int[]|null $claim_ids Claim quarantine.
	 * @param int[]|null $overdue_ids Overdue quarantine.
	 */
	private function persistMigrationBlocks( $claim_ids, $overdue_ids ): bool {
		$installer = new \SpringDevs\Subscription\Installer();
		$method    = new ReflectionMethod( $installer, 'persist_migration_block_sources' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (bool) $method->invoke( $installer, $claim_ids, $overdue_ids );
	}

	/** Verify status-write failure stops finalization. */
	public function test_status_write_failure_stops_finalization(): void {
		$GLOBALS['ashbi_finalizer_fail_status'] = true;

		$this->assertFalse( subscrpt_finalize_split_payment_completion( 42 ) );
		$this->assertSame( 'active', get_post_status( 42 ) );
		$this->assertArrayNotHasKey( '_subscrpt_split_payment_completed_fired', $GLOBALS['ashbi_finalizer_meta'][42] );
	}

	/** Verify next-date deletion failure stops before the callback. */
	public function test_next_date_delete_failure_stops_before_callback(): void {
		$GLOBALS['ashbi_finalizer_fail_delete'] = true;

		$this->assertFalse( subscrpt_finalize_split_payment_completion( 42 ) );
		$this->assertSame( array(), $GLOBALS['ashbi_actions'] );
		$this->assertArrayNotHasKey( '_subscrpt_split_payment_completed_fired', $GLOBALS['ashbi_finalizer_meta'][42] );
	}

	/** Verify callback-marker failure remains replayable. */
	public function test_callback_marker_failure_remains_replayable(): void {
		$GLOBALS['ashbi_finalizer_fail_marker'] = true;

		$this->assertFalse( subscrpt_finalize_split_payment_completion( 42 ) );
		$this->assertSame( 'subscrpt_split_payment_completed', $GLOBALS['ashbi_actions'][0][0] );
		$this->assertArrayNotHasKey( '_subscrpt_split_payment_completed_fired', $GLOBALS['ashbi_finalizer_meta'][42] );
	}

	/** Verify all completion effects are checked on success. */
	public function test_all_completion_effects_are_verified_on_success(): void {
		$this->assertTrue( subscrpt_finalize_split_payment_completion( 42 ) );
		$this->assertSame( 'completed', get_post_status( 42 ) );
		$this->assertArrayNotHasKey( '_subscrpt_next_date', $GLOBALS['ashbi_finalizer_meta'][42] );
		$this->assertTrue( (bool) get_post_meta( 42, '_subscrpt_split_payment_completed_fired', true ) );
	}

	/** Verify migration quarantine is scoped to affected subscriptions. */
	public function test_migration_quarantine_is_scoped_to_affected_subscription_ids(): void {
		$GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] = array( 42, 44 );

		$this->assertTrue( subscrpt_renewal_is_migration_blocked( 42 ) );
		$this->assertFalse( subscrpt_renewal_is_migration_blocked( 43 ) );
		$this->assertTrue( subscrpt_renewal_is_migration_blocked() );
	}

	/** Verify a legacy scalar migration block remains fail-closed. */
	public function test_legacy_scalar_migration_block_remains_fail_closed(): void {
		$GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] = true;

		$this->assertTrue( subscrpt_renewal_is_migration_blocked( 43 ) );
	}

	/** Verify overdue upgrade quarantines only detected IDs without mutation. */
	public function test_overdue_upgrade_quarantines_only_the_detected_ids_without_mutating_records(): void {
		$GLOBALS['wpdb']->overdue_rows = array( '42', '44', '42' );
		$installer                     = new \SpringDevs\Subscription\Installer();
		$method                        = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $installer );

		$this->assertSame( array( 42, 44 ), $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );
		$this->assertSame( array( 42, 44 ), $GLOBALS['ashbi_options']['subscrpt_overdue_renewal_quarantine_1'] );
		$this->assertSame( '2026-09-14 23:15:00', $GLOBALS['ashbi_options']['subscrpt_overdue_renewal_quarantine_1_completed_at'] );
		$this->assertSame( 'active', get_post_status( 42 ) );
		$this->assertSame( 2000000000, (int) get_post_meta( 42, '_subscrpt_next_date', true ) );
	}

	/** Verify an empty overdue scan still persists its completion marker. */
	public function test_empty_overdue_scan_still_persists_its_completion_marker(): void {
		$installer = new \SpringDevs\Subscription\Installer();
		$method    = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $installer );

		$this->assertSame( array(), $GLOBALS['ashbi_options']['subscrpt_overdue_renewal_quarantine_1'] );
		$this->assertSame( '2026-09-14 23:15:00', $GLOBALS['ashbi_options']['subscrpt_overdue_renewal_quarantine_1_completed_at'] );
		$this->assertArrayNotHasKey( 'subscrpt_renewal_migration_blocked', $GLOBALS['ashbi_options'] );
	}

	/** Verify claim and overdue sources are merged without clobbering each other. */
	public function test_claim_and_overdue_sources_are_unioned_without_clobbering_each_other(): void {
		$this->assertTrue( $this->persistMigrationBlocks( array( 42 ), array( 44 ) ) );
		$this->assertSame( array( 42, 44 ), $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );

		$this->assertTrue( $this->persistMigrationBlocks( array( 43 ), null ) );
		$this->assertSame( array( 43, 44 ), $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );

		$this->assertTrue( $this->persistMigrationBlocks( array(), null ) );
		$this->assertSame( array( 44 ), $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );
	}

	/** Verify unknown existing IDs become explicit operator holds. */
	public function test_unknown_existing_ids_become_explicit_operator_holds(): void {
		$GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] = array( 99 );

		$this->assertTrue( $this->persistMigrationBlocks( array( 42 ), array( 44 ) ) );
		$this->assertSame( array( 99 ), $GLOBALS['ashbi_options']['subscrpt_renewal_operator_hold_1'] );
		$this->assertSame( array( 42, 44, 99 ), $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );
	}

	/** Verify a legacy scalar block is preserved during overdue migration. */
	public function test_legacy_scalar_block_is_preserved_and_overdue_migration_does_not_complete(): void {
		$GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] = true;
		$GLOBALS['wpdb']->overdue_rows                                  = array( '42' );
		$installer = new \SpringDevs\Subscription\Installer();
		$method    = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $installer );

		$this->assertTrue( $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );
		$this->assertArrayNotHasKey( 'subscrpt_overdue_renewal_quarantine_1_completed_at', $GLOBALS['ashbi_options'] );
	}

	/** Verify inventory-write failure prevents the completion marker. */
	public function test_inventory_write_failure_prevents_completion_marker(): void {
		$GLOBALS['wpdb']->overdue_rows       = array( '42' );
		$GLOBALS['ashbi_fail_option_writes'] = array( 'subscrpt_overdue_renewal_quarantine_1' );
		$installer                           = new \SpringDevs\Subscription\Installer();
		$method                              = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $installer );

		$this->assertArrayNotHasKey( 'subscrpt_overdue_renewal_quarantine_1_completed_at', $GLOBALS['ashbi_options'] );
	}

	/** Verify active-block write failure prevents the completion marker. */
	public function test_active_block_write_failure_prevents_completion_marker(): void {
		$GLOBALS['wpdb']->overdue_rows       = array( '42' );
		$GLOBALS['ashbi_fail_option_writes'] = array( 'subscrpt_renewal_migration_blocked' );
		$installer                           = new \SpringDevs\Subscription\Installer();
		$method                              = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $installer );

		$this->assertArrayNotHasKey( 'subscrpt_overdue_renewal_quarantine_1_completed_at', $GLOBALS['ashbi_options'] );
	}

	/** Verify completion-marker failure remains retryable. */
	public function test_completion_marker_write_failure_remains_retryable(): void {
		$GLOBALS['ashbi_fail_option_writes'] = array( 'subscrpt_overdue_renewal_quarantine_1_completed_at' );
		$installer                           = new \SpringDevs\Subscription\Installer();
		$method                              = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $installer );

		$this->assertArrayNotHasKey( 'subscrpt_overdue_renewal_quarantine_1_completed_at', $GLOBALS['ashbi_options'] );
		$GLOBALS['ashbi_fail_option_writes'] = array();
		$method->invoke( $installer );
		$this->assertSame( '2026-09-14 23:15:00', $GLOBALS['ashbi_options']['subscrpt_overdue_renewal_quarantine_1_completed_at'] );
	}
}

<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

final class FinalizerWpdbFake {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public array $overdue_rows = array();

	public function prepare( $sql, ...$args ) {
		return $sql;
	}

	public function get_results( $sql ): array {
		return $GLOBALS['ashbi_finalizer_relations'];
	}

	public function get_col( $sql ): array {
		return $this->overdue_rows;
	}
}

final class FinalizerOrderFake {
	public function is_paid(): bool {
		return true;
	}
}

final class FinalizerLoggerFake {
	public function add( $source, $message ): void {
		$GLOBALS['ashbi_finalizer_logs'][] = array( $source, $message );
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['ashbi_finalizer_meta'][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['ashbi_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		if ( in_array( $key, $GLOBALS['ashbi_fail_option_writes'] ?? array(), true ) ) {
			return false;
		}
		$GLOBALS['ashbi_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		if ( in_array( $key, $GLOBALS['ashbi_fail_option_deletes'] ?? array(), true ) ) {
			return false;
		}
		unset( $GLOBALS['ashbi_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = false ) {
		return '2026-09-14 23:15:00';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		if ( '_subscrpt_split_payment_completed_fired' === $key && ! empty( $GLOBALS['ashbi_finalizer_fail_marker'] ) ) {
			return false;
		}
		$GLOBALS['ashbi_finalizer_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		if ( '_subscrpt_next_date' === $key && ! empty( $GLOBALS['ashbi_finalizer_fail_delete'] ) ) {
			return false;
		}
		unset( $GLOBALS['ashbi_finalizer_meta'][ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post_id ) {
		return $GLOBALS['ashbi_finalizer_status'][ $post_id ] ?? false;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr, $wp_error = false ) {
		if ( ! empty( $GLOBALS['ashbi_finalizer_fail_status'] ) ) {
			return 0;
		}
		$GLOBALS['ashbi_finalizer_status'][ $postarr['ID'] ] = $postarr['post_status'];
		return $postarr['ID'];
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return false;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['ashbi_actions'][] = array( $hook, $args );
	}
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new FinalizerLoggerFake();
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/functions.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Installer.php';

final class FinalizerDurabilityTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FinalizerWpdbFake();
		$GLOBALS['ashbi_orders'] = array( 10 => new FinalizerOrderFake() );
		$GLOBALS['ashbi_finalizer_relations'] = array( (object) array( 'order_id' => 10, 'type' => 'new' ) );
		$GLOBALS['ashbi_finalizer_meta'] = array(
			42 => array(
				'_subscrpt_product_id'    => 5,
				'_subscrpt_next_date'     => 2000000000,
			),
			5 => array( '_subscrpt_max_no_payment' => 1 ),
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

	public function test_status_write_failure_stops_finalization(): void {
		$GLOBALS['ashbi_finalizer_fail_status'] = true;

		$this->assertFalse( subscrpt_finalize_split_payment_completion( 42 ) );
		$this->assertSame( 'active', get_post_status( 42 ) );
		$this->assertArrayNotHasKey( '_subscrpt_split_payment_completed_fired', $GLOBALS['ashbi_finalizer_meta'][42] );
	}

	public function test_next_date_delete_failure_stops_before_callback(): void {
		$GLOBALS['ashbi_finalizer_fail_delete'] = true;

		$this->assertFalse( subscrpt_finalize_split_payment_completion( 42 ) );
		$this->assertSame( array(), $GLOBALS['ashbi_actions'] );
		$this->assertArrayNotHasKey( '_subscrpt_split_payment_completed_fired', $GLOBALS['ashbi_finalizer_meta'][42] );
	}

	public function test_callback_marker_failure_remains_replayable(): void {
		$GLOBALS['ashbi_finalizer_fail_marker'] = true;

		$this->assertFalse( subscrpt_finalize_split_payment_completion( 42 ) );
		$this->assertSame( 'subscrpt_split_payment_completed', $GLOBALS['ashbi_actions'][0][0] );
		$this->assertArrayNotHasKey( '_subscrpt_split_payment_completed_fired', $GLOBALS['ashbi_finalizer_meta'][42] );
	}

	public function test_all_completion_effects_are_verified_on_success(): void {
		$this->assertTrue( subscrpt_finalize_split_payment_completion( 42 ) );
		$this->assertSame( 'completed', get_post_status( 42 ) );
		$this->assertArrayNotHasKey( '_subscrpt_next_date', $GLOBALS['ashbi_finalizer_meta'][42] );
		$this->assertTrue( (bool) get_post_meta( 42, '_subscrpt_split_payment_completed_fired', true ) );
	}

	public function test_migration_quarantine_is_scoped_to_affected_subscription_ids(): void {
		$GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] = array( 42, 44 );

		$this->assertTrue( subscrpt_renewal_is_migration_blocked( 42 ) );
		$this->assertFalse( subscrpt_renewal_is_migration_blocked( 43 ) );
		$this->assertTrue( subscrpt_renewal_is_migration_blocked() );
	}

	public function test_legacy_scalar_migration_block_remains_fail_closed(): void {
		$GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] = true;

		$this->assertTrue( subscrpt_renewal_is_migration_blocked( 43 ) );
	}

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

	public function test_claim_and_overdue_sources_are_unioned_without_clobbering_each_other(): void {
		$this->assertTrue( $this->persistMigrationBlocks( array( 42 ), array( 44 ) ) );
		$this->assertSame( array( 42, 44 ), $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );

		$this->assertTrue( $this->persistMigrationBlocks( array( 43 ), null ) );
		$this->assertSame( array( 43, 44 ), $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );

		$this->assertTrue( $this->persistMigrationBlocks( array(), null ) );
		$this->assertSame( array( 44 ), $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );
	}

	public function test_unknown_existing_ids_become_explicit_operator_holds(): void {
		$GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] = array( 99 );

		$this->assertTrue( $this->persistMigrationBlocks( array( 42 ), array( 44 ) ) );
		$this->assertSame( array( 99 ), $GLOBALS['ashbi_options']['subscrpt_renewal_operator_hold_1'] );
		$this->assertSame( array( 42, 44, 99 ), $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );
	}

	public function test_legacy_scalar_block_is_preserved_and_overdue_migration_does_not_complete(): void {
		$GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] = true;
		$GLOBALS['wpdb']->overdue_rows = array( '42' );
		$installer = new \SpringDevs\Subscription\Installer();
		$method    = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $installer );

		$this->assertTrue( $GLOBALS['ashbi_options']['subscrpt_renewal_migration_blocked'] );
		$this->assertArrayNotHasKey( 'subscrpt_overdue_renewal_quarantine_1_completed_at', $GLOBALS['ashbi_options'] );
	}

	public function test_inventory_write_failure_prevents_completion_marker(): void {
		$GLOBALS['wpdb']->overdue_rows = array( '42' );
		$GLOBALS['ashbi_fail_option_writes'] = array( 'subscrpt_overdue_renewal_quarantine_1' );
		$installer = new \SpringDevs\Subscription\Installer();
		$method    = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $installer );

		$this->assertArrayNotHasKey( 'subscrpt_overdue_renewal_quarantine_1_completed_at', $GLOBALS['ashbi_options'] );
	}

	public function test_active_block_write_failure_prevents_completion_marker(): void {
		$GLOBALS['wpdb']->overdue_rows = array( '42' );
		$GLOBALS['ashbi_fail_option_writes'] = array( 'subscrpt_renewal_migration_blocked' );
		$installer = new \SpringDevs\Subscription\Installer();
		$method    = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $installer );

		$this->assertArrayNotHasKey( 'subscrpt_overdue_renewal_quarantine_1_completed_at', $GLOBALS['ashbi_options'] );
	}

	public function test_completion_marker_write_failure_remains_retryable(): void {
		$GLOBALS['ashbi_fail_option_writes'] = array( 'subscrpt_overdue_renewal_quarantine_1_completed_at' );
		$installer = new \SpringDevs\Subscription\Installer();
		$method    = new ReflectionMethod( $installer, 'backfill_overdue_renewal_quarantine' );
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

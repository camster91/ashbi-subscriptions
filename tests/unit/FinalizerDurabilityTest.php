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

	public function prepare( $sql, ...$args ) {
		return $sql;
	}

	public function get_results( $sql ): array {
		return $GLOBALS['ashbi_finalizer_relations'];
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
}

<?php
/**
 * Disposable database and rendering checks for persistent customer receipts.
 *
 * @package AshbiSubscriptions\Tests
 */

/**
 * Read only fabricated cancellation proof within the isolated evidence harness.
 *
 * @param callable $check Harness assertion collector.
 * @throws RuntimeException When fixture creation fails.
 */
function ashbi_check_cancellation_receipt( callable $check ): void {
	global $wpdb;
	$id       = 0;
	$table    = $wpdb->prefix . 'subscrpt_cancellation_barrier';
	$settings = array();
	foreach ( array( 'timezone_string', 'date_format', 'time_format' ) as $key ) {
		$settings[ $key ] = get_option( $key );
	}
	try {
		$id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'cancelled',
				'post_author' => get_current_user_id(),
				'post_title'  => 'Fabricated persistent cancellation receipt',
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			throw new RuntimeException( 'Disposable receipt fixture could not be created.' );
		}
		$reader = \SpringDevs\Subscription\Illuminate\CancellationEvidence::class;
		$notice = ( new ReflectionClass( \SpringDevs\Subscription\Illuminate\Cancellation::class ) )->newInstanceWithoutConstructor();
		$render = static function () use ( $notice, $id ) {
			ob_start();
			$notice->display_pending_cancellation_notice( $id );
			return ob_get_clean();
		};
		update_post_meta( $id, '_ashbi_cancellation_confirmed', 1 );
		$check( 'none' === $reader::receipt( $id )['state'] && '' === $render(), 'Status or confirmation metadata invented a cancellation receipt without recorded intent.' );
		$end      = strtotime( '2026-11-01 06:30:00 UTC' );
		$inserted = $wpdb->insert(
			$table,
			array(
				'subscription_id' => $id,
				'actor_id'        => get_current_user_id(),
				'request_id'      => hash( 'sha256', 'fabricated-receipt-' . $id ),
				'requested_at'    => current_time( 'mysql', true ),
				'access_end'      => $end,
			),
			array( '%d', '%d', '%s', '%s', '%d' )
		);
		$check( false !== $inserted, 'Could not insert fabricated receipt intent.' );
		$before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d', $table, $id ) );
		$first  = $render();
		$check( false !== strpos( $first, 'Cancellation confirmed.' ) && $first === $render(), 'Saved customer confirmation disappeared on a repeated account read.' );
		$after = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d', $table, $id ) );
		$check( (array) $before === (array) $after, 'Reading a receipt changed immutable intent.' );

		update_post_meta( $id, '_ashbi_cancellation_confirmed', 0 );
		$cached                                  = get_post_meta( $id );
		$cached['_ashbi_cancellation_confirmed'] = array( '1' );
		wp_cache_set( $id, $cached, 'post_meta' );
		$check( '1' === get_post_meta( $id, '_ashbi_cancellation_confirmed', true ), 'Stale-cache fixture was not established.' );
		$check( 'pending' === $reader::receipt( $id )['state'] && false !== strpos( $render(), 'Confirmation is pending' ), 'Stale confirmation metadata overstated the saved cancellation result.' );
		wp_cache_delete( $id, 'post_meta' );
		add_post_meta( $id, '_ashbi_cancellation_confirmed', 1 );
		$check( 'pending' === $reader::receipt( $id )['state'], 'Conflicting confirmation records were treated as complete proof.' );
		update_post_meta( $id, '_ashbi_cancellation_confirmed', 1 );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'pe_cancelled',
			)
		);
		update_post_meta( $id, '_subscrpt_cancel_at', $end + DAY_IN_SECONDS );
		update_option( 'timezone_string', 'America/Toronto' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		$check( false !== strpos( $render(), '2026-11-01 01:30' ), 'Receipt did not use the original access end in the store timezone at the DST boundary.' );

		$prefix          = $wpdb->prefix;
		$previous_errors = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test a missing disposable lookup table, restoring immediately below.
			$wpdb->prefix = $prefix . 'fabricated_missing_';
			$check( 'unavailable' === $reader::receipt( $id )['state'] && false !== strpos( $render(), 'temporarily unavailable' ), 'Storage failure claimed a cancellation outcome or lost the account notice.' );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact parent harness connection prefix.
			$wpdb->prefix = $prefix;
			$wpdb->suppress_errors( $previous_errors );
		}
	} finally {
		foreach ( $settings as $key => $value ) {
			update_option( $key, $value );
		}
		if ( $id && ! is_wp_error( $id ) ) {
			$wpdb->delete( $table, array( 'subscription_id' => $id ), array( '%d' ) );
			wp_delete_post( $id, true );
		}
	}
}

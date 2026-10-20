<?php
/**
 * Disposable database membership and pagination checks for cancellation review.
 *
 * @package AshbiSubscriptions\Tests
 */

/**
 * Use fabricated records only inside the existing isolated evidence harness.
 *
 * @param callable $check Harness assertion collector.
 * @throws RuntimeException When disposable fixture creation fails.
 */
function ashbi_check_cancellation_review( callable $check ): void {
	global $wpdb;
	$ids        = array();
	$expected   = array();
	$table      = $wpdb->prefix . 'subscrpt_cancellation_barrier';
	$query_args = array(
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => 2,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	);
	try {
		$cases = array(
			'legacy'    => array( 'pe_cancelled', false, false, 'subscrpt_order' ),
			'pending'   => array( 'pe_cancelled', true, false, 'subscrpt_order' ),
			'unknown'   => array( 'cancelled', true, false, 'subscrpt_order' ),
			'confirmed' => array( 'pe_cancelled', true, true, 'subscrpt_order' ),
			'flag_only' => array( 'pe_cancelled', false, true, 'subscrpt_order' ),
			'active'    => array( 'active', true, false, 'subscrpt_order' ),
			'other'     => array( 'publish', true, false, 'post' ),
		);
		foreach ( $cases as $name => $case ) {
			$id = wp_insert_post(
				array(
					'post_type'   => $case[3],
					'post_status' => $case[0],
					'post_title'  => 'Fabricated cancellation review ' . $name,
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				throw new RuntimeException( 'Disposable cancellation review fixture could not be created.' );
			}
			$ids[ $name ] = $id;
			update_post_meta( $id, '_subscrpt_auto_renew', 0 );
			if ( $case[1] ) {
				$inserted = $wpdb->insert(
					$table,
					array(
						'subscription_id' => $id,
						'actor_id'        => 0,
						'request_id'      => hash( 'sha256', 'fabricated-review-' . $id ),
						'requested_at'    => current_time( 'mysql', true ),
						'access_end'      => time() + DAY_IN_SECONDS,
					),
					array( '%d', '%d', '%s', '%s', '%d' )
				);
				$check( false !== $inserted, 'Could not insert fabricated review intent.' );
			}
			if ( $case[2] ) {
				update_post_meta( $id, '_ashbi_cancellation_confirmed', 1 );
			}
			if ( in_array( $name, array( 'pending', 'unknown', 'active' ), true ) ) {
				$expected[] = $id;
			}
		}
		$query_args['post__in'] = array_values( $ids );
		$first                  = \SpringDevs\Subscription\Admin\CancellationReview::query( $query_args );
		$check( array_slice( $expected, 0, 2 ) === array_map( 'intval', $first->posts ), 'Cancellation review included unrecorded/confirmed/unrelated records or lost pending results.' );
		$check( 3 === (int) $first->found_posts && 2 === (int) $first->max_num_pages, 'Cancellation review pagination totals are wrong.' );
		$second = \SpringDevs\Subscription\Admin\CancellationReview::query( array_merge( $query_args, array( 'paged' => 2 ) ) );
		$check( array_slice( $expected, 2 ) === array_map( 'intval', $second->posts ), 'Cancellation review second page lost a request.' );
		$cancelled = \SpringDevs\Subscription\Admin\CancellationReview::query( array_merge( $query_args, array( 'post_status' => 'cancelled' ) ) );
		$check( array( $ids['unknown'] ) === array_map( 'intval', $cancelled->posts ), 'Cancellation review failed to combine status filters.' );
		update_post_meta( $ids['pending'], '_ashbi_cancellation_confirmed', 1 );
		$updated = \SpringDevs\Subscription\Admin\CancellationReview::query( $query_args );
		$check( array_slice( $expected, 1 ) === array_map( 'intval', $updated->posts ), 'Newly confirmed request remained cached in review.' );

		$prefix          = $wpdb->prefix;
		$previous_errors = $wpdb->suppress_errors( true );
		$failed_visibly  = false;
		try {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Change only the disposable table lookup; restore it immediately.
			$wpdb->prefix = $prefix . 'fabricated_missing_';
			\SpringDevs\Subscription\Admin\CancellationReview::query( $query_args );
		} catch ( RuntimeException $error ) {
			$failed_visibly = false !== strpos( $error->getMessage(), 'could not be loaded' );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact original fixture lookup prefix.
			$wpdb->prefix = $prefix;
			$wpdb->suppress_errors( $previous_errors );
		}
		$check( $failed_visibly, 'Unavailable cancellation storage was presented as an empty backlog.' );
		$restored = \SpringDevs\Subscription\Admin\CancellationReview::query( $query_args );
		$check( array_slice( $expected, 1 ) === array_map( 'intval', $restored->posts ), 'Failed review query leaked its SQL filter.' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve the local harness request, not submitted business data.
		$previous_get = $_GET;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserve the local harness request, not submitted business data.
		$previous_post   = $_POST;
		$previous_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$buffer_level    = ob_get_level();
		$warnings        = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Assert that rendering emits no warnings, restoring the handler below.
		set_error_handler(
			static function ( $severity, $message ) use ( &$warnings ) {
				$warnings[] = $message;
				return true;
			},
			E_WARNING | E_NOTICE
		);
		try {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Fabricated local-only request data, restored below.
			$_GET                      = array(
				'page'                => 'wp-subscription-list',
				'cancellation_review' => '1',
				's'                   => (string) $ids['unknown'],
			);
			$_POST                     = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			$menu = ( new ReflectionClass( \SpringDevs\Subscription\Admin\Menu::class ) )->newInstanceWithoutConstructor();
			ob_start();
			$menu->render_subscriptions_page();
			$html = ob_get_clean();
			$check( false !== strpos( $html, 'Cancellation needs review' ) && false !== strpos( $html, 'without confirmed results' ), 'Merchant cancellation review control or next-action explanation is missing.' );
			$check( false !== strpos( $html, 'wp-subscription-details' ) && false !== strpos( $html, 'id=' . $ids['unknown'] ), 'Merchant review lost its subscription action link.' );
			$_GET['s'] = (string) $ids['confirmed'];
			ob_start();
			$menu->render_subscriptions_page();
			$html = ob_get_clean();
			$check( false !== strpos( $html, 'No cancellation requests match these filters' ), 'List request did not apply cancellation confirmation filtering.' );
			$_GET['cancellation_review'] = 'invalid';
			ob_start();
			$menu->render_subscriptions_page();
			$html = ob_get_clean();
			$check( false === strpos( $html, 'without confirmed results' ) && false !== strpos( $html, 'id=' . $ids['confirmed'] ), 'Invalid filter value did not return the ordinary list.' );
			$check( array() === $warnings, 'Rendering cancellation review raised PHP warnings/notices: ' . implode( '; ', $warnings ) );
		} finally {
			restore_error_handler();
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Restore the exact parent harness request.
			$_GET  = $previous_get;
			$_POST = $previous_post;
			if ( null === $previous_method ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $previous_method;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}
	} finally {
		foreach ( $ids as $id ) {
			$wpdb->delete( $table, array( 'subscription_id' => $id ), array( '%d' ) );
			wp_delete_post( $id, true );
		}
	}
}

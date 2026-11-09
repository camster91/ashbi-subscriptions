<?php
/**
 * Fabricated merchant cancellation checks in the guarded local HPOS harness.
 *
 * @package AshbiSubscriptions\Tests
 */

/**
 * Exercise the existing merchant controller and real durable cancellation service.
 *
 * @param callable $check Harness assertion collector.
 * @throws RuntimeException When the actual controller fails to save or redirect.
 */
function ashbi_check_merchant_cancellation( callable $check ): void {
	global $wpdb;
	$actor = get_current_user_id();
	$ids   = array();
	$order = null;
	// phpcs:ignore WordPress.Security.NonceVerification -- Snapshot only; the actual controller validates each fabricated POST below.
	$request    = array( $_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? '' );
	$deny_die   = static function () {
		return static function ( $message ) {
			throw new RuntimeException( esc_html( wp_strip_all_tags( $message ) ) );
		};
	};
	$mail_error = static function () {
		throw new RuntimeException( 'Fabricated unavailable merchant mailer.' );
	};
	$menu       = ( new ReflectionClass( \SpringDevs\Subscription\Admin\Menu::class ) )->newInstanceWithoutConstructor();
	$render     = static function ( $id ) use ( $menu ) {
		$_GET                      = array( 'id' => $id );
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		try {
			$menu->render_subscription_details_page();
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	};
	try {
		$order = wc_create_order();
		$order->set_payment_method( 'stripe' );
		$order->save();
		foreach ( array( 'cancelled', 'pe_cancelled' ) as $target ) {
			$id    = wp_insert_post(
				array(
					'post_type'   => 'subscrpt_order',
					'post_status' => 'active',
					'post_author' => $actor,
					'post_title'  => 'Fabricated merchant cancellation',
				)
			);
			$ids[] = $id;
			update_post_meta( $id, '_subscrpt_order_id', $order->get_id() );
			update_post_meta( $id, '_subscrpt_auto_renew', 1 );
			update_post_meta( $id, '_subscrpt_user_cancel', 'no' );
			$wpdb->insert(
				$wpdb->prefix . 'subscrpt_order_relation',
				array(
					'subscription_id' => $id,
					'order_id'        => $order->get_id(),
					'order_item_id'   => 0,
					'type'            => 'new',
				)
			);
			$screen = $render( $id );
			$check( false !== strpos( $screen, 'End subscription now' ) && false !== strpos( $screen, 'Schedule cancellation' ), 'Merchant access choices are missing from the actual details controller.' );

			add_filter( 'wp_die_handler', $deny_die );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array(
				'subscrpt_order_action'             => $target,
				'subscrpt_order_action_nonce_field' => 'fabricated-invalid',
			);
			$denied                    = false;
			try {
				$menu->render_subscription_details_page();
			} catch ( RuntimeException $error ) {
				$denied = 'Security check failed.' === $error->getMessage();
			}
			remove_filter( 'wp_die_handler', $deny_die );
			$check( $denied && ! \SpringDevs\Subscription\Illuminate\CancellationEvidence::blocked( $id ), 'Invalid admin nonce changed cancellation state.' );

			wp_set_current_user( 0 );
			$result = \SpringDevs\Subscription\Admin\Subscriptions::process_status_change( $id, $target );
			$check( empty( $result['barrier'] ) && 'active' === get_post_status( $id ), 'Guest reached merchant cancellation.' );
			wp_set_current_user( $actor );
			add_action( 'subscrpt_status_changed_admin_email_notification', $mail_error, -999 );
			$location = '';
			$capture  = static function ( $url ) use ( &$location ) {
				$location = $url;
				throw new RuntimeException( 'fabricated_merchant_redirect' );
			};
			add_filter( 'wp_redirect', $capture, -100 );
			add_filter( 'wp_die_handler', $deny_die );
			$_POST['subscrpt_order_action_nonce_field'] = wp_create_nonce( 'subscrpt_order_action_nonce' );
			try {
				$menu->render_subscription_details_page();
			} catch ( RuntimeException $error ) {
				if ( 'fabricated_merchant_redirect' !== $error->getMessage() ) {
					throw $error;
				}
			} finally {
				remove_filter( 'wp_redirect', $capture, -100 );
				remove_filter( 'wp_die_handler', $deny_die );
				remove_action( 'subscrpt_status_changed_admin_email_notification', $mail_error, -999 );
			}
			$check( admin_url( 'admin.php?page=wp-subscription-details&id=' . $id ) === $location, 'Valid merchant cancellation did not return to the saved subscription.' );
			$result  = \SpringDevs\Subscription\Illuminate\CancellationEvidence::receipt( $id );
			$barrier = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d', $wpdb->prefix . 'subscrpt_cancellation_barrier', $id ), ARRAY_A );
			$check( 'confirmed' === $result['state'] && $barrier && (int) $barrier['actor_id'] === $actor && get_post_status( $id ) === $target, 'Merchant cancellation failed to retain actor, access choice or durable intent after email failure.' );
			$check( false === \SpringDevs\Subscription\Illuminate\Helper::create_renewal_order( $id ), 'Merchant-cancelled subscription remains eligible for renewal.' );
			$check( 0 === (int) get_post_meta( $id, '_subscrpt_auto_renew', true ), 'Merchant cancellation left automatic renewal enabled.' );
			$screen = $render( $id );
			$check( false !== strpos( $screen, 'Cancellation saved' ) && false !== strpos( $screen, 'Billing cancellation is confirmed' ) && false === strpos( $screen, 'Activate Subscription' ), 'Merchant receipt is absent or permits reopening a durable cancellation.' );
			\SpringDevs\Subscription\Admin\Subscriptions::process_status_change( $id, $target );
			$replay = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d', $wpdb->prefix . 'subscrpt_cancellation_barrier', $id ), ARRAY_A );
			$check( $barrier === $replay, 'Merchant replay rewrote original cancellation evidence.' );
			if ( 'pe_cancelled' === $target ) {
				$check( (int) $barrier['access_end'] > time() && false !== strpos( $screen, 'Access is scheduled to end on' ), 'Scheduled cancellation lost its original access date.' );
				\SpringDevs\Subscription\Admin\Subscriptions::process_status_change( $id, 'cancelled' );
				\SpringDevs\Subscription\Illuminate\CancellationEvidence::repair( $id );
				$final = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d', $wpdb->prefix . 'subscrpt_cancellation_barrier', $id ), ARRAY_A );
				$check( $barrier === $final && 'cancelled' === get_post_status( $id ), 'Ending existing scheduled access rewrote history or repair reopened access.' );
				$stale              = get_post( $id );
				$stale->post_status = 'active';
				wp_cache_set( $id, $stale, 'posts' );
				\SpringDevs\Subscription\Illuminate\CancellationEvidence::repair( $id );
				$stored_status = $wpdb->get_var( $wpdb->prepare( 'SELECT post_status FROM %i WHERE ID = %d', $wpdb->posts, $id ) );
				$check( 'cancelled' === $stored_status && 'cancelled' === get_post_status( $id ), 'Stale pre-lock status reopened access after merchant termination.' );
			}
		}
	} finally {
		remove_filter( 'wp_die_handler', $deny_die );
		remove_action( 'subscrpt_status_changed_admin_email_notification', $mail_error, -999 );
		wp_set_current_user( $actor );
		list( $_GET, $_POST, $_SERVER['REQUEST_METHOD'] ) = $request;
		foreach ( $ids as $id ) {
			foreach ( array( 'subscrpt_cancellation_barrier', 'subscrpt_evidence_event', 'subscrpt_order_relation' ) as $table ) {
				$wpdb->delete( $wpdb->prefix . $table, array( 'subscription_id' => $id ) );
			}
			as_unschedule_all_actions( 'subscrpt_repair_cancellation', array( $id ), 'ashbi-subscriptions' );
			wp_delete_post( $id, true );
		}
		if ( $order ) {
			$order->delete( true );
		}
	}
}

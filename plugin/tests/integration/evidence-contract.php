<?php
/**
 * Disposable real-database evidence and contract checks.
 *
 * @package AshbiSubscriptions
 */

/**
 * Run only from the existing isolated, capability-gated integration harness.
 *
 * @param callable $check Parent harness assertion collector.
 */
function ashbi_check_evidence_contract( callable $check ): bool {
	global $wpdb;
	$missing          = new stdClass();
	$previous         = get_option( 'wp_subscription_contract_revision', $missing );
	$mysql_contention = false;
	$actor            = get_current_user_id();
	$order            = null;
	$product          = null;
	$subscription     = 0;
	$deny_mail        = static function () {
		return false;
	};
	add_filter( 'pre_wp_mail', $deny_mail );
	$deny_http = static function () {
		return new WP_Error( 'isolated_no_provider', 'Outbound requests are blocked during fabricated evidence checks.' );
	};
	add_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
	try {
		$text     = 'Fabricated isolated contract: USD 12.50 every two months.';
		$document = array(
			'enabled'      => true,
			'version'      => 'isolated-v1',
			'text'         => $text,
			'hash'         => hash( 'sha256', $text ),
			'approval_ref' => 'isolated-fixture-only',
		);
		update_option( 'wp_subscription_contract_revision', $document );
		$consent = new \SpringDevs\Subscription\Frontend\ContractConsent();
		$order   = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_payment_method( 'stripe' );
		$product = new WC_Product_Simple();
		$product->set_name( 'Fabricated isolated contract product' );
		$product->set_regular_price( '12.50' );
		$product->save();
		$item = new WC_Order_Item_Product();
		$item->set_product_id( $product->get_id() );
		$item->set_quantity( 2 );
		$item->set_total( 25 );
		$line = array(
			'product_id'              => $product->get_id(),
			'quantity'                => 2,
			'subscrpt_plan_id'        => 7,
			'subscrpt_signup_fee'     => 0,
			'subscrpt_max_no_payment' => 0,
			'subscription'            => array(
				'per_cost' => 12.5,
				'time'     => 2,
				'type'     => 'months',
				'trial'    => null,
			),
		);
		$consent->stamp_line( $item, 'isolated-line', $line );
		$item->update_meta_data( '_subscrpt_plan_id', 7 );
		$item->update_meta_data( '_subscrpt_plan_price', 12.5 );
		$item->update_meta_data( '_subscrpt_plan_terms', $line['subscription'] );
		$item->update_meta_data( '_subscrpt_signup_fee', 0 );
		$item->update_meta_data( '_subscrpt_max_no_payment', 0 );
		$order->add_item( $item );
		$order->set_total( 25 );
		$order->update_meta_data( '_ashbi_contract_required', '1' );
		$order->update_meta_data(
			'_ashbi_contract_consent',
			array(
				'document'        => $document,
				'snapshot'        => \SpringDevs\Subscription\Frontend\ContractConsent::order_snapshot( $order ),
				'accepted_at'     => current_time( 'mysql', true ),
				'actor_id'        => $actor,
				'payment_outcome' => 'not_confirmed',
			)
		);
		$order->save();
		$consent->persist_acceptance( $order->get_id() );
		$reloaded = wc_get_order( $order->get_id() );
		$check( $consent->order_can_pay( true, $reloaded ), 'Real WooCommerce persisted contract did not match its final order.' );
		$document['enabled'] = false;
		update_option( 'wp_subscription_contract_revision', $document );
		$check( $consent->order_can_pay( true, $reloaded ), 'Disabled config rejected original unchanged acceptance.' );
		$reloaded->set_total( 26 );
		$check( ! $consent->order_can_pay( true, $reloaded ), 'Changed final amount bypassed disabled-config consent enforcement.' );

		$subscription = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_author' => $actor,
				'post_status' => 'active',
				'post_title'  => 'Fabricated durable cancellation',
			)
		);
		update_post_meta( $subscription, '_subscrpt_order_id', $order->get_id() );
		update_post_meta( $subscription, '_subscrpt_auto_renew', 1 );
		update_post_meta( $subscription, '_subscrpt_user_cancel', 'yes' );
		$wpdb->insert(
			$wpdb->prefix . 'subscrpt_order_relation',
			array(
				'subscription_id' => $subscription,
				'order_id'        => $order->get_id(),
				'order_item_id'   => $item->get_id(),
				'type'            => 'new',
			)
		);
		if ( extension_loaded( 'mysqli' ) && $wpdb->dbh instanceof mysqli ) {
			// Two separate connections exercise actual connection-owned dispatch locks.
			$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$second->set_prefix( $wpdb->prefix );
			$primary = $wpdb;
			$check( \SpringDevs\Subscription\Illuminate\CancellationEvidence::lock( $subscription ), 'Could not acquire isolated primary dispatch lock.' );
			try {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Disposable harness switches the connection to test real mutex contention.
				$wpdb   = $second;
				$result = \SpringDevs\Subscription\Illuminate\CancellationEvidence::request( $subscription, $actor, 'isolated-two-connection-request' );
				$check( 'pending' === $result['state'] && ! empty( $result['barrier'] ), 'Contended cancellation failed to preserve intent before the dispatch lock.' );
				$mysql_contention = 'pending' === $result['state'] && ! empty( $result['barrier'] );
			} finally {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore exact original connection even when an assertion raises.
				$wpdb = $primary;
				\SpringDevs\Subscription\Illuminate\CancellationEvidence::unlock( $subscription );
				$second->close();
			}
		} else {
			\SpringDevs\Subscription\Illuminate\CancellationEvidence::request( $subscription, $actor, 'isolated-request' );
		}
		$check( \SpringDevs\Subscription\Illuminate\CancellationEvidence::blocked( $subscription ), 'Real stored cancellation barrier missing.' );
		\SpringDevs\Subscription\Illuminate\CancellationEvidence::repair( $subscription );
		$check( 0 === (int) get_post_meta( $subscription, '_subscrpt_auto_renew', true ), 'Durable repair did not disable local billing.' );
		$check( in_array( get_post_status( $subscription ), array( 'pe_cancelled', 'cancelled' ), true ), 'Durable repair did not preserve cancelled lifecycle.' );
		$export = \SpringDevs\Subscription\Illuminate\EvidenceExport::build( $subscription );
		$check( $export['subscription_id'] === $subscription && ! empty( $export['events'] ) && ! empty( $export['orders'][0]['acceptance'] ), 'Real evidence export lost source records.' );
	} finally {
		if ( $missing === $previous ) {
			delete_option( 'wp_subscription_contract_revision' );
		} else {
			update_option( 'wp_subscription_contract_revision', $previous ); }
		if ( $subscription ) {
			foreach ( array( 'subscrpt_cancellation_barrier', 'subscrpt_evidence_event', 'subscrpt_order_relation' ) as $table ) {
				$wpdb->delete( $wpdb->prefix . $table, array( 'subscription_id' => $subscription ) ); }
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'subscrpt_repair_cancellation', array( $subscription ), 'ashbi-subscriptions' ); }
			wp_delete_post( $subscription, true );
		}
		if ( $order ) {
			$wpdb->delete( $wpdb->prefix . 'subscrpt_contract_acceptance', array( 'order_id' => $order->get_id() ) );
			$order->delete( true );
		}
		if ( $product ) {
			$product->delete( true );
		}
		remove_filter( 'pre_wp_mail', $deny_mail );
		remove_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
	}
	return $mysql_contention;
}

<?php
/**
 * Classic variation cart and subscription lifecycle checks on a disposable site.
 *
 * @package AshbiSubscriptions
 */

/**
 * Exercise registered listeners with real WooCommerce products and pending orders.
 *
 * This fixture does not call a gateway, checkout payment processor or renewal worker.
 * It must run inside the authenticated disposable integration harness.
 *
 * @param callable $check Parent harness assertion collector.
 * @return bool Whether all assertions passed.
 * @throws RuntimeException When execution is not an authorized disposable context.
 */
function ashbi_verify_classic_variation_cart( callable $check ): bool {
	if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || ! current_user_can( 'manage_options' ) ) {
		throw new RuntimeException( 'Classic variation fixtures require an authorized disposable local environment.' );
	}
	global $wpdb;
	$passed           = true;
	$missing          = new stdClass();
	$previous         = get_option( 'wp_subscription_contract_revision', $missing );
	$previous_session = WC()->session;
	if ( ! $previous_session ) {
		WC()->initialize_session();
	}
	$previous_cart   = WC()->cart;
	$cart            = $previous_cart instanceof WC_Cart ? $previous_cart : new WC_Cart();
	$cart_contents   = $cart->cart_contents;
	$removed_items   = $cart->removed_cart_contents;
	$previous_notice = function_exists( 'wc_get_notices' ) ? wc_get_notices() : array();
	$products        = array();
	$orders          = array();
	$subscriptions   = array();
	$assert          = static function ( $condition, $message ) use ( $check, &$passed ) {
		$passed = $passed && (bool) $condition;
		$check( (bool) $condition, $message );
	};
	$deny_mail       = static function () {
		return false;
	};
	$deny_http       = static function () {
		return new WP_Error( 'isolated_classic_variation', 'Outbound requests are blocked during fabricated variation checks.' );
	};
	$capture         = static function ( $subscription_id, $arguments, $item ) use ( &$subscriptions, &$orders ) {
		if ( in_array( (int) $item->get_order_id(), $orders, true ) ) {
			$subscriptions[] = (int) $subscription_id;
		}
	};
	add_filter( 'pre_wp_mail', $deny_mail );
	add_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
	add_action( 'subscrpt_split_payment_created', $capture, -100, 3 );
	try {
		WC()->cart = $cart;
		update_option( 'wp_subscription_contract_revision', array( 'enabled' => false ) );
		$assert( has_filter( 'woocommerce_add_cart_item_data' ), 'Registered cart listeners are unavailable.' );
		$assert( has_action( 'woocommerce_checkout_order_processed' ) && has_action( 'woocommerce_store_api_checkout_order_processed' ), 'Registered checkout listeners are unavailable.' );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Fabricated isolated classic variations' );
		$parent->set_status( 'publish' );
		$parent->save();
		$products[] = $parent->get_id();
		$variations = array();
		foreach ( array( 1, 2, 3, 0 ) as $cadence ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( '12.50' );
			$variation->set_virtual( true );
			$variation->update_meta_data( '_subscrpt_enabled', $cadence ? 'yes' : '' );
			$variation->update_meta_data( '_subscrpt_timing_per', $cadence ? $cadence : 1 );
			$variation->update_meta_data( '_subscrpt_timing_option', 'months' );
			$variation->update_meta_data( '_subscrpt_payment_type', 'recurring' );
			$variation->update_meta_data( '_subscrpt_user_cancel', 'yes' );
			$variation->update_meta_data( '_subscrpt_trial_timing_per', 0 );
			$variation->update_meta_data( '_subscrpt_max_no_payment', 0 );
			$variation->save();
			$products[]             = $variation->get_id();
			$variations[ $cadence ] = $variation;
		}

		foreach ( $variations as $cadence => $variation ) {
			$cart->cart_contents         = array();
			$cart->removed_cart_contents = array();
			$assert( apply_filters( 'woocommerce_add_to_cart_validation', true, $parent->get_id(), 1, $variation->get_id() ), 'Classic variation add validation failed for cadence ' . $cadence . '.' );
			do_action( 'woocommerce_store_api_validate_add_to_cart', $variation );
			$data = apply_filters( 'woocommerce_add_cart_item_data', array(), $parent->get_id(), $variation->get_id(), 1 );
			if ( $cadence ) {
				$assert( isset( $data['subscription'] ) && $cadence === (int) $data['subscription']['time'], 'Registered cart hook lost variation cadence ' . $cadence . '.' );
				$assert( isset( $data['subscription'] ) && null === $data['subscription']['trial'] && 12.5 === (float) $data['subscription']['per_cost'], 'Classic variation price/trial snapshot changed.' );
			} else {
				$assert( ! isset( $data['subscription'] ), 'Plain one-time variation acquired a subscription snapshot.' );
			}
			$key                         = 'isolated-classic-' . $variation->get_id();
			$line                        = array_merge(
				$data,
				array(
					'key'          => $key,
					'product_id'   => $parent->get_id(),
					'variation_id' => $variation->get_id(),
					'quantity'     => 1,
					'data'         => $variation,
					'line_total'   => 12.5,
					'line_tax'     => 0,
				)
			);
			$cart->cart_contents[ $key ] = $line;
			do_action( 'woocommerce_check_cart_items' );
			$assert( isset( $cart->cart_contents[ $key ] ), 'Registered cart revalidation removed a valid variation.' );

			foreach ( array( 'classic', 'store-api' ) as $route ) {
				$order    = wc_create_order(
					array(
						'customer_id' => get_current_user_id(),
						'status'      => 'pending',
					)
				);
				$orders[] = (int) $order->get_id();
				$order->set_payment_method( '' );
				$item = new WC_Order_Item_Product();
				$item->set_product( $variation );
				$item->set_quantity( 1 );
				$item->set_subtotal( 12.5 );
				$item->set_total( 12.5 );
				do_action( 'woocommerce_checkout_create_order_line_item', $item, $key, $line, $order );
				$order->add_item( $item );
				$order->set_total( 12.5 );
				$order->save();
				if ( 'classic' === $route ) {
					do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );
				} else {
					do_action( 'woocommerce_store_api_checkout_order_processed', $order );
				}
				$relations = $wpdb->get_col( $wpdb->prepare( 'SELECT subscription_id FROM %i WHERE order_id = %d', $wpdb->prefix . 'subscrpt_order_relation', $order->get_id() ) );
				$assert( empty( $wpdb->last_error ), 'Fabricated variation order relations are unreadable.' );
				foreach ( (array) $relations as $id ) {
					$subscriptions[] = (int) $id;
				}
				$assert( $cadence ? 1 === count( $relations ) : 0 === count( $relations ), 'Wrong subscription count for ' . $route . ' variation cadence ' . $cadence . '.' );
				$assert( 'pending' === wc_get_order( $order->get_id() )->get_status(), 'Fabricated variation order unexpectedly changed from pending.' );
				if ( ! $cadence || 1 !== count( $relations ) ) {
					continue;
				}
				$subscription_id = (int) $relations[0];
				$assert( 'pending' === get_post_status( $subscription_id ), 'Unpaid variation subscription was activated.' );
				$stored_parent    = (int) get_post_meta( $subscription_id, '_subscrpt_product_id', true );
				$stored_variation = (int) get_post_meta( $subscription_id, '_subscrpt_variation_id', true );
				$assert( 0 === strcmp( (string) $parent->get_id(), (string) $stored_parent ) && 0 === strcmp( (string) $variation->get_id(), (string) $stored_variation ), 'Variation subscription lost its exact parent/variation identity.' );
				$assert( (int) get_post_meta( $subscription_id, '_subscrpt_timing_per', true ) === $cadence, 'Variation subscription lost its purchased cadence.' );
				$reloaded_item = wc_get_order( $order->get_id() )->get_item( $item->get_id() );
				$terms         = $reloaded_item->get_meta( '_subscrpt_meta' );
				$assert( is_array( $terms ) && (int) $terms['time'] === $cadence && 'months' === \SpringDevs\Subscription\Illuminate\Helper::get_typos( 2, $terms['type'] ), 'Persisted variation order item lost its canonical cadence.' );
				// Test eligibility only. Do not create a renewal order or dispatch payment.
				wp_update_post(
					array(
						'ID'          => $subscription_id,
						'post_status' => 'expired',
					)
				);
				$selected_renewal = apply_filters( 'woocommerce_add_cart_item_data', array(), $parent->get_id(), $variation->get_id(), 1 );
				$assert( isset( $selected_renewal['renew_subscrpt'] ) && $subscription_id === (int) $selected_renewal['renew_subscrpt'], 'Exact expired variation was not selected by the registered cart hook.' );
				$sibling      = $variations[ 1 === $cadence ? 2 : 1 ];
				$sibling_cart = apply_filters( 'woocommerce_add_cart_item_data', array(), $parent->get_id(), $sibling->get_id(), 1 );
				$assert( ! isset( $sibling_cart['renew_subscrpt'] ), 'Expired sibling variation tagged a fresh purchase as renewal.' );
				$reloaded_item->update_meta_data( '_renew_subscrpt', $subscription_id );
				$wrapped  = \SpringDevs\Subscription\Illuminate\Subscription\Subscription::get_subs_product( $variation );
				$resolved = \SpringDevs\Subscription\Illuminate\Helper::resolve_checkout_renewal_subscription( $reloaded_item, $wrapped );
				$assert( $subscription_id === $resolved, 'Exact variation renewal identity could not be resolved.' );
				$reloaded_item->set_variation_id( $variations[0]->get_id() );
				$assert( false === \SpringDevs\Subscription\Illuminate\Helper::resolve_checkout_renewal_subscription( $reloaded_item, $wrapped ), 'Another variation could inherit the selected renewal identity.' );
				wp_update_post(
					array(
						'ID'          => $subscription_id,
						'post_status' => 'pending',
					)
				);
			}
		}
		$valid = $variations[1];
		$saved = apply_filters( 'woocommerce_add_cart_item_data', array(), $parent->get_id(), $valid->get_id(), 1 );
		foreach ( array( '', 0 ) as $invalid_interval ) {
			$valid->update_meta_data( '_subscrpt_timing_per', $invalid_interval );
			$valid->save();
			$cart->cart_contents = array( 'invalid-restored' => array_merge( $saved, array( 'data' => $valid ) ) );
			do_action( 'woocommerce_check_cart_items' );
			$assert( ! isset( $cart->cart_contents['invalid-restored'] ), 'Restored cart accepted a missing or zero raw variation interval.' );
		}
		$valid->update_meta_data( '_subscrpt_timing_per', 1 );
		$valid->save();
	} finally {
		remove_action( 'subscrpt_split_payment_created', $capture, -100 );
		$cart->cart_contents         = $cart_contents;
		$cart->removed_cart_contents = $removed_items;
		WC()->cart                   = $previous_cart;
		if ( function_exists( 'wc_set_notices' ) ) {
			wc_set_notices( $previous_notice );
		}
		WC()->session = $previous_session;
		if ( $missing === $previous ) {
			delete_option( 'wp_subscription_contract_revision' );
		} else {
			update_option( 'wp_subscription_contract_revision', $previous );
		}
		foreach ( array_unique( $subscriptions ) as $id ) {
			foreach ( array( 'subscrpt_order_relation', 'subscrpt_renewal_claim', 'subscrpt_cancellation_barrier', 'subscrpt_evidence_event' ) as $table ) {
				$wpdb->delete( $wpdb->prefix . $table, array( 'subscription_id' => $id ), array( '%d' ) );
			}
			wp_delete_post( $id, true );
		}
		foreach ( $orders as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( array_reverse( $products ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->delete( true );
			}
		}
		remove_filter( 'pre_wp_mail', $deny_mail );
		remove_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
	}
	return $passed;
}

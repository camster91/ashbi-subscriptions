<?php
/**
 * Real simple-product cart, checkout-order and immutable consent regression.
 *
 * @package AshbiSubscriptions\Tests
 */

/**
 * Run only within the authorized disposable integration harness.
 *
 * @param callable $check Harness assertion collector.
 * @throws RuntimeException When fixture setup or checkout cannot complete.
 */
function ashbi_check_simple_consent_checkout( callable $check ): void {
	if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || ! current_user_can( 'manage_options' ) ) {
		throw new RuntimeException( 'Simple checkout fixtures require an authorized disposable environment.' );
	}
	global $wpdb;
	$missing  = new stdClass();
	$previous = get_option( 'wp_subscription_contract_revision', $missing );
	$cart     = WC()->cart;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserve the local harness request, restored below.
	$posted    = $_POST;
	$product   = null;
	$order_id  = 0;
	$deny_mail = static function () {
		return false;
	};
	$deny_http = static function () {
		return new WP_Error( 'isolated_simple_checkout' );
	};
	add_filter( 'pre_wp_mail', $deny_mail );
	add_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
	try {
		$text = 'Fabricated isolated terms: free monthly subscription. No payment provider is used.';
		$doc  = array(
			'enabled'      => true,
			'version'      => 'isolated-simple-v1',
			'text'         => $text,
			'hash'         => hash( 'sha256', $text ),
			'approval_ref' => 'fabricated-test-only',
		);
		update_option( 'wp_subscription_contract_revision', $doc );
		$product = new WC_Product_Simple();
		$product->set_name( 'Fabricated simple monthly consent checkout' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '0' );
		$product->set_virtual( true );
		foreach ( array(
			'_subscrpt_enabled'        => 'yes',
			'_subscrpt_timing_per'     => 1,
			'_subscrpt_timing_option'  => 'months',
			'_subscrpt_payment_type'   => 'recurring',
			'_subscrpt_user_cancel'    => 'yes',
			'_subscrpt_max_no_payment' => 0,
		) as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->save();
		WC()->cart = new WC_Cart();
		$key       = WC()->cart->add_to_cart( $product->get_id(), 1 );
		if ( ! $key ) {
			throw new RuntimeException( 'Fabricated simple subscription could not enter the cart.' );
		}
		WC()->cart->calculate_totals();
		$reader   = \SpringDevs\Subscription\Frontend\ContractConsent::class;
		$snapshot = $reader::cart_snapshot();
		$check( 'month' === ( $snapshot['items'][0]['plan']['type'] ?? '' ), 'Real simple cart did not reproduce its singular billing unit.' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Fabricated POST reaches hooks only after WooCommerce validation in production.
		$_POST  = array(
			'ashbi_contract_hash'     => $doc['hash'],
			'ashbi_contract_snapshot' => hash( 'sha256', wp_json_encode( $snapshot ) ),
		);
		$errors = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', array(), $errors );
		$check( in_array( 'ashbi_contract_consent', $errors->get_error_codes(), true ), 'Missing checkbox passed real checkout consent hooks.' );
		$_POST['ashbi_contract_accepted'] = '1';
		$errors                           = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', array(), $errors );
		$check( ! $errors->has_errors(), 'Checked simple subscription terms were rejected by real checkout hooks.' );
		if ( $errors->has_errors() ) {
			return;
		}
		$order_id = WC()->checkout()->create_order(
			array(
				'payment_method'  => '',
				'billing_email'   => 'simple-checkout@example.invalid',
				'billing_country' => 'US',
			)
		);
		if ( is_wp_error( $order_id ) || ! $order_id ) {
			$order_id = 0;
			throw new RuntimeException( 'Real WooCommerce checkout order creation failed.' );
		}
		$order = wc_get_order( $order_id );
		$check( 0.0 === (float) $order->get_total() && '' === $order->get_payment_method(), 'Fabricated checkout unexpectedly requires a provider.' );
		$consent = ( new ReflectionClass( $reader ) )->newInstanceWithoutConstructor();
		$consent->persist_acceptance( $order_id );
		$before = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE order_id = %d', $wpdb->prefix . 'subscrpt_contract_acceptance', $order_id ) );
		$check( is_string( $before ) && $consent->order_can_pay( true, wc_get_order( $order_id ) ), 'Real checkout order lost its immutable simple subscription acceptance.' );
		$consent->persist_acceptance( $order_id );
		$check( $before === $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE order_id = %d', $wpdb->prefix . 'subscrpt_contract_acceptance', $order_id ) ), 'Repeated acceptance changed the original evidence.' );
		$order->set_total( 1 );
		$check( ! $consent->order_can_pay( true, $order ), 'Changed simple checkout amount retained valid acceptance.' );
		$_POST['ashbi_contract_snapshot'] = str_repeat( '0', 64 );
		$errors                           = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', array(), $errors );
		$check( in_array( 'ashbi_contract_consent', $errors->get_error_codes(), true ), 'Changed displayed terms passed checkout validation.' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	} finally {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Restore the exact parent harness request.
		$_POST     = $posted;
		WC()->cart = $cart;
		if ( $missing === $previous ) {
			delete_option( 'wp_subscription_contract_revision' );
		} else {
			update_option( 'wp_subscription_contract_revision', $previous );
		}
		if ( $order_id ) {
			$wpdb->delete( $wpdb->prefix . 'subscrpt_contract_acceptance', array( 'order_id' => $order_id ), array( '%d' ) );
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		if ( $product ) {
			$product->delete( true );
		}
		remove_filter( 'pre_wp_mail', $deny_mail );
		remove_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
	}
}

<?php
/**
 * Disposable WordPress/WooCommerce security-boundary integration checks.
 *
 * Run only inside wp-env with: npm run test:integration
 */

use SpringDevs\Subscription\Ajax;
use SpringDevs\Subscription\Frontend\ActionController;
use SpringDevs\Subscription\Illuminate\GuestCheckout;

add_action( 'admin_post_ashbi_security_boundaries', 'ashbi_run_security_boundary_integration_checks' );

/**
 * Run the disposable integration assertions and return JSON.
 *
 * @return void
 */
function ashbi_run_security_boundary_integration_checks() {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
		wp_die( '', '', array( 'response' => 404 ) );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '', '', array( 'response' => 403 ) );
	}

	$failures = array();
	$check    = static function ( $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
	};

$subscription_type = get_post_type_object( 'subscrpt_order' );
$item_type         = get_post_type_object( 'subscrpt_order_item' );
$check( $subscription_type && ! $subscription_type->show_in_rest, 'Subscription records are exposed through core REST.' );
$check( $item_type && ! $item_type->show_in_rest, 'Subscription item records are exposed through core REST.' );

$ajax          = new Ajax();
$subscriber_id = wp_insert_user(
	array(
		'user_login' => 'ashbi-boundary-subscriber',
		'user_email' => 'ashbi-boundary-subscriber@example.test',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'subscriber',
	)
);
$check( ! is_wp_error( $subscriber_id ), 'Could not create the disposable Subscriber.' );
wp_set_current_user( (int) $subscriber_id );
$subscriber_nonce = wp_create_nonce( 'subscrpt_install_woocommerce_plugin' );
$check( ! $ajax->can_manage_dependency( 'install_plugins', 'subscrpt_install_woocommerce_plugin', $subscriber_nonce ), 'Subscriber passed dependency-install authorization.' );

wp_set_current_user( 1 );
$install_nonce  = wp_create_nonce( 'subscrpt_install_woocommerce_plugin' );
$activate_nonce = wp_create_nonce( 'subscrpt_activate_woocommerce_plugin' );
$check( $ajax->can_manage_dependency( 'install_plugins', 'subscrpt_install_woocommerce_plugin', $install_nonce ), 'Administrator failed valid install authorization.' );
$check( $ajax->can_manage_dependency( 'activate_plugins', 'subscrpt_activate_woocommerce_plugin', $activate_nonce ), 'Administrator failed valid activation authorization.' );
$check( ! $ajax->can_manage_dependency( 'install_plugins', 'subscrpt_install_woocommerce_plugin', 'invalid' ), 'Invalid dependency nonce was accepted.' );

$customer_id = wp_insert_user(
	array(
		'user_login' => 'ashbi-existing-customer',
		'user_email' => 'ashbi-existing-customer@example.test',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'customer',
	)
);
$check( ! is_wp_error( $customer_id ), 'Could not create the disposable Customer.' );
$previous_guest_setting = get_option( 'wp_subscription_allow_guest_checkout', '0' );
update_option( 'wp_subscription_allow_guest_checkout', '1' );
$guest = new GuestCheckout();
wp_set_current_user( 0 );
$bound_id = $guest->maybe_create_user( array( 'billing_email' => 'ashbi-existing-customer@example.test' ) );
$check( null === $bound_id, 'Existing email bound an unauthenticated guest to a customer account.' );
wp_set_current_user( (int) $customer_id );
$bound_id = $guest->maybe_create_user( array( 'billing_email' => 'ashbi-existing-customer@example.test' ) );
$check( (int) $customer_id === (int) $bound_id, 'Authenticated customer could not retain their own account binding.' );
update_option( 'wp_subscription_allow_guest_checkout', $previous_guest_setting );

$product_id = wp_insert_post(
	array(
		'post_type'   => 'product',
		'post_status' => 'publish',
		'post_title'  => 'Ashbi boundary product',
	)
);
$subscription_id = wp_insert_post(
	array(
		'post_type'   => 'subscrpt_order',
		'post_status' => 'pe_cancelled',
		'post_author' => (int) $customer_id,
		'post_title'  => 'Ashbi boundary subscription',
	)
);
update_post_meta( $subscription_id, '_subscrpt_product_id', $product_id );
update_post_meta( $subscription_id, '_subscrpt_cancel_at', time() + HOUR_IN_SECONDS );
$check( ActionController::can_reactivate_subscription( $subscription_id, time() ), 'Valid pending cancellation could not be reactivated.' );

foreach ( array( 'cancelled', 'expired', 'completed' ) as $terminal_status ) {
	wp_update_post( array( 'ID' => $subscription_id, 'post_status' => $terminal_status ) );
	$check( ! ActionController::can_reactivate_subscription( $subscription_id, time() ), "Terminal {$terminal_status} subscription could be reactivated." );
}

wp_update_post( array( 'ID' => $subscription_id, 'post_status' => 'pe_cancelled' ) );
update_post_meta( $subscription_id, '_subscrpt_cancel_at', time() - 1 );
$check( ! ActionController::can_reactivate_subscription( $subscription_id, time() ), 'Elapsed pending cancellation could be reactivated.' );

// A fully paid installment plan is terminal even if legacy status/meta still
// resembles a pending cancellation. Exercise the real Woo order and relation
// table rather than only the pure status helper.
$paid_order = wc_create_order();
$paid_order->set_total( 10 );
$paid_order->set_date_paid( time() );
$paid_order->set_status( 'completed' );
$paid_order->save();
update_post_meta( $product_id, '_subscrpt_max_no_payment', 1 );
update_post_meta( $subscription_id, '_subscrpt_cancel_at', time() + HOUR_IN_SECONDS );
wp_update_post( array( 'ID' => $subscription_id, 'post_status' => 'pe_cancelled' ) );

global $wpdb;
$relation_inserted = $wpdb->insert(
	$wpdb->prefix . 'subscrpt_order_relation',
	array(
		'subscription_id' => $subscription_id,
		'order_id'        => $paid_order->get_id(),
		'order_item_id'   => 0,
		'type'            => 'new',
	),
	array( '%d', '%d', '%d', '%s' )
);
$check( false !== $relation_inserted, 'Could not create the disposable paid installment relation.' );
$check( subscrpt_is_max_payments_reached( $subscription_id ), 'Paid installment limit was not detected.' );
$check( ! ActionController::can_reactivate_subscription( $subscription_id, time() ), 'Completed installment plan could be reactivated.' );
update_post_meta( $subscription_id, '_subscrpt_next_date', time() + DAY_IN_SECONDS );
$check( subscrpt_finalize_split_payment_completion( $subscription_id ), 'Final installment effects did not persist.' );
$check( 'completed' === get_post_status( $subscription_id ), 'Final installment did not persist completed status.' );
$check( ! get_post_meta( $subscription_id, '_subscrpt_next_date', true ), 'Final installment retained a next-payment date.' );
$check( (bool) get_post_meta( $subscription_id, '_subscrpt_split_payment_completed_fired', true ), 'Final installment callback marker was not persisted.' );

if ( $failures ) {
	wp_send_json_error( array( 'failures' => $failures ), 500 );
}

wp_send_json_success( array( 'message' => 'Ashbi security-boundary integration checks passed.' ) );
}

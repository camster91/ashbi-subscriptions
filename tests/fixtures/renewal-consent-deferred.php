<?php
/**
 * Consent can change after saved-token preparation but before deferred dispatch.
 *
 * @package AshbiSubscriptions\Tests
 */

define( 'CONSENT_FIXTURE_BOOTSTRAP_ONLY', true );
require __DIR__ . '/renewal-consent.php';
// The common fixture loads real CancellationEvidence with healthy empty barrier
// storage; deferred dispatch must still pass its production cancellation guard.
$GLOBALS['consent_options']  = array(
	'wp_subscription_renewal_process'   => 'auto',
	'wp_subscription_stripe_auto_renew' => '1',
);
$GLOBALS['consent_meta'][99] = array( '_subscrpt_auto_renew' => 1 );
$old                         = new WC_Order();
$old->method                 = 'stripe';
$old->meta                   = array(
	'_stripe_customer_id' => 'fixture-customer',
	'_stripe_source_id'   => 'fixture-source',
);
$new                         = new WC_Order();
$gateway                     = new ConsentGateway();
$gateway->copy_stripe_metadata( $new, $old, 99 );
$prepared = isset( $new->meta['_stripe_source_id'] );
// Mirror the explicit customer-off write after an order has already been prepared.
update_post_meta( 99, '_subscrpt_auto_renew', 0 );
$GLOBALS['fixture_orders'][501] = $new;
$GLOBALS['consent_gateway']     = $gateway;
$resume                         = new ReflectionMethod( SpringDevs\Subscription\Illuminate\Helper::class, 'resume_canonical_renewal_order' );
if ( PHP_VERSION_ID < 80100 ) {
	$resume->setAccessible( true );
}
$resume->invoke( null, $new, $old, 99, 601 );
$GLOBALS['provider_boundaries'] = 0;
$direct                         = ( new ReflectionClass( SpringDevs\Subscription\Illuminate\Gateways\Stripe\Stripe::class ) )->newInstanceWithoutConstructor();
$denied                         = $direct->pay_renew_order( $new, 99 );
echo json_encode(
	array(
		'prepared'            => $prepared,
		'dispatches'          => $gateway->dispatches,
		'provider_boundaries' => $GLOBALS['provider_boundaries'],
		'denied'              => $denied instanceof WP_Error,
		'raw'                 => $GLOBALS['consent_meta'][99]['_subscrpt_auto_renew'],
	)
);

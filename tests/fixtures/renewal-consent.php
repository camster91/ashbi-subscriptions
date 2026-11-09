<?php
/**
 * Authoritative consent fixture using real helpers and gateway entry points.
 * No WordPress database, provider requests, or customer data are used.
 *
 * @package AshbiSubscriptions\Tests
 */

/** Install an offline runtime dependency in this isolated process only.
 *
 * @param string $name Global name.
 * @param mixed  $value Fixture value.
 */
function set_consent_fixture_global( $name, $value ) {
	$GLOBALS[ $name ] = $value;
}

/** Fixture metadata.
 *
 * @param int    $id ID.
 * @param string $key Key.
 * @param bool   $single Single.
 * @return mixed
 */
function get_post_meta( $id, $key = '', $single = false ) {
	if ( '' === $key ) {
		return $GLOBALS['consent_meta'][ $id ] ?? array(); }
	$value = $GLOBALS['consent_meta'][ $id ][ $key ] ?? '';
	$value = is_scalar( $value ) ? (string) $value : $value;
	return $single ? $value : array( $value );
}
/** Fixture metadata existence.
 *
 * @param string $type Type.
 * @param int    $id ID.
 * @param string $key Key.
 * @return bool
 */
function metadata_exists( $type, $id, $key ) {
	return array_key_exists( $key, $GLOBALS['consent_meta'][ $id ] ?? array() );
}
/** Capture fixture writes.
 *
 * @param int    $id ID.
 * @param string $key Key.
 * @param mixed  $value Value.
 */
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['consent_meta'][ $id ][ $key ] = $value;
	$GLOBALS['consent_writes'][]            = array( $id, $key, $value );
}
/** Return only registered local orders.
 *
 * @param int $id Order ID.
 * @return mixed
 */
function wc_get_order( $id ) {
	return $GLOBALS['fixture_orders'][ $id ] ?? false;
}
/** Dispatch the real canonical renewal hook to an instrumented gateway double.
 *
 * @param string $hook Hook.
 * @param mixed  ...$args Hook arguments.
 */
function do_action( $hook, ...$args ) {
	if ( 'subscrpt_after_create_renew_order' === $hook ) {
		$GLOBALS['consent_gateway']->after_create_renew_order( ...$args );
	}
}

/** Fixture options.
 *
 * @param string $name Name.
 * @param mixed  $fallback Default.
 * @return mixed
 */
function get_option( $name, $fallback = false ) {
	return array_key_exists( $name, $GLOBALS['consent_options'] ) ? $GLOBALS['consent_options'][ $name ] : $fallback;
}
/** Fixture post.
 *
 * @param int $id ID.
 * @return object
 */
function get_post( $id ) {
	return (object) array(
		'ID'          => $id,
		'post_author' => 7,
		'post_type'   => 'subscrpt_order',
	); }
/** Fixture status.
 *
 * @param int $id ID.
 * @return string
 */
function get_post_status( $id ) {
	return $GLOBALS['fixture_statuses'][ $id ] ?? 'active'; }
/** Fixture logger.
 *
 * @return object
 */
function wc_get_logger() {
	return new class() {
		/** Ignore redacted local logs.
		 *
		 * @param string $source Source.
		 * @param string $message Message.
		 */
		public function add( $source, $message ) {
			$GLOBALS['fixture_logs'][] = array( $source, $message ); }
	};
}
/** Fixture escaping.
 *
 * @param string $text Text.
 * @return string
 */
function esc_html( $text ) {
	return $text; }
/** Generate a fixed request identity for isolated controller actions.
 *
 * @return string
 */
function wp_generate_uuid4() {
	return '00000000-0000-4000-8000-000000000099'; }
/** Fixture translation.
 *
 * @param string $text Text.
 * @param string $domain Domain.
 * @return string
 */
function __( $text, $domain ) {
	return 'subscription' === $domain ? $text : ''; }

/** No Stripe dependency or network. */
require __DIR__ . '/consent-doubles/class-wc-stripe-payment-gateway.php';
/** Fixture exception. */
require __DIR__ . '/consent-doubles/class-wc-stripe-exception.php';

/** Direct-dispatch denial response double. */
require __DIR__ . '/consent-doubles/class-wp-error.php';
/** Probe the first provider boundary, stopping before any network. */
require __DIR__ . '/consent-doubles/class-wc-stripe-order-helper.php';
/** Offline provider lookup recorder. */
require __DIR__ . '/consent-doubles/class-wc-stripe-api.php';

/** Local order double for metadata preparation. */
require __DIR__ . '/consent-doubles/class-consentorder.php';
require __DIR__ . '/consent-doubles/class-wc-order.php';

set_consent_fixture_global(
	'wpdb',
	new class() {
		/** Prefix.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';
		/** Healthy local database: the barrier table exists and contains no rows.
		 *
		 * @var string
		 */
		public $last_error = '';
		/** Return query.
		 *
		 * @param string $query Query.
		 * @param mixed  ...$args Args.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			$GLOBALS['fixture_query_args'][] = $args;
			return $query; }
		/** Pretend this renewal order owns its local canonical claim.
		 *
		 * @param string $query Query.
		 * @return int
		 */
		public function get_var( $query ) {
			$GLOBALS['fixture_queries'][] = $query;
			$this->last_error = '';
			// Exercise the real barrier reader against healthy empty storage. Locks
			// and the pre-existing renewal claim continue returning success below.
			if ( 0 === strpos( $query, 'SELECT subscription_id FROM' ) ) {
				return null;
			}
			return 1; }
		/** Return the local subscription relation for direct dispatch.
		 *
		 * @param string $query Query.
		 * @return array
		 */
		public function get_results( $query ) {
			$GLOBALS['fixture_queries'][] = $query;
			return array( (object) array( 'subscription_id' => 99 ) ); }
	}
);
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
require dirname( __DIR__, 2 ) . '/plugin/includes/functions.php';
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Helper.php';
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/RenewalClaim.php';
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/CancellationEvidence.php';
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Gateways/Stripe/Stripe.php';

/** Instrument real dispatch entry without a provider implementation. */
require __DIR__ . '/consent-doubles/class-consentgateway.php';

if ( defined( 'CONSENT_FIXTURE_BOOTSTRAP_ONLY' ) ) {
	return; }

$old         = new ConsentOrder();
$old->method = 'stripe';
$old->meta   = array(
	'_stripe_customer_id' => 'fixture-customer',
	'_stripe_source_id'   => 'fixture-source',
);
$cases       = array(
	'zero'         => 0,
	'missing'      => null,
	'string_zero'  => '0',
	'false'        => false,
	'string_false' => 'false',
	'no'           => 'no',
	'empty'        => '',
	'null'         => null,
	'invalid'      => 'unknown',
	'array'        => array(),
	'one'          => 1,
	'string_one'   => '1',
	'true'         => true,
	'string_true'  => 'true',
	'yes'          => 'yes',
);
$result      = array();
foreach ( array( 'auto', 'manual' ) as $renewal_mode ) {
	foreach ( array( '1', '0' ) as $stripe_mode ) {
		foreach ( $cases as $name => $value ) {
			$GLOBALS['consent_options']  = array(
				'wp_subscription_renewal_process'   => $renewal_mode,
				'wp_subscription_stripe_auto_renew' => $stripe_mode,
			);
			$GLOBALS['consent_meta'][99] = array( '_subscrpt_product_id' => 101 );
			if ( 'missing' !== $name ) {
				$GLOBALS['consent_meta'][99]['_subscrpt_auto_renew'] = $value; }
			$before                    = $GLOBALS['consent_meta'][99];
			$GLOBALS['consent_writes'] = array();
			$data                      = SpringDevs\Subscription\Illuminate\Helper::get_subscription_data( 99 );
			$new                       = new ConsentOrder();
			$gateway                   = new ConsentGateway();
			$gateway->copy_stripe_metadata( $new, $old, 99 );
			$gateway->after_create_renew_order( $new, $old, 99 );
			$refreshed                                   = SpringDevs\Subscription\Illuminate\Helper::get_subscription_data( 99 );
			$bancontact                                  = clone $old;
			$bancontact->method                          = 'stripe_bancontact';
			$bancontact->meta['_stripe_subscription_id'] = 'fixture-agreement';
			$bancontact_new                              = new ConsentOrder();
			$GLOBALS['provider_lookups']                 = 0;
			$gateway->copy_stripe_metadata( $bancontact_new, $bancontact, 99 );
			$GLOBALS['provider_boundaries'] = 0;
			$direct                         = ( new ReflectionClass( SpringDevs\Subscription\Illuminate\Gateways\Stripe\Stripe::class ) )->newInstanceWithoutConstructor();
			foreach ( array( 99, 0 ) as $dispatch_id ) {
				try {
					$direct->pay_renew_order( $new, $dispatch_id );
				} catch ( RuntimeException $error ) {
					if ( 'local-provider-boundary' !== $error->getMessage() ) {
						throw new RuntimeException( esc_html( $error->getMessage() ) ); }
				}
			}
			$writes_before_resume           = count( $GLOBALS['consent_writes'] );
			$preserved_before_resume        = $before === $GLOBALS['consent_meta'][99];
			$saved                          = new WC_Order();
			$saved->meta                    = $new->meta;
			$parent_order                   = new WC_Order();
			$parent_order->meta             = $old->meta;
			$parent_order->method           = $old->method;
			$GLOBALS['fixture_orders'][501] = $saved;
			$GLOBALS['consent_gateway']     = new ConsentGateway();
			$resume                         = new ReflectionMethod( SpringDevs\Subscription\Illuminate\Helper::class, 'resume_canonical_renewal_order' );
			if ( PHP_VERSION_ID < 80100 ) {
				$resume->setAccessible( true ); }
			$resumed = $resume->invoke( null, $saved, $parent_order, 99, 601 );
			$result[ $renewal_mode ][ $stripe_mode ][ $name ] = array(
				'display'                        => $data['is_auto_renew'],
				'refreshed'                      => $refreshed['is_auto_renew'],
				'preserved'                      => $preserved_before_resume,
				'writes'                         => $writes_before_resume,
				'cloned'                         => isset( $new->meta['_stripe_source_id'] ),
				'dispatches'                     => $gateway->dispatches,
				'provider_boundaries'            => $GLOBALS['provider_boundaries'],
				'provider_lookups'               => $GLOBALS['provider_lookups'],
				'bancontact_meta'                => ! empty( $bancontact_new->meta ),
				'resumed'                        => $resumed === $saved,
				'resume_dispatches'              => $GLOBALS['consent_gateway']->dispatches,
				'consent_preserved_after_resume' => array_intersect_key( $GLOBALS['consent_meta'][99], $before ) === $before,
			);
		}
	}
}
echo json_encode( $result );

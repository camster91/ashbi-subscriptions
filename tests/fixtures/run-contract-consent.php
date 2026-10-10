<?php
/**
 * Contract consent acceptance tests against real source and isolated WP doubles.
 *
 * No provider, database, historical customer, or network is contacted.
 *
 * @package AshbiSubscriptions\Tests
 */

/** Read only explicitly supplied configuration, never inherited consent. */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['contract_options'] ) ? $GLOBALS['contract_options'][ $name ] : $default;
}

/** Match WordPress's JSON helper for deterministic fixture hashes. */
function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

/** Canonical fabricated cart exposed to the real production snapshot builder. */
function WC() {
	return $GLOBALS['contract_wc'];
}

/** Fixed fixture currency; no store configuration is read. */
function get_woocommerce_currency() {
	return 'USD';
}

/** Minimal actual WooCommerce cart accessors consumed by the snapshot builder. */
final class ContractConsentCartFixture {
	public $items = array();
	public function get_cart() { return $this->items; }
	public function get_total( $context = 'view' ) { return '37.25'; }
	public function get_shipping_total() { return '0'; }
	public function get_discount_total() { return '0'; }
}

/** Fabricated classic product configuration, never historical customer data. */
final class ContractConsentProductFixture {
	public function get_meta( $key ) {
		return array( '_subscrpt_max_no_payment' => 5, '_subscrpt_timing_per' => 1, '_subscrpt_timing_option' => 'months' )[ $key ] ?? '';
	}
}

/** Historical auto-renew opt-in must never authorize contractual acceptance. */
function get_post_meta( $id, $key, $single = false ) {
	$GLOBALS['contract_historical_reads']++;
	return '_subscrpt_auto_renew' === $key ? '1' : '';
}

/** Capture unintended configuration changes during validation. */
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['contract_writes']++;
	return true;
}

/** Capture unintended historical backfills during validation. */
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['contract_writes']++;
	return true;
}

define( 'ABSPATH', '/' );
$GLOBALS['contract_options']          = array();
$GLOBALS['contract_writes']           = 0;
$GLOBALS['contract_historical_reads'] = 0;
require dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/ContractConsent.php';
$consent = ( new ReflectionClass( SpringDevs\Subscription\Frontend\ContractConsent::class ) )->newInstanceWithoutConstructor();
$text    = 'Sandbox approved subscription terms. Three monthly payments of USD 12.50.';
$doc     = array(
	'enabled'      => true,
	'version'      => 'fixture-v1',
	'text'         => $text,
	'hash'         => hash( 'sha256', $text ),
	'approval_ref' => 'fixture-approval-1',
);
$snapshot = array(
	'currency' => 'USD',
	'items'    => array(
		array(
			'product_id'   => 101,
			'variation_id' => 102,
			'plan_id'      => 7,
			'quantity'     => 1,
			'plan'         => array( 'price' => '12.50', 'time' => 1, 'type' => 'months' ),
		),
	),
);
$snapshot_hash = hash( 'sha256', wp_json_encode( $snapshot ) );
$result        = array( 'config' => array(), 'accepted_values' => array(), 'snapshots' => array() );
$configs       = array( 'missing' => null, 'scalar' => 'accepted', 'empty' => array(), 'approved' => $doc );
$bad_config = $doc;
unset( $bad_config['enabled'] );
$configs['missing_enabled'] = $bad_config;
foreach ( array( false, '1', 1, 'true' ) as $index => $enabled ) {
	$bad_config                     = $doc;
	$bad_config['enabled']          = $enabled;
	$configs[ 'enabled_' . $index ] = $bad_config;
}
foreach ( array( 'version', 'text', 'hash', 'approval_ref' ) as $key ) {
	$bad_config                       = $doc;
	unset( $bad_config[ $key ] );
	$configs[ 'missing_' . $key ]      = $bad_config;
	$bad_config                       = $doc;
	$bad_config[ $key ]                = '';
	$configs[ 'empty_' . $key ]        = $bad_config;
	$bad_config[ $key ]                = array( 'untrusted' );
	$configs[ 'nonscalar_' . $key ]    = $bad_config;
}
$bad_config                    = $doc;
$bad_config['text']            = $text . ' Changed without a revision.';
$configs['text_hash_mismatch'] = $bad_config;
foreach ( $configs as $name => $config ) {
	$GLOBALS['contract_options'] = null === $config ? array() : array( 'wp_subscription_contract_revision' => $config );
	$result['config'][ $name ]   = null !== $consent->approved_document();
}
$GLOBALS['contract_options'] = array( 'wp_subscription_contract_revision' => $doc );
$result['document']          = $consent->approved_document();
$simple = $snapshot;
$simple['items'][0]['variation_id'] = 0;
$simple['items'][0]['plan_id'] = 0;
$result['classic_simple'] = $consent->validate_acceptance( '1', $doc['hash'], $simple, hash( 'sha256', wp_json_encode( $simple ) ) );

// These line shapes match PlanCheckout::stamp_plan and Cart::add_to_cart_item_data,
// which use per_cost, not price; the classic path deliberately supplies time=null.
// The production builder must normalize these into its public validation schema.
$cart = new ContractConsentCartFixture();
$cart->items = array(
	array(
		'product_id' => 101, 'variation_id' => 102, 'quantity' => 2,
		'subscrpt_plan_id' => 7, 'subscrpt_signup_fee' => 3.25, 'subscrpt_max_no_payment' => 4,
		'line_total' => '25.00', 'line_tax' => '0', 'data' => new ContractConsentProductFixture(),
		'subscription' => array( 'time' => 2, 'type' => 'months', 'trial' => null, 'signup_fee' => 3.25, 'per_cost' => '12.50', 'max_no_payment' => 4 ),
	),
	array(
		'product_id' => 201, 'variation_id' => 0, 'quantity' => 1,
		'line_total' => '9.00', 'line_tax' => '0', 'data' => new ContractConsentProductFixture(),
		'subscription' => array( 'time' => null, 'type' => 'months', 'trial' => null, 'signup_fee' => null, 'per_cost' => '9.00', 'max_no_payment' => 5 ),
	),
);
$GLOBALS['contract_wc'] = (object) array( 'cart' => $cart );
$built_snapshot = $consent->cart_snapshot();
$result['canonical_cart'] = $built_snapshot;
$result['canonical_cart_valid'] = $consent->validate_acceptance( '1', $doc['hash'], $built_snapshot, hash( 'sha256', wp_json_encode( $built_snapshot ) ) );
foreach ( array( '1', '', '0', false, true, 1, 0, null, 'true', 'yes', 'on', ' 1 ', array( '1' ) ) as $value ) {
	$result['accepted_values'][] = $consent->validate_acceptance( $value, $doc['hash'], $snapshot, $snapshot_hash );
}
$result['forged_revision'] = $consent->validate_acceptance( '1', str_repeat( 'a', 64 ), $snapshot, $snapshot_hash );
$result['missing_revision'] = $consent->validate_acceptance( '1', '', $snapshot, $snapshot_hash );
$result['missing_snapshot_hash'] = $consent->validate_acceptance( '1', $doc['hash'], $snapshot );
$result['forged_snapshot_hash']  = $consent->validate_acceptance( '1', $doc['hash'], $snapshot, str_repeat( 'b', 64 ) );
$changed                        = $snapshot;
$changed['items'][0]['quantity'] = 2;
$result['changed_quantity']     = $consent->validate_acceptance( '1', $doc['hash'], $changed, $snapshot_hash );
$changed['items'][0]['quantity'] = 1;
$changed['items'][0]['plan']['price'] = '25.00';
$result['changed_price']             = $consent->validate_acceptance( '1', $doc['hash'], $changed, $snapshot_hash );
$changed = $doc;
$changed['text'] = 'Different approved sandbox terms.';
$changed['hash'] = hash( 'sha256', $changed['text'] );
$changed['version'] = 'fixture-v2';
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $changed;
$result['superseded_revision'] = $consent->validate_acceptance( '1', $doc['hash'], $snapshot, $snapshot_hash );
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $doc;
$bad_snapshots = array( 'empty' => array(), 'missing_items' => array( 'currency' => 'USD' ), 'empty_items' => array( 'currency' => 'USD', 'items' => array() ) );
$bad_snapshot = $snapshot;
unset( $bad_snapshot['currency'] );
$bad_snapshots['missing_currency'] = $bad_snapshot;
$bad_snapshot['currency'] = '';
$bad_snapshots['empty_currency'] = $bad_snapshot;
foreach ( array( 'product_id', 'variation_id', 'plan_id', 'quantity', 'plan' ) as $key ) {
	$bad_snapshot = $snapshot;
	unset( $bad_snapshot['items'][0][ $key ] );
	$bad_snapshots[ 'missing_' . $key ] = $bad_snapshot;
}
foreach ( array( 'price', 'time', 'type' ) as $key ) {
	$bad_snapshot = $snapshot;
	unset( $bad_snapshot['items'][0]['plan'][ $key ] );
	$bad_snapshots[ 'missing_plan_' . $key ] = $bad_snapshot;
}
foreach ( array( 'product_id' => 0, 'variation_id' => -1, 'plan_id' => -1, 'quantity' => 0 ) as $key => $value ) {
	$bad_snapshot = $snapshot;
	$bad_snapshot['items'][0][ $key ] = $value;
	$bad_snapshots[ 'invalid_' . $key ] = $bad_snapshot;
}
foreach ( $bad_snapshots as $name => $bad_snapshot ) {
	$result['snapshots'][ $name ] = $consent->validate_acceptance( '1', $doc['hash'], $bad_snapshot, hash( 'sha256', wp_json_encode( $bad_snapshot ) ) );
}
$GLOBALS['contract_options'] = array( 'wp_subscription_renewal_process' => 'auto', 'subscrpt_renewal_process' => 'auto' );
$result['historical_not_inferred'] = $consent->validate_acceptance( '1', $doc['hash'], $snapshot, $snapshot_hash );
$result['writes']                 = $GLOBALS['contract_writes'];
$result['historical_reads']       = $GLOBALS['contract_historical_reads'];
echo wp_json_encode( $result );

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
	$value = array_key_exists( $name, $GLOBALS['contract_options'] ) ? $GLOBALS['contract_options'][ $name ] : $default;
	return apply_filters( 'option_' . $name, $value );
}

/** Execute actual registered option filters in priority order. */
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['contract_filters'][ $hook ][ $priority ][] = $callback;
}

/** Minimal filter harness, preserving unrelated hooks unchanged. */
function apply_filters( $hook, $value, ...$args ) {
	$callbacks = $GLOBALS['contract_filters'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as $callback ) { $value = $callback( $value ); }
	}
	return $value;
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

/** Currency precision used by production monetary normalization. */
function wc_get_price_decimals() { return 2; }

/** Mirror WooCommerce decimal formatting for these fabricated USD amounts. */
function wc_format_decimal( $number, $dp = false, $trim_zeros = false ) {
	$value = false === $dp ? (string) $number : number_format( (float) $number, (int) $dp, '.', '' );
	return $trim_zeros ? rtrim( rtrim( $value, '0' ), '.' ) : $value;
}

/** Minimal actual WooCommerce cart accessors consumed by the snapshot builder. */
final class ContractConsentCartFixture {
	public $items = array();
	public $total = '37.25';
	public function get_cart() { return $this->items; }
	public function get_total( $context = 'view' ) { return $this->total; }
	public function get_shipping_total() { return '0'; }
	public function get_discount_total() { return '0'; }
}

/** Fabricated classic product configuration, never historical customer data. */
final class ContractConsentProductFixture {
	public function get_meta( $key ) {
		return array( '_subscrpt_max_no_payment' => 5, '_subscrpt_timing_per' => 1, '_subscrpt_timing_option' => 'months' )[ $key ] ?? '';
	}
}

/** Woo line double: identifiers/totals remain separate from stamped metadata. */
class WC_Order_Item_Product {
	public $meta = array();
	public $product_id = 101;
	public $variation_id = 102;
	public $quantity = 2;
	public $total = '25.00';
	public $tax = '0';
	public function get_id() { return 501; }
	public function get_product_id() { return $this->product_id; }
	public function get_variation_id() { return $this->variation_id; }
	public function get_quantity() { return $this->quantity; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->tax; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
	public function get_data() { return array( 'product_id' => $this->product_id, 'variation_id' => $this->variation_id ); }
}

/** Order double exposes the actual amount payable, not only prior acceptance. */
class WC_Order {
	public $id;
	public $items = array();
	public $meta = array();
	public $currency = 'USD';
	public $total = '31.50';
	public $meta_writes = 0;
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function get_currency() { return $this->currency; }
	public function get_total() { return $this->total; }
	public function get_shipping_total() { return '0'; }
	public function get_discount_total() { return '0'; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; $this->meta_writes++; }
	public function save() { return $this->id; }
}

/** Access only registered fabricated orders. */
function wc_get_order( $id ) { return $GLOBALS['contract_orders'][ $id ] ?? false; }

/** Capture Woo's usable notices instead of rendering a browser error. */
function wc_add_notice( $message, $type = 'success', $data = array() ) { $GLOBALS['contract_notices'][] = array( $message, $type ); }

/** Translation double for isolated exception/notice paths. */
function __( $text, $domain = '' ) { return $text; }

/** Escaped translation double; fixtures contain plain text only. */
function esc_html__( $text, $domain = '' ) { return $text; }

/** Real HTML escaping for render-path assertions, without a browser or WP. */
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return esc_attr( $url ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

/** Clock advances between retries to expose accidental evidence rewriting. */
function current_time( $type, $gmt = false ) { return $GLOBALS['contract_now']; }

/** An anonymous fixture is not identified as an authenticated customer. */
function get_current_user_id() { return 0; }

/** Checkout's already-validated posted strings are unescaped in this fixture. */
function wp_unslash( $value ) { return $value; }

/** Minimal immutable ledger model; it never changes an existing INSERT IGNORE row. */
final class ContractConsentLedgerFixture {
	public $prefix = 'fixture_';
	public $rows = array();
	public $inserts = 0;
	public $query_attempts = 0;
	public $fail_write = false;
	public $fail_read = false;
	public $corrupt_read = false;
	public $last_error = '';
	public $barriers = array( 3493 => 3493 );
	public function prepare( $sql, ...$args ) {
		return array( 'sql' => $sql, 'args' => count( $args ) === 1 && is_array( $args[0] ) ? $args[0] : $args );
	}
	public function query( $statement ) {
		if ( strpos( $statement['sql'], 'INSERT IGNORE' ) !== 0 ) { throw new RuntimeException( 'Unexpected fixture SQL operation.' ); }
		$this->query_attempts++;
		if ( $this->fail_write ) { return false; }
		$args = $statement['args'];
		$id = (int) $args[1];
		if ( isset( $this->rows[ $id ] ) ) { return 0; }
		$this->rows[ $id ] = $args[5];
		$this->inserts++;
		return 1;
	}
	public function get_var( $statement ) {
		if ( strpos( $statement['sql'], 'SELECT subscription_id' ) === 0 ) { return $this->barriers[ (int) $statement['args'][1] ] ?? null; }
		if ( strpos( $statement['sql'], 'SELECT payload' ) !== 0 ) { throw new RuntimeException( 'Unexpected fixture SQL read.' ); }
		if ( $this->fail_read ) { return null; }
		if ( $this->corrupt_read ) { return '{"unverified":"readback"}'; }
		return $this->rows[ (int) $statement['args'][1] ] ?? null;
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
$GLOBALS['contract_filters'] = array();
require dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/ContractConsent.php';
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Helper.php';
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

// A retry uses its immutable approved document and the actual final order,
// not the store's new wording or a recomputed hash of a self-supplied snapshot.
$frozen = array(
	'document' => $doc,
	'snapshot' => $snapshot,
	'accepted_at' => '2026-10-10 12:00:00',
	'actor_id' => 0,
	'payment_outcome' => 'not_confirmed',
);
$GLOBALS['contract_options'] = array();
$result['frozen_without_current_config'] = $consent->validate_frozen_acceptance( $frozen, $snapshot );
$revision_two = $doc;
$revision_two['text'] = 'Approved sandbox revision two applies to new purchases.';
$revision_two['hash'] = hash( 'sha256', $revision_two['text'] );
$revision_two['version'] = 'fixture-v2';
$revision_two['approval_ref'] = 'fixture-approval-2';
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $revision_two;
$result['frozen_after_revision_change'] = $consent->validate_frozen_acceptance( $frozen, $snapshot );
$result['frozen_order_mutations'] = array();
foreach ( array( 'product_id' => 999, 'variation_id' => 999, 'plan_id' => 999, 'quantity' => 2 ) as $field => $value ) {
	$actual = $snapshot;
	$actual['items'][0][ $field ] = $value;
	$result['frozen_order_mutations'][ $field ] = $consent->validate_frozen_acceptance( $frozen, $actual );
}
foreach ( array( 'price' => '25.00', 'time' => 2, 'type' => 'weeks' ) as $field => $value ) {
	$actual = $snapshot;
	$actual['items'][0]['plan'][ $field ] = $value;
	$result['frozen_order_mutations'][ 'plan_' . $field ] = $consent->validate_frozen_acceptance( $frozen, $actual );
}
$actual = $snapshot;
$actual['currency'] = 'CAD';
$result['frozen_order_mutations']['currency'] = $consent->validate_frozen_acceptance( $frozen, $actual );
$result['frozen_invalid_documents'] = array();
foreach ( array( 'text', 'hash', 'version', 'approval_ref' ) as $field ) {
	$unapproved = $frozen;
	unset( $unapproved['document'][ $field ] );
	$result['frozen_invalid_documents'][ 'missing_' . $field ] = $consent->validate_frozen_acceptance( $unapproved, $snapshot );
	$unapproved = $frozen;
	$unapproved['document'][ $field ] = '';
	$result['frozen_invalid_documents'][ 'empty_' . $field ] = $consent->validate_frozen_acceptance( $unapproved, $snapshot );
}
$unapproved = $frozen;
$unapproved['document']['text'] .= ' Undisclosed change.';
$result['frozen_invalid_documents']['changed_text'] = $consent->validate_frozen_acceptance( $unapproved, $snapshot );
$unapproved = $frozen;
$unapproved['document']['hash'] = str_repeat( 'a', 64 );
$result['frozen_invalid_documents']['forged_hash'] = $consent->validate_frozen_acceptance( $unapproved, $snapshot );
$result['frozen_invalid_documents']['empty_payload'] = $consent->validate_frozen_acceptance( array(), $snapshot );
$unapproved = $frozen;
unset( $unapproved['snapshot'] );
$result['frozen_invalid_documents']['missing_snapshot'] = $consent->validate_frozen_acceptance( $unapproved, $snapshot );
$unapproved = $frozen;
$unapproved['snapshot'] = array();
$result['frozen_invalid_documents']['empty_snapshot'] = $consent->validate_frozen_acceptance( $unapproved, array() );

// Exercise the final-order gate with WooCommerce-shaped CRUD doubles. The
// canonical plan metadata is independent of the initial contract line stamp.
$line = $cart->items[0];
$item = new WC_Order_Item_Product();
$consent->stamp_line( $item, 'fixture-cart-key', $line );
$item->meta['_subscrpt_plan_id'] = 7;
$item->meta['_subscrpt_plan_terms'] = $line['subscription'];
$item->meta['_subscrpt_plan_price'] = '12.50';
$item->meta['_subscrpt_signup_fee'] = '3.25';
$item->meta['_subscrpt_max_no_payment'] = 4;
$order = new WC_Order( 701 );
$order->items = array( 501 => $item );
$order->meta['_ashbi_contract_required'] = '1';
$cart->items = array( $line );
$cart->total = '31.50';
$result['order_snapshot'] = $consent->order_snapshot( $order );
$result['matching_cart_snapshot'] = $consent->cart_snapshot();
$order->meta['_ashbi_contract_consent'] = array(
	'document' => $doc, 'snapshot' => $result['matching_cart_snapshot'],
	'accepted_at' => '2026-10-10 12:00:00', 'actor_id' => 0, 'payment_outcome' => 'not_confirmed',
);
$GLOBALS['contract_orders'] = array( 701 => $order );
$GLOBALS['contract_notices'] = array();
$GLOBALS['contract_now'] = '2026-10-10 12:00:00';
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $doc;
$wpdb = new ContractConsentLedgerFixture();
$result['order_gate_error'] = null;
try {
	$consent->persist_acceptance( 701 );
	$first_ledger = $wpdb->rows;
	$first_meta = $order->meta;
	$GLOBALS['contract_now'] = '2026-10-10 12:02:00';
	$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $revision_two;
	$consent->persist_acceptance( 701 );
	$result['retry_can_pay'] = $consent->order_can_pay( true, $order );
	$result['already_nonpayable_stays_nonpayable'] = $consent->order_can_pay( false, $order );
	$result['retry_ledger_unchanged'] = $first_ledger === $wpdb->rows;
	$result['retry_meta_unchanged'] = $first_meta === $order->meta;
	$result['retry_insert_count'] = $wpdb->inserts;
} catch ( Throwable $error ) {
	$result['order_gate_error'] = $error->getMessage();
}
$result['order_gate_mutations'] = array();
$mutation_cases = array(
	'product' => array( 'product_id', 999 ), 'variation' => array( 'variation_id', 999 ),
	'quantity' => array( 'quantity', 3 ), 'line_amount' => array( 'total', '26.00' ), 'line_tax' => array( 'tax', '1.00' ),
);
foreach ( $mutation_cases as $name => $change ) {
	$changed_order = clone $order;
	$changed_item = clone $item;
	$changed_item->{ $change[0] } = $change[1];
	$changed_order->items = array( 501 => $changed_item );
	$result['order_gate_mutations'][ $name ] = $consent->order_can_pay( true, $changed_order );
}
foreach ( array( 'currency' => 'CAD', 'total' => '32.50' ) as $name => $value ) {
	$changed_order = clone $order;
	$changed_order->$name = $value;
	$result['order_gate_mutations'][ 'order_' . $name ] = $consent->order_can_pay( true, $changed_order );
}
foreach ( array( '_subscrpt_plan_id' => 999, '_subscrpt_plan_price' => '25.00', '_subscrpt_signup_fee' => '9.00', '_subscrpt_max_no_payment' => 9, '_subscrpt_payment_type' => 'split_payment', '_subscrpt_billing_length' => 12, '_subscrpt_plan_total' => '50.00' ) as $key => $value ) {
	$changed_order = clone $order;
	$changed_item = clone $item;
	$changed_item->meta[ $key ] = $value;
	$changed_order->items = array( 501 => $changed_item );
	$result['order_gate_mutations'][ $key ] = $consent->order_can_pay( true, $changed_order );
}
$changed_order = clone $order;
$changed_item = clone $item;
$changed_item->meta['_subscrpt_plan_terms']['time'] = 3;
$changed_order->items = array( 501 => $changed_item );
$result['order_gate_mutations']['canonical_cadence'] = $consent->order_can_pay( true, $changed_order );
$altered_evidence = clone $order;
$altered_evidence->meta['_ashbi_contract_consent']['document']['approval_ref'] = 'forged-fixture-approval';
$result['altered_evidence_rejected'] = ! $consent->order_can_pay( true, $altered_evidence );
$missing_evidence = clone $order;
unset( $missing_evidence->meta['_ashbi_contract_consent'] );
$result['missing_required_evidence_rejected'] = ! $consent->order_can_pay( true, $missing_evidence );
$result['guard_exception'] = null;
try {
	$consent->guard_order_pay( $missing_evidence );
} catch ( Throwable $error ) {
	$result['guard_exception'] = $error->getMessage();
}
$result['guard_notices'] = count( $GLOBALS['contract_notices'] );
$legacy_order = new WC_Order( 702 );
$legacy_order->items = array( 501 => clone $item );
$GLOBALS['contract_orders'][702] = $legacy_order;
$before_legacy_ledger = $wpdb->rows;
$result['legacy_can_pay'] = $consent->order_can_pay( true, $legacy_order );
$consent->guard_order_pay( $legacy_order );
$result['legacy_meta_unchanged'] = array() === $legacy_order->meta && 0 === $legacy_order->meta_writes;
$result['legacy_ledger_unchanged'] = $before_legacy_ledger === $wpdb->rows;
$result['final_ledger_insert_count'] = $wpdb->inserts;

// Required orders retain their evidence gate when the new-purchase feature is
// disabled. Changing configuration cannot make previously rejected evidence safe.
$before_eligibility_queries = $wpdb->query_attempts;
$disabled_revision = $revision_two;
$disabled_revision['enabled'] = false;
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $disabled_revision;
$result['disabled_config_valid_order_can_pay'] = $consent->order_can_pay( true, $order );
$result['disabled_config_missing_evidence_denied'] = ! $consent->order_can_pay( true, $missing_evidence );
$result['disabled_config_forged_evidence_denied'] = ! $consent->order_can_pay( true, $altered_evidence );
$result['disabled_config_legacy_can_pay'] = $consent->order_can_pay( true, $legacy_order );
$result['eligibility_wrote_ledger'] = $before_eligibility_queries !== $wpdb->query_attempts;

// Failed durable writes and unavailable/corrupt read-back must remain nonpayable.
// The fake database deliberately retains an inserted row across a read outage.
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $doc;
$write_failure_order = clone $order;
$write_failure_order->id = 703;
$GLOBALS['contract_orders'][703] = $write_failure_order;
$wpdb->fail_write = true;
$result['write_failure_threw'] = false;
try {
	$consent->persist_acceptance( 703 );
} catch ( Throwable $error ) {
	$result['write_failure_threw'] = true;
}
$result['write_failure_nonpayable'] = ! $consent->order_can_pay( true, $write_failure_order );
$result['write_failure_no_row'] = ! isset( $wpdb->rows[703] );
$wpdb->fail_write = false;
$read_failure_order = clone $order;
$read_failure_order->id = 704;
$GLOBALS['contract_orders'][704] = $read_failure_order;
$wpdb->fail_read = true;
$result['read_failure_threw'] = false;
try {
	$consent->persist_acceptance( 704 );
} catch ( Throwable $error ) {
	$result['read_failure_threw'] = true;
}
$before_failed_eligibility = $wpdb->query_attempts;
$result['read_failure_nonpayable'] = ! $consent->order_can_pay( true, $read_failure_order );
$wpdb->fail_read = false;
$wpdb->corrupt_read = true;
$result['corrupt_read_nonpayable'] = ! $consent->order_can_pay( true, $order );
$result['failed_eligibility_wrote_ledger'] = $before_failed_eligibility !== $wpdb->query_attempts;
$wpdb->corrupt_read = false;
$result['read_failure_evidence_retained'] = isset( $wpdb->rows[704] );
$result['read_recovery_can_pay'] = $consent->order_can_pay( true, $read_failure_order );

// PlanPrice/PlanCheckout produce floats in a live cart, whereas standalone
// WordPress item metadata is returned as strings after persistence/reload.
// Currency-equivalent values must bind without weakening amount comparisons.
$float_line = $line;
$float_line['subscription']['per_cost'] = 12.5;
$cart->items = array( $float_line );
$float_item = new WC_Order_Item_Product();
$consent->stamp_line( $float_item, 'float-price-cart', $float_line );
$float_item->meta['_subscrpt_plan_id'] = 7;
$float_item->meta['_subscrpt_plan_terms'] = $float_line['subscription'];
$float_item->meta['_subscrpt_plan_price'] = '12.5';
$float_item->meta['_subscrpt_signup_fee'] = '3.25';
$float_item->meta['_subscrpt_max_no_payment'] = '4';
$float_order = new WC_Order( 705 );
$float_order->items = array( 501 => $float_item );
$result['float_cart_snapshot'] = $consent->cart_snapshot();
$result['reloaded_price_snapshot'] = $consent->order_snapshot( $float_order );
$float_payload = array( 'document' => $doc, 'snapshot' => $result['float_cart_snapshot'] );
$result['float_price_binding_valid'] = $consent->validate_frozen_acceptance( $float_payload, $result['reloaded_price_snapshot'] );

// Classic checkout uses _subscrpt_meta rather than plan-term metadata. The
// subscribed cadence/trial/amount must match its stamp, not a later live product.
$classic_line = array(
	'product_id' => 201, 'variation_id' => 0, 'quantity' => 1,
	'line_total' => '9.00', 'line_tax' => '0', 'data' => new ContractConsentProductFixture(),
	'subscription' => array( 'time' => null, 'type' => 'months', 'trial' => null, 'signup_fee' => null, 'per_cost' => 9.0, 'max_no_payment' => 5 ),
);
$classic_item = new WC_Order_Item_Product();
$classic_item->product_id = 201;
$classic_item->variation_id = 0;
$classic_item->quantity = 1;
$classic_item->total = '9.00';
$consent->stamp_line( $classic_item, 'classic-cart-key', $classic_line );
$result['classic_canonical_meta'] = $classic_item->meta;
$classic_order = new WC_Order( 706 );
$classic_order->items = array( 501 => $classic_item );
$classic_order->total = '9.00';
$cart->items = array( $classic_line );
$cart->total = '9.00';
$classic_payload = array( 'document' => $doc, 'snapshot' => $consent->cart_snapshot() );
$result['classic_order_binding_valid'] = $consent->validate_frozen_acceptance( $classic_payload, $consent->order_snapshot( $classic_order ) );
$result['classic_canonical_mutations'] = array();
foreach ( array( 'time' => 2, 'type' => 'weeks', 'trial' => '14 days' ) as $key => $value ) {
	$changed_order = clone $classic_order;
	$changed_item = clone $classic_item;
	$changed_item->meta['_subscrpt_meta'][ $key ] = $value;
	$changed_order->items = array( 501 => $changed_item );
	$result['classic_canonical_mutations'][ $key ] = $consent->validate_frozen_acceptance( $classic_payload, $consent->order_snapshot( $changed_order ) );
}
foreach ( array( '_subscrpt_plan_price' => '10.00', '_subscrpt_signup_fee' => '3.25', '_subscrpt_max_no_payment' => 8, '_subscrpt_payment_type' => 'split_payment', '_subscrpt_billing_length' => 12, '_subscrpt_plan_total' => '50.00' ) as $key => $value ) {
	$changed_order = clone $classic_order;
	$changed_item = clone $classic_item;
	$changed_item->meta[ $key ] = $value;
	$changed_order->items = array( 501 => $changed_item );
	$result['classic_canonical_mutations'][ $key ] = $consent->validate_frozen_acceptance( $classic_payload, $consent->order_snapshot( $changed_order ) );
}

// Include a positively supported finite installment snapshot, not just drift.
$installment_line = $float_line;
$installment_line['subscrpt_payment_type'] = 'split_payment';
$installment_line['subscrpt_billing_length'] = 4;
$installment_line['subscrpt_plan_total'] = 50.0;
$installment_item = new WC_Order_Item_Product();
$consent->stamp_line( $installment_item, 'installment-key', $installment_line );
$installment_item->meta['_subscrpt_plan_id'] = 7;
$installment_item->meta['_subscrpt_plan_terms'] = $installment_line['subscription'];
$installment_item->meta['_subscrpt_plan_price'] = '12.5';
$installment_item->meta['_subscrpt_signup_fee'] = '3.25';
$installment_item->meta['_subscrpt_max_no_payment'] = 4;
$installment_item->meta['_subscrpt_payment_type'] = 'split_payment';
$installment_item->meta['_subscrpt_billing_length'] = 4;
$installment_item->meta['_subscrpt_plan_total'] = '50';
$installment_order = new WC_Order( 707 );
$installment_order->items = array( 501 => $installment_item );
$cart->items = array( $installment_line );
$cart->total = '31.50';
$result['installment_cart_snapshot'] = $consent->cart_snapshot();
$result['installment_order_snapshot'] = $consent->order_snapshot( $installment_order );

// Reviewed policy destinations form part of the immutable displayed document.
// Generic fixture wording and reserved example domain never use client terms.
$policy_doc = $doc;
$policy_doc['policy_url'] = 'https://example.test/shipping-refunds?section=terms&lang=en';
$policy_doc['hash'] = $consent->document_hash( $policy_doc );
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $policy_doc;
$result['policy_document'] = $consent->approved_document();
ob_start();
$consent->render();
$result['policy_render'] = ob_get_clean();
$result['policy_invalid_urls'] = array();
foreach ( array( '', 'http://example.test/policy', 'javascript:alert(1)', '//example.test/policy', 'https:///policy', 'https://', 'https://user@example.test/policy', 'https://user:pass@example.test/policy', "https://example.test/policy\nInjected", array( 'https://example.test' ), 42 ) as $invalid_url ) {
	$invalid_doc = $policy_doc;
	$invalid_doc['policy_url'] = $invalid_url;
	$invalid_doc['hash'] = $consent->document_hash( $invalid_doc );
	$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $invalid_doc;
	$result['policy_invalid_urls'][] = null === $consent->approved_document();
	$result['policy_invalid_frozen_urls'][] = ! $consent->validate_frozen_acceptance( array( 'document' => $invalid_doc, 'snapshot' => $result['installment_order_snapshot'] ), $result['installment_order_snapshot'] );
}
$changed_policy = $policy_doc;
$changed_policy['policy_url'] = 'https://example.test/replaced-policy';
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $changed_policy;
$result['policy_stale_hash_rejected'] = null === $consent->approved_document();
$result['policy_frozen_stale_hash_rejected'] = ! $consent->validate_frozen_acceptance( array( 'document' => $changed_policy, 'snapshot' => $result['installment_order_snapshot'] ), $result['installment_order_snapshot'] );
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $policy_doc;
$policy_order = clone $installment_order;
$policy_order->id = 708;
$policy_order->meta['_ashbi_contract_required'] = '1';
$policy_order->meta['_ashbi_contract_consent'] = array( 'document' => $policy_doc, 'snapshot' => $result['installment_order_snapshot'], 'accepted_at' => $GLOBALS['contract_now'], 'actor_id' => 0, 'payment_outcome' => 'not_confirmed' );
$GLOBALS['contract_orders'][708] = $policy_order;
$consent->persist_acceptance( 708 );
$policy_ledger_before = $wpdb->rows;
$result['policy_order_payable'] = $consent->order_can_pay( true, $policy_order );
$tampered_policy_order = clone $policy_order;
$tampered_policy_order->meta['_ashbi_contract_consent']['document']['policy_url'] = 'https://example.test/replaced-policy';
$tampered_policy_order->meta['_ashbi_contract_consent']['document']['hash'] = $consent->document_hash( $tampered_policy_order->meta['_ashbi_contract_consent']['document'] );
$result['policy_rehashed_tamper_denied'] = ! $consent->order_can_pay( true, $tampered_policy_order );
$result['policy_ledger_unchanged'] = $policy_ledger_before === $wpdb->rows;
$GLOBALS['contract_options']['wp_subscription_contract_revision'] = $doc;
$result['policy_legacy_hash'] = $consent->document_hash( $doc );
$result['policy_legacy_document_valid'] = null !== $consent->approved_document();
$result['policy_legacy_frozen_valid'] = $consent->validate_frozen_acceptance( $order->meta['_ashbi_contract_consent'], $consent->order_snapshot( $order ) );

// Load the actual recovery companion. The filter may disable new checkout UI
// but must not write approvals, erase evidence or defeat cancellation barriers.
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/CancellationEvidence.php';
$stored_config_before = $GLOBALS['contract_options'];
$ledger_before_companion = $wpdb->rows;
$barriers_before_companion = $wpdb->barriers;
$query_attempts_before_companion = $wpdb->query_attempts;
$result['barrier_before_companion'] = SpringDevs\Subscription\Illuminate\CancellationEvidence::blocked( 3493 );
require dirname( __DIR__, 2 ) . '/tools/evidence-preserving-rollback.php';
$result['companion_new_document_disabled'] = null === $consent->approved_document();
$result['companion_existing_order_payable'] = $consent->order_can_pay( true, $order );
$result['companion_missing_evidence_denied'] = ! $consent->order_can_pay( true, $missing_evidence );
$result['barrier_after_companion'] = SpringDevs\Subscription\Illuminate\CancellationEvidence::blocked( 3493 );
$result['companion_config_unchanged'] = $stored_config_before === $GLOBALS['contract_options'];
$result['companion_ledger_unchanged'] = $ledger_before_companion === $wpdb->rows;
$result['companion_barriers_unchanged'] = $barriers_before_companion === $wpdb->barriers;
$result['companion_no_ledger_writes'] = $query_attempts_before_companion === $wpdb->query_attempts;
$result['writes']                 = $GLOBALS['contract_writes'];
$result['historical_reads']       = $GLOBALS['contract_historical_reads'];
echo wp_json_encode( $result );

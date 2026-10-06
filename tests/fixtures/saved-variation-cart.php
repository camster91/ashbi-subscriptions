<?php
/** Saved cart restoration regression using the production cart listeners. */
// phpcs:ignoreFile -- Isolated WooCommerce fixture and dependency doubles.
namespace SpringDevs\Subscription\Illuminate\Plans {
	class PlanRepository {
		public static function resolve_for_product( $parent, $variation ) { return $GLOBALS['rows'][ $variation ] ?? array(); }
		public static function type_to_string( $type ) { return 'subscription'; }
		public static function interval_to_option( $interval ) { return 'months'; }
	}
	class PlanPrice {
		public static function purchase_price( $row ) {
			if ( $row['price'] < 0 ) { throw new \UnexpectedValueException( 'Unavailable price' ); }
			return $row['price'];
		}
	}
}
namespace SpringDevs\Subscription\Illuminate\Subscription {
	class Subscription { public static function get_subs_product( $product ) { return $product; } }
}
namespace {
	function get_post_meta( $id, $key, $single ) { return 10 === $id && '_subscrpt_variation_term_mode' === $key ? 'yes' : ''; }
	function __( $text, $domain ) { return $text; }
	function esc_html__( $text, $domain ) { return $text; }
	function WC() { return $GLOBALS['wc']; }
	function wc_add_notice( $message, $type ) { $GLOBALS['notices'][] = $message; }
	class CartProduct {
		public $enabled;
		public function __construct( $enabled ) { $this->enabled = $enabled; }
		public function is_type( $type ) { return 'variation' === $type; }
		public function is_enabled() { return $this->enabled; }
		public function get_meta( $key ) { return 'month'; }
		public function get_trial() { return null; }
	}
	class SavedCart {
		public $cart_contents = array();
		public function remove_cart_item( $key ) { unset( $this->cart_contents[ $key ] ); }
	}
	$root = $argv[1] ?? dirname( __DIR__, 2 );
	require $root . '/plugin/includes/Illuminate/Helper.php';
	require $root . '/plugin/includes/Frontend/PlanCheckout.php';
	require $root . '/plugin/includes/Frontend/Cart.php';
	$checkout = ( new ReflectionClass( \SpringDevs\Subscription\Frontend\PlanCheckout::class ) )->newInstanceWithoutConstructor();
	$validator = ( new ReflectionClass( \SpringDevs\Subscription\Frontend\Cart::class ) )->newInstanceWithoutConstructor();
	$row = array( 'plan_id' => 8, 'plan_group_id' => 1, 'group_type' => 1, 'billing_frequency' => 2, 'billing_interval' => 3, 'price' => 84.48, 'relation_data' => array( 'price_source' => 'variation' ), 'plan_data' => array(), 'signup_fee' => array(), 'free_trial' => 0 );
	$GLOBALS['rows'] = array( 11 => array( $row ) );
	$plain = array( 'product_id' => 10, 'variation_id' => 11, 'quantity' => 2, 'variation' => array( 'attribute_subscription' => '2 Month' ), 'data' => new CartProduct( true ) );
	if ( ! method_exists( $checkout, 'restore_mapped_cart_item' ) ) {
		$GLOBALS['wc'] = (object) array( 'cart' => new SavedCart() );
		$GLOBALS['wc']->cart->cart_contents = array( 'old-line' => $plain );
		$GLOBALS['notices'] = array();
		$validator->check_cart_items();
		if ( empty( $GLOBALS['wc']->cart->cart_contents['old-line'] ) ) { throw new RuntimeException( 'Core-era saved variation was removed by classic validation.' ); }
		throw new RuntimeException( 'Saved variation has no subscription snapshot.' );
	}
	$restored = $checkout->restore_mapped_cart_item( $plain, $plain, 'old-line' );
	if ( 8 !== $restored['subscrpt_plan_id'] || 2 !== $restored['quantity'] || $plain['variation'] !== $restored['variation'] || 84.48 !== $restored['subscription']['per_cost'] ) { throw new RuntimeException( 'Saved variation not restored exactly.' ); }
	$again = $checkout->restore_mapped_cart_item( $restored, $restored, 'old-line' );
	if ( $again !== $restored ) { throw new RuntimeException( 'Restoration is not idempotent.' ); }
	$one_time = $plain; $one_time['variation_id'] = 12; $one_time['data'] = new CartProduct( false );
	if ( $one_time !== $checkout->restore_mapped_cart_item( $one_time, $one_time, 'one-time' ) ) { throw new RuntimeException( 'One-time item changed.' ); }
	$conflict = $plain; $conflict['subscription'] = array( 'time' => 3, 'type' => 'months', 'trial' => null, 'per_cost' => 84.48 );
	$blocked = $checkout->restore_mapped_cart_item( $conflict, $conflict, 'conflict' );
	if ( empty( $blocked['ashbi_cart_restore_error'] ) || $blocked['subscription'] !== $conflict['subscription'] ) { throw new RuntimeException( 'Conflicting saved terms changed.' ); }
	$matching = $plain; $matching['subscription'] = $restored['subscription'];
	$matching['subscription']['per_cost'] = '84.4800';
	if ( empty( $checkout->restore_mapped_cart_item( $matching, $matching, 'matching' )['subscrpt_plan_id'] ) ) { throw new RuntimeException( 'Matching legacy snapshot rejected.' ); }
	$classic = $plain; $classic['product_id'] = 20; $classic['subscription'] = array( 'type' => 'months', 'trial' => null );
	$GLOBALS['wc'] = (object) array( 'cart' => new SavedCart() );
	$GLOBALS['wc']->cart->cart_contents = array( 'old-line' => $restored, 'one-time' => $one_time, 'conflict' => $blocked, 'classic' => $classic );
	$GLOBALS['notices'] = array();
	$validator->check_cart_items();
	if ( 4 !== count( $GLOBALS['wc']->cart->cart_contents ) || 1 !== count( $GLOBALS['notices'] ) || false !== strpos( $GLOBALS['notices'][0], 'removed' ) ) { throw new RuntimeException( 'Saved lines were removed or unsafe checkout was allowed.' ); }
	$GLOBALS['rows'][11][] = $row;
	if ( empty( $checkout->restore_mapped_cart_item( $plain, $plain, 'ambiguous' )['ashbi_cart_restore_error'] ) ) { throw new RuntimeException( 'Ambiguous mapping accepted.' ); }
	$GLOBALS['rows'][11] = array( array_merge( $row, array( 'price' => -1 ) ) );
	if ( empty( $checkout->restore_mapped_cart_item( $plain, $plain, 'missing-price' )['ashbi_cart_restore_error'] ) ) { throw new RuntimeException( 'Unavailable price accepted.' ); }
	echo "PASS\n";
}

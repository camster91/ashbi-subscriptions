<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Offline core dependency doubles.
/**
 * Isolated classic variation cart and checkout regression; no external services.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.OneDeclarationPerFile, Universal.Namespaces.DisallowDeclarationWithoutName -- Offline runtime doubles share this subprocess only.
namespace SpringDevs\Subscription\Illuminate\Subscription {
	/** Offline runtime dependency double. */
	class Subscription {
		/**
		 * Offline get subs product dependency double.
		 *
		 * @param mixed $id Fixture id.
		 * @return mixed Fixture result.
		 */
		public static function get_subs_product( $id ) {
			return is_object( $id ) ? $id : ( $GLOBALS['products'][ $id ] ?? false ); }
	}
}

namespace SpringDevs\Subscription\Illuminate {
	/** Offline runtime dependency double. */
	class Helper {
		/**
		 * Offline get typos dependency double.
		 *
		 * @param mixed $count Fixture count.
		 * @param mixed $unit Fixture unit.
		 * @return mixed Fixture result.
		 */
		public static function get_typos( $count, $unit ) {
			return rtrim( strtolower( $unit ), 's' ) . ( $count > 1 ? 's' : '' ); }
		/**
		 * Offline subscription exists dependency double.
		 *
		 * @param mixed $id Fixture id.
		 * @param mixed $status Fixture status.
		 * @return mixed Fixture result.
		 */
		public static function subscription_exists( $id, $status ) {
			return $GLOBALS['expired_parent'] ?? false; }
		/**
		 * Find fixture expired subscriptions.
		 *
		 * @param array $args Query arguments.
		 * @return array Candidate IDs.
		 */
		public static function get_subscriptions( $args ) {
			return $GLOBALS['expired_variations'] ?? array(); }
		/**
		 * Offline resolve checkout renewal subscription dependency double.
		 *
		 * @param mixed $item Fixture item.
		 * @param mixed $product Fixture product.
		 * @return mixed Fixture result.
		 */
		public static function resolve_checkout_renewal_subscription( $item, $product ) {
			return false; }
		/**
		 * Offline process new subscription order dependency double.
		 *
		 * @param mixed $item Fixture item.
		 * @param mixed $status Fixture status.
		 * @param mixed $product Fixture product.
		 * @return mixed Fixture result.
		 */
		public static function process_new_subscription_order( $item, $status, $product ) {
			$GLOBALS['created'][] = array( $item->get_order_id(), $product->get_id(), $status );
			return 900 + count( $GLOBALS['created'] ); }
	}
}

namespace {
	/**
	 * Offline add filter dependency double.
	 *
	 * @param mixed $hook Fixture hook.
	 * @param mixed $callback Fixture callback.
	 * @param mixed $priority Fixture priority.
	 * @param mixed $count Fixture count.
	 * @return mixed Fixture result.
	 */
	function add_filter( $hook, $callback, $priority = 10, $count = 1 ) {
		$GLOBALS['hooks'][ $hook ][ $priority ][] = array( $callback, $count ); }
	/**
	 * Offline add action dependency double.
	 *
	 * @param mixed $hook Fixture hook.
	 * @param mixed $callback Fixture callback.
	 * @param mixed $priority Fixture priority.
	 * @param mixed $count Fixture count.
	 * @return mixed Fixture result.
	 */
	function add_action( $hook, $callback, $priority = 10, $count = 1 ) {
		add_filter( $hook, $callback, $priority, $count ); }
	/**
	 * Offline apply filters dependency double.
	 *
	 * @param mixed $hook Fixture hook.
	 * @param mixed $value Fixture value.
	 * @param mixed ...$args Fixture args.
	 * @return mixed Fixture result.
	 */
	function apply_filters( $hook, $value, ...$args ) {
		$priorities = $GLOBALS['hooks'][ $hook ] ?? array();
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $args ), 0, $entry[1] ) );
			}
		} return $value; }
	/**
	 * Offline do action dependency double.
	 *
	 * @param mixed $hook Fixture hook.
	 * @param mixed ...$args Fixture args.
	 * @return mixed Fixture result.
	 */
	function do_action( $hook, ...$args ) {
		foreach ( ( $GLOBALS['hooks'][ $hook ] ?? array() ) as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) ); }
		} }
	/**
	 * Offline subscrpt product has plan dependency double.
	 *
	 * @param mixed $parent Fixture parent.
	 * @param mixed $variation Fixture variation.
	 * @return mixed Fixture result.
	 */
	function subscrpt_product_has_plan( $parent, $variation = 0 ) {
		return 14 === $variation; }
	/**
	 * Offline  dependency double.
	 *
	 * @param mixed $text Fixture text.
	 * @param mixed $domain Fixture domain.
	 * @return mixed Fixture result.
	 */
	function __( $text, $domain ) {
		return $text; }
	/**
	 * Offline esc html dependency double.
	 *
	 * @param mixed $text Fixture text.
	 * @param mixed $domain Fixture domain.
	 * @return mixed Fixture result.
	 */
	function esc_html__( $text, $domain ) {
		return $text; }
	/**
	 * Escape fixture assertion text.
	 *
	 * @param string $text Assertion text.
	 * @return string Escaped text.
	 */
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
	/**
	 * Offline WC dependency double.
	 *
	 * @return mixed Fixture result.
	 */
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Exact WooCommerce core API double.
		return $GLOBALS['wc']; }
	/**
	 * Offline wc add notice dependency double.
	 *
	 * @param mixed $message Fixture message.
	 * @param mixed $type Fixture type.
	 * @return mixed Fixture result.
	 */
	function wc_add_notice( $message, $type ) {
		$GLOBALS['notices'][] = $message; }
	/**
	 * Offline wc get order dependency double.
	 *
	 * @param mixed $id Fixture id.
	 * @return mixed Fixture result.
	 */
	function wc_get_order( $id ) {
		return $GLOBALS['orders'][ $id ]; }
	/**
	 * Offline wc update order item meta dependency double.
	 *
	 * @param mixed $id Fixture id.
	 * @param mixed $key Fixture key.
	 * @param mixed $value Fixture value.
	 * @return mixed Fixture result.
	 */
	function wc_update_order_item_meta( $id, $key, $value ) {
		$GLOBALS['item_meta'][ $id ][ $key ] = $value; }
	/**
	 * Offline update post meta dependency double.
	 *
	 * @param mixed $id Fixture id.
	 * @param mixed $key Fixture key.
	 * @param mixed $value Fixture value.
	 * @return mixed Fixture result.
	 */
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['subscription_meta'][ $id ][ $key ] = $value; }
	/** Offline runtime dependency double. */
	class FixtureProduct {
		/** Fixture state.
		 *
		 * @var mixed
		 */
		private $id;
		/** Fixture state.
		 *
		 * @var mixed
		 */
		private $type;
		/** Fixture state.
		 *
		 * @var mixed
		 */
		private $enabled;
		/** Fixture state.
		 *
		 * @var mixed
		 */
		private $time;
		/**
		 * Offline construct dependency double.
		 *
		 * @param mixed $id Fixture id.
		 * @param mixed $type Fixture type.
		 * @param mixed $enabled Fixture enabled.
		 * @param mixed $time Fixture time.
		 * @return mixed Fixture result.
		 */
		public function __construct( $id, $type, $enabled, $time = 1 ) {
			$this->id      = $id;
			$this->type    = $type;
			$this->enabled = $enabled;
			$this->time    = $time; }
		/**
		 * Offline get id dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_id() {
			return $this->id; }
		/**
		 * Offline is type dependency double.
		 *
		 * @param mixed $type Fixture type.
		 * @return mixed Fixture result.
		 */
		public function is_type( $type ) {
			return $type === $this->type; }
		/**
		 * Offline get type dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_type() {
			return $this->type; }
		/**
		 * Offline is enabled dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function is_enabled() {
			return $this->enabled; }
		/**
		 * Offline get timing option dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_timing_option() {
			return 'month'; }
		/**
		 * Offline get timing per dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_timing_per() {
			return max( 1, (int) $this->time ); }
		/**
		 * Offline has trial dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function has_trial() {
			return false; }
		/**
		 * Offline get trial dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_trial() {
			return null; }
		/**
		 * Offline get price dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_price() {
			return '26.99'; }
		/**
		 * Offline get meta dependency double.
		 *
		 * @param mixed $key Fixture key.
		 * @return mixed Fixture result.
		 */
		public function get_meta( $key ) {
			return '_subscrpt_timing_option' === $key ? 'months' : ( '_subscrpt_timing_per' === $key ? (string) $this->time : '' ); }
	}
	/** Offline runtime dependency double. */
	class FixtureCart {
		/** Fixture state.
		 *
		 * @var mixed
		 */
		public $cart_contents = array();
		/**
		 * Offline remove cart item dependency double.
		 *
		 * @param mixed $key Fixture key.
		 * @return mixed Fixture result.
		 */
		public function remove_cart_item( $key ) {
			unset( $this->cart_contents[ $key ] ); }
	}
	/** Offline runtime dependency double. */
	class FixtureItem {
		/** Fixture state.
		 *
		 * @var mixed
		 */
		private $id;
		/** Fixture state.
		 *
		 * @var mixed
		 */
		private $variation;
		/** Fixture state.
		 *
		 * @var mixed
		 */
		private $terms;
		/**
		 * Offline construct dependency double.
		 *
		 * @param mixed $id Fixture id.
		 * @param mixed $variation Fixture variation.
		 * @param mixed $terms Fixture terms.
		 * @return mixed Fixture result.
		 */
		public function __construct( $id, $variation, $terms ) {
			$this->id        = $id;
			$this->variation = $variation;
			$this->terms     = $terms; }
		/**
		 * Offline get id dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_id() {
			return $this->id; }
		/**
		 * Offline get order id dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_order_id() {
			return $this->id; }
		/**
		 * Offline get product id dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_product_id() {
			return 10; }
		/**
		 * Offline get variation id dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_variation_id() {
			return $this->variation; }
		/**
		 * Offline get meta dependency double.
		 *
		 * @param mixed $key Fixture key.
		 * @return mixed Fixture result.
		 */
		public function get_meta( $key ) {
			return '_ashbi_contract_plan' === $key ? $this->terms : ''; }
	}
	/** Offline runtime dependency double. */
	class FixtureOrder {
		/** Fixture state.
		 *
		 * @var mixed
		 */
		private $item;
		/**
		 * Offline construct dependency double.
		 *
		 * @param mixed $item Fixture item.
		 * @return mixed Fixture result.
		 */
		public function __construct( $item ) {
			$this->item = $item; }
		/**
		 * Offline get items dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_items() {
			return array( $this->item ); }
		/**
		 * Offline get status dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_status() {
			return 'pending'; }
		/**
		 * Offline get id dependency double.
		 *
		 * @return mixed Fixture result.
		 */
		public function get_id() {
			return $this->item->get_order_id(); }
	}
	/**
	 * Offline assert fixture dependency double.
	 *
	 * @throws RuntimeException When an assertion fails.
	 *
	 * @param mixed $condition Fixture condition.
	 * @param mixed $message Fixture message.
	 * @return mixed Fixture result.
	 */
	function assert_fixture( $condition, $message ) {
		if ( ! $condition ) {
			throw new RuntimeException( esc_html( $message ) ); } }

	/**
	 * Read fixture metadata.
	 *
	 * @param int    $id Record ID.
	 * @param string $key Key.
	 * @param bool   $single Single value.
	 * @return mixed Metadata.
	 */
	function get_post_meta( $id, $key, $single = false ) {
		return $GLOBALS['subscription_meta'][ $id ][ $key ] ?? ''; }
	/** Current fixture customer. @return int Customer ID. */
	function get_current_user_id() {
		return 5; }
	/**
	 * Fixture payment cap.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return bool Cap reached.
	 */
	function subscrpt_is_max_payments_reached( $subscription_id ) {
		return false; }
	require dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/Cart.php';
	require dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/Checkout.php';
	$GLOBALS['hooks']    = array();
	$GLOBALS['created']  = array();
	$GLOBALS['notices']  = array();
	$GLOBALS['products'] = array(
		10 => new FixtureProduct( 10, 'variable', false ),
		11 => new FixtureProduct( 11, 'variation', true ),
		12 => new FixtureProduct( 12, 'variation', true, 3 ),
		13 => new FixtureProduct( 13, 'variation', false ),
		14 => new FixtureProduct( 14, 'variation', true ),
		15 => new FixtureProduct( 15, 'variation', true, 2 ),
		16 => new FixtureProduct( 16, 'variation', true, 0 ),
		20 => new FixtureProduct( 20, 'simple', true ),
	);
	$cart                = new \SpringDevs\Subscription\Frontend\Cart();
	$checkout            = new \SpringDevs\Subscription\Frontend\Checkout();
	$monthly             = apply_filters( 'woocommerce_add_cart_item_data', array(), 10, 11, 1 );
	assert_fixture( isset( $monthly['subscription'] ), 'Registered WooCommerce filter dropped selected classic variation snapshot.' );
	assert_fixture( '26.99' === $monthly['subscription']['per_cost'] && 1 === $monthly['subscription']['time'], 'Monthly selected variation price/frequency changed.' );
	$two_month = apply_filters( 'woocommerce_add_cart_item_data', array(), 10, 15, 1 );
	assert_fixture( 2 === $two_month['subscription']['time'], 'Two-month cadence changed.' );
	try {
		apply_filters( 'woocommerce_add_cart_item_data', array(), 10, 16, 1 );
		throw new RuntimeException( 'Invalid variation schedule was accepted.' );
	} catch ( Exception $error ) {
		assert_fixture( false !== strpos( $error->getMessage(), 'billing schedule is unavailable' ), 'Unexpected invalid schedule exception.' ); }
	$quarterly = apply_filters( 'woocommerce_add_cart_item_data', array(), 10, 12, 1 );
	assert_fixture( 3 === $quarterly['subscription']['time'], 'Selected quarterly variation frequency was lost.' );
	assert_fixture( array() === apply_filters( 'woocommerce_add_cart_item_data', array(), 10, 13, 1 ), 'One-time variation incorrectly became subscription.' );
	$mapped = array(
		'subscrpt_plan_id' => 7,
		'subscription'     => array(
			'time'     => 2,
			'type'     => 'months',
			'trial'    => null,
			'per_cost' => 40,
		),
	);
	assert_fixture( $mapped === $cart->add_to_cart_item_data( $mapped, 10, 14 ), 'Classic listener rewrote mapped plan snapshot.' );
	assert_fixture( isset( apply_filters( 'woocommerce_add_cart_item_data', array(), 20, 0, 1 )['subscription'] ), 'Simple subscription regressed.' );
	$GLOBALS['wc']                                  = (object) array( 'cart' => new FixtureCart() );
	$GLOBALS['wc']->cart->cart_contents['selected'] = array_merge( $monthly, array( 'data' => $GLOBALS['products'][11] ) );
	$cart->check_cart_items();
	assert_fixture( isset( $GLOBALS['wc']->cart->cart_contents['selected'] ) && empty( $GLOBALS['notices'] ), 'Valid classic variation removed by cart validation.' );

	$GLOBALS['wc']->cart->cart_contents['invalid'] = array_merge( $monthly, array( 'data' => $GLOBALS['products'][16] ) );
	$cart->check_cart_items();
	assert_fixture( ! isset( $GLOBALS['wc']->cart->cart_contents['invalid'] ), 'Restored cart accepted a zero raw billing interval.' );
	$GLOBALS['expired_parent']         = 700;
	$GLOBALS['expired_variations']     = array( 700 );
	$GLOBALS['subscription_meta'][700] = array(
		'_subscrpt_variation_id' => 12,
		'_subscrpt_plan_id'      => 0,
	);
	assert_fixture( ! isset( apply_filters( 'woocommerce_add_cart_item_data', array(), 10, 11, 1 )['renew_subscrpt'] ), 'Expired sibling variation tagged a fresh purchase as renewal.' );
	assert_fixture( 700 === apply_filters( 'woocommerce_add_cart_item_data', array(), 10, 12, 1 )['renew_subscrpt'], 'Exact expired variation was not selected for renewal.' );
	assert_fixture( ! isset( apply_filters( 'woocommerce_add_cart_item_data', array(), 10, 13, 1 )['renew_subscrpt'] ), 'One-time variation acquired renewal status.' );
	assert_fixture( 701 === $cart->set_renew_status( array( 'renew_subscrpt' => 701 ), 10, 11 )['renew_subscrpt'], 'Explicit renewal identity changed.' );
	unset( $GLOBALS['expired_parent'], $GLOBALS['expired_variations'] );
	$blocks = $cart->extend_cart_item_data(
		array_merge(
			$quarterly,
			array(
				'data'     => $GLOBALS['products'][12],
				'quantity' => 2,
			)
		)
	);
	assert_fixture( 'months' === $blocks['type'] && 53.98 === $blocks['cost'], 'Store API recurring payload lost selected frequency/price.' );
	$terms = array(
		'plan' => array(
			'time'  => 3,
			'type'  => 'months',
			'trial' => null,
			'price' => 26.99,
		),
	);
	foreach ( array( 'classic', 'storeapi' ) as $index => $route ) {
		$fixture_order_id                       = 100 + $index;
		$item                                   = new FixtureItem( $fixture_order_id, 12, $terms );
		$fixture_order                          = new FixtureOrder( $item );
		$GLOBALS['orders'][ $fixture_order_id ] = $fixture_order;
		if ( 'classic' === $route ) {
			$checkout->create_subscription_after_checkout( $fixture_order_id );
		} else {
			$checkout->create_subscription_after_checkout_storeapi( $fixture_order ); }
		assert_fixture( count( $GLOBALS['created'] ) === $index + 1, $route . ' checkout failed to create variation subscription.' );
		assert_fixture( 3 === $GLOBALS['subscription_meta'][ 901 + $index ]['_subscrpt_timing_per'], $route . ' checkout lost accepted variation cadence.' );
		assert_fixture( 10 === $GLOBALS['subscription_meta'][ 901 + $index ]['_subscrpt_product_id'] && 12 === $GLOBALS['subscription_meta'][ 901 + $index ]['_subscrpt_variation_id'], $route . ' parent/variation renewal identity lost.' );
	}
	$one                    = new FixtureItem( 103, 13, array() );
	$GLOBALS['orders'][103] = new FixtureOrder( $one );
	$checkout->create_subscription_after_checkout( 103 );
	assert_fixture( 2 === count( $GLOBALS['created'] ), 'One-time variation created a subscription.' );
	$native                 = new FixtureItem( 104, 12, null );
	$GLOBALS['orders'][104] = new FixtureOrder( $native );
	$checkout->create_subscription_after_checkout( 104 );
	assert_fixture( 3 === count( $GLOBALS['created'] ) && 3 === $GLOBALS['subscription_meta'][903]['_subscrpt_timing_per'], 'Consent-disabled classic variation lost cadence/creation.' );
	$plan_item              = new FixtureItem( 105, 14, null );
	$GLOBALS['orders'][105] = new FixtureOrder( $plan_item );
	$checkout->create_subscription_after_checkout( 105 );
	assert_fixture( 3 === count( $GLOBALS['created'] ), 'Mapped variation created duplicate classic subscription.' );
	echo "PASS\n";
}

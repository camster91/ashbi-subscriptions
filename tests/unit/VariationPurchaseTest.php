<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Isolated WooCommerce doubles for variation purchase snapshots.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,Generic.Files.OneObjectStructurePerFile.MultipleFound,Universal.Files.SeparateFunctionsFromOO.Mixed,Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden,Universal.Namespaces.OneDeclarationPerFile.MultipleFound ,Generic.CodeAnalysis.UnusedFunctionParameter -- Scoped runtime doubles avoid altering other unit fixtures.
namespace SpringDevs\Subscription\Illuminate\Plans {
	/**
	 * Fixture product lookup.
	 *
	 * @param int $id ID.
	 * @return mixed
	 */
	function wc_get_product( $id ) {
		return $GLOBALS['variation_purchase_products'][ $id ] ?? false; }
	/**
	 * Fixture metadata lookup.
	 *
	 * @param int    $id ID.
	 * @param string $key Key.
	 * @param bool   $single Single.
	 * @return mixed
	 */
	function get_post_meta( $id, $key, $single ) {
		return $GLOBALS['variation_purchase_meta'][ $id ][ $key ] ?? ''; }
	/**
	 * Cached product rows double.
	 *
	 * @param string $key Key.
	 * @param string $group Group.
	 * @return array
	 */
	function wp_cache_get( $key, $group ) {
		return $GLOBALS['variation_purchase_rows'] ?? array(); }
	/**
	 * Integer double.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value ); }
}

namespace SpringDevs\Subscription\Frontend {
	/**
	 * Customer translation double.
	 *
	 * @param string $text Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( $text, $domain ) {
		return $text; }
	/**
	 * No one-time offer in this fixture.
	 *
	 * @param mixed $product Product.
	 * @return array
	 */
	function subscrpt_one_time_group( $product ) {
		return array(); }
	/** False in the isolated storefront fixture.
	 *
	 * @return bool
	 */
	function is_admin() {
		return false; }
	/**
	 * Metadata double.
	 *
	 * @param int    $id ID.
	 * @param string $key Key.
	 * @param bool   $single Single.
	 * @return mixed
	 */
	function get_post_meta( $id, $key, $single ) {
		return $GLOBALS['variation_purchase_meta'][ $id ][ $key ] ?? ''; }
	/**
	 * Integer double.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value ); }
	/**
	 * Unslash double.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value; }
}

namespace AshbiSubscriptions\Tests {
	use PHPUnit\Framework\TestCase;
	use SpringDevs\Subscription\Illuminate\Plans\PlanPrice;
	use SpringDevs\Subscription\Illuminate\Plans\PlanRepository;
	use SpringDevs\Subscription\Frontend\PlanCheckout;

	require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Plans/PlanPrice.php';
	require_once dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/Plans.php';

	/** A minimal live price object. */
	class VariationPriceDouble {
		/** Current price.
		 *
		 * @var string
		 */
		public $price = '17.50';
		/**
		 * Sale-aware price double.
		 *
		 * @return string
		 */
		public function get_price() {
			return $this->price;
		}

		/**
		 * Set a calculated price.
		 *
		 * @param float $price Price.
		 * @return void
		 */
		public function set_price( $price ) {
			$this->price = (string) $price; }
	}

	/** Exercise programmatic cart additions, hostile plan IDs and snapshots. */
	final class VariationPurchaseTest extends TestCase {
		/** Fresh mapped monthly variation for each test. */
		protected function setUp(): void {
			$GLOBALS['variation_purchase_meta']     = array(
				10 => array( '_subscrpt_variation_term_mode' => 'yes' ),
				11 => array( '_subscrpt_one_time_enabled' => 'yes' ),
			);
			$GLOBALS['variation_purchase_products'] = array( 11 => new VariationPriceDouble() );
			$GLOBALS['variation_purchase_rows']     = array(
				array(
					'oid'           => 10,
					'vid'           => 0,
					'plan_id'       => 7,
					'relation_data' => array( 'regular_price' => '99' ),
				),
				array(
					'oid'               => 10,
					'vid'               => 11,
					'plan_id'           => 8,
					'plan_group_id'     => 1,
					'group_type'        => 1,
					'billing_frequency' => 2,
					'billing_interval'  => 3,
					'relation_data'     => array(
						'price_source'  => 'variation',
						'regular_price' => '99',
					),
					'plan_data'         => array(),
					'signup_fee'        => array(),
					'free_trial'        => 0,
				),
			);
		}

		/** Clean fixtures so other tests are unaffected. */
		protected function tearDown(): void {
			unset( $GLOBALS['variation_purchase_meta'], $GLOBALS['variation_purchase_products'], $GLOBALS['variation_purchase_rows'] );
		}

		/** Real resolver excludes inherited seeds only in the opt-in mode. */
		public function test_resolver_opt_in_and_legacy(): void {
			self::assertSame( array( 8 ), array_column( PlanRepository::resolve_for_product( 10, 11 ), 'plan_id' ) );
			self::assertSame( array(), PlanRepository::resolve_for_product( 10, 12 ) );
			$GLOBALS['variation_purchase_meta'][10] = array();
			self::assertSame( array( 7, 8 ), array_column( PlanRepository::resolve_for_product( 10, 11 ), 'plan_id' ) );
		}

		/** Native WC sale price wins over typed data, and subsequent edits do not mutate the cart. */
		public function test_programmatic_addition_auto_selects_and_snapshots(): void {
			$checkout = ( new \ReflectionClass( PlanCheckout::class ) )->newInstanceWithoutConstructor();
			$item     = $checkout->add_plan_to_cart( array(), 10, 11 );
			self::assertSame( 8, $item['subscrpt_plan_id'] );
			self::assertSame( 17.5, $item['subscrpt_plan_price'] );
			self::assertSame( 2, $item['subscription']['time'] );
			self::assertSame( 'months', $item['subscription']['type'] );
			$GLOBALS['variation_purchase_products'][11]->price = '20';
			self::assertSame( 17.5, $item['subscription']['per_cost'] );
			self::assertSame( 20.0, PlanPrice::purchase_price( $GLOBALS['variation_purchase_rows'][1] ) );
		}

		/** Saved carts resolve exact terms without relying on a posted plan. */
		public function test_saved_variation_uses_exact_mapping_without_request(): void {
			$checkout = ( new \ReflectionClass( PlanCheckout::class ) )->newInstanceWithoutConstructor();
			$item     = array(
				'product_id'   => 10,
				'variation_id' => 11,
				'quantity'     => 2,
			);
			$restored = $checkout->restore_mapped_cart_item( $item, $item, 'saved' );
			self::assertSame( 8, $restored['subscrpt_plan_id'] );
			self::assertSame( 17.5, $restored['subscription']['per_cost'] );
			self::assertSame( 2, $restored['quantity'] );
			self::assertSame( $restored, $checkout->restore_mapped_cart_item( $restored, $restored, 'saved' ) );
			$item['variation_id'] = 12;
			self::assertSame( $item, $checkout->restore_mapped_cart_item( $item, $item, 'one-time' ) );
		}

		/** An invalid posted plan is overridden; unmapped variation is a plain purchase. */
		public function test_posted_plan_cannot_change_variation_mapping(): void {
			$checkout = ( new \ReflectionClass( PlanCheckout::class ) )->newInstanceWithoutConstructor();
			self::assertSame( 8, $checkout->add_plan_to_cart( array( 'subscrpt_plan_id' => 7 ), 10, 11 )['subscrpt_plan_id'] );
			self::assertSame( array(), $checkout->add_plan_to_cart( array( 'subscrpt_plan_id' => 8 ), 10, 12 ) );
		}

		/** A migrated trial keeps the recurring snapshot but charges no recurring line up front. */
		public function test_mapped_trial_initial_line_is_free(): void {
			$checkout = ( new \ReflectionClass( PlanCheckout::class ) )->newInstanceWithoutConstructor();
			$GLOBALS['variation_purchase_rows'][1]['free_trial'] = 7;
			$item         = $checkout->add_plan_to_cart( array(), 10, 11 );
			$item['data'] = $GLOBALS['variation_purchase_products'][11];
			$cart         = new class( $item ) {
				/** Cart fixture.
				 *
				 * @var array
				 */
				private $item;
				/**
				 * Store fixture.
				 *
				 * @param array $item Fixture.
				 */
				public function __construct( $item ) {
					$this->item = $item; }
				/** Cart lines.
				 *
				 * @return array
				 */
				public function get_cart() {
					return array( $this->item ); }
			};
			$checkout->set_cart_item_price( $cart );
			self::assertSame( '0', $item['data']->get_price() );
			self::assertSame( 17.5, $item['subscrpt_plan_price'] );
		}

		/** Storefront skips unavailable terms while cart failure remains catchable by WC. */
		public function test_missing_price_display_and_cart_notice(): void {
			$plans  = ( new \ReflectionClass( \SpringDevs\Subscription\Frontend\Plans::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( $plans, 'term_price' );
			$method->setAccessible( true );
			foreach ( array( '', '-1', 'invalid' ) as $price ) {
				$GLOBALS['variation_purchase_products'][11]->price = $price;
				self::assertNull( $method->invoke( $plans, $GLOBALS['variation_purchase_rows'][1] ) );
			}
			unset( $GLOBALS['variation_purchase_products'][11] );
			self::assertNull( $method->invoke( $plans, $GLOBALS['variation_purchase_rows'][1] ) );
			$contexts = new \ReflectionMethod( $plans, 'build_contexts' );
			$contexts->setAccessible( true );
			$product = new class() {
				/**
				 * Product type double.
				 *
				 * @param string $type Type.
				 * @return bool
				 */
				public function is_type( $type ) {
					return 'variation' === $type; }
				/** Variation ID.
				 *
				 * @return int
				 */
				public function get_id() {
					return 11; }
				/** Parent ID.
				 *
				 * @return int
				 */
				public function get_parent_id() {
					return 10; }
			};
			self::assertSame( array(), $contexts->invoke( $plans, $product ) );
			$checkout = ( new \ReflectionClass( PlanCheckout::class ) )->newInstanceWithoutConstructor();
			try {
				$checkout->add_plan_to_cart( array(), 10, 11 );
				self::fail( 'Unavailable price must reject the cart addition.' );
			} catch ( \Exception $error ) {
				self::assertSame( 'This subscription is currently unavailable. Please choose another option or contact the store.', $error->getMessage() );
			}
		}

		/** Missing live WC price fails closed. */
		public function test_missing_variation_price_rejected(): void {
			$GLOBALS['variation_purchase_products'][11]->price = '';
			$this->expectException( \UnexpectedValueException::class );
			PlanPrice::purchase_price( $GLOBALS['variation_purchase_rows'][1] );
		}
	}
}

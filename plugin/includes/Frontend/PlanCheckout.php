<?php
/**
 * Plan checkout consumption.
 *
 * The plan-gated billing path: cart item → order line item → subscription
 * snapshot. Every hook here acts ONLY when a plan was chosen (`subscrpt_plan_id`
 * on the cart / order item), so classic (non-plan) items follow the existing
 * `Frontend\Checkout` path byte-for-byte. Ashbi resolves every supported plan
 * type on simple products and concrete variations, then snapshots the selected
 * term onto the order and subscription so later renewals do not depend on a
 * mutable product or a paid extension.
 *
 * The subscription record is created from the plan term (price + cadence + trial
 * resolved at add-to-cart), written into the same classic snapshot meta the
 * engine already renews on — so a plan subscription renews on its snapshot with
 * no plan-specific renewal code.
 *
 * @package SpringDevs\Subscription
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName -- Legacy autoload paths are part of the public compatibility contract.

namespace SpringDevs\Subscription\Frontend;

use SpringDevs\Subscription\Illuminate\Helper;
use SpringDevs\Subscription\Illuminate\Plans\PlanRepository;
use SpringDevs\Subscription\Illuminate\Plans\PlanPrice;

/**
 * Class PlanCheckout
 */
class PlanCheckout {

	/**
	 * Register the plan-gated checkout hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_plan_to_cart' ), 20, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_cart_item_price' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_signup_fee' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_plan_order_item' ), 20, 3 );

		// Priority 9: create the subscription before the classic listener runs, so.
		// the classic simple path (which we also gate off in Frontend\Checkout).
		// never double-creates for a plan item.
		add_action( 'subscrpt_product_checkout', array( $this, 'create_plan_subscription' ), 9, 3 );
	}

	/**
	 * Resolve the plan term a shopper chose for a product, validating that the
	 * chosen plan id is actually one of the product's connected terms.
	 *
	 * @param int $product_id   Product parent id.
	 * @param int $plan_id      Chosen plan-term id.
	 * @param int $variation_id Variation id, or 0 for simple products.
	 *
	 * @return array|null Resolved plan row, or null if not valid for the product.
	 */
	private function resolve_chosen( $product_id, $plan_id, $variation_id = 0 ) {
		$plan_id = absint( $plan_id );
		if ( ! $plan_id ) {
			return null;
		}

		foreach ( PlanRepository::resolve_for_product( $product_id, $variation_id ) as $row ) {
			if ( (int) $row['plan_id'] === $plan_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Resolve a fallback plan for a bare add-to-cart — a direct link with no plan
	 * chosen (`?add-to-cart=ID`). One-time purchase wins when enabled: the item
	 * stays a plain one-time line at the native price (returns 0). Otherwise the
	 * product's first plan is selected, matching the on-page selector's default.
	 *
	 * @param int $product_id   Product parent id.
	 * @param int $variation_id Variation id, or 0 for simple products.
	 *
	 * @return int Plan-term id, or 0 to leave the item untouched.
	 */
	private function fallback_plan_id( $product_id, $variation_id = 0 ) {
		if ( 'yes' === get_post_meta( $product_id, '_subscrpt_variation_term_mode', true ) ) {
			return self::mapped_plan_id( PlanRepository::resolve_for_product( $product_id, $variation_id ) );
		}

		if ( ! function_exists( 'subscrpt_product_has_plan' ) || ! subscrpt_product_has_plan( $product_id, $variation_id ) ) {
			return 0;
		}

		// One-time enabled → keep it a one-time line at the native price.
		$one_time_id = $variation_id ? $variation_id : $product_id;
		if ( 'yes' === get_post_meta( $one_time_id, '_subscrpt_one_time_enabled', true ) ) {
			return 0;
		}

		// Default to the product's first plan (same order the selector defaults to).
		foreach ( PlanRepository::resolve_for_product( $product_id, $variation_id ) as $row ) {
			return (int) $row['plan_id'];
		}

		return 0;
	}

	/**
	 * A mapped variation has exactly one term; malformed multi-term data fails closed.
	 *
	 * @param array $rows Exact variation rows.
	 * @return int
	 * @throws \UnexpectedValueException On conflicting mappings.
	 */
	public static function mapped_plan_id( array $rows ) {
		if ( count( $rows ) > 1 ) {
			throw new \UnexpectedValueException( 'This variation has conflicting subscription terms. Please contact the store.' );
		}
		return 1 === count( $rows ) ? (int) $rows[0]['plan_id'] : 0;
	}

	/**
	 * Map a resolved plan row to checkout terms.
	 *
	 * @param array $row Resolved plan row.
	 *
	 * @throws \UnexpectedValueException When the subscription price is unavailable.
	 * @return array{price:float,total_price:float,time:int,option:string,trial:?string,signup_fee:float,payment_type:string,max_payments:int,billing_length:int,plan_data:array}
	 */
	private function term_terms( $row ) {

		$trial = null;
		if ( ! empty( $row['free_trial'] ) && (int) $row['free_trial'] > 0 ) {
			$trial_interval = isset( $row['plan_data']['free_trial_interval'] ) ? (string) $row['plan_data']['free_trial_interval'] : 'days';
			$trial          = (int) $row['free_trial'] . ' ' . $trial_interval;
		}

		try {
			$total_price = PlanPrice::purchase_price( $row );
		} catch ( \UnexpectedValueException $error ) {
			throw new \UnexpectedValueException( esc_html__( 'This subscription is currently unavailable. Please choose another option or contact the store.', 'subscription' ), 0, $error );
		}
		$plan_data   = is_array( $row['plan_data'] ) ? $row['plan_data'] : array();
		if ( in_array( $row['relation_data']['price_source'] ?? '', array( 'variation', 'product' ), true ) ) {
			$plan_data['ashbi_price_source'] = $row['relation_data']['price_source'];
		}
		$group_type  = PlanRepository::type_to_string( (int) $row['group_type'] );
		$payment_type = 'installments' === $group_type ? 'split_payment' : 'recurring';
		$billing_length = max( 0, (int) ( $row['billing_length'] ?? 0 ) );
		$installment_count = 'installments' === $group_type ? max( 2, (int) ( $plan_data['installment_count'] ?? 2 ) ) : 0;
		$max_payment = function_exists( 'subscrpt_plan_payment_limit' )
			? subscrpt_plan_payment_limit( $payment_type, $billing_length, $installment_count )
			: ( 'split_payment' === $payment_type ? $installment_count : $billing_length );
		$price       = $total_price;
		if ( 'split_payment' === $payment_type && $max_payment > 1 && function_exists( 'subscrpt_split_amounts' ) ) {
			$price = (float) subscrpt_split_amounts( $total_price, $max_payment )['per_installment'];
		}

		$signup_fee = isset( $row['signup_fee']['amount'] ) ? (float) $row['signup_fee']['amount'] : 0.0;

		return array(
			'price'          => $price,
			'total_price'    => $total_price,
			'time'           => max( 1, (int) $row['billing_frequency'] ),
			'option'         => PlanRepository::interval_to_option( (int) $row['billing_interval'] ),
			'trial'          => $trial,
			'signup_fee'     => $signup_fee,
			'payment_type'   => $payment_type,
			'max_payments'   => $max_payment,
			'billing_length' => $billing_length,
			'plan_data'      => $plan_data,
		);
	}

	/**
	 * Stamp the chosen plan onto the cart item.
	 *
	 * Reads `subscrpt_plan_id` from the add-to-cart request, resolves the term,
	 * and writes the plan id + a `subscription` array (same shape the classic
	 * engine uses) so cart display and calculation work unchanged.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product parent id.
	 * @param int   $variation_id   Variation id, or 0 for simple products.
	 * @param int   $quantity       Quantity.
	 *
	 * @return array
	 */
	public function add_plan_to_cart( $cart_item_data, $product_id, $variation_id = 0, $quantity = 1 ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		if ( $variation_id && function_exists( 'wc_get_product' ) ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation && $variation->get_parent_id() ) {
				$product_id = (int) $variation->get_parent_id();
			}
		}
		// Read from the request so both the product-page form (POST) and direct.
		// checkout links (`?add-to-cart=ID&subscrpt_plan_id=TERM`, GET) select a plan.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce verifies the add-to-cart request; we only read a plan id.
		$plan_id = ! empty( $cart_item_data['subscrpt_plan_id'] ) ? absint( $cart_item_data['subscrpt_plan_id'] ) : 0;
		if ( ! $plan_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce verifies the add-to-cart request; we only read a plan id.
			$plan_id = isset( $_REQUEST['subscrpt_plan_id'] ) ? absint( wp_unslash( $_REQUEST['subscrpt_plan_id'] ) ) : 0;
		}
		if ( 'yes' === get_post_meta( $product_id, '_subscrpt_variation_term_mode', true ) ) {
			$plan_id = $this->fallback_plan_id( $product_id, $variation_id );
			unset( $cart_item_data['subscrpt_plan_id'] );
		}
		if ( ! $plan_id ) {
			// Bare add-to-cart (direct link, no plan chosen): fall back to one-time.
			// when enabled, otherwise the product's first plan.
			$plan_id = $this->fallback_plan_id( $product_id, $variation_id );
			if ( ! $plan_id ) {
				return $cart_item_data;
			}
		}

		$row = $this->resolve_chosen( $product_id, $plan_id, $variation_id );
		if ( ! $row ) {
			return $cart_item_data;
		}

		$terms = $this->term_terms( $row );

		$cart_item_data['subscrpt_plan_id']        = $plan_id;
		$cart_item_data['subscrpt_plan_group_id']  = (int) $row['plan_group_id'];
		$cart_item_data['subscrpt_plan_price']     = $terms['price'];
		$cart_item_data['subscrpt_plan_total']     = $terms['total_price'];
		$cart_item_data['subscrpt_payment_type']   = $terms['payment_type'];
		$cart_item_data['subscrpt_max_no_payment'] = $terms['max_payments'];
		$cart_item_data['subscrpt_signup_fee']     = $terms['signup_fee'];
		$cart_item_data['subscrpt_variation_id']   = $variation_id;
		$cart_item_data['subscrpt_billing_length'] = $terms['billing_length'];
		$cart_item_data['subscrpt_plan_data']      = $terms['plan_data'];
		$cart_item_data['subscription']            = array(
			'time'           => $terms['time'],
			'type'           => $terms['option'],
			'trial'          => $terms['trial'],
			'signup_fee'     => $terms['signup_fee'],
			'per_cost'       => $terms['price'],
			'max_no_payment' => $terms['max_payments'],
		);

		return $cart_item_data;
	}

	/**
	 * Add a plan term's one-time signup fee to the initial cart order.
	 *
	 * The fee is intentionally a cart fee rather than part of the recurring
	 * line price. Renewal orders use the subscription's recurring price and
	 * therefore never charge the signup fee again.
	 *
	 * @param \WC_Cart $cart Cart object.
	 *
	 * @return void
	 */
	public function add_signup_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['subscrpt_plan_id'] ) || empty( $cart_item['subscrpt_signup_fee'] ) ) {
				continue;
			}

			$fee = (float) $cart_item['subscrpt_signup_fee'] * max( 1, (int) $cart_item['quantity'] );
			$cart->add_fee( __( 'Subscription sign-up fee', 'subscription' ), $fee, false );
		}
	}

	/**
	 * Override the cart line price with the resolved plan price.
	 *
	 * @param \WC_Cart $cart Cart object.
	 *
	 * @return void
	 */
	public function set_cart_item_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['subscrpt_plan_price'], $cart_item['data'] ) ) {
				$price = (float) $cart_item['subscrpt_plan_price'];
				if ( ! empty( $cart_item['subscription']['trial'] ) && in_array( $cart_item['subscrpt_plan_data']['ashbi_price_source'] ?? '', array( 'variation', 'product' ), true ) ) {
					$price = 0.0;
				}
				$cart_item['data']->set_price( $price );
			}
		}
	}

	/**
	 * Persist the chosen plan + terms onto the order line item so the resolved
	 * snapshot survives past cart session (the product carries no such meta).
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $cart_item     Cart item data.
	 *
	 * @return void
	 */
	public function save_plan_order_item( $item, $cart_item_key, $cart_item ) {
		if ( empty( $cart_item['subscrpt_plan_id'] ) ) {
			return;
		}

		$item->update_meta_data( '_subscrpt_variation_id', (int) ( $cart_item['subscrpt_variation_id'] ?? 0 ) );
		$item->update_meta_data( '_subscrpt_plan_id', (int) $cart_item['subscrpt_plan_id'] );
		$item->update_meta_data( '_subscrpt_plan_group_id', (int) ( $cart_item['subscrpt_plan_group_id'] ?? 0 ) );
		$item->update_meta_data( '_subscrpt_plan_price', (float) ( $cart_item['subscrpt_plan_price'] ?? 0 ) );
		$item->update_meta_data( '_subscrpt_plan_total', (float) ( $cart_item['subscrpt_plan_total'] ?? 0 ) );
		$item->update_meta_data( '_subscrpt_payment_type', (string) ( $cart_item['subscrpt_payment_type'] ?? 'recurring' ) );
		$item->update_meta_data( '_subscrpt_max_no_payment', (int) ( $cart_item['subscrpt_max_no_payment'] ?? 0 ) );
		$item->update_meta_data( '_subscrpt_signup_fee', (float) ( $cart_item['subscrpt_signup_fee'] ?? 0 ) );
		$item->update_meta_data( '_subscrpt_billing_length', (int) ( $cart_item['subscrpt_billing_length'] ?? 0 ) );
		$item->update_meta_data( '_subscrpt_plan_data', $cart_item['subscrpt_plan_data'] ?? array() );
		$item->update_meta_data( '_subscrpt_plan_terms', $cart_item['subscription'] ?? array() );
	}

	/**
	 * Create the subscription record from the order item's plan terms.
	 *
	 * Fires on `subscrpt_product_checkout` for every order item; acts only when
	 * the item carries `_subscrpt_plan_id`. Writes the same classic snapshot meta
	 * the engine renews on, but sourced from the plan term rather than the product.
	 *
	 * @param \WC_Order_Item $order_item  Order item.
	 * @param \WC_Product    $product     Product object.
	 * @param string         $post_status Subscription status.
	 *
	 * @throws \Exception When a renewal checkout loses its eligibility or period claim.
	 * @return void
	 */
	public function create_plan_subscription( $order_item, $product, $post_status ) {
		// Switching changes an existing subscription after payment; the target line.
		// must not create a second subscription during checkout processing.
		if ( in_array( $order_item->get_meta( '_wp_subs_switch' ), array( true, 1, '1' ), true ) ) {
			return;
		}
		$plan_id = (int) $order_item->get_meta( '_subscrpt_plan_id' );
		if ( ! $plan_id ) {
			return;
		}

		$renewal_requested       = ! empty( $order_item->get_meta( '_renew_subscrpt' ) );
		$renewal_subscription_id = Helper::resolve_checkout_renewal_subscription( $order_item, $product );
		if ( $renewal_requested && ! $renewal_subscription_id ) {
			$order = wc_get_order( $order_item->get_order_id() );
			if ( $order ) {
				$order->set_status( 'cancelled', esc_html__( 'Renewal checkout cancelled because the selected subscription is no longer eligible.', 'subscription' ) );
				$order->save();
			}
			throw new \Exception( esc_html__( 'The selected subscription cannot be renewed from this checkout.', 'subscription' ) );
		}
		if ( $renewal_subscription_id && 'cancelled' === $post_status ) {
			return;
		}
		if ( $renewal_subscription_id ) {
			$renewal_timing_per    = (int) get_post_meta( $renewal_subscription_id, '_subscrpt_timing_per', true );
			$renewal_timing_option = (string) get_post_meta( $renewal_subscription_id, '_subscrpt_timing_option', true );
			wc_update_order_item_meta(
				$order_item->get_id(),
				'_subscrpt_meta',
				array(
					'time'  => $renewal_timing_per > 0 ? $renewal_timing_per : 1,
					'type'  => $renewal_timing_option,
					'trial' => null,
				)
			);

			if ( ! Helper::process_order_renewal( $renewal_subscription_id, $order_item->get_order_id(), $order_item->get_id() ) ) {
				$order = wc_get_order( $order_item->get_order_id() );
				if ( $order ) {
					$order->set_status( 'cancelled', esc_html__( 'Renewal checkout cancelled because another order already owns this subscription period.', 'subscription' ) );
					$order->save();
				}
				throw new \Exception( esc_html__( 'A renewal order already exists for this subscription period. Please pay the existing order from your account.', 'subscription' ) );
			}

			update_post_meta( $renewal_subscription_id, '_subscrpt_order_id', $order_item->get_order_id() );
			update_post_meta( $renewal_subscription_id, '_subscrpt_order_item_id', $order_item->get_id() );
			do_action( 'subscrpt_order_checkout', $renewal_subscription_id, $order_item );
			return;
		}

		$terms = $order_item->get_meta( '_subscrpt_plan_terms' );
		$terms = is_array( $terms ) ? $terms : array();

		$timing_per    = (int) ( $terms['time'] ?? 1 );
		$timing_option = (string) ( $terms['type'] ?? 'months' );
		$trial         = $terms['trial'] ?? null;
		$type          = Helper::get_typos( $timing_per, $timing_option );

		wc_update_order_item_meta(
			$order_item->get_id(),
			'_subscrpt_meta',
			array(
				'time'  => $timing_per,
				'type'  => $timing_option,
				'trial' => $trial,
			)
		);

		$subscription_id = Helper::process_new_subscription_order( $order_item, $post_status, $product );
		if ( ! $subscription_id ) {
			return;
		}

		update_post_meta( $subscription_id, '_subscrpt_timing_per', $timing_per );
		update_post_meta( $subscription_id, '_subscrpt_timing_option', $timing_option );
		update_post_meta( $subscription_id, '_subscrpt_price', (float) $order_item->get_meta( '_subscrpt_plan_price' ) );
		update_post_meta( $subscription_id, '_subscrpt_product_id', (int) $order_item->get_product_id() );
		$variation_id = (int) $order_item->get_variation_id();
		if ( $variation_id > 0 ) {
			update_post_meta( $subscription_id, '_subscrpt_variation_id', $variation_id );
		}
		update_post_meta( $subscription_id, '_subscrpt_plan_id', $plan_id );
		update_post_meta( $subscription_id, '_subscrpt_plan_group_id', (int) $order_item->get_meta( '_subscrpt_plan_group_id' ) );
		update_post_meta( $subscription_id, '_subscrpt_user_cancel', $product->get_meta( '_subscrpt_user_cancel' ) );
		update_post_meta( $subscription_id, '_subscrpt_order_id', $order_item->get_order_id() );
		update_post_meta( $subscription_id, '_subscrpt_order_item_id', $order_item->get_id() );
		update_post_meta( $subscription_id, '_subscrpt_trial', $trial );
		$payment_type = (string) $order_item->get_meta( '_subscrpt_payment_type' );
		if ( ! $payment_type ) {
			$payment_type = 'recurring';
		}
		update_post_meta( $subscription_id, '_subscrpt_payment_type', $payment_type );
		update_post_meta( $subscription_id, '_subscrpt_max_no_payment', (int) $order_item->get_meta( '_subscrpt_max_no_payment' ) );
		update_post_meta( $subscription_id, '_subscrpt_signup_fee', (float) $order_item->get_meta( '_subscrpt_signup_fee' ) );
		update_post_meta( $subscription_id, '_subscrpt_billing_length', (int) $order_item->get_meta( '_subscrpt_billing_length' ) );
		update_post_meta( $subscription_id, '_subscrpt_plan_total', (float) $order_item->get_meta( '_subscrpt_plan_total' ) );
		update_post_meta( $subscription_id, '_subscrpt_plan_data', $order_item->get_meta( '_subscrpt_plan_data' ) );

		if ( 'active' === $post_status ) {
			$start_date = time();
			$next_date  = sdevs_wp_strtotime( $timing_per . ' ' . $type, $start_date );
			if ( $trial ) {
				$start_date = sdevs_wp_strtotime( $trial );
				$next_date  = $start_date;
			}
			update_post_meta( $subscription_id, '_subscrpt_start_date', $start_date );
			update_post_meta( $subscription_id, '_subscrpt_next_date', $next_date );
		}

		do_action( 'subscrpt_order_checkout', $subscription_id, $order_item );
	}
}

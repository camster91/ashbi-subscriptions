<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- This filename is part of the imported public compatibility surface.
/**
 * Checkout handlers for subscription plugin.
 *
 * @package wp_subscription
 */

namespace SpringDevs\Subscription\Frontend;

use SpringDevs\Subscription\Illuminate\Helper;
use SpringDevs\Subscription\Illuminate\Subscription\Subscription;

/**
 * Checkout class
 */
class Checkout {

	/**
	 * Initialize the class
	 */
	public function __construct() {
		// Subscription upgrade/downgrade order created hook.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'trigger_subscription_switch_order_created' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'trigger_subscription_switch_order_created_storeapi' ) );

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'create_subscription_after_checkout' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'create_subscription_after_checkout_storeapi' ) );
		add_action( 'woocommerce_resume_order', array( $this, 'remove_subscriptions' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_product_meta' ), 10, 3 );
	}

	/**
	 * Create subscription during checkout on storeAPI.
	 *
	 * @param \WC_Order $order Order Object.
	 */
	public function create_subscription_after_checkout_storeapi( $order ) {
		$this->create_subscription_after_checkout( $order->get_id() );
	}

	/**
	 * Create subscription during checkout.
	 *
	 * @param int $order_id Order ID.
	 * @throws \Exception When another order already owns the renewal period.
	 */
	public function create_subscription_after_checkout( $order_id ) {
		$order = wc_get_order( $order_id );

		// Grab the post status based on order status.
		$post_status = 'active';
		switch ( $order->get_status() ) {
			case 'on-hold':
			case 'pending':
				$post_status = 'pending';
				break;

			case 'failed':
			case 'cancelled':
				$post_status = 'cancelled';
				break;

			default:
				break;
		}

		// Create subscription for order items.
		$order_items = $order->get_items();
		foreach ( $order_items as $order_item ) {
			// A switch order pays for a target plan, but must never create a second.
			// subscription. Illuminate\Switching applies the target to the existing.
			// record after payment is confirmed.
			if ( in_array( $order_item->get_meta( '_wp_subs_switch' ), array( true, 1, '1' ), true ) ) {
				continue;
			}
			$variation_id = $order_item->get_variation_id();
			$product_id   = $variation_id ? $variation_id : $order_item->get_product_id();
			$product      = Subscription::get_subs_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			// Plan items are created by Frontend\PlanCheckout on `subscrpt_product_checkout`.
			// below (resolution order: tied plan first, else classic meta). Skipping.
			// them here keeps the classic path from creating a second subscription.
			if ( $product->is_type( 'simple' ) && ! $order_item->get_meta( '_subscrpt_plan_id' ) ) {
				// A plan product bought as One-Time carries no plan id — it is not a.
				// subscription, so never record one for it.
				$is_one_time = function_exists( 'subscrpt_product_has_plan' ) && subscrpt_product_has_plan( $product->get_id() );

				if ( $product->is_enabled() && ! $is_one_time ) {
					$renew_requested       = ! empty( $order_item->get_meta( '_renew_subscrpt' ) );
					$renew_subscription_id = Helper::resolve_checkout_renewal_subscription( $order_item, $product );
					$is_renew              = false !== $renew_subscription_id;
					if ( $renew_requested && ! $is_renew ) {
						$order->set_status( 'cancelled', esc_html__( 'Renewal checkout cancelled because the selected subscription is no longer eligible.', 'subscription' ) );
						$order->save();
						throw new \Exception( esc_html__( 'The selected subscription cannot be renewed from this checkout.', 'subscription' ) );
					}

					$timing_option = $product->get_timing_option();
					$trial         = $product->get_trial();

					wc_update_order_item_meta(
						$order_item->get_id(),
						'_subscrpt_meta',
						array(
							'time'  => 1,
							'type'  => $timing_option,
							'trial' => $trial,
						)
					);

					// Renew subscription if need!
					$selected_subscription_id = null;
					if ( $is_renew && 'cancelled' === $post_status ) {
						continue;
					} elseif ( $is_renew ) {
						$selected_subscription_id = $renew_subscription_id;
						if ( ! Helper::process_order_renewal( $selected_subscription_id, $order_id, $order_item->get_id() ) ) {
							$order->set_status( 'cancelled', esc_html__( 'Renewal checkout cancelled because another order already owns this subscription period.', 'subscription' ) );
							$order->save();
							throw new \Exception( esc_html__( 'A renewal order already exists for this subscription period. Please pay the existing order from your account.', 'subscription' ) );
						}
					} else {
						$selected_subscription_id = Helper::process_new_subscription_order( $order_item, $post_status, $product );
					}

					if ( $selected_subscription_id ) {
						// product related.
						update_post_meta( $selected_subscription_id, '_subscrpt_timing_option', $timing_option );
						update_post_meta( $selected_subscription_id, '_subscrpt_price', $product->get_price() );
						update_post_meta( $selected_subscription_id, '_subscrpt_user_cancel', $product->get_meta( '_subscrpt_user_cancel' ) );

						// order related.
						update_post_meta( $selected_subscription_id, '_subscrpt_order_id', $order_id );
						update_post_meta( $selected_subscription_id, '_subscrpt_order_item_id', $order_item->get_id() );

						// subscription related.
						update_post_meta( $selected_subscription_id, '_subscrpt_trial', $trial );

						do_action( 'subscrpt_order_checkout', $selected_subscription_id, $order_item );
					}
				}
			}

			do_action( 'subscrpt_product_checkout', $order_item, $product, $post_status );
		}
	}

	/**
	 * Remove subscriptions for resumed orders.
	 *
	 * @param int $order_id Order id.
	 *
	 * @return void
	 */
	public function remove_subscriptions( $order_id ) {
		global $wpdb;
		// delete subscriptions & order item meta.
		$histories = Helper::get_subscriptions_from_order( $order_id );
		foreach ( $histories as $history ) {
			$table_name = $wpdb->prefix . 'subscrpt_order_relation';
			// @phpcs:ignore
			$relation_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE subscription_id=%d', array( $table_name, $history->subscription_id ) ) );
			if ( 1 === (int) $relation_count ) {
				wp_delete_post( $history->subscription_id, true );
			}
			wc_delete_order_item_meta( $history->order_item_id, '_subscrpt_meta' );
		}

		// delete order subscription relation.
		global $wpdb;
		$table_name = $wpdb->prefix . 'subscrpt_order_relation';
		// phpcs:ignore
		$wpdb->delete( $table_name, array( 'order_id' => $order_id ), array( '%d' ) );
	}

	/**
	 * Save renew meta
	 *
	 * @param object $item Item.
	 * @param string $cart_item_key Cart Item Key.
	 * @param array  $cart_item Cart Item.
	 */
	public function save_order_item_product_meta( $item, $cart_item_key, $cart_item ) {
		if ( isset( $cart_item['renew_subscrpt'] ) ) {
			$item->update_meta_data( '_renew_subscrpt', $cart_item['renew_subscrpt'] );
		}

		if ( ! empty( $cart_item['wp_subs_switch'] ?? null ) && ! empty( $cart_item['switch_context'] ?? null ) ) {
			$switch_context = $cart_item['switch_context'];

			// Add switch context data to order item meta.
			$item->update_meta_data( '_wp_subs_switch', true, true );
			$item->update_meta_data( '_wp_subs_switch_context', $switch_context, true );
		}
	}

	/**
	 * Trigger subscription switch order created store API.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function trigger_subscription_switch_order_created_storeapi( $order ) {
		$this->trigger_subscription_switch_order_created( $order->get_id() ?? 0 );
	}

	/**
	 * Trigger subscription switch order created.
	 *
	 * @param int $order_id Order ID.
	 */
	public function trigger_subscription_switch_order_created( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order_items = $order->get_items();

		foreach ( $order_items as $order_item ) {
			$is_switch      = in_array( $order_item->get_meta( '_wp_subs_switch' ), array( true, 1, '1' ), true );
			$switch_context = $order_item->get_meta( '_wp_subs_switch_context' );

			if ( $is_switch ) {
				$switch_type = $switch_context['switch_type'] ?? 'upgrade';

				unset( $switch_context['nonce'] );
				unset( $switch_context['redirect_back_url'] );

				/**
				 * Action fired when subscription switch order is created.
				 *
				 * @param string    $switch_type    Switch type (upgrade/downgrade).
				 * @param \WC_Order $order          Order object.
				 * @param \WC_Order_Item_Product $order_item Order Item object.
				 * @param array     $switch_context Switch context data. [ 'switch_type', 'subscription_id', 'order_id', 'product_id', 'old_variation_id', 'new_variation_id' ]
				 */
				do_action( 'subscrpt_switch_order_created', $switch_type, $order, $order_item, $switch_context );
			}
		}
	}
}

<?php
/**
 * Automatic renewal lifecycle integration.
 *
 * @package SpringDevs\Subscription\Illuminate
 */

// PSR-4 class filename is retained for the public lifecycle compatibility path.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Illuminate;

/**
 * Class AutoRenewal
 *
 * @package SpringDevs\Subscription\Illuminate
 */
class AutoRenewal {

	/**
	 * Initialize the class
	 */
	public function __construct() {
		add_action( 'subscrpt_subscription_expired', array( $this, 'after_subscription_expired' ) );
		add_filter( 'subscrpt_renewal_item_meta', array( $this, 'filter_renewal_item_meta' ), 10, 4 );
		add_filter( 'subscrpt_renewal_product_args', array( $this, 'filter_renewal_product_args' ), 10, 4 );

		// Grace period hooks.
		add_action( 'subscrpt_subscription_expired', array( $this, 'maybe_trigger_grace_start_hook' ) );
		add_action( 'subscrpt_scheduled_grace_end', array( $this, 'trigger_grace_end_hook' ) );

		// Clear grace period on subscription re-activation.
		add_action( 'subscrpt_subscription_activated', array( $this, 'clear_grace_period_schedules' ) );
	}

	/**
	 * Filter renewal product args.
	 *
	 * @param array                  $product_args product args.
	 * @param \WC_Product            $product Product Object.
	 * @param \WC_Order_Item_Product $order_item      Order Item Object.
	 * @param int                    $subscription_id Subscription ID.
	 *
	 * @return array
	 */
	public function filter_renewal_product_args( $product_args, $product, $order_item, $subscription_id = 0 ) {
		$custom_price = $subscription_id ? get_post_meta( $subscription_id, '_subscrpt_custom_renewal_price', true ) : '';
		if ( 'updated' !== get_option( 'subscrpt_renewal_price', 'subscribed' ) && '' === $custom_price ) {
			return $product_args;
		}

		if ( ! $product ) {
			if ( ! is_admin() ) {
				wc_add_notice( __( 'Subscription early renewal order creation failed due to product deletion !', 'subscription' ), 'error' );
			}
			return false;
		}

		$product = $this->resolve_renewal_product( $product, (int) $subscription_id );
		if ( ! $product ) {
			if ( ! is_admin() ) {
				wc_add_notice( __( 'Subscription renewal order creation failed because the subscribed product or variation is no longer available.', 'subscription' ), 'error' );
			}
			return false;
		}

		$price = '' !== $custom_price
			? max( 0, (float) $custom_price )
			: (float) $product->get_price();

		$product_args = array(
			'name'     => $product->get_name(),
			'subtotal' => $price * $order_item->get_quantity(),
			'total'    => $price * $order_item->get_quantity(),
		);

		return $product_args;
	}

	/**
	 * Filter renewal item's meta.
	 *
	 * @param array                  $item_meta Item meta.
	 * @param \WC_Product            $product          Product Object.
	 * @param \WC_Order_Item_Product $order_item       Order Item Object.
	 * @param int                    $subscription_id Subscription ID.
	 *
	 * @return array
	 */
	public function filter_renewal_item_meta( $item_meta, $product, $order_item = null, $subscription_id = 0 ) {
		if ( 'updated' !== get_option( 'subscrpt_renewal_price', 'subscribed' ) || ! $product ) {
			return $item_meta;
		}

		$product = $this->resolve_renewal_product( $product, (int) $subscription_id );
		if ( ! $product ) {
			return $item_meta;
		}

		$timing_per    = $product->get_meta( '_subscrpt_timing_per' );
		$timing_option = $product->get_meta( '_subscrpt_timing_option' );

		return array(
			'time'  => empty( $timing_per ) ? 1 : $timing_per,
			'type'  => $timing_option,
			'trial' => null,
		);
	}

	/**
	 * Resolve the variation that was actually subscribed to.
	 *
	 * A variable parent is not a billable renewal line. The subscription snapshot
	 * therefore wins over a mutable product default, while the legacy product
	 * fallback remains available for records created before variation snapshots.
	 *
	 * @param \WC_Product|null $product          Source product.
	 * @param int              $subscription_id Subscription ID.
	 * @return \WC_Product|null
	 */
	private function resolve_renewal_product( $product, int $subscription_id = 0 ) {
		if ( $subscription_id ) {
			$variation_id = (int) get_post_meta( $subscription_id, '_subscrpt_variation_id', true );
			if ( $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					return $variation;
				}
			}
		}

		if ( $product && $product->is_type( 'variable' ) ) {
			$variation_id = (int) $product->get_meta( '_subscrpt_convertation_default_variation_for_renewal', true );
			$product      = $variation_id ? wc_get_product( $variation_id ) : false;
		}

		return $product ? $product : null;
	}

	/**
	 * After Expired Subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function after_subscription_expired( $subscription_id ) {
		// Check if maximum payment limit has been reached.
		if ( subscrpt_is_max_payments_reached( $subscription_id ) ) {
			subscrpt_finalize_split_payment_completion( $subscription_id );
			subscrpt_write_log( "Ashbi Subscriptions: Maximum payment limit reached for subscription #{$subscription_id}. Auto-renewal cancelled." );
			return;
		}

		if ( subscrpt_is_auto_renew_enabled() ) {
			// Change subscription status to pending if it was a trial subscription.
			$trial = get_post_meta( $subscription_id, '_subscrpt_trial', true );
			if ( ! empty( $trial ) ) {
				Action::status( 'pending', $subscription_id );
			}

			// Create renewal order.
			Helper::create_renewal_order( $subscription_id );
		}
	}

	/**
	 * Maybe run grace period hook.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function maybe_trigger_grace_start_hook( $subscription_id ) {
		$default_grace_period = get_option( 'subscrpt_default_payment_grace_period', '7' );
		if ( (int) $default_grace_period <= 0 ) {
			return;
		}

		$subscription_data  = Helper::get_subscription_data( $subscription_id );
		$next_datetime      = ! empty( $subscription_data['next_date'] ) ? strtotime( $subscription_data['next_date'] ) : 0;
		$grace_end_datetime = $next_datetime + ( (int) $default_grace_period * DAY_IN_SECONDS );

		// If grace period already ended.
		if ( time() >= $grace_end_datetime ) {
			return;
		}

		// Grace period started.
		do_action( 'subscrpt_grace_period_started', $subscription_id );

		subscrpt_write_log( "Subscription #{$subscription_id} grace period started." );

		// Set hook to run when grace period ends.
		$hook = 'subscrpt_scheduled_grace_end';
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_unschedule_action( $hook, array( 'subscription_id' => $subscription_id ) );
			as_schedule_single_action( $grace_end_datetime, $hook, array( 'subscription_id' => $subscription_id ), 'ashbi-subscriptions' );
		} else {
			wp_clear_scheduled_hook( $hook, array( $subscription_id ) );
			wp_schedule_single_event( $grace_end_datetime, $hook, array( $subscription_id ) );
		}
	}

	/**
	 * Trigger grace end hook.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function trigger_grace_end_hook( $subscription_id ) {
		subscrpt_write_log( "Subscription #{$subscription_id} grace period ended." );

		// Grace period ended.
		do_action( 'subscrpt_grace_period_ended', $subscription_id );
	}

	/**
	 * Clear grace period schedules.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function clear_grace_period_schedules( $subscription_id ) {
		$hook = 'subscrpt_scheduled_grace_end';
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( $hook, array( 'subscription_id' => $subscription_id ) );
		}
		wp_clear_scheduled_hook( $hook, array( $subscription_id ) );
	}
}

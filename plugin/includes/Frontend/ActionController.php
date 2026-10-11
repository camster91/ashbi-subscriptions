<?php
/**
 * Customer-facing subscription action controller.
 *
 * @package SpringDevs\Subscription\Frontend
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName -- Legacy class path is part of the public plugin compatibility contract.


namespace SpringDevs\Subscription\Frontend;

use SpringDevs\Subscription\Illuminate\Action;
use SpringDevs\Subscription\Illuminate\Helper;
use SpringDevs\Subscription\Illuminate\CancellationEvidence;
use SpringDevs\Subscription\Illuminate\Subscription\Subscription;

/**
 * Class ActionController
 *
 * @package SpringDevs\Subscription\Frontend
 */
class ActionController {

	/**
	 * Initialize the class
	 */
	public function __construct() {
		add_action( 'before_single_subscrpt_content', array( $this, 'control_action_subscrpt' ) );
	}

	/**
	 * Take Subscription Action.
	 */
	public function control_action_subscrpt() {
		if ( ! ( isset( $_GET['subscrpt_id'] ) && isset( $_GET['action'] ) && isset( $_GET['wpnonce'] ) ) ) {
			return;
		}

		$subscrpt_id = sanitize_text_field( wp_unslash( $_GET['subscrpt_id'] ) );
		$action      = sanitize_text_field( wp_unslash( $_GET['action'] ) );
		$wpnonce     = sanitize_text_field( wp_unslash( $_GET['wpnonce'] ) );
		$request_id = 'cancelled' === $action ? wp_generate_uuid4() : '';
		if ( 'cancelled' === $action ) {
			CancellationEvidence::record( 'request_received', 0, 0, $request_id );
		}

		// A guest-owned subscription has author ID 0. Never let an anonymous request
		// inherit that ownership merely because get_current_user_id() also returns 0.
		if ( ! is_user_logged_in() ) {
			if ( $request_id ) {
				CancellationEvidence::record( 'request_rejected', 0, 0, $request_id, array( 'code' => 'session_required' ) );
			}
			wc_add_notice( __( 'Please log in to manage this subscription.', 'subscription' ), 'error' );
			return wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		}

		// Nonce check.
		if ( ! wp_verify_nonce( $wpnonce, 'subscrpt_nonce' ) ) {
			if ( $request_id ) {
				CancellationEvidence::record( 'request_rejected', 0, 0, $request_id, array( 'code' => 'invalid_nonce' ) );
			}
			$error_notice = __( "You don't have permission to modify this subscription. If you believe this is an error, please contact support.", 'subscription' );
			wc_add_notice( $error_notice, 'error' );

			$view_subscription_endpoint = Subscription::get_user_endpoint( 'view_subs' );
			$redirect_url               = wc_get_endpoint_url( $view_subscription_endpoint, $subscrpt_id, wc_get_page_permalink( 'myaccount' ) );
			return wp_safe_redirect( $redirect_url );
		}

		// User check.
		$subs_post       = get_post( $subscrpt_id );
		if ( ! $subs_post || 'subscrpt_order' !== $subs_post->post_type ) {
			if ( $request_id ) {
				CancellationEvidence::record( 'request_rejected', 0, 0, $request_id, array( 'code' => 'invalid_subscription' ) );
			}
			wc_add_notice( __( "You don't have permission to modify this subscription. If you believe this is an error, please contact support.", 'subscription' ), 'error' );
			$view_subscription_endpoint = Subscription::get_user_endpoint( 'view_subs' );
			$redirect_url               = wc_get_endpoint_url( $view_subscription_endpoint, $subscrpt_id, wc_get_page_permalink( 'myaccount' ) );
			wp_safe_redirect( $redirect_url );
			exit;
		}
		$author_id       = $subs_post ? (int) $subs_post->post_author : 0;
		$current_user_id = get_current_user_id();
		$user_is_admin   = current_user_can( 'manage_options' );

		if ( ! $user_is_admin && ( 0 === (int) $author_id || (int) $author_id !== (int) $current_user_id ) ) {
			if ( $request_id ) {
				CancellationEvidence::record( 'request_rejected', 0, 0, $request_id, array( 'code' => 'ownership' ) );
			}
			$error_notice = __( "You don't have permission to modify this subscription. If you believe this is an error, please contact support.", 'subscription' );
			wc_add_notice( $error_notice, 'error' );

			$view_subscription_endpoint = Subscription::get_user_endpoint( 'view_subs' );
			$redirect_url               = wc_get_endpoint_url( $view_subscription_endpoint, $subscrpt_id, wc_get_page_permalink( 'myaccount' ) );
			return wp_safe_redirect( $redirect_url );
		}

		// Get view subscription endpoint slug.
		$view_subs_endpoint = Subscription::get_user_endpoint( 'view_subs' );

		// Check maximum payment limit for renewal-related actions (including early renewal).
		$renewal_actions = apply_filters( 'subscrpt_renewal_actions', array( 'renew', 'renew-on', 'early-renew' ) );
		if ( in_array( $action, $renewal_actions, true ) && subscrpt_is_max_payments_reached( $subscrpt_id ) ) {
			wc_add_notice( __( 'This subscription has reached its maximum payment limit and cannot be renewed further.', 'subscription' ), 'error' );
			wp_safe_redirect( wc_get_endpoint_url( $view_subs_endpoint, $subscrpt_id, wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}

		if ( 'renew' === $action && ! subscrpt_is_auto_renew_enabled() ) {
			$this->manual_renew_product( $subscrpt_id );
		} elseif ( 'cancelled' === $action ) {
			$result = CancellationEvidence::request( (int) $subscrpt_id, (int) $current_user_id, $request_id );
			$recorded = ! empty( $result['recorded'] );
			$message = $recorded && 'confirmed' === $result['state']
				? __( 'Cancellation confirmed. Future automatic billing is blocked. Any payment already sent for processing requires separate review.', 'subscription' )
				: ( $recorded && ! empty( $result['barrier'] ) ? __( 'Your cancellation request is recorded and local renewal billing is blocked. Confirmation is pending; any provider-managed billing or payment already in progress requires review.', 'subscription' ) : __( 'Cancellation could not be confirmed. Please retry from a fresh account session or contact support.', 'subscription' ) );
			wc_add_notice( $message, $recorded && ! empty( $result['barrier'] ) ? 'success' : 'error' );
		} elseif ( 'reactivate' === $action ) {
			if ( ! self::can_reactivate_subscription( (int) $subscrpt_id ) ) {
				wc_add_notice( __( 'This subscription can no longer be reactivated without completing a renewal payment.', 'subscription' ), 'error' );
			} else {
				Action::status( 'active', $subscrpt_id );
			}
		} elseif ( 'renew-on' === $action ) {
			update_post_meta( $subscrpt_id, '_subscrpt_auto_renew', 1 );
		} elseif ( 'renew-off' === $action ) {
			update_post_meta( $subscrpt_id, '_subscrpt_auto_renew', 0 );
		} elseif ( 'early-renew' === $action ) {
			$early_order = Helper::create_early_renewal_order( (int) $subscrpt_id );
			if ( ! $early_order ) {
				wc_add_notice( __( 'This subscription cannot be renewed early right now.', 'subscription' ), 'error' );
			} elseif ( $early_order instanceof \WC_Order && $early_order->needs_payment() ) {
				$this->redirect( $early_order->get_checkout_payment_url() );
			}
		} elseif ( 'pause' === $action ) {
			if ( ! Helper::pause_subscription( (int) $subscrpt_id ) ) {
				wc_add_notice( __( 'Only active subscriptions can be paused.', 'subscription' ), 'error' );
			}
		} elseif ( 'resume' === $action ) {
			if ( ! Helper::resume_subscription( (int) $subscrpt_id ) ) {
				wc_add_notice( __( 'This subscription cannot be resumed until its payment issue is resolved.', 'subscription' ), 'error' );
			}
		} elseif ( 'renew' === $action && subscrpt_is_auto_renew_enabled() ) {
			Helper::create_renewal_order( $subscrpt_id );
		} else {
			// Safety check: If this is any kind of renewal action and limit is reached, block it.
			if ( subscrpt_is_max_payments_reached( $subscrpt_id ) &&
				( strpos( $action, 'renew' ) !== false || strpos( $action, 'renewal' ) !== false ) ) {
				wc_add_notice( __( 'This subscription has reached its maximum payment limit and cannot be renewed further.', 'subscription' ), 'error' );
				wp_safe_redirect( wc_get_endpoint_url( $view_subs_endpoint, $subscrpt_id, wc_get_page_permalink( 'myaccount' ) ) );
				exit;
			}

			do_action( 'subscrpt_execute_actions', $subscrpt_id, $action );
		}
		wp_safe_redirect( wc_get_endpoint_url( $view_subs_endpoint, $subscrpt_id, wc_get_page_permalink( 'myaccount' ) ) );
		exit;
	}

	/**
	 * Decide whether a customer may undo a pending cancellation without payment.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $now Current timestamp override for tests.
	 * @return bool
	 */
	public static function can_reactivate_subscription( int $subscription_id, int $now = 0 ): bool {
		if ( 'pe_cancelled' !== get_post_status( $subscription_id ) || subscrpt_is_max_payments_reached( $subscription_id ) ) {
			return false;
		}

		$cancel_at = (int) get_post_meta( $subscription_id, '_subscrpt_cancel_at', true );
		if ( ! $cancel_at ) {
			$cancel_at = (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
		}

		$comparison_time = $now > 0 ? $now : time();

		return $cancel_at > $comparison_time;
	}

	/**
	 * Manually Renew Subscription.
	 *
	 * @param Int $subscrpt_id Subscription ID.
	 */
	public function manual_renew_product( $subscrpt_id ) {
		$product_id                = get_post_meta( $subscrpt_id, '_subscrpt_product_id', true );
		$subscription_variation_id = get_post_meta( $subscrpt_id, '_subscrpt_variation_id', true );
		$plan_id                   = (int) get_post_meta( $subscrpt_id, '_subscrpt_plan_id', true );

		$variation_id = ! empty( $subscription_variation_id ) ? (int) $subscription_variation_id : 0;

		WC()->cart->empty_cart();

		$cart_item_data = array( 'renew_subscrpt' => (int) $subscrpt_id );
		if ( $plan_id > 0 ) {
			$cart_item_data['subscrpt_plan_id'] = $plan_id;
		}

		WC()->cart->add_to_cart(
			$product_id,
			1,
			$variation_id,
			array(),
			$cart_item_data
		);

		// Empty unless the store set one, and wc_add_notice( '' ) renders an empty.
		// green box rather than nothing, so only add it when there is a message.
		$cart_notice = subscrpt_get_manual_renew_cart_notice();
		if ( '' !== $cart_notice ) {
			wc_add_notice( $cart_notice, 'success' );
		}
		$this->redirect( wc_get_cart_url() );
	}

	/**
	 * Redirect on URL.
	 *
	 * @param String $url URL.
	 */
	public function redirect( $url ) {
		wp_safe_redirect( esc_url( $url ) );
		exit;
	}
}

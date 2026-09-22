<?php
/**
 * Customer payment-method changes for subscription renewals.
 *
 * The controller only handles saved WooCommerce payment tokens. It never
 * accepts card data and it deliberately leaves remote gateway authorization to
 * the gateway that created the token.
 *
 * @package SpringDevs\Subscription\Frontend
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName -- Legacy class filenames are part of the public plugin compatibility contract.

namespace SpringDevs\Subscription\Frontend;

use SpringDevs\Subscription\Illuminate\Helper;
use SpringDevs\Subscription\Illuminate\Subscription\Subscription;

/**
 * Customer-facing saved payment-method controller.
 */
class PaymentMethodController {
	/**
	 * Gateway identifiers whose saved token can be copied to a Stripe renewal.
	 *
	 * PayPal subscriptions use a remote billing agreement rather than a
	 * WooCommerce payment token, so they are intentionally not included here.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_GATEWAY_IDS = array(
		'stripe',
		'stripe_ideal',
		'stripe_sepa',
		'sepa_debit',
		'stripe_bancontact',
	);

	/**
	 * Initialize the controller.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'handle_submission' ), 20 );
	}

	/**
	 * Get the saved-token context for a customer-owned subscription.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $user_id         Authenticated customer ID.
	 * @return array|null
	 */
	public static function get_subscription_context( int $subscription_id, int $user_id ): ?array {
		if ( $subscription_id <= 0 || $user_id <= 0 ) {
			return null;
		}

		$subscription = get_post( $subscription_id );
		if (
			! $subscription
			|| 'subscrpt_order' !== $subscription->post_type
			|| (int) $subscription->post_author !== $user_id
		) {
			return null;
		}

		$subscription_data = Helper::get_subscription_data( $subscription_id );
		$order_id          = (int) ( $subscription_data['order']['order_id'] ?? 0 );
		$order             = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		// A customer-owned subscription must not point at another customer's order.
		if ( $order->get_customer_id() && (int) $order->get_customer_id() !== $user_id ) {
			return null;
		}

		if ( ! self::is_supported_gateway( $order->get_payment_method() ) ) {
			return null;
		}

		if ( ! in_array( get_post_status( $subscription_id ), array( 'pending', 'active', 'on_hold', 'pe_cancelled' ), true ) ) {
			return null;
		}

		$tokens        = array();
		$current_token = (string) $order->get_meta( '_stripe_source_id' );
		foreach ( self::get_customer_tokens( $user_id ) as $token ) {
			if ( ! $token instanceof \WC_Payment_Token || ! self::is_supported_gateway( $token->get_gateway_id() ) ) {
				continue;
			}

			$token_value = (string) $token->get_token();
			$tokens[]    = array(
				'id'         => (int) $token->get_id(),
				'label'      => self::get_token_label( $token ),
				'is_current' => '' !== $current_token && hash_equals( $current_token, $token_value ),
			);
		}

		return array(
			'order'        => $order,
			'tokens'       => $tokens,
			'manage_url'   => wc_get_endpoint_url( 'payment-methods', '', wc_get_page_permalink( 'myaccount' ) ),
			'nonce_action' => 'subscrpt_payment_method_' . $subscription_id,
		);
	}

	/**
	 * Change the saved token used by a customer-owned Stripe subscription.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $token_id        WooCommerce payment-token ID.
	 * @param int $user_id         Authenticated customer ID.
	 * @return true|\WP_Error
	 */
	public static function change_subscription_payment_method( int $subscription_id, int $token_id, int $user_id ) {
		$context = self::get_subscription_context( $subscription_id, $user_id );
		if ( ! $context ) {
			return new \WP_Error( 'subscrpt_payment_method_unavailable', __( 'This subscription cannot change its payment method.', 'subscription' ) );
		}

		$token = class_exists( 'WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get( $token_id ) : null;
		if (
			! $token instanceof \WC_Payment_Token
			|| (int) $token->get_user_id() !== $user_id
			|| ! self::is_supported_gateway( $token->get_gateway_id() )
		) {
			return new \WP_Error( 'subscrpt_payment_method_invalid', __( 'That saved payment method is not available for this account.', 'subscription' ) );
		}

		$token_value = (string) $token->get_token();
		if ( ! preg_match( '/\A(?:pm|src|tok)_[A-Za-z0-9]+\z/', $token_value ) ) {
			return new \WP_Error( 'subscrpt_payment_method_invalid', __( 'That saved payment method could not be validated.', 'subscription' ) );
		}

		$order        = $context['order'];
		$order_source = (string) $order->get_meta( '_stripe_customer_id' );
		$user_source  = (string) get_user_option( '_stripe_customer_id', $user_id );
		if ( '' !== $order_source && '' !== $user_source && ! hash_equals( $order_source, $user_source ) ) {
			return new \WP_Error( 'subscrpt_payment_method_customer_mismatch', __( 'The saved payment method belongs to a different payment account.', 'subscription' ) );
		}

		if ( '' === $order_source && '' !== $user_source ) {
			$order->update_meta_data( '_stripe_customer_id', $user_source );
		}
		$current_source = (string) $order->get_meta( '_stripe_source_id' );
		if ( '' !== $current_source && hash_equals( $current_source, $token_value ) ) {
			return true;
		}
		$order->update_meta_data( '_stripe_source_id', $token_value );
		$order->set_payment_method_title( self::get_token_label( $token ) );
		$order->add_order_note( __( 'Customer updated the saved payment method for this subscription.', 'subscription' ) );
		$order->save();

		/**
		 * Fires after the canonical order points at the customer's selected token.
		 *
		 * @param int              $subscription_id Subscription post ID.
		 * @param int              $order_id        Canonical order ID.
		 * @param int              $token_id        WooCommerce token ID.
		 * @param string           $gateway_id      Token gateway identifier.
		 */
		do_action( 'subscrpt_payment_method_changed', $subscription_id, (int) $order->get_id(), $token_id, (string) $token->get_gateway_id() );

		return true;
	}

	/**
	 * Handle the customer account form before the endpoint renders.
	 *
	 * @return void
	 */
	public function handle_submission() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified immediately below before any mutation.
		if ( 'POST' !== strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || empty( $_POST['subscrpt_payment_method_submit'] ) ) {
			return;
		}

		$subscription_id = isset( $_POST['subscrpt_id'] ) ? absint( wp_unslash( $_POST['subscrpt_id'] ) ) : 0;
		$redirect_url    = self::get_redirect_url( $subscription_id );
		$nonce           = isset( $_POST['subscrpt_payment_method_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['subscrpt_payment_method_nonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'subscrpt_payment_method_' . $subscription_id ) ) {
			wc_add_notice( __( 'Your payment-method update could not be verified.', 'subscription' ), 'error' );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		$token_id = isset( $_POST['subscrpt_payment_token'] ) ? absint( wp_unslash( $_POST['subscrpt_payment_token'] ) ) : 0;
		$result   = self::change_subscription_payment_method( $subscription_id, $token_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		} else {
			wc_add_notice( __( 'Your subscription payment method was updated.', 'subscription' ), 'success' );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Fetch payment tokens without exposing raw token data to the template.
	 *
	 * @param int $user_id Customer ID.
	 * @return \WC_Payment_Token[]
	 */
	private static function get_customer_tokens( int $user_id ): array {
		if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
			return array();
		}

		$tokens = array();
		foreach ( self::get_supported_gateway_ids() as $gateway_id ) {
			$gateway_tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );
			if ( is_array( $gateway_tokens ) ) {
				$tokens = array_merge( $tokens, $gateway_tokens );
			}
		}

		return $tokens;
	}

	/**
	 * Format a safe customer-facing token label.
	 *
	 * @param \WC_Payment_Token $token Saved token.
	 * @return string
	 */
	private static function get_token_label( \WC_Payment_Token $token ): string {
		$label = method_exists( $token, 'get_display_name' ) ? (string) $token->get_display_name() : __( 'Saved payment method', 'subscription' );
		$last4 = method_exists( $token, 'get_last4' ) ? (string) $token->get_last4() : '';
		if ( '' !== $last4 && false === strpos( $label, $last4 ) ) {
			// translators: %s: Last four digits of the saved payment method.
			$label .= ' ' . sprintf( __( 'ending in %s', 'subscription' ), $last4 );
		}

		return '' !== $label ? $label : __( 'Saved payment method', 'subscription' );
	}

	/**
	 * Determine whether a gateway stores a token usable by the Stripe renewal path.
	 *
	 * @param string $gateway_id Gateway identifier.
	 * @return bool
	 */
	private static function is_supported_gateway( string $gateway_id ): bool {
		return in_array( $gateway_id, self::get_supported_gateway_ids(), true );
	}

	/**
	 * Get the gateway identifiers allowed for saved-token replacement.
	 *
	 * @return string[]
	 */
	private static function get_supported_gateway_ids(): array {
		$gateway_ids = apply_filters( 'subscrpt_customer_payment_method_gateway_ids', self::SUPPORTED_GATEWAY_IDS );
		$gateway_ids = is_array( $gateway_ids ) ? $gateway_ids : self::SUPPORTED_GATEWAY_IDS;

		return array_values( array_unique( array_map( 'sanitize_key', $gateway_ids ) ) );
	}

	/**
	 * Build the canonical account redirect.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @return string
	 */
	private static function get_redirect_url( int $subscription_id ): string {
		$endpoint = Subscription::get_user_endpoint( 'view_subs' );
		return wc_get_endpoint_url( $endpoint, $subscription_id, wc_get_page_permalink( 'myaccount' ) );
	}
}

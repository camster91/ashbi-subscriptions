<?php
/**
 * Service container bootstrap.
 *
 * @package SpringDevs\Subscription
 */
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription;

use SpringDevs\Subscription\Frontend\Checkout;
use SpringDevs\Subscription\Frontend\PlanCheckout;
use SpringDevs\Subscription\Illuminate\AutoRenewal;
use SpringDevs\Subscription\Illuminate\Cancellation;
use SpringDevs\Subscription\Illuminate\Cron;
use SpringDevs\Subscription\Illuminate\Email;
use SpringDevs\Subscription\Illuminate\Order;
use SpringDevs\Subscription\Illuminate\Post;
use SpringDevs\Subscription\Illuminate\Stats;
use SpringDevs\Subscription\Illuminate\Switching;
use SpringDevs\Subscription\Illuminate\Gateways\Stripe\Stripe;
use SpringDevs\Subscription\Illuminate\GuestCheckout;
use SpringDevs\Subscription\Illuminate\RoleManagement;
use SpringDevs\Subscription\Illuminate\Subscription\Subscription;

/**
 * Globally Load Scripts.
 */
class Illuminate {

	/**
	 * Whether the optional Stripe renewal adapter has been initialized.
	 *
	 * @var bool
	 */
	private $stripe_initialized = false;

	/**
	 * Whether Stripe initialization was deferred until WordPress init.
	 *
	 * @var bool
	 */
	private $stripe_retry_registered = false;

	/**
	 * Initialize the Class.
	 */
	public function __construct() {
		$this->stripe_initialization();
		$this->paypal_initialization();

		new Subscription();
		new RoleManagement();
		new Order();
		new Cron();
		new Cancellation();
		\SpringDevs\Subscription\Illuminate\CancellationEvidence::register_hooks();
		new \SpringDevs\Subscription\Illuminate\EvidenceExport();
		new Stats();
		new Post();
		new Checkout();
		// Ashbi owns the plan checkout path, including advanced plan types and.
		// variation-specific terms. The legacy paid plugin must never be required.
		// for a subscription created by this plugin.
		new PlanCheckout();
		new GuestCheckout();
		new AutoRenewal();
		new Switching();
		new Email();

		// Hide the internal plan snapshot meta from the admin order-item screen.
		// WooCommerce's admin item view lists every meta key not in this filter.
		// (it does not hide the leading-underscore prefix there). Registered here.
		// for every Ashbi request so these internal keys stay out of the screen.
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_plan_order_itemmeta' ) );
	}

	/**
	 * Hide the plan snapshot meta keys on the admin order-item screen.
	 *
	 * @param array $keys Hidden order-item meta keys.
	 *
	 * @return array
	 */
	public function hidden_plan_order_itemmeta( $keys ) {
		return array_merge(
			(array) $keys,
			array(
				'_subscrpt_plan_id',
				'_subscrpt_plan_group_id',
				'_subscrpt_plan_price',
				'_subscrpt_plan_payment_type',
				'_subscrpt_plan_max_no_payment',
				'_subscrpt_plan_terms',
				'_subscrpt_plan_total',
				'_subscrpt_payment_type',
				'_subscrpt_max_no_payment',
				'_subscrpt_signup_fee',
				'_subscrpt_billing_length',
				'_subscrpt_plan_data',
				'_subscrpt_variation_id',
			)
		);
	}

	/**
	 * Stripe Initialization.
	 *
	 * @return void
	 */
	public function stripe_initialization() {
		if ( $this->stripe_initialized ) {
			return;
		}

		// A plugin can be loaded before WooCommerce Stripe in the active-plugin.
		// order. Defer once until all plugins have finished loading instead of.
		// autoloading our adapter against an incomplete Stripe runtime.
		if ( ! function_exists( 'woocommerce_gateway_stripe' ) || ! defined( 'WC_STRIPE_MAIN_FILE' ) ) {
			if ( ! $this->stripe_retry_registered ) {
				$this->stripe_retry_registered = true;
				add_action( 'init', array( $this, 'stripe_initialization' ), 5 );
			}

			return;
		}

		$stripe_directory = dirname( WC_STRIPE_MAIN_FILE );
		$exception_file   = $stripe_directory . '/includes/class-wc-stripe-exception.php';
		if ( ! class_exists( 'WC_Stripe_Exception' ) ) {
			if ( ! is_readable( $exception_file ) ) {
				subscrpt_write_log( 'Stripe integration skipped because its exception compatibility class is unavailable.' );
				return;
			}
			include_once $exception_file;
		}

		if ( ! class_exists( 'WC_Payment_Gateway_CC' ) ) {
			include_once dirname( WC_PLUGIN_FILE ) . '/includes/gateways/class-wc-payment-gateway-cc.php';
		}

		include_once $stripe_directory . '/includes/compat/trait-wc-stripe-subscriptions-utilities.php';
		include_once $stripe_directory . '/includes/compat/trait-wc-stripe-pre-orders.php';
		include_once $stripe_directory . '/includes/compat/trait-wc-stripe-subscriptions.php';
		include_once $stripe_directory . '/includes/abstracts/abstract-wc-stripe-payment-gateway.php';

		if ( class_exists( '\WC_Stripe_Payment_Gateway' ) ) {
			new Stripe();
			$this->stripe_initialized = true;
		}
	}

	/**
	 * PayPal Gateway Initialization.
	 *
	 * @return void
	 */
	public function paypal_initialization() {
		$is_paypal_integration_enabled = 'on' === get_option( 'wp_subs_paypal_integration_enabled', 'off' );

		// Register the PayPal gateway with WooCommerce.
		if ( $is_paypal_integration_enabled ) {
			add_filter( 'woocommerce_payment_gateways', array( $this, 'register_paypal_gateway' ) );
		}
	}

	/**
	 * Register our custom PayPal gateway with WooCommerce
	 *
	 * @param array $gateways Payment gateways.
	 * @return array
	 */
	public function register_paypal_gateway( $gateways ) {
		$gateways[] = 'SpringDevs\\Subscription\\Illuminate\\Gateways\\Paypal\\Paypal';
		return $gateways;
	}
}

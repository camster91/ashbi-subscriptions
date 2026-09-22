<?php
/**
 * PayPal gateway integration for subscription payments and reconciliation.
 *
 * @package SpringDevs\Subscription\Illuminate\Gateways
 */

// PSR-4 class filename is retained for the public gateway compatibility path.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Illuminate\Gateways\Paypal;

use Exception;
use SpringDevs\Subscription\Illuminate\Action;
use SpringDevs\Subscription\Illuminate\Helper;
use SpringDevs\Subscription\Illuminate\Subscription\Subscription;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Class PayPal
 * PayPal Payment Gateway for Subscription Plugin
 *
 * @package SpringDevs\Subscription\Illuminate\Gateways
 */
class Paypal extends \WC_Payment_Gateway {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Sandbox mode.
	 *
	 * @var bool
	 */
	public $sandbox_mode = false;

	/**
	 * PayPal Client ID.
	 *
	 * @var string
	 */
	protected $client_id;

	/**
	 * PayPal Client Secret.
	 *
	 * @var string
	 */
	protected $client_secret;

	/**
	 * PayPal Webhook ID.
	 *
	 * @var string
	 */
	protected $webhook_id;

	/**
	 * API endpoint for PayPal.
	 *
	 * @var string
	 */
	protected $api_endpoint;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id                 = 'wp_subscription_paypal';
		$this->has_fields         = false;
		$this->method_title       = __( 'PayPal for Ashbi Subscriptions', 'subscription' );
		$this->method_description = __( 'Accept recurring subscription payments through PayPal.', 'subscription' );
		$this->supports           = array( 'products', 'subscriptions', 'refunds' );
		// Do not distribute or reference a provider logo. Integrators may still.
		// supply their own licensed artwork through the legacy filter.
		$this->icon = apply_filters( 'wp_subscription_paypal_icon', '' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Plugin variables.
		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// PayPal Credentials.
		$this->sandbox_mode = 'yes' === $this->get_option( 'testmode', 'no' );

		if ( $this->sandbox_mode ) {
			$this->client_id     = $this->get_option( 'sandbox_client_id' );
			$this->client_secret = $this->get_option( 'sandbox_client_secret' );
			$this->webhook_id    = $this->get_option( 'sandbox_webhook_id' );
		} else {
			$this->client_id     = $this->get_option( 'client_id' );
			$this->client_secret = $this->get_option( 'client_secret' );
			$this->webhook_id    = $this->get_option( 'webhook_id' );
		}

		// Set Webhook URL.
		$this->update_option( 'webhook_url', $this->get_webhook_url() );

		// Set API endpoint.
		$this->api_endpoint = $this->sandbox_mode ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

		// Store first instance as the singleton (WooCommerce creates it; blocks integration reuses it).
		if ( null === self::$instance ) {
			self::$instance = $this;
		}

		// Ensure PayPal mapping table exists (create if missing).
		// This handles cases where plugin activation hook may have been skipped.
		try {
			PaypalDB::maybe_create_tables();
		} catch ( \Throwable $e ) {
			subscrpt_write_debug_log( 'PayPal DB ensure failed: ' . $e->getMessage() );
		}

		// Actions.
		$this->init_actions();
	}

	/**
	 * Get the singleton gateway instance.
	 *
	 * Returns the WooCommerce-managed instance when available. Falls back to
	 * creating a new instance only if the gateway has not been loaded yet.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize actions for the gateway.
	 */
	protected function init_actions() {
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Process order after payment.
		add_action( 'woocommerce_thankyou', array( $this, 'order_received_page' ) );

		// Hide gateway if no wp_subscription products are available.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'remove_wp_subs_paypal_gateway' ) );

		// WooCommerce webhook.
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'process_webhook' ) );

		// Cancel subscription.
		add_action( 'subscrpt_subscription_expired', array( $this, 'handle_subscription_cancellation' ) );
		add_action( 'subscrpt_subscription_cancelled', array( $this, 'handle_subscription_cancellation' ) );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		// Gateway settings styles.
		wp_enqueue_style( 'wp-subscription-gateway-settings', SUBSCRPT_ASSETS . '/css/gateway.css', array(), SUBSCRPT_VERSION, 'all' );

		// Settings JS.
		wp_enqueue_script( 'wp-subscription-gateway-settings-script', SUBSCRPT_ASSETS . '/js/gateway.js', array( 'jquery' ), SUBSCRPT_VERSION, true );

		// Live/Sandbox toggle script.
		wp_enqueue_script( 'wp-subscription-gateway-settings-toggle-script', SUBSCRPT_ASSETS . '/js/gateway_options_toggler.js', array( 'jquery' ), SUBSCRPT_VERSION, true );

		$this->form_fields = array(
			'enabled'                    => array(
				'title'       => __( 'Enable/Disable', 'subscription' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable PayPal for Ashbi Subscriptions', 'subscription' ),
				'default'     => 'no',
				'description' => __( 'Enable or disable the PayPal subscription payment gateway.', 'subscription' ),
				'desc_tip'    => true,
				'class'       => 'wpsubs-toggle',
			),
			'testmode'                   => array(
				'title'       => __( 'Test Mode', 'subscription' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable PayPal Sandbox', 'subscription' ),
				'default'     => 'no',
				'description' => __( 'PayPal sandbox can be used to test payments without using real money.', 'subscription' ),
				'desc_tip'    => true,
				'class'       => 'wpsubs-toggle',
			),

			'title'                      => array(
				'title'       => __( 'Title', 'subscription' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'subscription' ),
				'default'     => __( 'PayPal', 'subscription' ),
				'desc_tip'    => true,
			),
			'description'                => array(
				'title'       => __( 'Description', 'subscription' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'subscription' ),
				'default'     => __( 'Pay via PayPal; you can pay with your credit card if you do not have a PayPal account.', 'subscription' ),
				'desc_tip'    => true,
				'css'         => 'width: 400px; height: 75px;',
			),

			'paypal_creds_title'         => array(
				'title'       => __( 'PayPal Credentials', 'subscription' ),
				'type'        => 'title',
				'description' => '',
				'class'       => 'wpsubs-paypal-live-creds',
			),
			'paypal_sandbox_creds_title' => array(
				'title'       => __( 'PayPal Sandbox Credentials', 'subscription' ),
				'type'        => 'title',
				'description' => '',
				'class'       => 'wpsubs-paypal-sandbox-creds',
			),

			'paypal_creds_desc'          => array(
				'title'       => '',
				'type'        => 'title',
				'description' => sprintf(
					// Translators: %1$s is the link to PayPal developer account, %2$s is the link to My Apps & Credentials.
					__( 'Create a <a href="%1$s" target="_blank">PayPal developer account</a>, go to <a href="%2$s" target="_blank">My Apps & Credentials</a>, select the toggle ( Sandbox or Live ), create an app, and copy <b>Client ID</b> and <b>Secret</b>.', 'subscription' ),
					'https://developer.paypal.com',
					'https://developer.paypal.com/dashboard/applications'
				),
			),
			'email'                      => array(
				'title'       => __( 'Email', 'subscription' ),
				'type'        => 'email',
				'description' => __( 'PayPal Email Address (used to receive payments)', 'subscription' ),
				'default'     => '',
				'desc_tip'    => true,
			),

			// Live Credentials.
			'client_id'                  => array(
				'title'       => __( 'Client ID', 'subscription' ),
				'type'        => 'password',
				'description' => __( 'Enter your PayPal Client ID copied from PayPal Apps & Credentials.', 'subscription' ),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'wpsubs-paypal-live-creds',
			),
			'client_secret'              => array(
				'title'       => __( 'Secret', 'subscription' ),
				'type'        => 'password',
				'description' => __( 'Enter your PayPal Secret copied from PayPal Apps & Credentials.', 'subscription' ),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'wpsubs-paypal-live-creds',
			),
			'webhook_id'                 => array(
				'title'       => __( 'Webhook ID', 'subscription' ),
				'type'        => 'password',
				'description' => __( 'Enter your Webhook ID copied from PayPal Apps & Credentials for webhook validation.', 'subscription' ),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'wpsubs-paypal-live-creds',
			),

			// Sandbox Credentials.
			'sandbox_client_id'          => array(
				'title'       => __( 'Client ID', 'subscription' ),
				'type'        => 'password',
				'description' => __( 'Enter your PayPal Client ID copied from PayPal Apps & Credentials.', 'subscription' ),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'wpsubs-paypal-sandbox-creds',
			),
			'sandbox_client_secret'      => array(
				'title'       => __( 'Secret', 'subscription' ),
				'type'        => 'password',
				'description' => __( 'Enter your PayPal Secret copied from PayPal Apps & Credentials.', 'subscription' ),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'wpsubs-paypal-sandbox-creds',
			),
			'sandbox_webhook_id'         => array(
				'title'       => __( 'Webhook ID', 'subscription' ),
				'type'        => 'password',
				'description' => __( 'Enter your Webhook ID copied from PayPal Apps & Credentials for webhook validation.', 'subscription' ),
				'default'     => '',
				'desc_tip'    => true,
				'class'       => 'wpsubs-paypal-sandbox-creds',
			),

			'webhook_url'                => array(
				'title'       => __( 'Webhook URL', 'subscription' ),
				'type'        => 'text',
				'description' => __( '<p>In the <strong style="color:#1d4ed8">Apps & Credentials</strong> page of PayPal developer account open the newly created application and click <strong style="color:#1d4ed8">Add Webhook</strong> button.<br> On the <strong>Webhook URL</strong> field use this webhook link', 'subscription' ),
				'default'     => $this->get_webhook_url(),
				'disabled'    => true,
				'class'       => 'wpsubs-webhook-url',
			),
		);
	}

	/**
	 * Check if paypal can be used for the currency selected in the store.
	 *
	 * @return boolean
	 */
	public function is_currency_supported() {
		return in_array(
			get_woocommerce_currency(),
			apply_filters(
				'wp_subs_paypal_supported_currencies',
				array( 'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RUB', 'INR' )
			),
			true
		);
	}

	/**
	 * Show admin options is valid for use.
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		if ( $this->is_currency_supported() ) {
			parent::admin_options();
		} else {
			$currency_not_supported_message = sprintf(
				// Translators: %s is the title of the payment gateway.
				__( '<strong>%s</strong> options are disabled. PayPal Standard does not support your store currency.', 'subscription' ),
				$this->title
			);

			?>
			<div class="inline error">
				<p>
					<?php echo wp_kses_post( $currency_not_supported_message ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Get webhook URL for PayPal.
	 */
	public function get_webhook_url(): string {
		return add_query_arg( 'wc-api', $this->id, trailingslashit( get_home_url() ) );
	}

	/**
	 * Process order after payment received.
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_received_page( $order_id ) {
		if ( ! is_order_received_page() || empty( $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		// Return if order is not valid.
		if ( ! $order ) {
			return;
		}
		// Return if the order is not using WPSUBS PayPal.
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		// Return if the order is already completed.
		if ( 'completed' === $order->get_status() ) {
			// Translators: %d is the order ID.
			$log_message = sprintf( __( 'Order %d was already completed. Skipping PayPal check.', 'subscription' ), $order_id );
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// ? We are checking $_GET parameters directly from PayPal redirect, nonce is not applicable here.
		$returned_subscription_id = isset( $_GET['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_GET['subscription_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$paypal_payment_approved = false;
		$paypal_subscription_id  = $order->get_meta( $this->get_meta_key( 'subscription_id' ), true );

		// OLD key migration.
		// If no data check if the data exists with the old key. And update if necessary.
		// ? Dev note: Remove after JAN 1, 2026.
		if ( empty( $paypal_subscription_id ) ) {
			$paypal_subscription_id = $order->get_meta( '_wp_subs_paypal_subscription_id', true );

			if ( ! empty( $paypal_subscription_id ) ) {
				$order->update_meta_data( $this->get_meta_key( 'subscription_id' ), $paypal_subscription_id );
				$order->save();
			}
		}

		// The PayPal approval must be for the exact subscription created for this.
		// WooCommerce order. Never let a return-query identifier select a different.
		// PayPal object and thereby serve as payment proof for the current order.
		if (
			empty( $paypal_subscription_id )
			|| ( ! empty( $returned_subscription_id ) && ! hash_equals( (string) $paypal_subscription_id, (string) $returned_subscription_id ) )
		) {
			subscrpt_write_log( "PayPal return did not match the subscription stored for order #{$order_id}." );
			return;
		}

		$paypal_subscription_data = $this->get_paypal_subscription( $paypal_subscription_id );

		if ( $paypal_subscription_data && 'ACTIVE' === ( $paypal_subscription_data->status ?? '' ) ) {
			$paypal_payment_approved = true;
		}

		if ( $paypal_payment_approved ) {
			$order->update_status( 'completed', __( 'PayPal payment completed successfully.', 'subscription' ) );
			$order->save();

			// Pre-populate mapping table so the first webhook can resolve without the slow order-meta fallback query.
			$subscriptions = Helper::get_subscriptions_from_order( $order_id );
			$subscription  = ! empty( $subscriptions ) ? reset( $subscriptions ) : null;

			if ( ! $subscription ) {
				foreach ( $order->get_items() as $item ) {
					$tmp = Helper::get_subscription_from_order_item_id( $item->get_id() );
					if ( ! empty( $tmp ) ) {
						$subscription = $tmp;
						break;
					}
				}
			}

			if ( $subscription ) {
				PaypalDB::upsert_mapping(
					$paypal_subscription_id,
					(int) $subscription->subscription_id,
					(int) $order_id
				);
			}
		}
	}

	/**
	 * Remove PayPal gateway if no wp_subscription products are in checkout.
	 *
	 * @param array $available_gateways Available gateways.
	 */
	public function remove_wp_subs_paypal_gateway( $available_gateways ) {
		if ( ! is_checkout() || ! is_array( $available_gateways ) || empty( $available_gateways ) ) {
			return $available_gateways;
		}

		$has_subs_in_cart = false;
		$cart_items       = WC()->cart->cart_contents;
		foreach ( $cart_items as $cart_item ) {
			if (
				isset( $cart_item['subscription'] ) ||
				$cart_item['data']->get_meta( '_subscrpt_enabled' )
			) {
				$has_subs_in_cart = true;
				break;
			}
		}

		if ( ! $has_subs_in_cart && isset( $available_gateways[ $this->id ] ) ) {
			unset( $available_gateways[ $this->id ] );
		}

		return $available_gateways;
	}

	/**
	 * Process webhook from PayPal.
	 */
	public function process_webhook() {
		// Get raw webhook data.
		$raw_body = file_get_contents( 'php://input' );
		$headers  = function_exists( 'getallheaders' ) ? getallheaders() : array();

		if ( empty( $raw_body ) ) {
			subscrpt_write_log( 'PayPal webhook data is empty.' );
			subscrpt_write_debug_log( 'PayPal - process_webhook EMPTY' );
			wp_die( 'PayPal webhook data is empty.', '400 Bad Request', array( 'response' => 400 ) );
		}

		// Verify webhook.
		$this->verify_webhook( $headers, $raw_body );

		// Decode webhook data.
		$webhook_data = json_decode( $raw_body, true );
		if ( ! is_array( $webhook_data ) ) {
			$log_message = __( 'PayPal webhook body is not valid JSON.', 'subscription' );
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context_from_raw_body( $raw_body ) );
			wp_die( esc_html( $log_message ), '400 Bad Request', array( 'response' => 400 ) );
		}
		$event_id = sanitize_text_field( (string) ( $webhook_data['id'] ?? '' ) );
		if ( '' === $event_id ) {
			$log_message = __( 'PayPal webhook event ID is missing.', 'subscription' );
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context( $webhook_data ) );
			wp_die( esc_html( $log_message ), '400 Bad Request', array( 'response' => 400 ) );
		}

		// Get event type from webhook data.
		$event = isset( $webhook_data['event_type'] ) ? sanitize_text_field( $webhook_data['event_type'] ) : '';

		// Supported transaction events.
		$transaction_events = array(
			'PAYMENT.SALE.COMPLETED',
			'PAYMENT.SALE.REFUNDED',
		);

		// Supported subscription events.
		$subscription_events = array(
			'BILLING.SUBSCRIPTION.ACTIVATED',
			'BILLING.SUBSCRIPTION.UPDATED',
			'BILLING.SUBSCRIPTION.EXPIRED',
			'BILLING.SUBSCRIPTION.SUSPENDED',
			'BILLING.SUBSCRIPTION.CANCELLED',
		);

		// Get subscription ID from webhook data.
		$paypal_subscription_id = isset( $webhook_data['resource']['billing_agreement_id'] )
			? sanitize_text_field( $webhook_data['resource']['billing_agreement_id'] )
			: ( isset( $webhook_data['resource']['id'] ) ? sanitize_text_field( $webhook_data['resource']['id'] ) : null );

		// Look up WP subscription ID from the PayPal mapping table.
		$wpsubs_id = ! empty( $paypal_subscription_id ) ? PaypalDB::get_subscription_by_paypal_id( $paypal_subscription_id ) : null;

		// If no WP subscription ID found, try to find the order using the PayPal subscription ID in order meta.
		if ( empty( $wpsubs_id ) && ! empty( $paypal_subscription_id ) ) {
			$chk_orders = wc_get_orders(
				array(
					'meta_key'   => $this->get_meta_key( 'subscription_id' ),
					'meta_value' => $paypal_subscription_id,
					'limit'      => 1,
				)
			);

			$chk_order         = ! empty( $chk_orders ) ? reset( $chk_orders ) : null;
			$chk_subscriptions = $chk_order ? Helper::get_subscriptions_from_order( $chk_order->get_id() ) : null;
			$chk_subscription  = ! empty( $chk_subscriptions ) ? reset( $chk_subscriptions ) : null;

			if ( ! $chk_subscription && $chk_order ) {
				foreach ( $chk_order->get_items() as $item ) {
					$tmp = Helper::get_subscription_from_order_item_id( $item->get_id() );
					if ( ! empty( $tmp ) ) {
						$chk_subscription = $tmp;
						break;
					}
				}
			}

			// Update mapping table.
			if ( ! empty( $chk_subscription ) ) {
				PaypalDB::upsert_mapping(
					$paypal_subscription_id,
					(int) $chk_subscription->subscription_id,
					(int) $chk_order->get_id()
				);
				$wpsubs_id = (int) $chk_subscription->subscription_id;
			}
		}

		// Order object.
		$order = null;

		// Get transaction ID from webhook data.
		$transaction_id = isset( $webhook_data['resource']['sale_id'] )
			? sanitize_text_field( $webhook_data['resource']['sale_id'] )
			: ( isset( $webhook_data['resource']['id'] ) ? sanitize_text_field( $webhook_data['resource']['id'] ) : '' );
		if ( in_array( $event, $transaction_events, true ) && '' === $transaction_id ) {
			$log_message = __( 'PayPal transaction identifier is missing.', 'subscription' );
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context( $webhook_data ) );
			wp_die( esc_html( $log_message ), '400 Bad Request', array( 'response' => 400 ) );
		}

		// Get order by Transaction ID.
		if ( ! empty( $transaction_id ) ) {
			$orders = wc_get_orders( array( 'transaction_id' => $transaction_id ) );

			if ( ! empty( $orders ) ) {
				$order = reset( $orders );
			}
		}

		// Get parent order if the order is a refund order action.
		if ( $order && strpos( get_class( $order ), 'OrderRefund' ) ) {
			$parent_id = $order->get_parent_id() ?? null;

			if ( $parent_id ) {
				$order = wc_get_order( $parent_id );
			}
		}

		// Gate ALL events when both $order and $wpsubs_id are unresolved — return 425 so PayPal.
		// retries. Previously this guard was subscription-events-only; transaction events (e.g.
		// PAYMENT.SALE.COMPLETED) fell through and returned a 404 which PayPal treats as a.
		// permanent failure and never retries, leaving the renewal order in "pending" forever.
		if ( empty( $order ) && empty( $wpsubs_id ) ) {
			$log_message = sprintf(
				// translators: %s: event name.
				__( 'PayPal webhook received [%s]. Order not found. Queuing for retry.', 'subscription' ),
				$event,
			);
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context( $webhook_data ) );
			wp_die( esc_html( $log_message ), '425 Too Early', array( 'response' => 425 ) );
		}

		// Finally, handle the webhook.
		if ( in_array( $event, $transaction_events, true ) ) {
			$this->handle_transaction_event( $webhook_data, $order, $transaction_id, $paypal_subscription_id, $wpsubs_id, $event_id );
		} elseif ( in_array( $event, $subscription_events, true ) ) {
			$this->handle_subscription_event( $webhook_data, $order, $transaction_id, $paypal_subscription_id, $wpsubs_id );
		} else {
			$log_message = sprintf(
				// translators: %1$s: alert name; %2$s: order id.
				__( 'PayPal webhook received [%s]. No actions taken.', 'subscription' ),
				$event,
			);
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context( $webhook_data ) );
			wp_die( esc_html( $log_message ), '200 success', array( 'response' => 200 ) );
		}
	}

	/**
	 * Return minimal webhook context for debug logs without writing the payload.
	 *
	 * PayPal webhook resources may contain payer, address, order, and payment
	 * details. Those values are useful to the webhook handler but do not belong in
	 * WordPress or PHP debug logs. Keep only the event class and presence flags.
	 *
	 * @param array $webhook_data Verified webhook payload.
	 * @return string
	 */
	private function webhook_debug_context( array $webhook_data ): string {
		$resource = isset( $webhook_data['resource'] ) && is_array( $webhook_data['resource'] )
			? $webhook_data['resource']
			: array();

		return wp_json_encode(
			array(
				'event_type'         => sanitize_text_field( (string) ( $webhook_data['event_type'] ?? '' ) ),
				'has_event_id'       => ! empty( $webhook_data['id'] ),
				'has_resource_id'    => ! empty( $resource['id'] ),
				'has_transaction_id' => ! empty( $resource['sale_id'] ),
			)
		);
	}

	/**
	 * Return minimal context when the webhook cannot be decoded yet.
	 *
	 * @param string $raw_body Raw verified-request candidate.
	 * @return string
	 */
	private function webhook_debug_context_from_raw_body( string $raw_body ): string {
		return wp_json_encode(
			array(
				'payload_bytes' => strlen( $raw_body ),
				'payload_json'  => null !== json_decode( $raw_body, true ),
			)
		);
	}

	/**
	 * Return response-shape context without logging gateway response values.
	 *
	 * @param mixed $response_data Decoded PayPal response.
	 * @return string
	 */
	private function gateway_response_debug_context( $response_data ): string {
		if ( is_object( $response_data ) ) {
			$response_data = get_object_vars( $response_data );
		}

		if ( ! is_array( $response_data ) ) {
			return wp_json_encode( array( 'response' => 'invalid' ) );
		}

		return wp_json_encode(
			array(
				'response' => 'error',
				'fields'   => array_values(
					array_intersect(
						array_keys( $response_data ),
						array( 'name', 'debug_id', 'error', 'error_description', 'message' )
					)
				),
			)
		);
	}

	/**
	 * Verify webhook data from PayPal.
	 *
	 * @param array  $headers       Headers from the request.
	 * @param string $raw_body Webhook raw body from PayPal.
	 */
	public function verify_webhook( array $headers, string $raw_body ) {
		if ( empty( $this->webhook_id ) ) {
			subscrpt_write_log( 'PayPal webhook: Webhook ID is not configured.' );
			wp_die( 'Error: PayPal webhook ID is not configured.', '401 Unauthorized', array( 'response' => 401 ) );
		}

		// Get PayPal Access Token.
		$access_token = $this->get_paypal_access_token();
		if ( ! $access_token ) {
			subscrpt_write_log( 'PayPal webhook: Access Token unavailable.' );
			wp_die( 'Error: Access token not available. Cannot verify webhook.', '503 Service Unavailable', array( 'response' => 503 ) );
		}

		// Prepare the request data to verify the webhook.
		$payload = array(
			'auth_algo'         => $headers['PAYPAL-AUTH-ALGO'] ?? $headers['Paypal-Auth-Algo'] ?? '',
			'cert_url'          => $headers['PAYPAL-CERT-URL'] ?? $headers['Paypal-Cert-Url'] ?? '',
			'transmission_id'   => $headers['PAYPAL-TRANSMISSION-ID'] ?? $headers['Paypal-Transmission-Id'] ?? '',
			'transmission_sig'  => $headers['PAYPAL-TRANSMISSION-SIG'] ?? $headers['Paypal-Transmission-Sig'] ?? '',
			'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? $headers['Paypal-Transmission-Time'] ?? '',
			'webhook_id'        => $this->webhook_id ?? '',
			'webhook_event'     => $raw_body,
		);

		// Verify webhook via REST API.
		$verified = $this->verify_paypal_webhook_rest_api( $payload, $raw_body, $access_token );

		if ( null === $verified ) {
			subscrpt_write_log( 'PayPal webhook verification service unavailable.' );
			wp_die( 'Error: PayPal webhook verification is temporarily unavailable.', '503 Service Unavailable', array( 'response' => 503 ) );
		}

		if ( ! $verified ) {
			subscrpt_write_log( 'PayPal webhook verification failed.' );
			subscrpt_write_debug_log( 'Webhook verification failed. ' . $this->webhook_debug_context_from_raw_body( $raw_body ) );
			wp_die( 'Error: PayPal webhook verification failed.', '403 Forbidden', array( 'response' => 403 ) );
		}
	}

	/**
	 * Verify PayPal webhook with REST API.
	 *
	 * @param array  $payload       Payload data for verification.
	 * @param string $raw_body Webhook raw body from PayPal.
	 * @param string $access_token  PayPal Access Token.
	 * @return bool|null True when verified, false when invalid, null when PayPal is unavailable.
	 */
	protected function verify_paypal_webhook_rest_api( array $payload, string $raw_body, string $access_token ): ?bool {
		// Fix the webhook_event to be an array.
		$payload['webhook_event'] = json_decode( $raw_body, true );

		// Verify webhook signature via PayPal REST API.
		try {
			$url  = $this->api_endpoint . '/v1/notifications/verify-webhook-signature';
			$args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			);

			$response = wp_remote_post( $url, $args );
			if ( is_wp_error( $response ) ) {
				subscrpt_write_log( 'PayPal webhook verification request failed: ' . $response->get_error_message() );
				return null;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			if ( $status_code < 200 || $status_code >= 300 ) {
				subscrpt_write_log( "PayPal webhook verification returned HTTP {$status_code}." );
				return ( $status_code >= 500 || 429 === $status_code ) ? null : false;
			}

			$response_data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $response_data ) ) {
				subscrpt_write_log( 'PayPal webhook verification returned an invalid response.' );
				return null;
			}

			$verification_status = $response_data['verification_status'] ?? null;

			if ( empty( $verification_status ) || 'success' !== strtolower( $verification_status ) ) {
				subscrpt_write_debug_log( 'PayPal Webhook Verification: ' . $this->gateway_response_debug_context( $response_data ) );
				return false;
			}

			return true;

		} catch ( \Exception $e ) {
			$log_message = 'PayPal Webhook Verification Failed: ' . $e->getMessage();
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return null;
		}
	}

	/**
	 * Process Payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		return $this->process_paypal_payment( $order );
	}

	/**
	 * Process payments in PayPal.
	 *
	 * @param WC_Order $order The order object.
	 */
	protected function process_paypal_payment( WC_Order $order ): array {
		// Get PayPal Access Token.
		$access_token = $this->get_paypal_access_token();
		if ( ! $access_token ) {
			return array(
				'result'   => 'error',
				'redirect' => '',
				'response' => 'PayPal payment failed. Please try again.',
			);
		}

		// Get the first order item.
		// Based on the logic, the order sould contain only one subscription item.
		$order_items = $order->get_items();
		$order_item  = ! empty( $order_items ) ? reset( $order_items ) : null;

		// Get WooCommerce Product.
		$wc_product_id   = null;
		$wc_variation_id = null;
		$wc_product      = null;
		if ( $order_item && $order_item instanceof WC_Order_Item_Product ) {
			$wc_product_id   = $order_item->get_product_id();
			$wc_variation_id = $order_item->get_variation_id();
			$wc_product      = wc_get_product( $wc_product_id );
		}

		if ( ! $wc_product ) {
			return array(
				'result'   => 'error',
				'redirect' => '',
				'response' => 'Invalid product in order. Please check the order details.',
			);
		}

		// Get PayPal Product ID.
		$paypal_product_id = $this->get_paypal_product_id( $wc_product_id, $access_token );

		if ( ! $paypal_product_id ) {
			return array(
				'result'   => 'error',
				'redirect' => '',
				'response' => 'PayPal payment failed. Please try again. (Failed to get PayPal product ID)',
			);
		}

		// Get PayPal Plan ID.
		$paypal_plan_id = $this->get_paypal_plan_id( $wc_product_id, $wc_variation_id, $paypal_product_id, $access_token );

		if ( ! $paypal_plan_id ) {
			return array(
				'result'   => 'error',
				'redirect' => '',
				'response' => 'PayPal payment failed. Please try again. (Failed to get PayPal plan ID)',
			);
		}

		// Get return URL.
		$return_url = $this->get_return_url( $order );
		$return_url = wp_http_validate_url( $return_url ) ? $return_url : home_url( $return_url );

		// Create Subscription in PayPal.
		$paypal_subscription_data = array(
			'plan_id'             => $paypal_plan_id,
			'request_key'         => 'order:' . (int) $order->get_id(),
			'application_context' => array(
				'return_url' => $return_url,
				'cancel_url' => $order->get_cancel_order_url(),
			),
		);

		$paypal_subscription = $this->create_paypal_subscription( $paypal_subscription_data, $access_token );

		if ( empty( $paypal_subscription->id ?? null ) ) {
			return array(
				'result'   => 'error',
				'redirect' => '',
				'response' => 'PayPal payment failed. Please try again. (Failed to create PayPal subscription)',
			);
		}

		// Save PayPal Subscription ID in order meta.
		$order->update_meta_data( $this->get_meta_key( 'subscription_id' ), $paypal_subscription->id );
		$order->save();

		// Get payment link.
		$paypal_subscription_pay_link = null;
		foreach ( ( $paypal_subscription->links ?? array() ) as $link_obj ) {
			if ( 'approve' === $link_obj->rel ) {
				$paypal_subscription_pay_link = $link_obj->href;
				break;
			}
		}

		if ( empty( $paypal_subscription_pay_link ) ) {
			return array(
				'result'   => 'error',
				'redirect' => '',
				'response' => 'PayPal payment failed. Please try again. (Failed to get PayPal subscription approval link)',
			);
		} else {
			return array(
				'result'   => 'success',
				'redirect' => $paypal_subscription_pay_link,
			);
		}
	}

	/**
	 * Get PayPal product ID.
	 *
	 * @param int    $wc_product_id WooCommerce Product ID.
	 * @param string $access_token  PayPal Access Token.
	 */
	public function get_paypal_product_id( int $wc_product_id, string $access_token ): ?string {
		$wc_product = wc_get_product( $wc_product_id );
		if ( ! $wc_product ) {
			return null;
		}

		// Get data from product meta.
		$paypal_data = get_post_meta( $wc_product_id, $this->get_meta_key( 'product_data' ), true );
		$paypal_data = is_array( $paypal_data ) ? $paypal_data : array();

		$paypal_product_id = $paypal_data['product_id'] ?? null;

		// If PayPal product ID is not available in meta, get or create a new PayPal product.
		if ( ! $paypal_product_id ) {
			$paypal_product = $this->get_or_create_paypal_product( $wc_product, $access_token );

			if ( $paypal_product ) {
				$paypal_product_id = $paypal_product->id;

				// Save PayPal product ID in WooCommerce product meta.
				$data = array(
					'product_id'    => $paypal_product->id,
					'image_url'     => $paypal_product->image_url ?? '',
					'home_url'      => home_url(),
					'wc_product_id' => $wc_product_id,
				);
				update_post_meta( $wc_product_id, $this->get_meta_key( 'product_data' ), $data );
			}
		}

		// Return PayPal product ID or null if not found.
		return $paypal_product_id;
	}

	/**
	 * Get PayPal plan ID.
	 *
	 * @param int    $wc_product_id WooCommerce Product ID.
	 * @param int    $wc_variation_id WooCommerce Variation ID.
	 * @param string $paypal_product_id PayPal Product ID.
	 * @param string $access_token  PayPal Access Token.
	 */
	public function get_paypal_plan_id( int $wc_product_id, int $wc_variation_id, string $paypal_product_id, string $access_token ): ?string {
		$wc_product = wc_get_product( $wc_product_id );
		if ( 0 !== $wc_variation_id ) {
			$wc_product = wc_get_product( $wc_variation_id );
		}

		// Generate fingerprint of current critical billing fields (price, currency, interval, trial, signup fee, cycles).
		$fingerprint = $this->generate_plan_fingerprint( $wc_product );

		// Load stored plans array from product meta.
		$stored_plans = get_post_meta( $wc_product_id, $this->get_meta_key( 'plans' ), true );
		if ( ! is_array( $stored_plans ) ) {
			$stored_plans = array();
		}

		// Return the existing plan whose fingerprint matches the current product configuration.
		foreach ( $stored_plans as $plan_entry ) {
			if ( isset( $plan_entry['fingerprint'] ) && $plan_entry['fingerprint'] === $fingerprint ) {
				return $plan_entry['plan_id'];
			}
		}

		// No matching plan found — create a new one for the current configuration.
		$plan_data                = $this->generate_plan_data( $wc_product, $paypal_product_id );
		$plan_data['request_key'] = 'product:' . $paypal_product_id . '|fingerprint:' . $fingerprint;
		$paypal_plan              = $this->create_paypal_plan( $plan_data, $access_token );

		if ( $paypal_plan ) {
			$stored_plans[] = array(
				'plan_id'     => $paypal_plan->id,
				'fingerprint' => $fingerprint,
			);
			update_post_meta( $wc_product_id, $this->get_meta_key( 'plans' ), $stored_plans );
			return $paypal_plan->id;
		}

		return null;
	}

	/**
	 * Get or create PayPal product.
	 *
	 * @param WC_Product $wc_product WooCommerce Product.
	 * @param string     $access_token PayPal Access Token.
	 */
	public function get_or_create_paypal_product( WC_Product $wc_product, string $access_token ) {
		// Prepare product data.
		$product_data = array(
			'name'          => $this->truncate_string( $wc_product->get_name(), 126 ),
			'description'   => $this->truncate_string( $wc_product->get_short_description(), 256 ),
			'type'          => $wc_product->get_virtual() ? 'DIGITAL' : 'PHYSICAL',
			'image_url'     => $wc_product->get_image_id() ? $this->truncate_string( wp_get_attachment_url( $wc_product->get_image_id() ), 2000 ) : '',
			'home_url'      => $this->truncate_string( get_permalink( $wc_product->get_id() ), 2000 ),
			'wc_product_id' => $wc_product->get_id(),
			'request_key'   => 'wc-product:' . (int) $wc_product->get_id(),
		);

		// Reconcile the remote catalog before creating anything. A missing local.
		// mapping can happen after a restore or metadata cleanup; creating first.
		// would leave an orphaned PayPal product and duplicate future plans.
		$paypal_product = $this->find_paypal_product( $product_data, $access_token );
		if ( false === $paypal_product ) {
			return null;
		}

		if ( $paypal_product ) {
			return $paypal_product;
		}

		// If not found, create a new PayPal product.
		return $this->create_paypal_product( $product_data, $access_token );
	}

	/**
	 * Handle transaction event from PayPal.
	 *
	 * @param array         $webhook_data    Webhook data from PayPal.
	 * @param WC_Order|null $order           Order object, or null if not yet resolved by transaction ID.
	 * @param string|null   $transaction_id  Transaction ID from webhook data.
	 * @param string|null   $subscription_id PayPal subscription ID from webhook data.
	 * @param int|null      $wpsubs_id       WP subscription post ID resolved from mapping table.
	 */
	public function handle_transaction_event( array $webhook_data, ?WC_Order $order, ?string $transaction_id, ?string $subscription_id, ?int $wpsubs_id = null, ?string $event_id = null ) {
		$transaction_lock = '';
		global $wpdb;
		if ( ! empty( $transaction_id ) ) {
			$transaction_lock = 'ashbi_paypal_transaction_' . substr( hash( 'sha256', (string) $transaction_id ), 0, 32 );
			if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $transaction_lock ) ) ) {
				wp_die( 'PayPal transaction event is being reconciled.', '503 Service Unavailable', array( 'response' => 503 ) );
			}
		}

		try {
			return $this->handle_transaction_event_locked( $webhook_data, $order, $transaction_id, $subscription_id, $wpsubs_id, $event_id );
		} finally {
			if ( '' !== $transaction_lock ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $transaction_lock ) );
			}
		}
	}

	/**
	 * Apply a transaction webhook while its transaction identity is serialized.
	 *
	 * @param array         $webhook_data    Webhook data from PayPal.
	 * @param WC_Order|null $order           Order object, or null if not yet resolved by transaction ID.
	 * @param string|null   $transaction_id  Transaction ID from webhook data.
	 * @param string|null   $subscription_id PayPal subscription ID from webhook data.
	 * @param int|null      $wpsubs_id       WP subscription post ID resolved from mapping table.
	 */
	private function handle_transaction_event_locked( array $webhook_data, ?WC_Order $order, ?string $transaction_id, ?string $subscription_id, ?int $wpsubs_id = null, ?string $event_id = null ) {
		// Get event type.
		$event = $webhook_data['event_type'] ?? 'N/A';

		switch ( $event ) {
			case 'PAYMENT.SALE.COMPLETED':
				if ( $order && $this->has_processed_transaction_event( $order, $event_id ) ) {
					$log_message = sprintf(
						// translators: %s: transaction id.
						__( 'Transaction webhook [PAYMENT.SALE.COMPLETED] already reconciled for transaction #%s. Skipping.', 'subscription' ),
						$transaction_id
					);
					subscrpt_write_log( $log_message );
					wp_die( esc_html( $log_message ), '200 Success', array( 'response' => 200 ) );
				}

				// If order was found by transaction_id and is already completed, this is a duplicate delivery.
				if ( $order && $order->has_status( 'completed' ) ) {
					$log_message = sprintf(
						// translators: %s: transaction id.
						__( 'Transaction webhook [PAYMENT.SALE.COMPLETED] already processed for transaction #%s. Skipping.', 'subscription' ),
						$transaction_id
					);
					subscrpt_write_log( $log_message );
					wp_die( esc_html( $log_message ), '200 Success', array( 'response' => 200 ) );
				}

				// Resolve order via subscription when not found by transaction_id.
				if ( ! $order && $wpsubs_id ) {
					$related      = Helper::get_related_orders( $wpsubs_id );
					$latest_row   = ! empty( $related ) ? reset( $related ) : null;
					$latest_order = $latest_row ? wc_get_order( (int) $latest_row->order_id ) : null;

					if ( $latest_order ) {
						$existing_txn = $latest_order->get_transaction_id();

						if ( $existing_txn && $existing_txn === $transaction_id ) {
							// Same transaction already on the order — duplicate delivery.
							$log_message = sprintf(
								// translators: %s: transaction id.
								__( 'Transaction webhook [PAYMENT.SALE.COMPLETED] already processed for transaction #%s. Skipping.', 'subscription' ),
								$transaction_id
							);
							subscrpt_write_log( $log_message );
							wp_die( esc_html( $log_message ), '200 Success', array( 'response' => 200 ) );
						} elseif ( $existing_txn && $existing_txn !== $transaction_id ) {
							// Order already has a different transaction — this is a renewal payment.
							$order = Helper::create_renewal_order( $wpsubs_id );
							if ( $order ) {
								// translators: %s: transaction id.
								$order->add_order_note( sprintf( __( 'Renewal order created by PayPal webhook. Transaction ID: %s', 'subscription' ), $transaction_id ) );
								$order->save();
							}
						} else {
							// No transaction ID yet — initial payment arriving before or after thank-you page.
							$order = $latest_order;
						}
					}
				}

				if ( ! $order ) {
					$log_message = sprintf(
						// translators: %1$s: event; %2$s: subscription id.
						__( 'Transaction webhook received [%1$s]. No order found for subscription #%2$s.', 'subscription' ),
						$event,
						$subscription_id
					);
					subscrpt_write_log( $log_message );
					subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context( $webhook_data ) );
					$response_code  = $wpsubs_id ? 503 : 404;
					$response_title = $wpsubs_id ? '503 Service Unavailable' : '404 not found';
					wp_die( esc_html( $log_message ), esc_html( $response_title ), array( 'response' => absint( $response_code ) ) );
				}

				if ( ! $order instanceof \WC_Order ) {
					$log_message = sprintf(
						// translators: %s: subscription id.
						__( 'Transaction webhook received [PAYMENT.SALE.COMPLETED]. Failed to create renewal order for subscription #%s.', 'subscription' ),
						$wpsubs_id
					);
					subscrpt_write_log( $log_message );
					subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context( $webhook_data ) );
					wp_die( esc_html( $log_message ), '500 Internal Error', array( 'response' => 500 ) );
				}

				if ( in_array( $order->get_status(), array( 'refunded', 'cancelled' ), true ) ) {
					$log_message = sprintf(
						// translators: %s: order ID.
						__( 'PayPal completion webhook ignored for terminal order #%s.', 'subscription' ),
						$order->get_id()
					);
					$this->record_transaction_event( $order, $event_id );
					$order->save();
					subscrpt_write_log( $log_message );
					wp_die( esc_html( $log_message ), '200 Success', array( 'response' => 200 ) );
				}

				$order->set_transaction_id( $transaction_id );

				// If already completed (e.g. thank-you page ran first), just record the transaction ID.
				if ( $order->has_status( 'completed' ) ) {
					$this->record_transaction_event( $order, $event_id );
					$order->add_order_note( __( 'PayPal transaction ID recorded by webhook.', 'subscription' ) );
					$order->save();

					// translators: %s: alert name.
					$log_message = sprintf( __( 'Transaction webhook received [%s]. Order already completed; transaction ID updated.', 'subscription' ), $event );
					subscrpt_write_log( $log_message );
					wp_die( esc_html( $log_message ), '200 Success', array( 'response' => 200 ) );
				}

				if ( $order->update_status( 'completed' ) ) {
					$this->record_transaction_event( $order, $event_id );
					$order->add_order_note( __( 'Payment completed by paypal webhook.', 'subscription' ) );
					$order->save();

					// translators: %s: alert name.
					$log_message = sprintf( __( 'Transaction webhook received [%s]. Payment completed.', 'subscription' ), $event );
					subscrpt_write_log( $log_message );
					wp_die( esc_html( $log_message ), '200 Success', array( 'response' => 200 ) );
				} else {
					$order->add_order_note( __( 'Failed to complete payment. Requested by paypal webhook.', 'subscription' ) );
					$order->save();

					// translators: %s: alert name.
					$log_message = sprintf( __( 'Transaction webhook received [%s]. Payment completion failed.', 'subscription' ), $event );
					subscrpt_write_log( $log_message );
					wp_die( esc_html( $log_message ), '506 Internal Error', array( 'response' => 506 ) );
				}
				break;

			case 'PAYMENT.SALE.REFUNDED':
				if ( ! $order ) {
					$log_message = sprintf(
						// translators: %1$s: event; %2$s: subscription id.
						__( 'Transaction webhook received [%1$s]. No order found for subscription #%2$s.', 'subscription' ),
						$event,
						$subscription_id
					);
					subscrpt_write_log( $log_message );
					subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context( $webhook_data ) );
					wp_die( esc_html( $log_message ), '404 not found', array( 'response' => 404 ) );
				}

				$event_id       = sanitize_text_field( (string) ( $webhook_data['id'] ?? '' ) );
				$seen_event_ids = get_post_meta( $order->get_id(), '_subscrpt_paypal_refund_event_ids', true );
				$seen_event_ids = is_array( $seen_event_ids ) ? array_values( array_filter( array_map( 'sanitize_text_field', $seen_event_ids ) ) ) : array();
				if ( '' !== $event_id && in_array( $event_id, $seen_event_ids, true ) ) {
					$log_message = sprintf(
						// translators: %s: PayPal webhook event name.
						__( 'Transaction webhook [%s] was already reconciled. Skipping duplicate refund delivery.', 'subscription' ),
						$event
					);
					subscrpt_write_log( $log_message );
					wp_die( esc_html( $log_message ), '200 Success', array( 'response' => 200 ) );
				}

				$refund_amount = (float) ( $webhook_data['resource']['amount']['total'] ?? 0 );
				$order_total   = (float) $order->get_total();
				$is_full       = $refund_amount >= $order_total;

				if ( $is_full ) {
					$order->update_status( 'refunded' );
				}

				$order->add_order_note(
					$is_full
						? __( 'Full payment refunded by PayPal webhook.', 'subscription' )
						: sprintf(
							// translators: %s: refunded amount.
							__( 'Partial payment refunded by PayPal webhook. Amount: %s', 'subscription' ),
							wc_price( $refund_amount, array( 'currency' => $order->get_currency() ) )
						)
				);
				if ( '' !== $event_id ) {
					$seen_event_ids[] = $event_id;
					$order->update_meta_data( '_subscrpt_paypal_refund_event_ids', array_slice( array_values( array_unique( $seen_event_ids ) ), -50 ) );
				}
				$order->save();

				// translators: %s: alert name.
				$log_message = sprintf( __( 'Transaction webhook received [%s]. Payment refunded.', 'subscription' ), $event );
				subscrpt_write_log( $log_message );
				wp_die( esc_html( $log_message ), '200 Success', array( 'response' => 200 ) );
				break;

			default:
				$log_message = sprintf(
					// translators: %s: alert name.
					__( 'Transaction webhook received [%s]. No actions taken.', 'subscription' ),
					$event,
				);
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context( $webhook_data ) );
				wp_die( esc_html( $log_message ), '200 success', array( 'response' => 200 ) );
		}
	}

	/**
	 * Determine whether a completed-sale event was already reconciled for an order.
	 *
	 * @param WC_Order|null $order   WooCommerce order.
	 * @param string|null   $event_id PayPal event ID.
	 * @return bool
	 */
	private function has_processed_transaction_event( ?WC_Order $order, ?string $event_id ): bool {
		if ( ! $order || '' === (string) $event_id ) {
			return false;
		}

		$event_ids = $order->get_meta( '_subscrpt_paypal_transaction_event_ids', true );
		$event_ids = is_array( $event_ids ) ? array_values( array_filter( array_map( 'sanitize_text_field', $event_ids ) ) ) : array();

		return in_array( (string) $event_id, $event_ids, true );
	}

	/**
	 * Record a completed-sale event after its local order mutation succeeds.
	 *
	 * @param WC_Order|null $order   WooCommerce order.
	 * @param string|null   $event_id PayPal event ID.
	 * @return void
	 */
	private function record_transaction_event( ?WC_Order $order, ?string $event_id ): void {
		if ( ! $order || '' === (string) $event_id ) {
			return;
		}

		$event_ids = $order->get_meta( '_subscrpt_paypal_transaction_event_ids', true );
		$event_ids = is_array( $event_ids ) ? array_values( array_filter( array_map( 'sanitize_text_field', $event_ids ) ) ) : array();
		$event_ids[] = sanitize_text_field( (string) $event_id );
		$order->update_meta_data( '_subscrpt_paypal_transaction_event_ids', array_slice( array_values( array_unique( $event_ids ) ), -50 ) );
	}

	/**
	 * Handle subscription event from PayPal.
	 *
	 * @param array         $webhook_data           Webhook data from PayPal.
	 * @param WC_Order|null $order                  Order object, or null when resolved via $wpsubs_id.
	 * @param string|null   $transaction_id          Transaction ID from webhook data.
	 * @param string|null   $paypal_subscription_id  PayPal subscription ID from webhook data.
	 * @param int|null      $wpsubs_id               WP subscription post ID resolved from mapping table.
	 */
	public function handle_subscription_event( array $webhook_data, ?WC_Order $order, ?string $transaction_id, ?string $paypal_subscription_id, ?int $wpsubs_id = null ) {
		// Get event type.
		$event = $webhook_data['event_type'] ?? 'N/A';

		if ( ! $wpsubs_id ) {
			// Subscription.
			$subscription = Helper::get_subscriptions_from_order( $order->get_id() );
			$subscription = ! empty( $subscription ) ? reset( $subscription ) : null;

			// If no subscription, try to get from order item.
			if ( empty( $subscription ) ) {
				$log_message = sprintf(
				// translators: %s: alert name.
					__( 'Subscription webhook received [%s]. Subscription not found. Attempting to get from order item.', 'subscription' ),
					$event
				);
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message );

				$order_items = $order->get_items();
				foreach ( $order_items as $item ) {
					$tmp_subs = Helper::get_subscription_from_order_item_id( $item->get_id() );

					if ( ! empty( $tmp_subs ) ) {
						$subscription = $tmp_subs;

						if ( ! empty( $subscription->subscription_id ?? null ) ) {
							$log_message = sprintf(
								// translators: %s: subscription id.
								__( 'Subscription found [ID: %s]. Processing webhook.', 'subscription' ),
								$subscription->subscription_id
							);
							subscrpt_write_log( $log_message );
							subscrpt_write_debug_log( $log_message );
						}
						break;
					}
				}
			}

			// If still no subscription, exit.
			if ( empty( $subscription ) || empty( $subscription->subscription_id ?? null ) ) {
				$log_message = sprintf(
					// translators: %s: alert name.
					__( 'Subscription webhook received [%s]. Subscription not found. Stopping Process.', 'subscription' ),
					$event,
				);
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message . ' ' . $this->webhook_debug_context( $webhook_data ) );
				wp_die( esc_html( $log_message ), '404 not found', array( 'response' => 404 ) );
			}

			$subscription_id = $subscription->subscription_id;
		} else {
			$subscription_id = $wpsubs_id;
		}

		$event_time_raw = trim( (string) ( $webhook_data['create_time'] ?? '' ) );
		if ( '' === $event_time_raw ) {
			wp_die( 'PayPal subscription event timestamp is invalid.', '400 Bad Request', array( 'response' => 400 ) );
		}
		try {
			$event_datetime = new \DateTimeImmutable( $event_time_raw );
		} catch ( \Exception $exception ) {
			wp_die( 'PayPal subscription event timestamp is invalid.', '400 Bad Request', array( 'response' => 400 ) );
		}
		$event_time = $event_datetime->getTimestamp();
		$event_id   = sanitize_text_field( $webhook_data['id'] ?? '' );
		if ( '' === $event_id ) {
			wp_die( 'PayPal subscription event ID is missing.', '400 Bad Request', array( 'response' => 400 ) );
		}

		global $wpdb;
		$lock_name = 'ashbi_paypal_subscription_' . (int) $subscription_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ) ) {
			wp_die( 'PayPal subscription event is being reconciled.', '503 Service Unavailable', array( 'response' => 503 ) );
		}

		if ( hash_equals( (string) get_post_meta( $subscription_id, '_subscrpt_paypal_last_event_id', true ), $event_id ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			wp_die( 'PayPal subscription event already reconciled.', '200 success', array( 'response' => 200 ) );
		}
		$last_event_time_precise = (string) get_post_meta( $subscription_id, '_subscrpt_paypal_last_event_time_precise', true );
		if ( '' !== $last_event_time_precise ) {
			try {
				$last_event_datetime = new \DateTimeImmutable( $last_event_time_precise );
				if ( $event_datetime < $last_event_datetime ) {
					$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
					wp_die( 'Stale PayPal subscription event ignored.', '200 success', array( 'response' => 200 ) );
				}
			} catch ( \Exception $exception ) {
				subscrpt_write_log( "Invalid stored PayPal event watermark for subscription #{$subscription_id}; reconciling from PayPal." );
			}
		}

		if ( empty( $paypal_subscription_id ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			wp_die( 'PayPal subscription identifier is unavailable.', '503 Service Unavailable', array( 'response' => 503 ) );
		}

		// Re-read the authoritative PayPal object for every distinct event. Delivery.
		// order is not guaranteed, so applying the event name itself can resurrect a.
		// cancelled subscription or discard two transitions created in one second.
		$paypal_subscription = $this->get_paypal_subscription( $paypal_subscription_id );
		$remote_status       = strtoupper( (string) ( $paypal_subscription->status ?? '' ) );
		$status_map          = array(
			'ACTIVE'    => array( 'active', 'active' ),
			'SUSPENDED' => array( 'on-hold', 'suspended' ),
			'CANCELLED' => array( 'cancelled', 'cancelled' ),
			'EXPIRED'   => array( 'expired', 'expired' ),
		);

		if ( ! isset( $status_map[ $remote_status ] ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			wp_die( 'PayPal subscription state is not yet reconcilable.', '503 Service Unavailable', array( 'response' => 503 ) );
		}

		$expected_remote_status = array(
			'BILLING.SUBSCRIPTION.ACTIVATED' => 'ACTIVE',
			'BILLING.SUBSCRIPTION.SUSPENDED' => 'SUSPENDED',
			'BILLING.SUBSCRIPTION.CANCELLED' => 'CANCELLED',
			'BILLING.SUBSCRIPTION.EXPIRED'   => 'EXPIRED',
		);
		$expected_status        = $expected_remote_status[ $event ] ?? '';
		if ( $expected_status && $remote_status !== $expected_status ) {
			$remote_update_time     = trim( (string) ( $paypal_subscription->status_update_time ?? '' ) );
			$remote_update_datetime = null;
			if ( '' !== $remote_update_time ) {
				try {
					$remote_update_datetime = new \DateTimeImmutable( $remote_update_time );
				} catch ( \Exception $exception ) {
					$remote_update_datetime = null;
				}
			}

			// A newer/equal authoritative update proves this webhook is stale. If.
			// PayPal's object is still older than the webhook, ask for redelivery.
			// until the API has converged instead of acknowledging a lost transition.
			if ( ! $remote_update_datetime || $remote_update_datetime < $event_datetime ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
				wp_die( 'PayPal subscription state has not converged yet.', '503 Service Unavailable', array( 'response' => 503 ) );
			}
		}

		list( $local_status, $stored_status ) = $status_map[ $remote_status ];
		if ( get_post_status( $subscription_id ) !== $local_status ) {
			Action::status( $local_status, (int) $subscription_id );
		}
		update_post_meta( $subscription_id, $this->get_meta_key( 'paypal_subs_status' ), $stored_status );
		$log_message = sprintf(
			/* translators: 1: PayPal event name, 2: authoritative remote status. */
			__( 'Subscription webhook [%1$s] reconciled to PayPal status %2$s.', 'subscription' ),
			$event,
			$remote_status
		);

		update_post_meta( $subscription_id, '_subscrpt_paypal_last_event_time', $event_time );
		update_post_meta( $subscription_id, '_subscrpt_paypal_last_event_time_precise', $event_datetime->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.u\Z' ) );
		update_post_meta( $subscription_id, '_subscrpt_paypal_last_event_id', $event_id );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		subscrpt_write_log( $log_message );
		wp_die( esc_html( $log_message ), '200 success', array( 'response' => 200 ) );
	}

	/**
	 * Handle subscription cancellation.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function handle_subscription_cancellation( int $subscription_id ) {
		$order_id = get_post_meta( $subscription_id, '_subscrpt_order_id', true );
		$order    = wc_get_order( $order_id );

		// Get order payment method.
		$payment_method = $order->get_payment_method();

		// Get paypal subscription status from subscription meta.
		$paypal_subs_status = get_post_meta( $subscription_id, $this->get_meta_key( 'paypal_subs_status' ), true );

		// Only process if the payment method is PayPal and the subscription is not already cancelled.
		if ( ( $payment_method !== $this->id ) || ( ! empty( $paypal_subs_status ) && 'cancelled' === $paypal_subs_status ) ) {
			return;
		}

		// Get paypal subscription ID from order meta.
		$paypal_subscription_id = $order->get_meta( $this->get_meta_key( 'subscription_id' ) );

		if ( empty( $paypal_subscription_id ) ) {
			subscrpt_write_log( 'PayPal subscription ID not found in order meta. Attempting to get from order history.' );

			global $wpdb;
			$table_name      = $wpdb->prefix . 'subscrpt_order_relation';
			$order_histories = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					'SELECT * FROM %i WHERE subscription_id=%d ORDER BY order_id DESC',
					array( $table_name, $subscription_id )
				)
			);

			foreach ( $order_histories as $history ) {
				// Get order ID from history.
				$order_id = $history->order_id ?? null;
				$order    = wc_get_order( $order_id );

				// Get PayPal subscription ID from order meta.
				$tmp_paypal_subs_id = $order->get_meta( $this->get_meta_key( 'subscription_id' ) );

				// OLD key migration.
				// If no data check if the data exists with the old key. And update if necessary.
				// ? Dev note: Remove after JAN 1, 2026.
				if ( empty( $tmp_paypal_subs_id ) ) {
					$tmp_paypal_subs_id = $order->get_meta( '_wp_subs_paypal_subscription_id', true );

					if ( ! empty( $tmp_paypal_subs_id ) ) {
						$order->update_meta_data( $this->get_meta_key( 'subscription_id' ), $tmp_paypal_subs_id );
						$order->save();
					}
				}

				if ( ! empty( $tmp_paypal_subs_id ) ) {
					$paypal_subscription_id = $tmp_paypal_subs_id;
					break;
				}
			}
		}

		// Get PayPal Access Token.
		$access_token = $this->get_paypal_access_token();
		if ( ! $access_token ) {
			subscrpt_write_log( 'Access token not found. Retrying.' );

			$access_token = $this->get_paypal_access_token();

			if ( ! $access_token ) {
				subscrpt_write_log( 'Access token not found.' );
				subscrpt_write_log( "Failed to cancel subscription #{$subscription_id} in PayPal." );
				return;
			}
		}

		// Cancel subscription in PayPal.
		$result = $this->cancel_paypal_subscription( $paypal_subscription_id, $access_token, 'Customer requested cancellation.' );
		if ( $result ) {
			update_post_meta( $subscription_id, $this->get_meta_key( 'paypal_subs_status' ), 'cancelled' );

			subscrpt_write_log( "Subscription #{$subscription_id} cancelled successfully in PayPal." );
		} else {
			subscrpt_write_log( "Failed to cancel subscription #{$subscription_id} in PayPal." );
		}
	}

	// * ------------------------------------------------------------------------ * //.
	// * -------------------- Utility Methods [start] --------------------------- * //.

	/**
	 * Truncate long string.
	 *
	 * @param string $long_string The long string to truncate.
	 * @param int    $max_length  The maximum length of the string.
	 * @return string The truncated string if it exceeds the maximum length, otherwise the original string
	 */
	public function truncate_string( string $long_string, int $max_length = 48 ): string {
		return strlen( $long_string ) <= $max_length ? $long_string : substr( $long_string, 0, $max_length );
	}

	/**
	 * Build a deterministic PayPal idempotency key for a logical create request.
	 *
	 * PayPal replays a request with the same key instead of creating a second
	 * object after a client timeout. The mode is part of the digest so sandbox
	 * and live requests can never collide in local logs or tooling.
	 *
	 * @param string $operation Logical PayPal operation.
	 * @param string $request_key Stable local identity for the operation.
	 */
	private function get_paypal_request_id( string $operation, string $request_key ): string {
		$mode = $this->sandbox_mode ? 'sandbox' : 'live';
		return 'ashbi-' . $operation . '-' . substr( hash( 'sha256', $mode . '|' . $request_key ), 0, 64 );
	}

	/**
	 * Get Prefixed Meta Key.
	 * Prefix the key with '_wp_subs_' to avoid possible conflicts with other plugins.
	 *
	 * @param string      $key The key to prefix.
	 * @param string|null $mode_override Optional mode override (sandbox/live).
	 */
	public function get_meta_key( string $key, ?string $mode_override = null ): string {
		$keys         = array(
			'product_data'       => 'product_data',
			'plan_id'            => 'plan_id',
			'plan_desc'          => 'plan_description',
			'plans'              => 'plans',
			'subscription_id'    => 'subscription_id',
			'paypal_subs_status' => 'paypal_subs_status',
		);
		$selected_key = $keys[ $key ] ?? $key;

		$mode_string = $this->sandbox_mode ? 'sandbox_' : 'live_';
		if ( ! empty( $mode_override ) ) {
			$mode_string = 'sandbox' === $mode_override ? 'sandbox_' : 'live_';
		}

		return '_wp_subs_paypal_' . $mode_string . $selected_key;
	}

	/**
	 * Convert a billing interval string to PayPal's uppercase singular format.
	 * subscrpt_get_typos function of the plugin have translator on the intervals. PayPal will only accept english.
	 *
	 * @param string $interval Raw interval string (e.g. 'month', 'months', 'WEEK').
	 * @return string PayPal interval constant: DAY, WEEK, MONTH, or YEAR.
	 */
	private function convert_paypal_interval( string $interval ): string {
		switch ( strtolower( $interval ) ) {
			case 'day':
			case 'days':
				return 'DAY';
			case 'week':
			case 'weeks':
				return 'WEEK';
			case 'month':
			case 'months':
				return 'MONTH';
			case 'year':
			case 'years':
				return 'YEAR';
			default:
				return 'MONTH';
		}
	}

	/**
	 * Generate a fingerprint hash of all critical billing fields for a product.
	 *
	 * The fingerprint encodes every field that determines a distinct PayPal billing
	 * plan (price, currency, interval, trial, signup fee, cycle count). Two products
	 * with identical critical fields produce the same fingerprint and can share a plan.
	 *
	 * @param WC_Product $wc_product WooCommerce product (simple or variation).
	 * @return string MD5 hash of the critical fields.
	 */
	private function generate_plan_fingerprint( WC_Product $wc_product ): string {
		$wpsubs_product = Subscription::get_subs_product( $wc_product );
		$meta_cycles    = $wc_product->get_meta( '_subscrpt_max_no_payment' );
		$total_cycles   = $meta_cycles ? $meta_cycles : 0;

		$data = array(
			'price'          => number_format( (float) wc_get_price_including_tax( $wc_product ), 2, '.', '' ),
			'currency'       => get_woocommerce_currency(),
			'interval'       => $this->convert_paypal_interval( $wpsubs_product->get_timing_option() ),
			'interval_count' => (int) $wpsubs_product->get_timing_per(),
			'trial_interval' => $this->convert_paypal_interval( $wpsubs_product->get_trial_timing_option() ),
			'trial_count'    => (int) $wpsubs_product->get_trial_timing_per(),
			'signup_fee'     => number_format( (float) $wpsubs_product->get_signup_fee(), 2, '.', '' ),
			'total_cycles'   => (int) $total_cycles,
		);

		return md5( wp_json_encode( $data ) );
	}

	/**
	 * Generate PayPal Plan Data.
	 *
	 * @param WC_Product $wc_product WooCommerce Product.
	 * @param string     $paypal_product_id PayPal Product ID.
	 */
	public function generate_plan_data( WC_Product $wc_product, string $paypal_product_id ): array {
		// Get the Ashbi Subscriptions wrapped product.
		// $wpsubs_product type WC_Product.
		$wpsubs_product = Subscription::get_subs_product( $wc_product );

		// Name.
		$name = $this->truncate_string( $wc_product->get_name(), 126 );

		// Description.
		$description = $this->truncate_string( $wc_product->get_short_description(), 126 );

		// Price.
		$price = wc_get_price_including_tax( $wc_product );

		// Recurring Details.
		$plan_length    = $wpsubs_product->get_timing_per();
		$plan_interval  = $this->convert_paypal_interval( $wpsubs_product->get_timing_option() );
		$trial_length   = $wpsubs_product->get_trial_timing_per();
		$trial_interval = $this->convert_paypal_interval( $wpsubs_product->get_trial_timing_option() );
		$signup_fee     = $wpsubs_product->get_signup_fee();

		// Get value for total_cycles from _subscrpt_max_no_payment.
		$meta_cycles  = $wc_product->get_meta( '_subscrpt_max_no_payment' );
		$total_cycles = $meta_cycles ? $meta_cycles : 0;

		// Billing Cycles.
		$billing_cycles = array();

		// Add trial cycle in billing cycles if available.
		if ( (int) $trial_length > 0 ) {
			$billing_cycles[] = array(
				'tenure_type'  => 'TRIAL',
				'sequence'     => 1,
				'total_cycles' => $total_cycles,
				'frequency'    => array(
					'interval_unit'  => $trial_interval,
					'interval_count' => (int) $trial_length,
				),
			);
		}

		// Add regular cycle in billing cycles.
		$billing_cycles[] = array(
			'tenure_type'    => 'REGULAR',
			'sequence'       => count( $billing_cycles ) + 1,
			'total_cycles'   => 0,
			'pricing_scheme' => array(
				'fixed_price' => array(
					'value'         => number_format( (float) $price, 2, '.', '' ),
					'currency_code' => get_woocommerce_currency(),
				),
			),
			'frequency'      => array(
				'interval_unit'  => $plan_interval,
				'interval_count' => (int) $plan_length,
			),
		);

		// Payment Preferences.
		$payment_preferences = array(
			'auto_bill_outstanding'     => true,
			'setup_fee_failure_action'  => 'CANCEL',
			'payment_failure_threshold' => 3,
			'setup_fee'                 => array(
				'value'         => number_format( (float) $signup_fee, 2, '.', '' ),
				'currency_code' => get_woocommerce_currency(),
			),
		);

		// Final Data.
		$plan_data = array(
			'product_id'          => $paypal_product_id,
			'name'                => $name,
			'description'         => $description,
			'billing_cycles'      => $billing_cycles,
			'quantity_supported'  => false,
			'payment_preferences' => $payment_preferences,
		);
		return $plan_data;
	}

	// * -------------------- Utility Methods [end] --------------------------- * //.
	// * ---------------------------------------------------------------------- * //.


	// * ---------------------------------------------------------------- * //.
	// * -------------------- API Operations [start] -------------------- * //.
	// ? Keep this section strictly for API operations. No other logic like data extraction should be added here.

	/**
	 * Find an existing PayPal catalog product for a WooCommerce product.
	 *
	 * @param array  $product_data Product identity fields.
	 * @param string $access_token PayPal access token.
	 * @return object|false|null Existing product, unavailable response, or no match.
	 */
	private function find_paypal_product( array $product_data, string $access_token ) {
		$target_name     = trim( (string) ( $product_data['name'] ?? '' ) );
		$target_home_url = untrailingslashit( trim( (string) ( $product_data['home_url'] ?? '' ) ) );
		if ( '' === $target_name || '' === $target_home_url ) {
			return null;
		}

		$page      = 1;
		$page_size = 20;
		try {
			while ( $page <= 100 ) {
				$url = add_query_arg(
					array(
						'page_size'      => $page_size,
						'page'           => $page,
						'total_required' => 'true',
					),
					$this->api_endpoint . '/v1/catalogs/products'
				);

				$response = wp_remote_get(
					$url,
					array(
						'method'  => 'GET',
						'headers' => array(
							'Authorization' => 'Bearer ' . $access_token,
							'Content-Type'  => 'application/json',
						),
					)
				);

				if ( is_wp_error( $response ) ) {
					subscrpt_write_log( 'PayPal product catalog lookup failed: ' . $response->get_error_message() );
					return false;
				}

				$status_code = (int) wp_remote_retrieve_response_code( $response );
				if ( $status_code < 200 || $status_code >= 300 ) {
					subscrpt_write_log( "PayPal product catalog lookup returned HTTP {$status_code}." );
					return false;
				}

				$response_data = json_decode( wp_remote_retrieve_body( $response ) );
				if ( ! is_object( $response_data ) || ! isset( $response_data->products ) || ! is_array( $response_data->products ) ) {
					subscrpt_write_log( 'PayPal product catalog lookup returned an invalid response.' );
					return false;
				}

				foreach ( $response_data->products as $product ) {
					$remote_name     = trim( (string) ( $product->name ?? '' ) );
					$remote_home_url = untrailingslashit( trim( (string) ( $product->home_url ?? '' ) ) );
					if ( $target_name === $remote_name && $target_home_url === $remote_home_url && ! empty( $product->id ?? null ) ) {
						return $product;
					}
				}

				$total_pages = (int) ( $response_data->total_pages ?? 0 );
				if ( count( $response_data->products ) < $page_size || ( $total_pages > 0 && $page >= $total_pages ) ) {
					break;
				}
				++$page;
			}
		} catch ( \Exception $e ) {
			subscrpt_write_log( 'PayPal product catalog lookup failed: ' . $e->getMessage() );
			return false;
		}

		return null;
	}

	/**
	 * Get PayPal Access Token.
	 */
	private function get_paypal_access_token(): ?string {
		try {
			$url  = $this->api_endpoint . '/v1/oauth2/token';
			$args = array(
				'method'  => 'POST',
				'headers' => array(
					'Accept'          => 'application/json',
					'Accept-Language' => 'en_US',
					'Authorization'   => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ), // phpcs:ignore
				),
				'body'    => array(
					'grant_type' => 'client_credentials',
				),
			);

			$response      = wp_remote_post( $url, $args );
			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $response_data->error ) || ! isset( $response_data->access_token ) ) {
				$error_description = ! empty( $response_data ) ? $response_data->error_description ?? 'Unknown error' : 'Unknown error';
				$log_message       = 'Gateway Error : PayPal access token - ' . $error_description;
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message );

				return null;
			}

			return $response_data->access_token;
		} catch ( \Exception $e ) {
			$log_message = $e->getMessage();
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );

			return null;
		}
	}

	/**
	 * Create PayPal product.
	 *
	 * @param array  $product_data   Product data to create.
	 * @param string $access_token   PayPal Access Token.
	 */
	private function create_paypal_product( array $product_data, string $access_token ): ?object {
		if ( empty( $product_data['name'] ?? null ) || empty( $product_data['type'] ?? null ) ) {
			$log_message = __( 'PayPal Product Creation Error: Product data is incomplete. Name and type are required.', 'subscription' );
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return null;
		}

		// Prepare the body for the API request.
		$body = array(
			'name' => $product_data['name'],
			'type' => $product_data['type'],
		);
		if ( ! empty( $product_data['description'] ?? null ) ) {
			$body['description'] = $product_data['description'];
		}
		if ( ! empty( $product_data['category'] ?? null ) ) {
			$body['category'] = $product_data['category'];
		}
		if ( ! empty( $product_data['image_url'] ?? null ) && ! strpos( $product_data['image_url'], '.test' ) ) {
			$body['image_url'] = $product_data['image_url'];
		}
		if ( ! empty( $product_data['home_url'] ?? null ) && ! strpos( $product_data['home_url'], '.test' ) ) {
			$body['home_url'] = $product_data['home_url'];
		}

		try {
			$url  = $this->api_endpoint . '/v1/catalogs/products';
			$args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization'     => 'Bearer ' . $access_token,
					'Content-Type'      => 'application/json',
					'Prefer'            => 'return=representation',
					'PayPal-Request-Id' => $this->get_paypal_request_id( 'product', (string) ( $product_data['request_key'] ?? $product_data['home_url'] ?? $product_data['name'] ) ),
				),
				'body'    => wp_json_encode( $body ),
			);

			$response      = wp_remote_post( $url, $args );
			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response_data->id ?? null ) ) {
				$log_message = 'Error creating PayPal product: ' . ( $response_data->error_description ?? 'Unknown error' );
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message . ' ' . $this->gateway_response_debug_context( $response_data ) );
				return null;
			}

			return $response_data;
		} catch ( \Exception $e ) {
			$log_message = 'Error creating PayPal product: ' . $e->getMessage();
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return null;
		}
	}

	/**
	 * Create PayPal plan.
	 *
	 * @param array  $plan_data     Plan data to create.
	 * @param string $access_token  PayPal Access Token.
	 */
	private function create_paypal_plan( array $plan_data, string $access_token ): ?object {
		// Prepare the body for the API request.
		$body = array(
			'product_id'          => $plan_data['product_id'],
			'name'                => $plan_data['name'],
			'billing_cycles'      => $plan_data['billing_cycles'],
			'payment_preferences' => $plan_data['payment_preferences'],
		);
		if ( ! empty( $plan_data['description'] ?? null ) ) {
			$body['description'] = $plan_data['description'];
		}
		if ( ! empty( $plan_data['quantity_supported'] ?? null ) ) {
			$body['quantity_supported'] = $plan_data['quantity_supported'];
		}

		try {
			$url  = $this->api_endpoint . '/v1/billing/plans';
			$args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization'     => 'Bearer ' . $access_token,
					'Content-Type'      => 'application/json',
					'Prefer'            => 'return=representation',
					'PayPal-Request-Id' => $this->get_paypal_request_id( 'plan', (string) ( $plan_data['request_key'] ?? $plan_data['product_id'] . '|' . $plan_data['name'] ) ),
				),
				'body'    => wp_json_encode( $body ),
			);

			$response      = wp_remote_post( $url, $args );
			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response_data->id ?? null ) ) {
				$log_message = 'Error creating PayPal plan: ' . ( $response_data->error_description ?? $response_data->message ?? 'Unknown error' );
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message . ' ' . $this->gateway_response_debug_context( $response_data ) );
				return null;
			}

			return $response_data;
		} catch ( \Exception $e ) {
			$log_message = 'Error creating PayPal plan: ' . $e->getMessage();
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return null;
		}
	}

	/**
	 * Create PayPal subscription.
	 *
	 * @param array  $paypal_subscription_data PayPal subscription data.
	 * @param string $access_token    PayPal Access Token.
	 */
	private function create_paypal_subscription( array $paypal_subscription_data, string $access_token ): ?object {
		// Prepare the body for the API request.
		$body = array(
			'plan_id'             => $paypal_subscription_data['plan_id'],
			'application_context' => $paypal_subscription_data['application_context'],
		);

		try {
			$url  = $this->api_endpoint . '/v1/billing/subscriptions';
			$args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization'     => 'Bearer ' . $access_token,
					'Content-Type'      => 'application/json',
					'Prefer'            => 'return=representation',
					'PayPal-Request-Id' => $this->get_paypal_request_id( 'subscription', (string) ( $paypal_subscription_data['request_key'] ?? $paypal_subscription_data['plan_id'] ) ),
				),
				'body'    => wp_json_encode( $body ),
			);

			$response      = wp_remote_post( $url, $args );
			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response_data->id ?? null ) ) {
				$log_message = 'Error creating PayPal subscription: ' . ( $response_data->error_description ?? $response_data->message ?? 'Unknown error' );
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message . ' ' . $this->gateway_response_debug_context( $response_data ) );
				return null;
			}

			return $response_data;
		} catch ( \Exception $e ) {
			$log_message = 'Error creating PayPal subscription: ' . $e->getMessage();
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return null;
		}
	}

	/**
	 * Process a refund via PayPal Captures API.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param float  $amount   Amount to refund, or null for full refund.
	 * @param string $reason   Reason for refund.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'invalid_order', __( 'Order not found.', 'subscription' ) );
		}

		$capture_id = $order->get_transaction_id();
		if ( ! $capture_id ) {
			return new \WP_Error( 'no_capture_id', __( 'PayPal capture ID not found on this order.', 'subscription' ) );
		}

		$access_token = $this->get_paypal_access_token();
		if ( ! $access_token ) {
			return new \WP_Error( 'no_access_token', __( 'Failed to get PayPal access token.', 'subscription' ) );
		}

		try {
			$url  = $this->api_endpoint . "/v1/payments/sale/{$capture_id}/refund";
			$body = array();

			if ( null !== $amount ) {
				$body['amount'] = array(
					'total'    => number_format( (float) $amount, 2, '.', '' ),
					'currency' => $order->get_currency(),
				);
			}

			if ( ! empty( $reason ) ) {
				$body['description'] = substr( $reason, 0, 255 );
			}

			// WooCommerce only creates the local refund after this gateway returns.
			// success. The current refund count therefore remains stable across a.
			// transport timeout and changes for the next legitimate partial refund.
			$existing_refunds  = method_exists( $order, 'get_refunds' ) ? $order->get_refunds() : array();
			$refund_sequence   = count( (array) $existing_refunds ) + 1;
			$refund_amount     = null === $amount ? 'full' : number_format( (float) $amount, 2, '.', '' );
			$refund_request_id = $this->get_paypal_request_id(
				'refund',
				'order:' . (int) $order->get_id() . '|sequence:' . $refund_sequence . '|amount:' . $refund_amount
			);

			$args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization'     => 'Bearer ' . $access_token,
					'Content-Type'      => 'application/json',
					'PayPal-Request-Id' => $refund_request_id,
				),
				'body'    => wp_json_encode( $body ),
			);

			$response      = wp_remote_post( $url, $args );
			$response_code = (int) wp_remote_retrieve_response_code( $response );
			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( 201 === $response_code ) {
				$refund_id = $response_data->id ?? '';
				$order->add_order_note(
					sprintf(
						// translators: %s: PayPal refund ID.
						__( 'PayPal refund initiated. Refund ID: %s', 'subscription' ),
						$refund_id
					)
				);
				return true;
			}

			$error_message = $response_data->message ?? $response_data->error_description ?? 'Unknown error';
			$log_message   = 'PayPal refund failed: ' . $error_message;
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message . ' ' . $this->gateway_response_debug_context( $response_data ) );

			return new \WP_Error( 'paypal_refund_failed', $error_message );

		} catch ( \Exception $e ) {
			$log_message = 'PayPal refund exception: ' . $e->getMessage();
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return new \WP_Error( 'paypal_refund_exception', $e->getMessage() );
		}
	}

	/**
	 * Cancel PayPal subscription.
	 *
	 * @param string $subscription_id PayPal Subscription ID.
	 * @param string $access_token    PayPal Access Token.
	 * @param string $reason          Reason for cancellation.
	 */
	private function cancel_paypal_subscription( string $subscription_id, string $access_token, string $reason = 'admin cancel' ): bool {
		// Prepare the body for the API request.
		$body = array(
			'reason' => $reason,
		);

		try {
			$url = $this->api_endpoint . "/v1/billing/subscriptions/$subscription_id/cancel";

			$args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			);

			$response      = wp_remote_post( $url, $args );
			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response_data->message ?? null ) ) {
				$log_message = 'Error cancelling PayPal subscription: ' . ( $response_data->message ?? 'Unknown error' );
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message . ' ' . $this->gateway_response_debug_context( $response_data ) );
				return false;
			}

			return true;
		} catch ( \Exception $e ) {
			$log_message = 'Error cancelling PayPal subscription: ' . $e->getMessage();
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return false;
		}
	}

	/**
	 * Get PayPal order details.
	 *
	 * @param string $order_id      PayPal Order ID.
	 */
	public function get_paypal_order( string $order_id ) {
		// Get PayPal Access Token.
		$access_token = $this->get_paypal_access_token();
		if ( ! $access_token ) {
			subscrpt_write_log( 'Failed to get PayPal order; Access Token unavailable.' );
			return false;
		}

		try {
			$url  = $this->api_endpoint . "/v2/checkout/orders/$order_id";
			$args = array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
			);

			$response      = wp_remote_get( $url, $args );
			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response_data->id ?? null ) ) {
				$log_message = 'Error getting PayPal order: ' . ( $response_data->error_description ?? $response_data->message ?? 'Unknown error' );
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message . ' ' . $this->gateway_response_debug_context( $response_data ) );
				return null;
			}

			return $response_data;
		} catch ( \Exception $e ) {
			$log_message = 'Failed to get PayPal order; ' . $e->getMessage();
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return false;
		}
	}

	/**
	 * Get PayPal subscription details.
	 *
	 * @param string $subscription_id PayPal Subscription ID.
	 */
	public function get_paypal_subscription( string $subscription_id ): ?object {
		// Get PayPal Access Token.
		$access_token = $this->get_paypal_access_token();
		if ( ! $access_token ) {
			subscrpt_write_log( 'Failed to get PayPal Subscription; Access Token unavailable.' );
			return null;
		}

		try {
			$url  = $this->api_endpoint . "/v1/billing/subscriptions/$subscription_id";
			$args = array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
			);

			$response      = wp_remote_get( $url, $args );
			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response_data->id ?? null ) ) {
				$log_message = 'Error getting PayPal subscription: ' . ( $response_data->error_description ?? $response_data->message ?? 'Unknown error' );
				subscrpt_write_log( $log_message );
				subscrpt_write_debug_log( $log_message . ' ' . $this->gateway_response_debug_context( $response_data ) );
				return null;
			}

			return $response_data;
		} catch ( \Exception $e ) {
			$log_message = 'Failed to get PayPal subscription; ' . $e->getMessage();
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return null;
		}
	}

	// * -------------------- API Operations [end] -------------------- * //.
	// * -------------------------------------------------------------- * //.
}

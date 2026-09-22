<?php
/**
 * Disposable WordPress/WooCommerce security-boundary integration checks.
 *
 * Run only inside wp-env with: npm run test:integration
 *
 * @package AshbiSubscriptions
 */

use SpringDevs\Subscription\Ajax;
use SpringDevs\Subscription\Api\DiagnosticsController;
use SpringDevs\Subscription\Frontend\ActionController;
use SpringDevs\Subscription\Illuminate\Action;
use SpringDevs\Subscription\Illuminate\AutoRenewal;
use SpringDevs\Subscription\Illuminate\GuestCheckout;
use SpringDevs\Subscription\Illuminate\Helper;
use SpringDevs\Subscription\Illuminate\Plans\PlanRepository;
use SpringDevs\Subscription\Illuminate\Stats;
use SpringDevs\Subscription\Illuminate\Switching;

add_action( 'admin_post_ashbi_security_boundaries', 'ashbi_run_security_boundary_integration_checks' );

/**
 * Run the disposable integration assertions and return JSON.
 *
 * @return void
 */
function ashbi_run_security_boundary_integration_checks() {
	try {
		$host                   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$environment_type       = (string) wp_get_environment_type();
		$staging_runner_enabled = 'staging' === $environment_type && '1' === (string) getenv( 'ASHBI_ALLOW_STAGING_INTEGRATION' );
		if ( ! in_array( $environment_type, array( 'local', 'development' ), true ) && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) && ! $staging_runner_enabled ) {
			wp_die( '', '', array( 'response' => 404 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		$administrator_id = get_current_user_id();

		$failures = array();
		$check    = static function ( $condition, string $message ) use ( &$failures ): void {
			if ( ! $condition ) {
				$failures[] = $message;
			}
		};

		$subscription_type = get_post_type_object( 'subscrpt_order' );
		$item_type         = get_post_type_object( 'subscrpt_order_item' );
		$check( $subscription_type && ! $subscription_type->show_in_rest, 'Subscription records are exposed through core REST.' );
		$check( $item_type && ! $item_type->show_in_rest, 'Subscription item records are exposed through core REST.' );
		$plugin_bootstrap = sdevs_subscription();
		$check( isset( $plugin_bootstrap->hpos_compatibility_declared ) && true === $plugin_bootstrap->hpos_compatibility_declared, 'WooCommerce HPOS compatibility was not declared before initialization.' );
		$check( false !== has_action( 'woocommerce_blocks_cart_block_registration' ), 'WooCommerce cart block integration was not registered.' );
		$check( false !== has_action( 'woocommerce_blocks_checkout_block_registration' ), 'WooCommerce checkout block integration was not registered.' );
		$check( class_exists( 'Sdevs_Subscrpt_WC_Integration' ), 'WooCommerce block integration class was not loaded.' );
		if ( class_exists( 'Sdevs_Subscrpt_WC_Integration' ) ) {
			$block_integration = new \Sdevs_Subscrpt_WC_Integration();
			$check( array( 'sdevs_subscrpt_cart_block' ) === $block_integration->get_script_handles(), 'WooCommerce block integration omitted the frontend script.' );
			$check( array( 'sdevs_subscrpt_cart_block' ) === $block_integration->get_editor_script_handles(), 'WooCommerce block integration omitted the editor script.' );
		}

		$hpos_mode             = '';
		$previous_hpos_setting = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The local-only runner uses this fabricated mode selector; the route remains administrator-gated.
		if ( isset( $_GET['ashbi_hpos'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The local-only runner uses this fabricated mode selector; the route remains administrator-gated.
			$requested_hpos = sanitize_key( wp_unslash( $_GET['ashbi_hpos'] ) );
			if ( in_array( $requested_hpos, array( 'on', 'off' ), true ) ) {
				$hpos_mode             = $requested_hpos;
				$previous_hpos_setting = get_option( 'woocommerce_custom_orders_table_enabled', null );
				update_option( 'woocommerce_custom_orders_table_enabled', 'on' === $hpos_mode ? 'yes' : 'no', false );
				$check(
					function_exists( 'wps_subscription_is_wc_order_hpos_enabled' )
						&& ( 'on' === $hpos_mode ) === wps_subscription_is_wc_order_hpos_enabled(),
					'WooCommerce HPOS mode did not match the requested disposable matrix mode.'
				);
			}
		}

		$ajax          = new Ajax();
		$subscriber    = get_user_by( 'login', 'ashbi-boundary-subscriber' );
		$subscriber_id = $subscriber ? $subscriber->ID : wp_insert_user(
			array(
				'user_login' => 'ashbi-boundary-subscriber',
				'user_email' => 'ashbi-boundary-subscriber@example.test',
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'subscriber',
			)
		);
		$check( ! is_wp_error( $subscriber_id ), 'Could not create the disposable Subscriber.' );
		wp_set_current_user( (int) $subscriber_id );
		$subscriber_nonce = wp_create_nonce( 'subscrpt_install_woocommerce_plugin' );
		$check( ! $ajax->can_manage_dependency( 'install_plugins', 'subscrpt_install_woocommerce_plugin', $subscriber_nonce ), 'Subscriber passed dependency-install authorization.' );

		wp_set_current_user( $administrator_id );
		$install_nonce  = wp_create_nonce( 'subscrpt_install_woocommerce_plugin' );
		$activate_nonce = wp_create_nonce( 'subscrpt_activate_woocommerce_plugin' );
		$check( $ajax->can_manage_dependency( 'install_plugins', 'subscrpt_install_woocommerce_plugin', $install_nonce ), 'Administrator failed valid install authorization.' );
		$check( $ajax->can_manage_dependency( 'activate_plugins', 'subscrpt_activate_woocommerce_plugin', $activate_nonce ), 'Administrator failed valid activation authorization.' );
		$check( ! $ajax->can_manage_dependency( 'install_plugins', 'subscrpt_install_woocommerce_plugin', 'invalid' ), 'Invalid dependency nonce was accepted.' );

		// Register the normal REST hooks in this admin-post request so the disposable.
		// site exercises the same versioned diagnostics route that a real REST request.
		// would receive.
		do_action( 'rest_api_init' );
		$diagnostics_route = rest_get_server()->get_routes()['/wpsubscription/v1/diagnostics'] ?? array();
		$check( ! empty( $diagnostics_route ), 'Versioned diagnostics REST route was not registered.' );
		$diagnostics_response = rest_do_request( new WP_REST_Request( 'GET', '/wpsubscription/v1/diagnostics' ) );
		$check( ! $diagnostics_response->is_error(), 'Diagnostics REST route returned an error.' );
		$diagnostics_data = $diagnostics_response->get_data();
		$check( isset( $diagnostics_data['subscriptions']['status_counts'] ), 'Diagnostics response omitted status counts.' );
		$check( isset( $diagnostics_data['migration']['blocked'] ), 'Diagnostics response omitted migration state.' );
		$diagnostics_json = wp_json_encode( $diagnostics_data );
		$check( false === strpos( $diagnostics_json, 'webhook_secret' ), 'Diagnostics response exposed a webhook secret field.' );
		$check( false === strpos( $diagnostics_json, '_stripe_secret' ), 'Diagnostics response exposed a Stripe secret field.' );
		$check( false === strpos( $diagnostics_json, 'customer_email' ), 'Diagnostics response exposed a customer email field.' );

		$plan_routes = rest_get_server()->get_routes();
		$check( ! empty( $plan_routes['/wpsubscription/v1/plans/groups'] ), 'Plan group REST route was not registered.' );
		$check( ! empty( $plan_routes['/wpsubscription/v1/plans/terms'] ), 'Plan term REST route was not registered.' );
		$check( ! empty( $plan_routes['/wpsubscription/v1/plans/relations'] ), 'Plan relation REST route was not registered.' );
		$groups_response = rest_do_request( new WP_REST_Request( 'GET', '/wpsubscription/v1/plans/groups' ) );
		$check( ! $groups_response->is_error(), 'Admin plan group REST route returned an error.' );
		$check( 3 === PlanRepository::type_to_int( 'installments' ), 'Installment plan type did not map to its canonical storage value.' );
		$check( 'months' === PlanRepository::interval_to_option( 3 ), 'Monthly plan interval did not map to the legacy billing unit.' );

		$variable_product = new \WC_Product_Variable();
		$variable_product->set_name( 'Ashbi fabricated variable plan product' );
		$variable_product->set_status( 'publish' );
		$variable_product->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $variable_product->get_id() );
		$variation->set_regular_price( '24.00' );
		$variation->set_price( '24.00' );
		$variation->save();
		$plan_group_id  = PlanRepository::insert_group(
			array(
				'title'        => 'Ashbi fabricated installments',
				'type'         => 'installments',
				'product_type' => 0,
				'status'       => 'active',
			)
		);
		$plan_id        = PlanRepository::insert_plan(
			array(
				'plan_group_id'     => $plan_group_id,
				'title'             => 'Three monthly payments',
				'type'              => 'installments',
				'billing_frequency' => 1,
				'billing_interval'  => 3,
				'billing_length'    => 3,
				'signup_fee'        => array( 'amount' => '5.00' ),
				'data'              => array( 'installment_count' => 3 ),
				'status'            => 'active',
			)
		);
		$relation_id    = PlanRepository::insert_relation(
			array(
				'plan_id' => $plan_id,
				'oid'     => $variable_product->get_id(),
				'vid'     => $variation->get_id(),
				'type'    => PlanRepository::REL_PRODUCT,
				'data'    => array(
					'price'          => '24.00',
					'regular_price'  => '24.00',
					'signup_fee'     => '5.00',
					'max_no_payment' => 3,
				),
			)
		);
		$resolved_plans = PlanRepository::resolve_for_product( $variable_product->get_id(), $variation->get_id() );
		$check( $plan_group_id && $plan_id && $relation_id, 'Could not create the fabricated variable installment plan.' );
		$check( 1 === count( $resolved_plans ), 'Variable variation plan resolution returned the wrong number of plans.' );
		$check( $resolved_plans && (int) $resolved_plans[0]['vid'] === $variation->get_id(), 'Variable variation relation did not resolve to its concrete variation.' );
		$check( $resolved_plans && '5.00' === (string) $resolved_plans[0]['relation_data']['signup_fee'], 'Signup fee was not preserved in the plan relation snapshot.' );
		$check( $resolved_plans && 3 === (int) $resolved_plans[0]['relation_data']['max_no_payment'], 'Installment count was not preserved in the plan relation snapshot.' );
		$check( empty( PlanRepository::resolve_for_product( $variable_product->get_id() ) ), 'Variation-only plan leaked into the variable parent selector.' );

		// Exercise the classic cart transformation with fabricated data only. This.
		// runs the registered WooCommerce filter, resolves the concrete variation.
		// term, applies the split price, adds the one-time signup fee, and snapshots.
		// the selected plan onto an in-memory order line item without creating or.
		// charging an order.
		$plan_checkout = new \SpringDevs\Subscription\Frontend\PlanCheckout();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Synthetic request state is scoped to this disposable integration assertion.
		$had_plan_request = array_key_exists( 'subscrpt_plan_id', $_REQUEST );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Synthetic request state is scoped to this disposable integration assertion.
		$previous_plan_id             = $had_plan_request ? $_REQUEST['subscrpt_plan_id'] : null;
		$_REQUEST['subscrpt_plan_id'] = (string) $plan_id;
		try {
			$cart_item_data = apply_filters(
				'woocommerce_add_cart_item_data',
				array(),
				$variable_product->get_id(),
				$variation->get_id(),
				1
			);
		} finally {
			if ( $had_plan_request ) {
				$_REQUEST['subscrpt_plan_id'] = $previous_plan_id;
			} else {
				unset( $_REQUEST['subscrpt_plan_id'] );
			}
		}
		$check( (int) ( $cart_item_data['subscrpt_plan_id'] ?? 0 ) === (int) $plan_id, 'Classic cart filter did not stamp the selected plan.' );
		$check( 'split_payment' === (string) ( $cart_item_data['subscrpt_payment_type'] ?? '' ), 'Classic cart filter did not preserve installment payment type.' );
		$check( 8.0 === (float) ( $cart_item_data['subscrpt_plan_price'] ?? 0 ), 'Classic cart filter did not split the installment price.' );
		$check( 5.0 === (float) ( $cart_item_data['subscrpt_signup_fee'] ?? 0 ), 'Classic cart filter did not preserve the signup fee.' );
		$blocks_cart      = new \SpringDevs\Subscription\Frontend\Cart();
		$blocks_item_data = $blocks_cart->extend_cart_item_data(
			array_merge(
				$cart_item_data,
				array(
					'quantity' => 1,
					'data'     => $variation,
				)
			)
		);
		$check( 'month' === (string) ( $blocks_item_data['type'] ?? '' ), 'Blocks Store API payload did not normalize the plan cadence.' );
		$check( 8.0 === (float) ( $blocks_item_data['cost'] ?? 0 ), 'Blocks Store API payload did not expose the recurring installment cost.' );
		$check( 5.0 === (float) ( $blocks_item_data['signup_fee'] ?? 0 ), 'Blocks Store API payload did not expose the signup fee.' );
		$check( 3 === (int) ( $blocks_item_data['max_no_payment'] ?? 0 ), 'Blocks Store API payload did not expose the installment count.' );
		$cart_item_data['data']     = $variation;
		$cart_item_data['quantity'] = 1;
		$fake_cart                  = new class( $cart_item_data ) {
			/**
			 * Fabricated cart items.
			 *
			 * @var array
			 */
			private $items;

			/**
			 * Fabricated cart fees.
			 *
			 * @var array
			 */
			private $fees = array();

			/**
			 * Store a fabricated cart item.
			 *
			 * @param array $item Fabricated cart item.
			 */
			public function __construct( $item ) {
				$this->items = array( 'ashbi-fabricated' => $item );
			}

			/**
			 * Return fabricated cart items.
			 *
			 * @return array
			 */
			public function get_cart() {
				return $this->items;
			}

			/**
			 * Record a fabricated fee.
			 *
			 * @param string $name      Fee name.
			 * @param float  $amount    Fee amount.
			 * @param bool   $taxable   Whether the fee is taxable.
			 * @param string $tax_class Fee tax class.
			 *
			 * @return void
			 */
			public function add_fee( $name, $amount = 0, $taxable = false, $tax_class = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- compact() consumes these named arguments.
				$this->fees[] = compact( 'name', 'amount', 'taxable', 'tax_class' );
			}

			/**
			 * Return fabricated cart fees.
			 *
			 * @return array
			 */
			public function get_fees() {
				return $this->fees;
			}
		};
		$plan_checkout->set_cart_item_price( $fake_cart );
		$plan_checkout->add_signup_fee( $fake_cart );
		$check( 8.0 === (float) $variation->get_price(), 'Classic cart calculation did not apply the installment line price.' );
		$fees = $fake_cart->get_fees();
		$check( 1 === count( $fees ) && 5.0 === (float) $fees[0]['amount'], 'Classic cart calculation did not add the one-time signup fee.' );
		$order_line = new \WC_Order_Item_Product();
		$plan_checkout->save_plan_order_item( $order_line, 'ashbi-fabricated', $cart_item_data );
		$check( (int) $plan_id === (int) $order_line->get_meta( '_subscrpt_plan_id' ), 'Classic order-line snapshot omitted the plan id.' );
		$check( 3 === (int) $order_line->get_meta( '_subscrpt_max_no_payment' ), 'Classic order-line snapshot omitted installment count.' );
		$check( 5.0 === (float) $order_line->get_meta( '_subscrpt_signup_fee' ), 'Classic order-line snapshot omitted signup fee.' );
		$plan_order = wc_create_order(
			array(
				'customer_id' => 1,
				'status'      => 'pending',
			)
		);
		$check( $plan_order instanceof \WC_Order, 'Could not create the disposable unpaid plan order.' );
		$plan_subscription_id = 0;
		if ( $plan_order instanceof \WC_Order ) {
			$plan_order_item_id = $plan_order->add_product( $variation, 1 );
			$plan_order->calculate_totals( false );
			$plan_order->save();
			$plan_order_item = $plan_order->get_item( $plan_order_item_id );
			$check( $plan_order_item instanceof \WC_Order_Item_Product, 'Could not load the disposable plan order line.' );
			if ( $plan_order_item instanceof \WC_Order_Item_Product ) {
				$plan_checkout->save_plan_order_item( $plan_order_item, (string) $plan_order_item_id, $cart_item_data );
				$plan_order_item->save();
				try {
					$plan_checkout->create_plan_subscription( $plan_order_item, $variation, 'active' );
					$plan_subscription_id = (int) $plan_order_item->get_meta( '_subscrpt_subscription_id' );
				} catch ( \Throwable $error ) {
					$failures[] = 'Plan checkout subscription creation raised ' . get_class( $error ) . ': ' . $error->getMessage();
				}
				global $wpdb;
				$plan_subscription_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT subscription_id FROM {$wpdb->prefix}subscrpt_order_relation WHERE order_id = %d AND order_item_id = %d AND type = %s ORDER BY id DESC LIMIT 1",
						$plan_order->get_id(),
						$plan_order_item_id,
						'new'
					)
				);
				$check( $plan_subscription_id > 0, 'Plan checkout did not create a subscription snapshot.' );
				if ( $plan_subscription_id > 0 ) {
					$check( (int) get_post_meta( $plan_subscription_id, '_subscrpt_plan_id', true ) === (int) $plan_id, 'Plan subscription snapshot omitted the selected plan.' );
					$check( $variation->get_id() === (int) get_post_meta( $plan_subscription_id, '_subscrpt_variation_id', true ), 'Plan subscription snapshot omitted the concrete variation.' );
					$check( 'split_payment' === (string) get_post_meta( $plan_subscription_id, '_subscrpt_payment_type', true ), 'Plan subscription snapshot omitted installment payment type.' );
					$check( 3 === (int) get_post_meta( $plan_subscription_id, '_subscrpt_max_no_payment', true ), 'Plan subscription snapshot omitted installment limit.' );
					$check( 5.0 === (float) get_post_meta( $plan_subscription_id, '_subscrpt_signup_fee', true ), 'Plan subscription snapshot omitted signup fee.' );
					$check( 24.0 === (float) get_post_meta( $plan_subscription_id, '_subscrpt_split_total', true ), 'Plan subscription snapshot omitted the original split total.' );
					$check( 8.0 === (float) get_post_meta( $plan_subscription_id, '_subscrpt_price', true ), 'Plan subscription snapshot omitted the per-installment price.' );
				}
			}
			$plan_order->delete( true );
		}
		if ( $plan_subscription_id > 0 ) {
			wp_delete_post( $plan_subscription_id, true );
		}
		PlanRepository::delete_group( (int) $plan_group_id );
		if ( '' !== $hpos_mode ) {
			if ( null === $previous_hpos_setting ) {
				delete_option( 'woocommerce_custom_orders_table_enabled' );
			} else {
				update_option( 'woocommerce_custom_orders_table_enabled', $previous_hpos_setting, false );
			}
		}

		// The activation request should leave durable schema markers behind. These.
		// checks prove the candidate was actually activated in the disposable site,.
		// rather than merely loaded for this request.
		$check( (int) get_option( 'subscrpt_installed', 0 ) > 0, 'Activation did not persist the installed marker.' );
		$check( '1.5.0' === (string) get_option( 'subscrpt_db_version', '' ), 'Activation did not persist the current schema version.' );

		$customer    = get_user_by( 'login', 'ashbi-existing-customer' );
		$customer_id = $customer ? $customer->ID : wp_insert_user(
			array(
				'user_login' => 'ashbi-existing-customer',
				'user_email' => 'ashbi-existing-customer@example.test',
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'customer',
			)
		);
		$check( ! is_wp_error( $customer_id ), 'Could not create the disposable Customer.' );
		$previous_guest_setting = get_option( 'wp_subscription_allow_guest_checkout', '0' );
		update_option( 'wp_subscription_allow_guest_checkout', '1' );
		$guest = new GuestCheckout();
		wp_set_current_user( 0 );
		$bound_id = $guest->maybe_create_user( array( 'billing_email' => 'ashbi-existing-customer@example.test' ) );
		$check( null === $bound_id, 'Existing email bound an unauthenticated guest to a customer account.' );
		wp_set_current_user( (int) $customer_id );
		$bound_id = $guest->maybe_create_user( array( 'billing_email' => 'ashbi-existing-customer@example.test' ) );
		$check( (int) $customer_id === (int) $bound_id, 'Authenticated customer could not retain their own account binding.' );
		update_option( 'wp_subscription_allow_guest_checkout', $previous_guest_setting );

		$product_id      = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Ashbi boundary product',
			)
		);
		$subscription_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'pe_cancelled',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi boundary subscription',
			)
		);
		update_post_meta( $subscription_id, '_subscrpt_product_id', $product_id );
		update_post_meta( $subscription_id, '_subscrpt_cancel_at', time() + HOUR_IN_SECONDS );
		$check( ActionController::can_reactivate_subscription( $subscription_id, time() ), 'Valid pending cancellation could not be reactivated.' );

		// Exercise customer payment-method replacement using only fabricated Woo
		// payment-token records. The controller must accept the owner's token,
		// reject another user's token, and update only the canonical order's
		// gateway reference; it never receives raw card data.
		$payment_source_order         = null;
		$payment_subscription_id      = 0;
		$payment_token                = null;
		$payment_foreign_token        = null;
		$payment_unsupported_token    = null;
		$payment_token_id             = 0;
		$payment_foreign_token_id     = 0;
		$payment_unsupported_token_id = 0;
		$previous_stripe_customer     = get_user_option( '_stripe_customer_id', (int) $customer_id );
		$stripe_token_sync_filter     = null;
		global $wp_filter;
		$token_filter_hook = $wp_filter['woocommerce_get_customer_payment_tokens'] ?? null;
		if ( $token_filter_hook instanceof \WP_Hook && isset( $token_filter_hook->callbacks[10] ) ) {
			foreach ( $token_filter_hook->callbacks[10] as $registered_callback ) {
				$callback = $registered_callback['function'] ?? null;
				if ( is_array( $callback ) && is_object( $callback[0] ?? null ) && is_a( $callback[0], 'WC_Stripe_Payment_Tokens' ) ) {
					$stripe_token_sync_filter = $callback;
					remove_filter( 'woocommerce_get_customer_payment_tokens', $stripe_token_sync_filter, 10 );
					break;
				}
			}
		}
		$create_payment_token = static function ( string $gateway_id, string $token_value, int $user_id ): ?\WC_Payment_Token {
			global $wpdb;
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'woocommerce_payment_tokens',
				array(
					'gateway_id' => $gateway_id,
					'token'      => $token_value,
					'user_id'    => $user_id,
					'type'       => 'CC',
					'is_default' => 0,
				),
				array( '%s', '%s', '%d', '%s', '%d' )
			);
			$token_id = false !== $inserted ? (int) $wpdb->insert_id : 0;

			return $token_id > 0 ? \WC_Payment_Tokens::get( $token_id ) : null;
		};
		try {
			$payment_token    = $create_payment_token( 'stripe', 'pm_ashbiboundary4242', (int) $customer_id );
			$payment_token_id = $payment_token instanceof \WC_Payment_Token ? (int) $payment_token->get_id() : 0;
			$check( $payment_token_id > 0, 'Could not create the fabricated customer payment token.' );
			$loaded_payment_token = $payment_token_id > 0 ? \WC_Payment_Tokens::get( $payment_token_id ) : null;
			$check( $loaded_payment_token instanceof \WC_Payment_Token && (int) $loaded_payment_token->get_user_id() === (int) $customer_id, 'WooCommerce did not reload the fabricated customer token for its owner.' );
			$check( $loaded_payment_token instanceof \WC_Payment_Token && 'stripe' === (string) $loaded_payment_token->get_gateway_id(), 'WooCommerce did not preserve the fabricated token gateway.' );
			$payment_token = $loaded_payment_token;

			$payment_foreign_token    = $create_payment_token( 'stripe', 'pm_ashbiforeign4242', 1 );
			$payment_foreign_token_id = $payment_foreign_token instanceof \WC_Payment_Token ? (int) $payment_foreign_token->get_id() : 0;
			$check( $payment_foreign_token_id > 0, 'Could not create the fabricated foreign payment token.' );

			$payment_unsupported_token    = $create_payment_token( 'stripe_cc', 'pm_ashbicc4242', (int) $customer_id );
			$payment_unsupported_token_id = $payment_unsupported_token instanceof \WC_Payment_Token ? (int) $payment_unsupported_token->get_id() : 0;
			$check( $payment_unsupported_token_id > 0, 'Could not create the fabricated unsupported-gateway payment token.' );

			$payment_source_order   = wc_create_order(
				array(
					'customer_id' => (int) $customer_id,
					'status'      => 'pending',
				)
			);
			$payment_source_item_id = $payment_source_order instanceof \WC_Order ? $payment_source_order->add_product( $variation, 1 ) : 0;
			if ( $payment_source_order instanceof \WC_Order ) {
				$payment_source_order->set_payment_method( 'stripe' );
				$payment_source_order->set_payment_method_title( 'Visa ending in 0000' );
				$payment_source_order->update_meta_data( '_stripe_customer_id', 'cus_ashbi_boundary' );
				$payment_source_order->update_meta_data( '_stripe_source_id', 'pm_ashbi_old_0000' );
				$payment_source_order->calculate_totals( false );
				$payment_source_order->set_status( 'completed' );
				$payment_source_order->save();
			}
			update_user_option( (int) $customer_id, '_stripe_customer_id', 'cus_ashbi_boundary', false );
			$payment_subscription_id = wp_insert_post(
				array(
					'post_type'   => 'subscrpt_order',
					'post_status' => 'active',
					'post_author' => (int) $customer_id,
					'post_title'  => 'Ashbi fabricated payment-method subscription',
				)
			);
			update_post_meta( $payment_subscription_id, '_subscrpt_product_id', $variable_product->get_id() );
			update_post_meta( $payment_subscription_id, '_subscrpt_order_id', $payment_source_order instanceof \WC_Order ? $payment_source_order->get_id() : 0 );
			update_post_meta( $payment_subscription_id, '_subscrpt_order_item_id', $payment_source_item_id );
			update_post_meta( $payment_subscription_id, '_subscrpt_price', '24.00' );
			$payment_context = \SpringDevs\Subscription\Frontend\PaymentMethodController::get_subscription_context( (int) $payment_subscription_id, (int) $customer_id );
			$check( is_array( $payment_context ) && 1 === count( $payment_context['tokens'] ?? array() ), 'Customer payment-method context did not expose the owner token.' );
			$check( $payment_token instanceof \WC_Payment_Token && (int) $payment_token->get_id() === (int) $payment_token_id && (int) $payment_token->get_user_id() === (int) $customer_id && 'stripe' === (string) $payment_token->get_gateway_id(), 'Payment-method fixture did not retain the owner token identity.' );
			$service_loaded_token = \WC_Payment_Tokens::get( (int) $payment_token_id );
			$check( $service_loaded_token instanceof \WC_Payment_Token, 'Payment-method fixture could not reload the token immediately before the controller call.' );
			$payment_result = \SpringDevs\Subscription\Frontend\PaymentMethodController::change_subscription_payment_method( (int) $payment_subscription_id, (int) $payment_token_id, (int) $customer_id );
			$check( true === $payment_result, 'Customer payment-method replacement rejected the owner token.' );
			$payment_source_order = $payment_source_order instanceof \WC_Order ? wc_get_order( $payment_source_order->get_id() ) : false;
			$check( $payment_source_order instanceof \WC_Order && 'pm_ashbiboundary4242' === (string) $payment_source_order->get_meta( '_stripe_source_id' ), 'Customer payment-method replacement did not update the canonical Stripe source.' );
			$repeat_payment_result = \SpringDevs\Subscription\Frontend\PaymentMethodController::change_subscription_payment_method( (int) $payment_subscription_id, (int) $payment_token_id, (int) $customer_id );
			$check( true === $repeat_payment_result, 'Repeating the selected payment method was not idempotent.' );
			$foreign_result = \SpringDevs\Subscription\Frontend\PaymentMethodController::change_subscription_payment_method( (int) $payment_subscription_id, (int) $payment_foreign_token_id, (int) $customer_id );
			$check( is_wp_error( $foreign_result ), 'Customer payment-method replacement accepted another user\'s token.' );
			$unsupported_result = \SpringDevs\Subscription\Frontend\PaymentMethodController::change_subscription_payment_method( (int) $payment_subscription_id, (int) $payment_unsupported_token_id, (int) $customer_id );
			$check( is_wp_error( $unsupported_result ), 'Customer payment-method replacement accepted an unsupported gateway token.' );
		} finally {
			if ( $payment_source_order instanceof \WC_Order ) {
				$payment_source_order->delete( true );
			}
			if ( $payment_subscription_id > 0 ) {
				wp_delete_post( $payment_subscription_id, true );
			}
			if ( $payment_token instanceof \WC_Payment_Token && $payment_token->get_id() ) {
				$payment_token->delete( true );
			}
			if ( $payment_foreign_token instanceof \WC_Payment_Token && $payment_foreign_token->get_id() ) {
				$payment_foreign_token->delete( true );
			}
			if ( $payment_unsupported_token instanceof \WC_Payment_Token && $payment_unsupported_token->get_id() ) {
				$payment_unsupported_token->delete( true );
			}
			if ( false === $previous_stripe_customer ) {
				delete_user_option( (int) $customer_id, '_stripe_customer_id' );
			} else {
				update_user_option( (int) $customer_id, '_stripe_customer_id', $previous_stripe_customer, false );
			}
			if ( is_array( $stripe_token_sync_filter ) ) {
				add_filter( 'woocommerce_get_customer_payment_tokens', $stripe_token_sync_filter, 10, 3 );
			}
		}

		foreach ( array( 'cancelled', 'expired', 'completed' ) as $terminal_status ) {
			wp_update_post(
				array(
					'ID'          => $subscription_id,
					'post_status' => $terminal_status,
				)
			);
			$check( ! ActionController::can_reactivate_subscription( $subscription_id, time() ), "Terminal {$terminal_status} subscription could be reactivated." );
		}

		wp_update_post(
			array(
				'ID'          => $subscription_id,
				'post_status' => 'pe_cancelled',
			)
		);
		update_post_meta( $subscription_id, '_subscrpt_cancel_at', time() - 1 );
		$check( ! ActionController::can_reactivate_subscription( $subscription_id, time() ), 'Elapsed pending cancellation could be reactivated.' );

		// Exercise the real customer lifecycle transitions with a separate fabricated
		// subscription. No gateway, payment method, or customer-facing request is used.
		$lifecycle_subscription_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'active',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi fabricated lifecycle subscription',
			)
		);
		update_post_meta( $lifecycle_subscription_id, '_subscrpt_product_id', $product_id );
		update_post_meta( $lifecycle_subscription_id, '_subscrpt_next_date', time() + DAY_IN_SECONDS );
		$lifecycle_warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Integration assertion deliberately captures warnings from malformed legacy lifecycle data.
		set_error_handler(
			static function ( $severity, $message, $file, $line ) use ( &$lifecycle_warnings ) {
				$lifecycle_warnings[] = sprintf( '%s (%s:%d)', $message, $file, $line );
				return true;
			}
		);
		try {
			$check( Helper::pause_subscription( (int) $lifecycle_subscription_id ), 'Active fabricated subscription could not be paused.' );
			$check( 'on_hold' === get_post_status( $lifecycle_subscription_id ), 'Pause did not persist the on-hold subscription status.' );
			$check( 'manual' === get_post_meta( $lifecycle_subscription_id, '_subscrpt_hold_reason', true ), 'Pause did not persist the manual hold reason.' );
			$check( Helper::resume_subscription( (int) $lifecycle_subscription_id ), 'Manually paused subscription could not be resumed.' );
			$check( 'active' === get_post_status( $lifecycle_subscription_id ), 'Resume did not restore the active subscription status.' );
			$check( '' === (string) get_post_meta( $lifecycle_subscription_id, '_subscrpt_hold_reason', true ), 'Resume left the manual hold reason behind.' );
			Action::status( 'pe_cancelled', (int) $lifecycle_subscription_id );
			$scheduled_cancel_at = (int) get_post_meta( $lifecycle_subscription_id, '_subscrpt_cancel_at', true );
			$check( $scheduled_cancel_at > time(), 'Scheduled cancellation did not persist a future cancellation timestamp.' );
			$check( ActionController::can_reactivate_subscription( (int) $lifecycle_subscription_id, time() ), 'Scheduled cancellation could not be reactivated before its due time.' );
			Action::status( 'active', (int) $lifecycle_subscription_id );
			$check( 'active' === get_post_status( $lifecycle_subscription_id ), 'Reactivation did not restore the scheduled subscription.' );
			$check( '' === (string) get_post_meta( $lifecycle_subscription_id, '_subscrpt_cancel_at', true ), 'Reactivation left the scheduled cancellation timestamp behind.' );
		} finally {
			restore_error_handler();
		}
		$check( empty( $lifecycle_warnings ), 'Malformed lifecycle subscription emitted PHP warnings: ' . implode( '; ', $lifecycle_warnings ) );
		wp_delete_post( $lifecycle_subscription_id, true );

		// Exercise early renewal against a completed fabricated source order. The
		// duplicate request must reuse the same pending order and leave access active.
		global $wpdb;
		$early_source_order   = wc_create_order(
			array(
				'customer_id' => (int) $customer_id,
				'status'      => 'pending',
			)
		);
		$early_source_item_id = $early_source_order instanceof \WC_Order ? $early_source_order->add_product( $variation, 1 ) : 0;
		if ( $early_source_order instanceof \WC_Order ) {
			$early_source_order->calculate_totals( false );
			$early_source_order->set_date_paid( time() );
			$early_source_order->set_status( 'completed' );
			$early_source_order->save();
		}
		wc_update_order_item_meta(
			$early_source_item_id,
			'_subscrpt_meta',
			array(
				'time'  => 1,
				'type'  => 'month',
				'trial' => null,
			)
		);
		$early_subscription_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'active',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi fabricated early-renewal subscription',
			)
		);
		update_post_meta( $early_subscription_id, '_subscrpt_product_id', $variable_product->get_id() );
		update_post_meta( $early_subscription_id, '_subscrpt_variation_id', $variation->get_id() );
		update_post_meta( $early_subscription_id, '_subscrpt_order_id', $early_source_order instanceof \WC_Order ? $early_source_order->get_id() : 0 );
		update_post_meta( $early_subscription_id, '_subscrpt_order_item_id', $early_source_item_id );
		update_post_meta( $early_subscription_id, '_subscrpt_price', '24.00' );
		update_post_meta( $early_subscription_id, '_subscrpt_next_date', time() + DAY_IN_SECONDS );
		$early_relation_inserted = $wpdb->insert(
			$wpdb->prefix . 'subscrpt_order_relation',
			array(
				'subscription_id' => $early_subscription_id,
				'order_id'        => $early_source_order instanceof \WC_Order ? $early_source_order->get_id() : 0,
				'order_item_id'   => $early_source_item_id,
				'type'            => 'new',
			),
			array( '%d', '%d', '%d', '%s' )
		);
		$check( false !== $early_relation_inserted, 'Could not create the fabricated early-renewal source relation.' );
		$early_order_one = Helper::create_early_renewal_order( (int) $early_subscription_id );
		$check( $early_order_one instanceof \WC_Order, 'Early renewal did not create a pending customer-paid order.' );
		$check( 'active' === get_post_status( $early_subscription_id ), 'Early renewal changed active subscription access before payment.' );
		$early_anchor = (int) get_post_meta( $early_subscription_id, '_subscrpt_early_renewal_anchor', true );
		$check( $early_anchor > time(), 'Early renewal did not persist its future billing anchor.' );
		$check( $early_order_one instanceof \WC_Order && 'early-renew' === (string) $early_order_one->get_meta( '_subscrpt_renewal_type' ), 'Early renewal order did not preserve its renewal type.' );
		$early_order_two = Helper::create_early_renewal_order( (int) $early_subscription_id );
		$check( $early_order_one instanceof \WC_Order && $early_order_two instanceof \WC_Order && $early_order_one->get_id() === $early_order_two->get_id(), 'Duplicate early renewal created a second pending order.' );
		$early_relation_table = $wpdb->prefix . 'subscrpt_order_relation';
		$early_relation_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE subscription_id = %d AND type = %s',
				array(
					$early_relation_table,
					$early_subscription_id,
					'early-renew',
				)
			)
		);
		$check( 1 === $early_relation_count, 'Early renewal duplicate protection did not preserve one relation.' );
		if ( $early_order_one instanceof \WC_Order ) {
			$early_order_one->delete( true );
		}
		if ( $early_source_order instanceof \WC_Order ) {
			$early_source_order->delete( true );
		}
		$wpdb->delete( $early_relation_table, array( 'subscription_id' => $early_subscription_id ), array( '%d' ) );
		wp_delete_post( $early_subscription_id, true );

		// Exercise the classic manual-renewal ownership and idempotency boundary
		// with a fabricated expired subscription and unpaid renewal order.
		$manual_source_order   = wc_create_order(
			array(
				'customer_id' => (int) $customer_id,
				'status'      => 'pending',
			)
		);
		$manual_source_item_id = $manual_source_order instanceof \WC_Order ? $manual_source_order->add_product( $variation, 1 ) : 0;
		if ( $manual_source_order instanceof \WC_Order ) {
			$manual_source_order->calculate_totals( false );
			$manual_source_order->set_date_paid( time() );
			$manual_source_order->set_status( 'completed' );
			$manual_source_order->save();
		}
		wc_update_order_item_meta(
			$manual_source_item_id,
			'_subscrpt_meta',
			array(
				'time'  => 1,
				'type'  => 'month',
				'trial' => null,
			)
		);
		$manual_subscription_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'expired',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi fabricated manual-renewal subscription',
			)
		);
		update_post_meta( $manual_subscription_id, '_subscrpt_product_id', $variable_product->get_id() );
		update_post_meta( $manual_subscription_id, '_subscrpt_variation_id', $variation->get_id() );
		update_post_meta( $manual_subscription_id, '_subscrpt_order_id', $manual_source_order instanceof \WC_Order ? $manual_source_order->get_id() : 0 );
		update_post_meta( $manual_subscription_id, '_subscrpt_order_item_id', $manual_source_item_id );
		update_post_meta( $manual_subscription_id, '_subscrpt_price', '24.00' );
		update_post_meta( $manual_subscription_id, '_subscrpt_next_date', time() - 1 );
		$manual_relation_inserted = $wpdb->insert(
			$wpdb->prefix . 'subscrpt_order_relation',
			array(
				'subscription_id' => $manual_subscription_id,
				'order_id'        => $manual_source_order instanceof \WC_Order ? $manual_source_order->get_id() : 0,
				'order_item_id'   => $manual_source_item_id,
				'type'            => 'new',
			),
			array( '%d', '%d', '%d', '%s' )
		);
		$manual_order             = wc_create_order(
			array(
				'customer_id' => (int) $customer_id,
				'status'      => 'pending',
			)
		);
		$manual_order_item_id     = $manual_order instanceof \WC_Order ? $manual_order->add_product( $variation, 1 ) : 0;
		$manual_order_item        = $manual_order instanceof \WC_Order ? $manual_order->get_item( $manual_order_item_id ) : false;
		if ( $manual_order_item instanceof \WC_Order_Item_Product ) {
			$manual_order_item->update_meta_data( '_renew_subscrpt', $manual_subscription_id );
			$manual_order_item->save();
		}
		if ( $manual_order instanceof \WC_Order ) {
			$manual_order->calculate_totals( false );
			$manual_order->save();
		}
		$check( false !== $manual_relation_inserted, 'Could not create the fabricated manual-renewal source relation.' );
		$check( $manual_order_item instanceof \WC_Order_Item_Product, 'Could not create the fabricated manual-renewal order item.' );
		$resolved_manual_subscription = $manual_order_item instanceof \WC_Order_Item_Product
			? Helper::resolve_checkout_renewal_subscription( $manual_order_item, $variation )
			: false;
		$check( (int) $manual_subscription_id === (int) $resolved_manual_subscription, 'Manual renewal checkout did not resolve the exact subscription owner.' );
		$manual_processed       = $manual_order_item instanceof \WC_Order_Item_Product && $manual_order instanceof \WC_Order
			? Helper::process_order_renewal( (int) $manual_subscription_id, $manual_order->get_id(), $manual_order_item_id )
			: false;
		$manual_processed_again = $manual_order_item instanceof \WC_Order_Item_Product && $manual_order instanceof \WC_Order
			? Helper::process_order_renewal( (int) $manual_subscription_id, $manual_order->get_id(), $manual_order_item_id )
			: false;
		$manual_relation_count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE subscription_id = %d AND order_id = %d AND type = %s',
				array(
					$wpdb->prefix . 'subscrpt_order_relation',
					$manual_subscription_id,
					$manual_order instanceof \WC_Order ? $manual_order->get_id() : 0,
					'renew',
				)
			)
		);
		$check( $manual_processed, 'Manual renewal checkout did not claim the expired subscription period.' );
		$check( $manual_processed_again, 'Manual renewal retry did not reuse the existing renewal relation.' );
		$check( 1 === $manual_relation_count, 'Manual renewal retry created a duplicate renewal relation.' );
		if ( $manual_order instanceof \WC_Order ) {
			$manual_order->delete( true );
		}
		if ( $manual_source_order instanceof \WC_Order ) {
			$manual_source_order->delete( true );
		}
		$wpdb->delete( $wpdb->prefix . 'subscrpt_order_relation', array( 'subscription_id' => $manual_subscription_id ), array( '%d' ) );
		wp_delete_post( $manual_subscription_id, true );

		// Exercise custom renewal pricing and the grace-period event/schedule
		// boundary with fabricated subscriptions and no gateway interaction.
		$auto_renewal            = new AutoRenewal();
		$pricing_subscription_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'active',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi fabricated custom-price subscription',
			)
		);
		update_post_meta( $pricing_subscription_id, '_subscrpt_variation_id', $variation->get_id() );
		update_post_meta( $pricing_subscription_id, '_subscrpt_custom_renewal_price', '17.50' );
		$previous_renewal_price = get_option( 'subscrpt_renewal_price', 'subscribed' );
		update_option( 'subscrpt_renewal_price', 'subscribed', false );
		$pricing_item = new \WC_Order_Item_Product();
		$pricing_item->set_quantity( 2 );
		$pricing_args = $auto_renewal->filter_renewal_product_args( array(), $variation, $pricing_item, (int) $pricing_subscription_id );
		$check( is_array( $pricing_args ) && 35.0 === (float) ( $pricing_args['subtotal'] ?? 0 ), 'Custom renewal pricing did not apply the subscription snapshot price.' );
		$check( is_array( $pricing_args ) && 35.0 === (float) ( $pricing_args['total'] ?? 0 ), 'Custom renewal pricing did not preserve the order quantity.' );

		$grace_subscription_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'expired',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi fabricated grace-period subscription',
			)
		);
		update_post_meta( $grace_subscription_id, '_subscrpt_price', '24.00' );
		update_post_meta( $grace_subscription_id, '_subscrpt_next_date', time() - 10 );
		$previous_grace_period = get_option( 'subscrpt_default_payment_grace_period', '7' );
		update_option( 'subscrpt_default_payment_grace_period', 2, false );
		$grace_started        = false;
		$grace_ended          = false;
		$grace_start_listener = static function ( $subscription_id ) use ( &$grace_started, $grace_subscription_id ): void {
			if ( (int) $grace_subscription_id === (int) $subscription_id ) {
				$grace_started = true;
			}
		};
		$grace_end_listener   = static function ( $subscription_id ) use ( &$grace_ended, $grace_subscription_id ): void {
			if ( (int) $grace_subscription_id === (int) $subscription_id ) {
				$grace_ended = true;
			}
		};
		add_action( 'subscrpt_grace_period_started', $grace_start_listener );
		add_action( 'subscrpt_grace_period_ended', $grace_end_listener );
		$auto_renewal->maybe_trigger_grace_start_hook( (int) $grace_subscription_id );
		$scheduled_grace = function_exists( 'as_has_scheduled_action' )
			? (bool) as_has_scheduled_action( 'subscrpt_scheduled_grace_end', array( 'subscription_id' => $grace_subscription_id ), 'ashbi-subscriptions' )
			: (bool) wp_next_scheduled( 'subscrpt_scheduled_grace_end', array( $grace_subscription_id ) );
		$check( $grace_started, 'Grace-period start action did not fire for the overdue fabricated subscription.' );
		$check( $scheduled_grace, 'Grace-period end was not scheduled for the overdue fabricated subscription.' );
		$auto_renewal->trigger_grace_end_hook( (int) $grace_subscription_id );
		$check( $grace_ended, 'Grace-period end action did not fire.' );
		$auto_renewal->clear_grace_period_schedules( (int) $grace_subscription_id );
		remove_action( 'subscrpt_grace_period_started', $grace_start_listener );
		remove_action( 'subscrpt_grace_period_ended', $grace_end_listener );
		if ( false === $previous_renewal_price ) {
			delete_option( 'subscrpt_renewal_price' );
		} else {
			update_option( 'subscrpt_renewal_price', $previous_renewal_price, false );
		}
		if ( false === $previous_grace_period ) {
			delete_option( 'subscrpt_default_payment_grace_period' );
		} else {
			update_option( 'subscrpt_default_payment_grace_period', $previous_grace_period, false );
		}
		wp_delete_post( $pricing_subscription_id, true );
		wp_delete_post( $grace_subscription_id, true );

		// Exercise the paid upgrade/downgrade switch application with a fabricated
		// completed order. The switch must validate the exact customer owner, update
		// the subscription snapshot only after payment, and remain idempotent when
		// WooCommerce fires both payment-complete and status callbacks.
		$switch_target_product = new \WC_Product_Simple();
		$switch_target_product->set_name( 'Ashbi fabricated switch target' );
		$switch_target_product->set_status( 'publish' );
		$switch_target_product->set_regular_price( '39.00' );
		$switch_target_product->set_price( '39.00' );
		$switch_target_product->save();
		$switch_target_product->update_meta_data( '_subscrpt_enabled', 'yes' );
		$switch_target_product->update_meta_data( '_subscrpt_payment_type', 'recurring' );
		$switch_target_product->update_meta_data( '_subscrpt_timing_per', 1 );
		$switch_target_product->update_meta_data( '_subscrpt_timing_option', 'months' );
		$switch_target_product->save();
		$switching = new Switching();

		// Switching access must not treat anonymous user ID zero as a customer. A
		// capable administrator may still inspect a guest-owned subscription, but a
		// wrong post type and a guest-owned record must fail closed for guests.
		$wrong_type_switch_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'active',
				'post_author' => 0,
				'post_title'  => 'Ashbi fabricated wrong-type switch record',
			)
		);
		$guest_switch_id      = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'active',
				'post_author' => 0,
				'post_title'  => 'Ashbi fabricated guest switch subscription',
			)
		);
		$owner_switch_id      = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'active',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi fabricated owned switch subscription',
			)
		);
		wp_set_current_user( 0 );
		$check( ! Switching::can_switch_subscription( (int) $wrong_type_switch_id ), 'Anonymous user could access a wrong-type switch record.' );
		$check( ! Switching::can_switch_subscription( (int) $guest_switch_id ), 'Anonymous user inherited ownership of a guest switch record.' );
		wp_set_current_user( (int) $customer_id );
		$check( Switching::can_switch_subscription( (int) $owner_switch_id ), 'Authenticated subscription owner could not access switching.' );
		wp_set_current_user( (int) $administrator_id );
		$check( Switching::can_switch_subscription( (int) $guest_switch_id ), 'Capable administrator could not access a guest switch record.' );
		wp_set_current_user( (int) $customer_id );

		// Paid reconciliation must reject every invalid identity before relation or
		// subscription metadata mutation, including the legacy zero/zero pairing.
		$assert_rejected_switch = static function ( string $label, string $post_type, int $post_author, int $order_customer_id ) use ( $switch_target_product, $product_id, $switching, $check ): void {
			global $wpdb;
			$invalid_subscription_id = wp_insert_post(
				array(
					'post_type'   => $post_type,
					'post_status' => 'active',
					'post_author' => $post_author,
					'post_title'  => 'Ashbi fabricated rejected switch subscription',
				)
			);
			update_post_meta( $invalid_subscription_id, '_subscrpt_product_id', $product_id );
			update_post_meta( $invalid_subscription_id, '_subscrpt_price', '24.00' );
			$invalid_order   = wc_create_order(
				array(
					'customer_id' => $order_customer_id,
					'status'      => 'pending',
				)
			);
			$invalid_item_id = $invalid_order instanceof \WC_Order ? $invalid_order->add_product( $switch_target_product, 1 ) : 0;
			$invalid_item    = $invalid_order instanceof \WC_Order ? $invalid_order->get_item( $invalid_item_id ) : false;
			$check( $invalid_item instanceof \WC_Order_Item_Product, $label . ' could not create its fabricated order line.' );
			if ( $invalid_order instanceof \WC_Order && $invalid_item instanceof \WC_Order_Item_Product ) {
				$invalid_item->update_meta_data( '_wp_subs_switch', 1 );
				$invalid_item->update_meta_data(
					'_wp_subs_switch_context',
					array(
						'subscription_id'  => (int) $invalid_subscription_id,
						'product_id'       => $switch_target_product->get_id(),
						'new_variation_id' => 0,
						'plan_id'          => 0,
					)
				);
				$invalid_item->save();
				$invalid_order->calculate_totals( false );
				$invalid_order->set_status( 'completed' );
				$invalid_order->save();
				$switching->apply_order( $invalid_order );
				$invalid_item = $invalid_order->get_item( $invalid_item_id );
				$check( (int) get_post_meta( $invalid_subscription_id, '_subscrpt_product_id', true ) === (int) $product_id, $label . ' mutated the subscription product snapshot.' );
				$check( '' === (string) $invalid_item->get_meta( '_subscrpt_switch_applied' ), $label . ' persisted a switch idempotency marker.' );
				$relation_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE subscription_id = %d AND order_id = %d AND type = %s',
						array( $wpdb->prefix . 'subscrpt_order_relation', $invalid_subscription_id, $invalid_order->get_id(), 'switch' )
					)
				);
				$check( 0 === $relation_count, $label . ' created a switch relation before authorization.' );
			}
			if ( $invalid_order instanceof \WC_Order ) {
				$invalid_order->delete( true );
			}
			if ( $invalid_subscription_id > 0 ) {
				$wpdb->delete( $wpdb->prefix . 'subscrpt_order_relation', array( 'subscription_id' => $invalid_subscription_id ), array( '%d' ) );
				wp_delete_post( $invalid_subscription_id, true );
			}
		};
		$assert_rejected_switch( 'Wrong-type switch', 'post', 0, 0 );
		$assert_rejected_switch( 'Guest-owned switch', 'subscrpt_order', 0, 0 );
		$assert_rejected_switch( 'Zero-customer switch', 'subscrpt_order', (int) $customer_id, 0 );
		$mismatch_customer_id = (int) $administrator_id === (int) $customer_id ? (int) $subscriber_id : (int) $administrator_id;
		$assert_rejected_switch( 'Mismatched-customer switch', 'subscrpt_order', (int) $customer_id, $mismatch_customer_id );
		wp_delete_post( $wrong_type_switch_id, true );
		wp_delete_post( $guest_switch_id, true );
		wp_delete_post( $owner_switch_id, true );

		$switch_subscription_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'active',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi fabricated switch subscription',
			)
		);
		update_post_meta( $switch_subscription_id, '_subscrpt_product_id', $product_id );
		update_post_meta( $switch_subscription_id, '_subscrpt_price', '24.00' );
		update_post_meta( $switch_subscription_id, '_subscrpt_payment_type', 'recurring' );
		update_post_meta( $switch_subscription_id, '_subscrpt_timing_per', 1 );
		update_post_meta( $switch_subscription_id, '_subscrpt_timing_option', 'months' );
		$switch_order         = wc_create_order(
			array(
				'customer_id' => (int) $customer_id,
				'status'      => 'pending',
			)
		);
		$switch_order_item_id = $switch_order instanceof \WC_Order ? $switch_order->add_product( $switch_target_product, 1 ) : 0;
		$switch_order_item    = $switch_order instanceof \WC_Order ? $switch_order->get_item( $switch_order_item_id ) : false;
		$check( $switch_order_item instanceof \WC_Order_Item_Product, 'Could not create the fabricated subscription switch order line.' );
		if ( $switch_order instanceof \WC_Order && $switch_order_item instanceof \WC_Order_Item_Product ) {
			$switch_order_item->update_meta_data( '_wp_subs_switch', 1 );
			$switch_order_item->update_meta_data(
				'_wp_subs_switch_context',
				array(
					'switch_type'      => 'upgrade',
					'subscription_id'  => (int) $switch_subscription_id,
					'product_id'       => $switch_target_product->get_id(),
					'old_variation_id' => 0,
					'new_variation_id' => 0,
					'plan_id'          => 0,
					'target_price'     => 39.0,
				)
			);
			$switch_order_item->update_meta_data( '_subscrpt_payment_type', 'recurring' );
			$switch_order_item->save();
			$switch_order->calculate_totals( false );
			$switch_order->set_status( 'completed' );
			$switch_order->save();
			$switching->apply_order( $switch_order );
			$switching->apply_order( $switch_order );
			$switch_order_item = $switch_order->get_item( $switch_order_item_id );
			$check( (int) $switch_target_product->get_id() === (int) get_post_meta( $switch_subscription_id, '_subscrpt_product_id', true ), 'Paid switch did not update the subscription product snapshot.' );
			$check( 39.0 === (float) get_post_meta( $switch_subscription_id, '_subscrpt_price', true ), 'Paid switch did not update the subscription recurring price.' );
			$check( (int) $switch_order->get_id() === (int) get_post_meta( $switch_subscription_id, '_subscrpt_switch_order_id', true ), 'Paid switch did not persist its canonical order.' );
			$check( '1' === (string) $switch_order_item->get_meta( '_subscrpt_switch_applied' ), 'Paid switch did not persist its idempotency marker.' );
			$switch_relation_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					' SELECT COUNT(*) FROM %i WHERE subscription_id = %d AND order_id = %d AND type = %s',
					array(
						$wpdb->prefix . 'subscrpt_order_relation',
						$switch_subscription_id,
						$switch_order->get_id(),
						'switch',
					)
				)
			);
			$check( 1 === $switch_relation_count, 'Repeated switch callbacks created duplicate relation rows.' );
		}

		// Classify a synthetic coupon as recurring without sending any order or
		// payment data to a gateway. The same filters drive the renewal snapshot.
		$recurring_coupon_code = 'ashbi-fabricated-recurring';
		$recurring_coupon      = new \WC_Coupon();
		$recurring_coupon->set_code( $recurring_coupon_code );
		$recurring_coupon->set_discount_type( 'fixed_product' );
		$recurring_coupon->set_amount( '5.00' );
		$recurring_coupon->set_product_ids( array( $switch_target_product->get_id() ) );
		$recurring_coupon->save();
		$recurring_coupon_filter = static function ( $is_recurring, $coupon ) use ( $recurring_coupon_code ): bool {
			return (string) $recurring_coupon_code === (string) $coupon->get_code();
		};
		$recurring_limit_filter  = static function ( $limit, $coupon ) use ( $recurring_coupon_code ): int {
			return (string) $recurring_coupon_code === (string) $coupon->get_code() ? 3 : (int) $limit;
		};
		add_filter( 'subscrpt_coupon_is_recurring', $recurring_coupon_filter, 10, 3 );
		add_filter( 'subscrpt_coupon_recurring_limit', $recurring_limit_filter, 10, 3 );
		$cart                      = function_exists( 'WC' ) ? WC()->cart : null;
		$recurring_source_order    = null;
		$recurring_renewal_order   = null;
		$recurring_subscription_id = 0;
		try {
			if ( $cart ) {
				$cart->empty_cart();
				$cart_item_key = $cart->add_to_cart( $switch_target_product->get_id(), 1 );
				$check( is_string( $cart_item_key ) && '' !== $cart_item_key, 'Could not add the fabricated recurring-coupon product to the cart.' );
				$check( $cart->add_discount( $recurring_coupon_code ), 'Could not apply the fabricated recurring coupon to the cart.' );
				$cart->calculate_totals();
				$discounts = is_string( $cart_item_key ) ? Helper::get_cart_item_coupon_discounts( $cart_item_key ) : array();
				$check( 5.0 === (float) ( $discounts['recurring'] ?? 0 ), 'Recurring coupon classification omitted the synthetic renewal discount.' );
				$check( 0.0 === (float) ( $discounts['non_recurring'] ?? 0 ), 'Recurring coupon classification treated the synthetic discount as one-time.' );
				$check( 3 === (int) ( $discounts['recurring_limit'] ?? 0 ), 'Recurring coupon classification omitted the synthetic payment limit.' );
			}

			// Build a completed source order with a real Woo coupon line, then use the
			// renewal constructor to prove the eligible discount reaches the charge total.
			$recurring_source_order   = wc_create_order(
				array(
					'customer_id' => (int) $customer_id,
					'status'      => 'pending',
				)
			);
			$recurring_source_item_id = $recurring_source_order instanceof \WC_Order
				? $recurring_source_order->add_product(
					$switch_target_product,
					1,
					array(
						'name'     => $switch_target_product->get_name(),
						'subtotal' => 39.0,
						'total'    => 39.0,
					)
				)
				: 0;
			if ( $recurring_source_order instanceof \WC_Order && $recurring_source_item_id ) {
				$recurring_source_order->add_coupon( $recurring_coupon_code, 5.0, 0.0 );
				$recurring_source_order->calculate_totals( false );
				$recurring_source_order->set_status( 'completed' );
				$recurring_source_order->save();
			}
			$recurring_subscription_id = wp_insert_post(
				array(
					'post_type'   => 'subscrpt_order',
					'post_status' => 'active',
					'post_author' => (int) $customer_id,
					'post_title'  => 'Ashbi fabricated recurring-coupon subscription',
				)
			);
			update_post_meta( $recurring_subscription_id, '_subscrpt_price', '39.00' );
			update_post_meta( $recurring_subscription_id, '_subscrpt_order_item_id', $recurring_source_item_id );
			$recurring_relation_inserted = $wpdb->insert(
				$wpdb->prefix . 'subscrpt_order_relation',
				array(
					'subscription_id' => $recurring_subscription_id,
					'order_id'        => $recurring_source_order instanceof \WC_Order ? $recurring_source_order->get_id() : 0,
					'order_item_id'   => $recurring_source_item_id,
					'type'            => 'new',
				),
				array( '%d', '%d', '%d', '%s' )
			);
			$recurring_renewal_data      = $recurring_source_order instanceof \WC_Order
				? Helper::create_new_order_for_renewal(
					$recurring_source_order,
					$recurring_source_order->get_item( $recurring_source_item_id ),
					array(
						'name'     => $switch_target_product->get_name(),
						'subtotal' => 39.0,
						'total'    => 39.0,
					),
					(int) $recurring_subscription_id
				)
				: false;
			$recurring_renewal_order     = is_array( $recurring_renewal_data ) ? ( $recurring_renewal_data['order'] ?? null ) : null;
			if ( $recurring_renewal_order instanceof \WC_Order ) {
				$recurring_renewal_order->calculate_totals( false );
			}
			$recurring_renewal_coupons = $recurring_renewal_order instanceof \WC_Order ? $recurring_renewal_order->get_items( 'coupon' ) : array();
			$recurring_renewal_coupon  = reset( $recurring_renewal_coupons );
			$check( false !== $recurring_relation_inserted, 'Could not create the recurring-coupon source relation.' );
			$check( $recurring_renewal_order instanceof \WC_Order, 'Renewal constructor did not create the recurring-coupon order.' );
			$check( 1 === count( $recurring_renewal_coupons ), 'Renewal constructor did not carry one recurring coupon line.' );
			$check( $recurring_renewal_coupon instanceof \WC_Order_Item_Coupon && 5.0 === (float) $recurring_renewal_coupon->get_discount(), 'Renewal coupon line did not preserve the recurring discount.' );
			$check(
				$recurring_renewal_order instanceof \WC_Order && 34.0 === (float) $recurring_renewal_order->get_total(),
				'Renewal total did not include the recurring coupon discount.'
			);
		} finally {
			remove_filter( 'subscrpt_coupon_is_recurring', $recurring_coupon_filter, 10 );
			remove_filter( 'subscrpt_coupon_recurring_limit', $recurring_limit_filter, 10 );
			if ( $cart ) {
				$cart->empty_cart();
			}
			$recurring_coupon->delete( true );
			if ( $recurring_renewal_order instanceof \WC_Order ) {
				$recurring_renewal_order->delete( true );
			}
			if ( $recurring_source_order instanceof \WC_Order ) {
				$recurring_source_order->delete( true );
			}
			if ( $recurring_subscription_id > 0 ) {
				$wpdb->delete( $wpdb->prefix . 'subscrpt_order_relation', array( 'subscription_id' => $recurring_subscription_id ), array( '%d' ) );
				wp_delete_post( $recurring_subscription_id, true );
			}
		}
		if ( $switch_order instanceof \WC_Order ) {
			$switch_order->delete( true );
		}
		if ( $switch_subscription_id > 0 ) {
			wp_delete_post( $switch_subscription_id, true );
		}
		$switch_target_product->delete( true );

		// A fully paid installment plan is terminal even if legacy status/meta still.
		// resembles a pending cancellation. Exercise the real Woo order and relation.
		// table rather than only the pure status helper.
		$paid_order = wc_create_order();
		$paid_order->set_total( 10 );
		$paid_order->set_date_paid( time() );
		$paid_order->set_status( 'completed' );
		$paid_order->save();
		update_post_meta( $product_id, '_subscrpt_max_no_payment', 1 );
		update_post_meta( $subscription_id, '_subscrpt_cancel_at', time() + HOUR_IN_SECONDS );
		wp_update_post(
			array(
				'ID'          => $subscription_id,
				'post_status' => 'pe_cancelled',
			)
		);

		global $wpdb;
		$relation_inserted = $wpdb->insert(
			$wpdb->prefix . 'subscrpt_order_relation',
			array(
				'subscription_id' => $subscription_id,
				'order_id'        => $paid_order->get_id(),
				'order_item_id'   => 0,
				'type'            => 'new',
			),
			array( '%d', '%d', '%d', '%s' )
		);
		$check( false !== $relation_inserted, 'Could not create the disposable paid installment relation.' );
		$check( subscrpt_is_max_payments_reached( $subscription_id ), 'Paid installment limit was not detected.' );
		$check( ! ActionController::can_reactivate_subscription( $subscription_id, time() ), 'Completed installment plan could be reactivated.' );
		update_post_meta( $subscription_id, '_subscrpt_next_date', time() + DAY_IN_SECONDS );
		$check( subscrpt_finalize_split_payment_completion( $subscription_id ), 'Final installment effects did not persist.' );
		$check( 'completed' === get_post_status( $subscription_id ), 'Final installment did not persist completed status.' );
		$check( ! get_post_meta( $subscription_id, '_subscrpt_next_date', true ), 'Final installment retained a next-payment date.' );
		$check( (bool) get_post_meta( $subscription_id, '_subscrpt_split_payment_completed_fired', true ), 'Final installment callback marker was not persisted.' );

		// Exercise deactivation against disposable scheduled work and prove that.
		// business history remains present. The plugin is not deactivated through the.
		// WordPress admin here; this invokes the same registered lifecycle callback.
		$plugin_instance = function_exists( 'sdevs_subscription' ) ? sdevs_subscription() : null;
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'subscrpt_retry_renewal_payment' );
		$check( false !== wp_next_scheduled( 'subscrpt_retry_renewal_payment' ), 'Could not seed disposable renewal work for deactivation.' );
		if ( is_object( $plugin_instance ) && method_exists( $plugin_instance, 'deactivate' ) ) {
			$plugin_instance->deactivate();
			$check( false === wp_next_scheduled( 'subscrpt_retry_renewal_payment' ), 'Deactivation left disposable renewal work scheduled.' );
		} else {
			$failures[] = 'Plugin lifecycle instance was unavailable for deactivation.';
		}
		$check( get_post( $subscription_id ) instanceof WP_Post, 'Deactivation removed the subscription record.' );

		// The default uninstall path must be safe to run after deactivation. Force.
		// the documented opt-in switch off for this disposable assertion and restore.
		// the previous value afterward.
		$previous_remove_data = get_option( 'subscrpt_remove_data_on_uninstall', false );
		update_option( 'subscrpt_remove_data_on_uninstall', false );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', SUBSCRPT_FILE );
		}
		require_once SUBSCRPT_PATH . '/uninstall.php';
		$check( get_post( $subscription_id ) instanceof WP_Post, 'Default uninstall removed the subscription record.' );
		$check( '1.5.0' === (string) get_option( 'subscrpt_db_version', '' ), 'Default uninstall removed the schema marker.' );
		if ( false === $previous_remove_data ) {
			delete_option( 'subscrpt_remove_data_on_uninstall' );
		} else {
			update_option( 'subscrpt_remove_data_on_uninstall', $previous_remove_data );
		}

		$unaffected_subscription_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'active',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi unaffected migration subscription',
			)
		);
		$previous_migration_block   = get_option( 'subscrpt_renewal_migration_blocked', false );
		update_option( 'subscrpt_renewal_migration_blocked', array( $subscription_id ), false );
		$check( subscrpt_renewal_is_migration_blocked( $subscription_id ), 'Quarantined overdue subscription was not blocked.' );
		$check( ! subscrpt_renewal_is_migration_blocked( $unaffected_subscription_id ), 'Unrelated subscription was blocked by a scoped migration quarantine.' );
		if ( false === $previous_migration_block ) {
			delete_option( 'subscrpt_renewal_migration_blocked' );
		} else {
			update_option( 'subscrpt_renewal_migration_blocked', $previous_migration_block, false );
		}

		// Exercise aggregate reporting with a fabricated subscription. These checks.
		// intentionally use only a synthetic price and schedule; no customer, order,.
		// token, or gateway data is read or charged.
		$metrics_subscription_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'active',
				'post_author' => (int) $customer_id,
				'post_title'  => 'Ashbi fabricated reporting subscription',
			)
		);
		$check( ! is_wp_error( $metrics_subscription_id ) && $metrics_subscription_id > 0, 'Could not create the fabricated reporting subscription.' );
		if ( ! is_wp_error( $metrics_subscription_id ) && $metrics_subscription_id > 0 ) {
			update_post_meta( $metrics_subscription_id, '_subscrpt_price', '30.00' );
			update_post_meta( $metrics_subscription_id, '_subscrpt_timing_option', 'month' );
			update_post_meta( $metrics_subscription_id, '_subscrpt_timing_per', 1 );
			try {
				$active_mrr = Stats::calculate_active_mrr();
				$check( $active_mrr >= 30.0, 'Active MRR omitted the fabricated subscription.' );
			} catch ( \Throwable $error ) {
				$failures[] = 'Active MRR raised ' . get_class( $error ) . ': ' . $error->getMessage();
			}
			wp_update_post(
				array(
					'ID'          => $metrics_subscription_id,
					'post_status' => 'on_hold',
				)
			);
			try {
				$revenue_at_risk = Stats::calculate_revenue_at_risk();
				$check( $revenue_at_risk >= 30.0, 'Revenue-at-risk omitted the fabricated on-hold subscription.' );
			} catch ( \Throwable $error ) {
				$failures[] = 'Revenue-at-risk raised ' . get_class( $error ) . ': ' . $error->getMessage();
			}
		}

		if ( $failures ) {
			wp_send_json_error( array( 'failures' => $failures ), 500 );
		}

		wp_send_json_success( array( 'message' => 'Ashbi security-boundary integration checks passed.' ) );
	} catch ( \Throwable $error ) {
		wp_send_json_error(
			array(
				'failures' => array(
					'Integration check raised ' . get_class( $error ) . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine(),
				),
			),
			500
		);
	}
}

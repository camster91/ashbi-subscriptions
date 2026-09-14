<?php
/**
 * Static-analysis declarations for runtime symbols supplied by WordPress,
 * WooCommerce, Action Scheduler, WooCommerce Stripe, and optional Pro code.
 * This file is never loaded by the plugin.
 */

namespace {
	define( 'ABSPATH', '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'EP_PAGES', 4096 );
	define( 'EP_ROOT', 64 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'WEEK_IN_SECONDS', 604800 );
	define( 'WP_PLUGIN_DIR', '/wp-content/plugins' );
	define( 'WC_STRIPE_MAIN_FILE', '/wp-content/plugins/woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' );
	define( 'WC_PLUGIN_FILE', '/wp-content/plugins/woocommerce/woocommerce.php' );
	define( 'SUBSCRPT_VERSION', '0.0.0' );
	define( 'SUBSCRPT_FILE', '/wp-content/plugins/ashbi-subscriptions/subscription.php' );
	define( 'SUBSCRPT_PATH', '/wp-content/plugins/ashbi-subscriptions' );
	define( 'SUBSCRPT_INCLUDES', '/wp-content/plugins/ashbi-subscriptions/includes' );
	define( 'SUBSCRPT_TEMPLATES', '/wp-content/plugins/ashbi-subscriptions/templates/' );
	define( 'SUBSCRPT_URL', 'https://example.test/wp-content/plugins/ashbi-subscriptions' );
	define( 'SUBSCRPT_ASSETS', 'https://example.test/wp-content/plugins/ashbi-subscriptions/assets' );

	class WP_CLI {
		public static function line( $message ) {}
	}

	function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {}
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {}
	function as_unschedule_action( $hook, $args = array(), $group = '' ) {}

	class WC_Stripe_Payment_Gateway extends WC_Payment_Gateway {
		protected function prepare_order_source( $order ) {}
		protected function create_and_confirm_intent_for_off_session( $order, $source, $amount ) {}
		protected function maybe_remove_non_existent_customer( $error, $order ) {}
		protected function throw_localized_message( $intent, $order ) {}
		protected function get_latest_charge_from_intent( $intent ) {}
		protected function process_response( $response, $order ) {}
		protected function get_level3_data_from_order( $order ) {}
		protected function generate_payment_request( $order, $source ) {}
		protected function has_subscription( $order_id ) {}
		protected function save_payment_method_requested() {}
	}

	class WC_Stripe_Order_Helper {
		public static function get_instance() {}
		public function validate_minimum_order_amount( $order ) {}
		public function lock_order_payment( $order ) {}
		public function unlock_order_payment( $order ) {}
		public function get_stripe_customer_id( $order ) {}
		public function get_stripe_source_id( $order ) {}
	}

	class WC_Stripe_Exception extends \Exception {}

	class WC_Stripe_Logger {
		public static function info( $message ) {}
		public static function error( $message ) {}
	}

	class WC_Stripe_Intent_Status {
		const SUCCEEDED = 'succeeded';
		const REQUIRES_ACTION = 'requires_action';
		const REQUIRES_CONFIRMATION = 'requires_confirmation';
	}

	class WC_Stripe_Helper {
		public static function get_stripe_amount( $amount, $currency = '' ) {}
		public static function add_payment_method_to_request_array( $source, $request ) {}
	}

	class WC_Stripe_API {
		public static function retrieve( $endpoint ) {}
		public static function request( $request, $endpoint, $method = 'POST' ) {}
		public static function request_with_level3_data( $request, $endpoint, $level3_data, $order ) {}
	}
}

namespace SpringDevs\SubscriptionPro\Illuminate {
	class PaymentFailureHandler {
		public static function is_access_suspended( $subscription_id ) {}
	}
}

namespace Automattic\WooCommerce\Blocks\Domain\Services {
	class ExtendRestApi {}
}

namespace Automattic\WooCommerce\Internal\DataStores\Orders {
	class CustomOrdersTableController {}
}

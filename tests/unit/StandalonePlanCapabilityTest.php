<?php
/**
 * Contract tests for standalone Ashbi plan and lifecycle capabilities.
 *
 * @package AshbiSubscriptions
 */

use PHPUnit\Framework\TestCase;

/**
 * The Ashbi build must own the plan path when the upstream paid plugin is not
 * installed. These are source-level contract tests because WordPress and
 * WooCommerce are intentionally not booted by the fast unit suite.
 */

/**
 * Verify paid-plugin features are owned by the Ashbi build.
 */
final class StandalonePlanCapabilityTest extends TestCase {
	/**
	 * Read one source file for contract assertions.
	 *
	 * @param string $relative Repository-relative source path.
	 * @return string
	 */
	private function source( string $relative ): string {
		$path = dirname( __DIR__, 2 ) . '/' . ltrim( $relative, '/' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixture.
		$source = file_get_contents( $path );

		self::assertIsString( $source, 'Unable to read ' . $relative );

		return $source;
	}

	/**
	 * Verify the canonical namespace and the compatibility boundary.
	 *
	 * The paid namespace is intentionally not scanned in test fixtures because
	 * fixtures may model an unavailable dependency for fail-closed tests.
	 */
	public function test_namespace_strategy_preserves_the_public_api_without_paid_aliases(): void {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixture.
		$composer     = json_decode( (string) file_get_contents( $root . '/plugin/composer.json' ), true );
		$plugin       = $this->source( 'plugin/bootstrap.php' );
		$legacy       = $this->source( 'plugin/includes/LegacyCompat.php' );
		$architecture = $this->source( 'docs/ARCHITECTURE.md' );

		self::assertIsArray( $composer );
		self::assertSame(
			'includes/',
			$composer['autoload']['psr-4']['SpringDevs\\Subscription\\'] ?? null
		);
		self::assertStringContainsString( "require_once SUBSCRPT_INCLUDES . '/LegacyCompat.php';", $plugin );
		self::assertStringContainsString( 'WP_SUBSCRIPTION_VERSION', $legacy );
		self::assertStringContainsString( 'wp_subscrpt_write_log', $legacy );
		self::assertStringContainsString( 'subscrpt_split_payment_next_due_date', $legacy );
		self::assertStringNotContainsString( 'SpringDevs\\SubscriptionPro', $legacy );
		self::assertStringContainsString( 'There are intentionally no aliases', $architecture );

		$illuminate  = $this->source( 'plugin/includes/Illuminate.php' );
		$diagnostics = $this->source( 'plugin/includes/Api/DiagnosticsController.php' );
		self::assertStringContainsString( "add_action( 'plugins_loaded', array( \$this, 'init_plugin' ), 20 )", $plugin );
		self::assertStringContainsString( "add_action( 'init', array( \$this, 'stripe_initialization' ), 5 )", $illuminate );
		self::assertStringContainsString( "class_exists( 'SpringDevs\\\\Subscription\\\\Illuminate\\\\Gateways\\\\Stripe\\\\Stripe', false )", $diagnostics );

		$paid_namespace_references = array();
		$iterator                  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/plugin/includes', FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixture.
			$contents = file_get_contents( $file->getPathname() );
			if ( false !== $contents && false !== strpos( $contents, 'SpringDevs\\SubscriptionPro' ) ) {
				$paid_namespace_references[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		self::assertSame( array(), $paid_namespace_references );
	}

	/** Verify early activation logging cannot fatal before WooCommerce helpers load. */
	public function test_early_activation_logging_fails_safe_without_the_woocommerce_logger(): void {
		$functions = $this->source( 'plugin/includes/functions.php' );

		self::assertStringContainsString( "if ( ! function_exists( 'wc_get_logger' ) )", $functions );
		self::assertStringContainsString( "error_log( 'wp_subscription: ' . \$message )", $functions );
		self::assertStringContainsString( "return;\n\t}\n\n\t\$logger = wc_get_logger();", $functions );
	}

	/** Verify the standalone build bootstraps plans and checkout. */
	public function test_standalone_bootstraps_own_plan_frontend_and_checkout(): void {
		$illuminate = $this->source( 'plugin/includes/Illuminate.php' );
		$frontend   = $this->source( 'plugin/includes/Frontend.php' );

		self::assertStringContainsString( 'new PlanCheckout();', $illuminate );
		self::assertStringContainsString( 'new Plans();', $frontend );
		self::assertStringNotContainsString( 'if ( ! subscrpt_pro_activated() ) {\n\t\t\tnew PlanCheckout();', $illuminate );
		self::assertStringNotContainsString( 'if ( ! subscrpt_pro_activated() ) {\n\t\t\tnew Plans();', $frontend );
	}

	/** Verify checkout carries variation and advanced term state. */
	public function test_plan_checkout_carries_variation_and_advanced_term_state(): void {
		$checkout = $this->source( 'plugin/includes/Frontend/PlanCheckout.php' );
		$helper   = $this->source( 'plugin/includes/Illuminate/Helper.php' );

		self::assertStringContainsString( "add_plan_to_cart' ), 20, 4", $checkout );
		self::assertStringContainsString( '$variation_id', $checkout );
		self::assertStringContainsString( "'subscrpt_max_no_payment'", $checkout );
		self::assertStringContainsString( "'subscrpt_signup_fee'", $checkout );
		self::assertStringContainsString( "'_subscrpt_payment_type'", $helper );
		self::assertStringContainsString( "'_subscrpt_max_no_payment'", $helper );
	}

	/** Verify advanced plan types and variation relations are not gated. */
	public function test_advanced_plan_types_and_variation_relations_are_not_pro_gated(): void {
		$controller = $this->source( 'plugin/includes/Api/PlanController.php' );
		$admin      = $this->source( 'plugin/includes/Admin/Product/Plans.php' );
		$term_modal = $this->source( 'plugin/includes/Admin/views/plans/modal-term.php' );

		self::assertStringNotContainsString( 'subscrpt_plan_type_pro', $controller );
		self::assertStringContainsString( 'subscrpt_variation_product_mismatch', $controller );
		self::assertStringNotContainsString( 'pro_active && (int) $group[\'type\'] !== $recurring', $admin );
		self::assertStringNotContainsString( 'disabled( ! $pro_active )', $term_modal );
		self::assertStringNotContainsString( 'disabled( $pro_locked )', $term_modal );
	}

	/** Verify variable storefront and plan renewal paths are supported. */
	public function test_variable_storefront_path_and_plan_renewal_are_supported(): void {
		$plans    = $this->source( 'plugin/includes/Frontend/Plans.php' );
		$checkout = $this->source( 'plugin/includes/Frontend/Checkout.php' );
		$cart     = $this->source( 'plugin/includes/Frontend/Cart.php' );
		$template = $this->source( 'plugin/templates/product/plan-selector.php' );

		self::assertStringContainsString( "is_type( 'variable' )", $plans );
		self::assertStringContainsString( 'data-subscrpt-plan-contexts', $template );
		self::assertStringNotContainsString( '&& ! subscrpt_pro_activated() && ! $order_item->get_meta', $checkout );
		self::assertStringContainsString( '$variation_id = 0', $cart );
	}

	/** Verify pause and resume use the canonical on-hold state. */
	public function test_pause_resume_uses_the_subscription_canonical_on_hold_state(): void {
		$helper = $this->source( 'plugin/includes/Illuminate/Helper.php' );
		$action = $this->source( 'plugin/includes/Illuminate/Action.php' );

		self::assertStringContainsString( "Action::status( 'on_hold', \$subscription_id )", $helper );
		self::assertStringContainsString( "case 'on_hold':", $action );
		self::assertStringContainsString( "case 'on-hold':", $action );
	}

	/** Verify lifecycle settings are not paid-plugin-only. */
	public function test_standalone_lifecycle_settings_are_not_paid_plugin_only(): void {
		$settings = $this->source( 'plugin/includes/Admin/ProSettingsFields.php' );
		$flow     = $this->source( 'plugin/includes/Admin/CancellationFlow.php' );
		$cancel   = $this->source( 'plugin/includes/Illuminate/Cancellation.php' );

		self::assertStringContainsString( "add_action( 'admin_init', array( \$this, 'register_settings' ) )", $settings );
		self::assertStringContainsString( "'subscrpt_cancellation_delay'", $flow );
		self::assertStringContainsString( 'sanitize_delay', $flow );
		self::assertStringContainsString( "'subscrpt_recovery_event_retention_days'", $flow );
		self::assertStringContainsString( "'subscrpt_recovery_event_retention_days' => array( 30, 3650 )", $flow );
		self::assertStringNotContainsString( "subscrpt_pro_activated() ? get_option( 'subscrpt_cancellation_delay'", $cancel );
		self::assertStringNotContainsString( 'if ( ! subscrpt_pro_activated() )', $flow );
	}

	/** Verify core lifecycle services do not require the paid add-on. */
	public function test_core_lifecycle_services_do_not_require_the_paid_add_on(): void {
		$email      = $this->source( 'plugin/includes/Illuminate/Email.php' );
		$endpoint   = $this->source( 'plugin/includes/Illuminate/Subscription/Subscription.php' );
		$checkout   = $this->source( 'plugin/includes/Frontend/Checkout.php' );
		$order      = $this->source( 'plugin/includes/Illuminate/Order.php' );
		$illuminate = $this->source( 'plugin/includes/Illuminate.php' );
		$admin      = $this->source( 'plugin/includes/Admin.php' );

		self::assertStringNotContainsString( 'subscrpt_pro_activated()', $email );
		self::assertStringNotContainsString( 'subscrpt_pro_activated()', $endpoint );
		self::assertStringNotContainsString( '! $order_id || ! subscrpt_pro_activated()', $checkout );
		self::assertStringNotContainsString( 'SubscriptionPro', $order );
		self::assertStringNotContainsString( 'subscription-pro/subscription-pro.php', $admin );
		self::assertStringContainsString( "is_plugin_active( 'woocommerce/woocommerce.php' )", $admin );
		self::assertStringContainsString( "'_subscrpt_plan_total'", $illuminate );
		self::assertStringContainsString( "'_subscrpt_variation_id'", $illuminate );
	}

	/** Verify customer pause and resume actions reach the lifecycle controller. */
	public function test_customer_pause_and_resume_actions_are_wired_to_the_lifecycle_controller(): void {
		$controller = $this->source( 'plugin/includes/Frontend/ActionController.php' );
		$account    = $this->source( 'plugin/includes/Frontend/MyAccount.php' );

		self::assertStringContainsString( "'pause' === \$action", $controller );
		self::assertStringContainsString( "'resume' === \$action", $controller );
		self::assertStringContainsString( 'Helper::pause_subscription', $controller );
		self::assertStringContainsString( 'Helper::resume_subscription', $controller );
		self::assertStringContainsString( "'pause'", $account );
		self::assertStringContainsString( "'resume'", $account );
	}

	/** Verify customer payment-method replacement is scoped to saved tokens. */
	public function test_customer_payment_method_change_is_token_scoped_and_gateway_bounded(): void {
		$controller = $this->source( 'plugin/includes/Frontend/PaymentMethodController.php' );
		$frontend   = $this->source( 'plugin/includes/Frontend.php' );
		$account    = $this->source( 'plugin/includes/Frontend/MyAccount.php' );
		$template   = $this->source( 'plugin/templates/myaccount/single.php' );

		self::assertStringContainsString( 'WC_Payment_Tokens::get', $controller );
		self::assertStringContainsString( 'get_user_id()', $controller );
		self::assertStringContainsString( "'_stripe_source_id'", $controller );
		self::assertStringContainsString( 'hash_equals( $current_source, $token_value )', $controller );
		self::assertStringNotContainsString( "'stripe_cc'", $controller );
		self::assertStringContainsString( 'wp_verify_nonce', $controller );
		self::assertStringContainsString( "'subscrpt_customer_payment_method_gateway_ids'", $controller );
		self::assertStringContainsString( 'new PaymentMethodController();', $frontend );
		self::assertStringContainsString( 'get_subscription_context', $account );
		self::assertStringNotContainsString( 'isset( $saved_methods[\'cc\'] )', $account );
		self::assertStringContainsString( 'subscrpt_payment_method_nonce', $template );
		self::assertStringContainsString( 'Manage saved payment methods', $template );
	}

	/** Verify admin reports and health use standalone views. */
	public function test_admin_reports_and_health_use_standalone_views(): void {
		$menu = $this->source( 'plugin/includes/Admin/Menu.php' );

		self::assertStringContainsString( "include __DIR__ . '/views/reports.php';", $menu );
		self::assertStringContainsString( "include __DIR__ . '/views/health.php';", $menu );
		self::assertStringContainsString( "include __DIR__ . '/views/delivery.php';", $menu );
		self::assertStringNotContainsString( 'if ( ! subscrpt_pro_activated() )', $menu );
		self::assertStringNotContainsString( "include 'views/reports-preview.php';", $menu );
		self::assertStringNotContainsString( "include 'views/health-preview.php';", $menu );
		self::assertStringNotContainsString( "include 'views/delivery-preview.php';", $menu );

		self::assertFileExists( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/reports.php' );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/health.php' );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/delivery.php' );
		self::assertFileDoesNotExist( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/pro-upgrade-modal.php' );
		self::assertFileDoesNotExist( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/reports-preview.php' );
		self::assertFileDoesNotExist( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/health-preview.php' );
		self::assertFileDoesNotExist( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/delivery-preview.php' );
	}

	/** Verify the overview describes optional gateway adapters accurately. */
	public function test_overview_does_not_overclaim_bundled_gateway_support(): void {
		$overview = $this->source( 'plugin/includes/Admin/Subscriptions.php' );

		self::assertStringNotContainsString( 'out of the box', $overview );
		self::assertStringContainsString( 'through their own WooCommerce adapters', $overview );
	}

	/** Verify onboarding exposes the complete standalone plan builder. */
	public function test_onboarding_exposes_the_complete_standalone_plan_builder(): void {
		$wizard = $this->source( 'plugin/includes/Admin/views/onboarding-wizard.php' );

		self::assertStringNotContainsString( 'subscrpt_pro_activated()', $wizard );
		self::assertStringNotContainsString( "'pro'   => true", $wizard );
		self::assertStringNotContainsString( 'readonly max="1"', $wizard );
		self::assertStringContainsString( "'type' => array( 'simple', 'variable' )", $wizard );
	}

	/** Verify subscription details render the core activity log. */
	public function test_subscription_details_renders_the_core_activity_log(): void {
		$details = $this->source( 'plugin/includes/Admin/views/subscription-details.php' );

		self::assertStringContainsString( 'get_comments(', $details );
		self::assertStringContainsString( "'type__in' => array( 'order_note', 'subscription_note' )", $details );
		self::assertStringNotContainsString( 'Activity history is unavailable', $details );
		self::assertStringNotContainsString( 'subscrpt_pro_activated()', $details );
	}

	/** Verify split-payment access end is owned by the standalone helper. */
	public function test_split_payment_access_end_is_owned_by_the_standalone_helper(): void {
		$helper    = $this->source( 'plugin/includes/Illuminate/Helper.php' );
		$myaccount = $this->source( 'plugin/templates/myaccount/single.php' );
		$functions = $this->source( 'plugin/includes/functions.php' );

		self::assertStringContainsString( 'get_split_access_end_date_string', $helper );
		self::assertStringContainsString( 'Helper::get_split_access_end_date_string', $myaccount );
		self::assertStringNotContainsString( 'SpringDevs\\SubscriptionPro\\Illuminate\\SplitPaymentHandler', $myaccount );
		self::assertStringNotContainsString( 'subscrpt_pro_activated()', $myaccount );
		self::assertStringContainsString( "get_post_meta( \$subscription_id, '_subscrpt_max_no_payment', true )", $functions );
		self::assertStringContainsString( "get_post_meta( \$subscription_id, '_subscrpt_payment_type', true )", $functions );
	}

	/** Verify plugin lifecycle has a safe opt-in uninstall path. */
	public function test_plugin_lifecycle_has_a_safe_opt_in_uninstall_path(): void {
		$plugin    = $this->source( 'plugin/bootstrap.php' );
		$uninstall = $this->source( 'plugin/uninstall.php' );

		self::assertStringContainsString( 'register_activation_hook( SUBSCRPT_FILE', $plugin );
		self::assertStringContainsString( 'register_deactivation_hook( SUBSCRPT_FILE', $plugin );
		self::assertStringContainsString( "'subscrpt_hourly_cron'", $plugin );
		self::assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $uninstall );
		self::assertStringContainsString( "get_option( 'subscrpt_remove_data_on_uninstall', false )", $uninstall );
		self::assertStringContainsString( 'Subscriptions and order history are retained by default', $uninstall );
	}

	/** Verify support diagnostics are versioned, read-only, and aggregate-only. */
	public function test_support_diagnostics_are_versioned_read_only_and_aggregate_only(): void {
		$api         = $this->source( 'plugin/includes/API.php' );
		$diagnostics = $this->source( 'plugin/includes/Api/DiagnosticsController.php' );

		self::assertStringContainsString( 'new DiagnosticsController()', $api );
		self::assertStringContainsString( "'/diagnostics'", $diagnostics );
		self::assertStringContainsString( 'WP_REST_Server::READABLE', $diagnostics );
		self::assertStringContainsString( "current_user_can( 'manage_woocommerce' )", $diagnostics );
		self::assertStringContainsString( 'status_counts', $diagnostics );
		self::assertStringContainsString( 'revenue_at_risk', $diagnostics );
		self::assertStringNotContainsString( 'get_users(', $diagnostics );
		self::assertStringNotContainsString( 'get_customer', $diagnostics );
		self::assertStringNotContainsString( '_stripe_secret', $diagnostics );
		self::assertStringNotContainsString( 'webhook_secret', $diagnostics );
	}

	/** Verify deactivation clears queues without touching business records. */
	public function test_deactivation_clears_plugin_owned_queues_without_touching_business_records(): void {
		$plugin    = $this->source( 'plugin/bootstrap.php' );
		$uninstall = $this->source( 'plugin/uninstall.php' );
		$hooks     = array(
			'subscrpt_scheduled_grace_end',
			'subscrpt_retry_renewal_payment',
			'subscrpt_retry_subscription_schedule',
			'subscrpt_queue_trial_order_autocomplete',
			'subscrpt_send_delayed_expired_email',
		);

		self::assertStringContainsString( 'as_unschedule_all_actions', $plugin );
		self::assertStringContainsString( "'ashbi-subscriptions'", $plugin );
		self::assertStringContainsString( 'as_unschedule_all_actions', $uninstall );
		self::assertStringContainsString( "'ashbi-subscriptions'", $uninstall );

		foreach ( $hooks as $hook ) {
			self::assertStringContainsString( "'{$hook}'", $plugin );
			self::assertStringContainsString( "'{$hook}'", $uninstall );
		}

		self::assertStringContainsString( "if ( ! get_option( 'subscrpt_remove_data_on_uninstall', false ) )", $uninstall );
		self::assertStringContainsString( "'subscrpt_recovery_event_retention_days'", $uninstall );
		self::assertStringContainsString( "'subscrpt_recovery_event_last_purge'", $uninstall );
	}

	/** Verify integrations are optional external adapters. */
	public function test_integrations_are_optional_external_adapters_not_paid_plugin_gates(): void {
		$integrations = $this->source( 'plugin/includes/Admin/Integrations.php' );
		$view         = $this->source( 'plugin/includes/Admin/views/integrations.php' );

		self::assertStringContainsString( "'is_optional'", $integrations );
		self::assertStringNotContainsString( "'is_pro'", $integrations );
		self::assertStringNotContainsString( 'requires Pro plugin', $integrations );
		self::assertStringContainsString( 'External', $view );
		self::assertStringNotContainsString( 'SUBSCRIPT_PRO_VERSION', $view );
		self::assertStringNotContainsString( 'Not included in this build', $view );
		self::assertStringNotContainsString( '$is_pro', $view );
	}

	/** Verify settings render as live standalone controls. */
	public function test_settings_are_rendered_as_live_standalone_controls(): void {
		$settings_view = $this->source( 'plugin/includes/Admin/views/settings.php' );
		$helper        = $this->source( 'plugin/includes/Admin/SettingsHelper.php' );
		$fields        = $this->source( 'plugin/includes/Admin/ProSettingsFields.php' );

		self::assertStringNotContainsString( 'category_is_pro_locked', $settings_view );
		self::assertStringNotContainsString( 'pro_badge_html()', $settings_view );
		self::assertStringNotContainsString( 'self::pro_badge_html()', $helper );
		self::assertStringNotContainsString( "'pro_locked'", $fields );
		self::assertStringContainsString( 'register_setting(', $fields );
	}

	/** Verify compatibility helpers never hide standalone features. */
	public function test_legacy_paid_lock_compatibility_helpers_never_hide_standalone_features(): void {
		$helper = $this->source( 'plugin/includes/Admin/SettingsHelper.php' );
		$email  = $this->source( 'plugin/includes/Illuminate/Emails/AdminCancellationEmail.php' );

		self::assertStringContainsString( "public static function group_is_pro_locked( array \$group ) {\n\t\treturn false;", $helper );
		self::assertStringContainsString( "public static function category_is_pro_locked( array \$group_ids, array \$settings_fields ) {\n\t\treturn false;", $helper );
		self::assertStringContainsString( "public static function pro_badge_html() {\n\t\treturn '';", $helper );
		self::assertStringNotContainsString( 'Not included in this build', $helper );
		self::assertStringNotContainsString( 'Unavailable', $helper );
		self::assertStringNotContainsString( 'free plugin', $email );
		self::assertStringNotContainsString( 'Free deliberately', $email );
		self::assertStringNotContainsString( "Pro's", $email );
		self::assertStringNotContainsString( 'Registered only while Pro is inactive', $email );
	}

	/** Verify the dashboard does not render removed paid markers. */
	public function test_dashboard_does_not_render_removed_paid_feature_markers(): void {
		$app     = $this->source( 'plugin/src/dashboard/App.js' );
		$cards   = $this->source( 'plugin/src/dashboard/BuildCards.js' );
		$icon    = $this->source( 'plugin/src/dashboard/Icon.js' );
		$support = $this->source( 'plugin/includes/Admin/views/support.php' );

		self::assertStringNotContainsString( 'ProBadge', $app );
		self::assertStringNotContainsString( 'chart.pro', $app );
		self::assertStringNotContainsString( 'ProBadge', $cards );
		self::assertStringNotContainsString( 'card.pro', $cards );
		self::assertStringNotContainsString( 'free plugin', $icon );
		self::assertStringNotContainsString( 'marked unavailable', $support );
		self::assertStringNotContainsString( 'Not included in this build', $support );
	}

	/** Verify switching is a complete idempotent paid-order flow. */
	public function test_switching_is_a_complete_paid_order_flow_with_idempotent_application(): void {
		$switching  = $this->source( 'plugin/includes/Illuminate/Switching.php' );
		$illuminate = $this->source( 'plugin/includes/Illuminate.php' );
		$myaccount  = $this->source( 'plugin/includes/Frontend/MyAccount.php' );
		$template   = $this->source( 'plugin/templates/myaccount/single.php' );

		self::assertStringContainsString( 'woocommerce_payment_complete', $switching );
		self::assertStringContainsString( 'subscrpt_switch_applied', $switching );
		self::assertStringContainsString( 'subscrpt_order_relation', $switching );
		self::assertStringContainsString( 'new Switching();', $illuminate );
		self::assertStringContainsString( 'Switching::get_options', $myaccount );
		self::assertStringContainsString( 'subscrpt_switch_submit', $template );
		self::assertStringContainsString( 'subscrpt_switch_nonce', $template );
	}

	/** Verify early renewal is a paid-order flow that preserves active access. */
	public function test_early_renewal_is_a_paid_order_flow_and_does_not_cancel_active_access(): void {
		$action  = $this->source( 'plugin/includes/Frontend/ActionController.php' );
		$helper  = $this->source( 'plugin/includes/Illuminate/Helper.php' );
		$order   = $this->source( 'plugin/includes/Illuminate/Order.php' );
		$account = $this->source( 'plugin/includes/Frontend/MyAccount.php' );

		self::assertStringContainsString( "'early-renew' === \$action", $action );
		self::assertStringContainsString( 'Helper::create_early_renewal_order', $action );
		self::assertStringContainsString( 'create_early_renewal_order', $helper );
		self::assertStringContainsString( "'early-renew'", $helper );
		self::assertStringContainsString( "'early-renew' === \$history->type", $order );
		self::assertStringContainsString( 'woocommerce_payment_complete', $order );
		self::assertStringContainsString( "'subscrpt_early_renew'", $account );
		self::assertStringContainsString( "'early-renew'", $account );
		self::assertStringContainsString( 'is_numeric( $filtered_next_date )', $order );
	}

	/** Verify renewal pricing and variation resolution preserve snapshots. */
	public function test_renewal_price_and_variation_resolution_preserve_subscription_snapshots(): void {
		$auto_renewal = $this->source( 'plugin/includes/Illuminate/AutoRenewal.php' );
		$helper       = $this->source( 'plugin/includes/Illuminate/Helper.php' );
		$order        = $this->source( 'plugin/includes/Illuminate/Order.php' );

		self::assertStringContainsString( "'_subscrpt_variation_id'", $auto_renewal );
		self::assertStringContainsString( "'_subscrpt_custom_renewal_price'", $auto_renewal );
		self::assertStringContainsString( 'apply_filters( \'subscrpt_renewal_product_args\'', $helper );
		self::assertStringContainsString( '$subscription_id', $helper );
		self::assertStringContainsString( "'_subscrpt_price'", $helper );
		self::assertStringContainsString( "'_subscrpt_renewal_payment_failed'", $order );
		self::assertStringContainsString( "'_subscrpt_renewal_recovered'", $order );
		self::assertStringContainsString( "woocommerce_order_status_changed', array( \$this, 'order_status_changed' ), 10, 2", $order );
	}

	/** Verify custom renewal price control has capability and nonce protection. */
	public function test_custom_renewal_price_has_a_capability_and_nonce_protected_admin_control(): void {
		$menu = $this->source( 'plugin/includes/Admin/Menu.php' );
		$view = $this->source( 'plugin/includes/Admin/views/subscription-details.php' );

		self::assertStringContainsString( 'subscrpt_custom_renewal_price_nonce', $menu );
		self::assertStringContainsString( '_subscrpt_custom_renewal_price', $menu );
		self::assertStringContainsString( 'delete_post_meta', $menu );
		self::assertStringContainsString( 'subscrpt_custom_renewal_price_nonce', $view );
		self::assertStringContainsString( 'Leave blank to use the store renewal setting', $view );
	}

	/** Verify plan product management has no paid add-on lock. */
	public function test_plan_product_management_has_no_paid_addon_lock(): void {
		$products_tab = $this->source( 'plugin/includes/Admin/views/plans/tab-products.php' );
		$term_modal   = $this->source( 'plugin/includes/Admin/views/plans/modal-term.php' );

		self::assertStringNotContainsString( '$pro_active', $products_tab );
		self::assertStringNotContainsString( 'if ( $pro_active )', $products_tab );
		self::assertStringNotContainsString( '$adv_lock', $term_modal );
		self::assertStringNotContainsString( '$pro_badge', $term_modal );
	}
}

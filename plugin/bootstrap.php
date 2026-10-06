<?php
/**
 * Plugin runtime, loaded only after coexistence checks pass.
 *
 * @package Subscription
 */
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use SpringDevs\Subscription\Illuminate\Gateways\Paypal\Paypal_Blocks_Integration;

require_once __DIR__ . '/vendor/autoload.php';

// Disposable integration endpoint. Its callback is local-environment/admin-only,.
// and the tests directory is excluded from release packages.
if ( ! function_exists( 'ashbi_run_security_boundary_integration_checks' ) && file_exists( __DIR__ . '/tests/integration/security-boundaries.php' ) ) {
	require_once __DIR__ . '/tests/integration/security-boundaries.php';
}

/**
 * Sdevs_Subscription class
 *
 * @class Sdevs_Subscription The class that holds the entire plugin
 */
final class Sdevs_Subscription {


	/**
	 * Plugin version
	 *
	 * @var string
	 */
	const VERSION = '2.1.2';

	/**
	 * Holds various class instances
	 *
	 * @var array
	 */
	private $container = array();

	/**
	 * Whether WooCommerce accepted the HPOS compatibility declaration.
	 *
	 * @var bool
	 */
	private $hpos_compatibility_declared = false;

	/**
	 * Constructor for the Sdevs_Wc_Subscription class
	 *
	 * Sets up all the appropriate hooks and actions
	 * within our plugin.
	 */
	private function __construct() {
		$this->define_constants();
		// Register before WooCommerce's plugins_loaded callbacks can load Blocks.
		$this->container['block'] = new SpringDevs\Subscription\Illuminate\Block();

		register_activation_hook( SUBSCRPT_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( SUBSCRPT_FILE, array( $this, 'deactivate' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ), 5 );

		// WooCommerce loads its core helper functions during its plugin bootstrap.
		// Initialize after the other plugins_loaded callbacks so activation and.
		// disposable integration requests can rely on those helpers being ready.
		add_action( 'plugins_loaded', array( $this, 'init_plugin' ), 20 );
	}

	/**
	 * Initializes the Sdevs_Wc_Subscription() class
	 *
	 * Checks for an existing Sdevs_Wc_Subscription() instance
	 * and if it doesn't find one, creates it.
	 *
	 * @return Sdevs_Subscription|bool
	 */
	public static function init() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new Sdevs_Subscription();
		}

		return $instance;
	}

	/**
	 * Magic getter to bypass referencing plugin.
	 *
	 * @param mixed $prop Prop.
	 *
	 * @return mixed
	 */
	public function __get( $prop ) {
		if ( array_key_exists( $prop, $this->container ) ) {
			return $this->container[ $prop ];
		}

		return $this->{$prop};
	}

	/**
	 * Magic isset to bypass referencing plugin.
	 *
	 * @param mixed $prop Prop.
	 *
	 * @return bool
	 */
	public function __isset( $prop ) {
		return isset( $this->{$prop} ) || isset( $this->container[ $prop ] );
	}

	/**
	 * Define the constants
	 *
	 * @return void
	 */
	public function define_constants() {
		define( 'SUBSCRPT_VERSION', self::VERSION );
		define( 'SUBSCRPT_PATH', dirname( SUBSCRPT_FILE ) );
		define( 'SUBSCRPT_INCLUDES', SUBSCRPT_PATH . '/includes' );
		define( 'SUBSCRPT_TEMPLATES', SUBSCRPT_PATH . '/templates/' );
		define( 'SUBSCRPT_URL', plugins_url( '', SUBSCRPT_FILE ) );
		define( 'SUBSCRPT_ASSETS', SUBSCRPT_URL . '/assets' );

		// Load legacy constant aliases (WP_SUBSCRIPTION_* → SUBSCRPT_*) for.
		// backwards compatibility with subscription-pro and third-party code.
		require_once SUBSCRPT_INCLUDES . '/LegacyCompat.php';
	}

	/**
	 * Load the plugin after all plugins are loaded
	 *
	 * @return void
	 */
	public function init_plugin() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Declare compatibility with WooCommerce High-Performance Order Storage.
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SUBSCRPT_FILE, true );
			$this->hpos_compatibility_declared = true;
		}
	}

	/**
	 * Install database tables and schedule the deferred rewrite flush.
	 */
	public function activate() {
		$installer = new SpringDevs\Subscription\Installer();
		$installer->run();
		update_option( 'subscrpt_rewrite_flush_pending', 1 );
	}

	/**
	 * Stop plugin-owned recurring and queued work when the plugin is disabled.
	 *
	 * Business records and payment references are intentionally left intact. The
	 * next activation can rebuild durable work from the subscription/order state.
	 *
	 * @return void
	 */
	public function deactivate() {
		$cron_hooks = array(
			'subscrpt_hourly_cron',
			'subscrpt_daily_cron',
			'subscrpt_scheduled_grace_end',
			'subscrpt_retry_renewal_payment',
			'subscrpt_retry_subscription_schedule',
			'subscrpt_queue_trial_order_autocomplete',
			'subscrpt_send_delayed_expired_email',
		);

		foreach ( $cron_hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		$action_hooks = array(
			'subscrpt_scheduled_grace_end',
			'subscrpt_retry_renewal_payment',
			'subscrpt_retry_subscription_schedule',
			'subscrpt_queue_trial_order_autocomplete',
		);

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( $action_hooks as $hook ) {
				as_unschedule_all_actions( $hook, array(), 'ashbi-subscriptions' );
			}
		}
	}

	/**
	 * Include the required files
	 *
	 * @return void
	 */
	public function includes() {
		// Include functions file first to ensure global functions are available.
		require_once SUBSCRPT_INCLUDES . '/functions.php';
		require_once SUBSCRPT_INCLUDES . '/Admin/AdminComponents.php';

		if ( $this->is_request( 'admin' ) ) {
			$this->container['admin'] = new SpringDevs\Subscription\Admin();
		}

		if ( $this->is_request( 'frontend' ) ) {
			$this->container['frontend'] = new SpringDevs\Subscription\Frontend();
		}

		$this->container['illuminate'] = new SpringDevs\Subscription\Illuminate();
	}

	/**
	 * Initialize the hooks
	 *
	 * @return void
	 */
	public function init_hooks() {
		add_action( 'init', array( $this, 'init_classes' ) );
		add_action( 'init', array( $this, 'localization_setup' ) );
		add_action( 'init', array( $this, 'run_update' ) );

		// Register the subscription record type before lifecycle services run.
		add_action(
			'init',
			function () {
				register_post_type(
					'subscrpt_order',
					array(
						'public'          => false,
						'show_ui'         => true,
						'show_in_menu'    => false,
						'supports'        => array( 'title' ),
						'capability_type' => 'post',
						'capabilities'    => array(
							'create_posts' => false,
						),
						'map_meta_cap'    => true,
					)
				);
			},
			0
		);
	}

	/**
	 * Need to do some actions after update plugin
	 *
	 * @return void
	 */
	public function run_update() {
		$upgrade = new \SpringDevs\Subscription\Upgrade();
		$upgrade->run();
	}

	/**
	 * Instantiate the required classes
	 *
	 * @return void
	 */
	public function init_classes() {
		if ( $this->is_request( 'ajax' ) ) {
			$this->container['ajax']            = new SpringDevs\Subscription\Ajax();
			$this->container['onboarding_ajax'] = new SpringDevs\Subscription\Admin\OnboardingAjax();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'ashbi-subscriptions migrate-variations', new SpringDevs\Subscription\Illuminate\Migration\VariationMigrationCommand() );
		}

		$this->container['api']    = new SpringDevs\Subscription\API();
		$this->container['assets'] = new SpringDevs\Subscription\Assets();
	}

	/**
	 * Initialize plugin for localization
	 *
	 * Note: WordPress automatically loads translations for plugins hosted on WordPress.org
	 * since version 4.6, so manual loading is not required.
	 */
	public function localization_setup() {
		// WordPress auto-loads translations for plugins hosted on WordPress.org since v4.6.
	}

	/**
	 * What type of request is this?
	 *
	 * @param string $type admin, ajax, cron or frontend.
	 *
	 * @return bool
	 */
	private function is_request( $type ) {
		switch ( $type ) {
			case 'admin':
				return is_admin();

			case 'ajax':
				return defined( 'DOING_AJAX' );

			case 'rest':
				return defined( 'REST_REQUEST' );

			case 'cron':
				return defined( 'DOING_CRON' );

			case 'frontend':
				return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
		}

		return false;
	}
} // Sdevs_Wc_Subscription.

// Add Paypal Gateway Blocks.
if ( ! function_exists( 'subscrpt_register_paypal_block' ) ) {
	/**
	 * Register the PayPal block for WooCommerce Blocks.
	 */
	function subscrpt_register_paypal_block() {
		// Check if the required class exists.
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		// Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action.
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new Paypal_Blocks_Integration() );
			}
		);
	}

	// Register PayPal integration only if WordPress functions are available.
	if ( function_exists( 'get_option' ) ) {
		// Is PayPal integration enabled?
		$is_paypal_integration_enabled = 'on' === get_option( 'wp_subs_paypal_integration_enabled', 'off' );
		if ( $is_paypal_integration_enabled ) {
			add_action( 'woocommerce_blocks_loaded', 'subscrpt_register_paypal_block' );
		}
	}
}

/**
 * Initialize the main plugin
 *
 * @return Sdevs_Subscription|bool
 */
function sdevs_subscription() {
	return Sdevs_Subscription::init();
}

/**
 *  Kick-off the plugin
 */
sdevs_subscription();

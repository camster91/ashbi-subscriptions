<?php
/**
 * Admin bootstrap.
 *
 * @package SpringDevs\Subscription
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- Legacy class filename is part of the public plugin compatibility contract.

namespace SpringDevs\Subscription;

use SpringDevs\Subscription\Admin\Dashboard;
use SpringDevs\Subscription\Admin\Integrations;
use SpringDevs\Subscription\Admin\Required;
use SpringDevs\Subscription\Admin\Links;
use SpringDevs\Subscription\Admin\CancellationFlow;
use SpringDevs\Subscription\Admin\Menu;
use SpringDevs\Subscription\Admin\Order as AdminOrder;
use SpringDevs\Subscription\Admin\Plans;
use SpringDevs\Subscription\Admin\Product;
use SpringDevs\Subscription\Admin\ProSettingsFields;
use SpringDevs\Subscription\Admin\Settings;
use SpringDevs\Subscription\Admin\Subscriptions;
use SpringDevs\Subscription\Illuminate\Comments;

/**
 * The admin class
 */
class Admin {

	/**
	 * Initialize the class
	 */
	public function __construct() {
		$this->dispatch_actions();
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// Ashbi Subscriptions only depends on WooCommerce. Required() owns the.
		// missing-dependency notice; no paid vendor plugin is needed to load the.
		// standalone admin.
		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			new Required();
			return;
		}

		new Required();
		new Menu();
		new Dashboard();
		new Plans();
		new CancellationFlow();
		new Product\Plans();
		new Integrations();
		new Product();
		new Subscriptions();
		new AdminOrder();
		new Comments();
		new Settings();
		new ProSettingsFields();
		new Links();
	}

	/**
	 * Dispatch and bind actions
	 *
	 * @return void
	 */
	public function dispatch_actions() {
		add_action( 'save_post_product', array( $this, 'flush_gsc_cache' ) );
	}

	/**
	 * Bust the GSC product-exists cache when any product is saved.
	 *
	 * @return void
	 */
	public function flush_gsc_cache() {
		delete_transient( 'subscrpt_has_enabled_product' );
	}
}

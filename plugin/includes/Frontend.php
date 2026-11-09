<?php
/**
 * Frontend bootstrap.
 *
 * @package SpringDevs\Subscription
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- Legacy class filename is part of the public plugin compatibility contract.

namespace SpringDevs\Subscription;

use SpringDevs\Subscription\Frontend\ActionController;
use SpringDevs\Subscription\Frontend\Cart;
use SpringDevs\Subscription\Frontend\Downloadable;
use SpringDevs\Subscription\Frontend\MyAccount;
use SpringDevs\Subscription\Frontend\Order as FrontendOrder;
use SpringDevs\Subscription\Frontend\Plans;
use SpringDevs\Subscription\Frontend\Product;
use SpringDevs\Subscription\Frontend\PaymentMethodController;

/**
 * Frontend handler class
 */
class Frontend {

	/**
	 * Frontend constructor.
	 */
	public function __construct() {
		new \SpringDevs\Subscription\Frontend\ContractConsent();
		new Product();
		// Ashbi owns the storefront plan UI, including multi-plan selectors and.
		// per-variation terms. It is not delegated to the legacy paid plugin.
		new Plans();
		new Cart();
		new FrontendOrder();
		new ActionController();
		new PaymentMethodController();
		new MyAccount();
		new Downloadable();
	}
}

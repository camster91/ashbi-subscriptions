<?php
/**
 * Main subscription class.
 *
 * Compatibility wrapper for product helpers and customer endpoints. The
 * legacy Helper remains the lifecycle boundary for the imported public API;
 * moving those methods is deferred until a separately tested namespace
 * migration can preserve extension behavior.
 *
 * @package Subscription
 */

// PSR-4 class filename is retained for the public subscription compatibility path.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Illuminate\Subscription;

use SpringDevs\Subscription\Illuminate\Helper;
use SpringDevs\Subscription\Utils\Product;
use SpringDevs\Subscription\Utils\ProductFactory;
use WC_Product;

/**
 * Class Subscription
 *
 * @package Illuminate\Subscription
 */
class Subscription {
	/**
	 * Get WC product in subscription wrapper.
	 *
	 * @param WC_Product|int $product Product.
	 * @return Product|false
	 */
	public static function get_subs_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( absint( $product ) );
		}

		return $product ? ProductFactory::load( $product ) : false;
	}

	/**
	 * Get subscription endpoint.
	 *
	 * @param string $view View type.
	 * @return string
	 */
	public static function get_user_endpoint( string $view = 'subs_list' ) {
		switch ( strtolower( $view ) ) {
			case 'view_subs':
				$default_endpoint = 'subscription';
				$endpoint         = get_option( 'wpsubs_custom_view_subscription_endpoint', $default_endpoint );
				break;

			case 'subs_list':
			default:
				$default_endpoint = 'subscriptions';
				$endpoint         = get_option( 'wpsubs_custom_subscriptions_endpoint', $default_endpoint );
				break;
		}

		return untrailingslashit( $endpoint );
	}
}

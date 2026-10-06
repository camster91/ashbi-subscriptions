<?php
/**
 * Plugin Name: Ashbi Subscriptions
 * Description: Adds recurring purchases, subscription management, and automated renewals to WooCommerce stores.
 *
 * Version: 2.1.2
 * Update URI: false
 *
 * Author: Ashbi
 *
 * Text Domain: subscription
 * Domain Path: /languages
 *
 * Requires PHP: 7.4
 *
 * Requires at least: 6.2
 * Tested up to: 7.1
 *
 * WC requires at least: 6.0
 * WC tested up to: 10.3
 *
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Subscription
 */
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed

// don't call the file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ashbi_subscriptions_detect_conflict' ) ) {
	/**
	 * Detect another loaded owner of the shared plugin identity.
	 *
	 * @param string $own_file Main plugin file.
	 * @return bool
	 */
	function ashbi_subscriptions_detect_conflict( string $own_file ): bool {
		$own_path = realpath( $own_file );
		if ( class_exists( 'Sdevs_Subscription', false ) ) {
			$owner = new ReflectionClass( 'Sdevs_Subscription' );
			if ( realpath( $owner->getFileName() ) !== realpath( dirname( $own_file ) . '/bootstrap.php' ) ) {
				return true;
			}
		}
		foreach ( array( 'WP_SUBSCRIPTION_FILE', 'SUBSCRPT_FILE' ) as $constant ) {
			if ( defined( $constant ) && realpath( constant( $constant ) ) !== $own_path ) {
				return true;
			}
		}
		return false;
	}
}

if ( ashbi_subscriptions_detect_conflict( __FILE__ ) ) {
	if ( ! function_exists( 'ashbi_subscriptions_core_conflict_notice' ) ) {
		/** Display the shared-runtime conflict to plugin administrators. */
		function ashbi_subscriptions_core_conflict_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ashbi Subscriptions is inactive because another subscription plugin (WP Subscription Core / WPSubscription) is active and must be deactivated first.', 'subscription' ) . '</p></div>';
		}
	}
	add_action( 'admin_notices', 'ashbi_subscriptions_core_conflict_notice' );
	register_activation_hook(
		__FILE__,
		function () {
			wp_die( esc_html__( 'Deactivate WP Subscription Core first, then activate Ashbi Subscriptions', 'subscription' ) );
		}
	);
	return;
}

if ( ! defined( 'SUBSCRPT_FILE' ) ) {
	define( 'SUBSCRPT_FILE', __FILE__ );
}
require_once __DIR__ . '/bootstrap.php';

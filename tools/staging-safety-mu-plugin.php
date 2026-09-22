<?php
/**
 * Fail-closed safety controls for isolated Ashbi subscription rehearsals.
 *
 * @package AshbiSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'staging' !== wp_get_environment_type() ) {
	return;
}

add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'woocommerce_available_payment_gateways', '__return_empty_array', PHP_INT_MAX );
add_filter( 'woocommerce_cart_needs_payment', '__return_false', PHP_INT_MAX );
add_filter( 'woocommerce_order_needs_payment', '__return_false', PHP_INT_MAX );

add_filter(
	'pre_http_request',
	static function ( $preempt, $parsed_args, $url ) {
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return $preempt;
		}

		return new WP_Error( 'ashbi_staging_external_http_blocked', 'External HTTP requests are disabled on this staging rehearsal.' );
	},
	PHP_INT_MAX,
	3
);

add_filter(
	'wp_robots',
	static function ( array $robots ): array {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	},
	PHP_INT_MAX
);

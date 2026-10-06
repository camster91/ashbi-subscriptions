<?php
/**
 * Disposable hosted validation only; never shipped in the candidate ZIP.
 *
 * @package AshbiSubscriptions
 */

add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( array( 'stripe.com', 'paypal.com', 'paypalobjects.com' ) as $provider ) {
			if ( $host === $provider || substr( $host, -strlen( '.' . $provider ) ) === '.' . $provider ) {
				return new WP_Error( 'ashbi_validation_no_provider', 'Provider requests are disabled in package validation.' );
			}
		}
		return $response;
	},
	PHP_INT_MAX,
	3
);

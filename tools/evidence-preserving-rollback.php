<?php
/**
 * Optional MU recovery companion: disable new consent while retaining protections.
 *
 * @package AshbiSubscriptions
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'option_wp_subscription_contract_revision',
	static function ( $document ) {
		if ( is_array( $document ) ) {
			$document['enabled'] = false;
		}
		return $document;
	},
	PHP_INT_MAX
);

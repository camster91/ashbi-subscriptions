<?php
/**
 * Unit-test bootstrap.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		return $GLOBALS['ashbi_orders'][ $order_id ] ?? $GLOBALS['ashbi_counting_orders'][ $order_id ] ?? false;
	}
}

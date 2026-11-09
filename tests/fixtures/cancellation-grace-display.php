<?php
/**
 * Read real subscription display data against fabricated lifecycle metadata.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable -- Compact isolated WordPress metadata doubles.
define( 'DAY_IN_SECONDS', 86400 );
function get_post_meta( $id, $key = '', $single = false ) {
	$meta = array( '_subscrpt_next_date' => time() - 60, '_subscrpt_auto_renew' => 0 );
	return '' === $key ? $meta : ( $meta[ $key ] ?? '' );
}
function get_post( $id ) { return (object) array( 'post_author' => 7 ); }
function get_post_status( $id ) { return $GLOBALS['grace_status']; }
function get_option( $key, $default = false ) { return $default; }
function subscrpt_subscription_auto_renew_enabled( $id ) { return false; }
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Helper.php';
$result = array();
foreach ( array( 'active', 'on_hold', 'expired', 'pe_cancelled', 'cancelled', 'pending', 'completed' ) as $status ) {
	$GLOBALS['grace_status'] = $status;
	$result[ $status ] = \SpringDevs\Subscription\Illuminate\Helper::get_subscription_data( 99 );
}
echo json_encode( $result );

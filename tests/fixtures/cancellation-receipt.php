<?php
/**
 * Render actual cancellation notices using isolated, fabricated storage.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable -- Compact WordPress/database doubles for a read-only rendering test.
define( 'ABSPATH', '/' );
define( 'SUBSCRPT_ASSETS', '/fixture-assets' );
define( 'SUBSCRPT_VERSION', 'fixture' );
define( 'SUBSCRPT_TEMPLATES', dirname( __DIR__, 2 ) . '/plugin/templates/' );
function get_post_status( $id ) { return $GLOBALS['receipt_case']['status']; }
function get_post_meta( $id, $key, $single = true ) {
	return '_ashbi_cancellation_confirmed' === $key ? $GLOBALS['receipt_case']['cached_flag'] : time() + 172800;
}
function get_option( $key, $default = false ) { return 'date_format' === $key ? 'Y-m-d' : ( 'time_format' === $key ? 'H:i' : $default ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function wp_enqueue_style( ...$args ) {}
function date_i18n( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function wp_date( $format, $timestamp ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'America/Toronto' ) )->format( $format ); }
function wc_get_template( $path, $args, $unused, $base ) { extract( $args ); include $base . $path; }
function update_post_meta( ...$args ) { throw new RuntimeException( 'A receipt view must never change subscription data.' ); }
class ReceiptDatabase {
	public $prefix = 'wp_';
	public $postmeta = 'wp_postmeta';
	public $last_error = '';
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_row( $sql ) {
		if ( 'throw' === $GLOBALS['receipt_case']['error'] ) { throw new RuntimeException( 'fabricated storage exception' ); }
		$this->last_error = $GLOBALS['receipt_case']['error'] ? 'fabricated database unavailable' : '';
		return $GLOBALS['receipt_case']['row'];
	}
}
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/CancellationEvidence.php';
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Cancellation.php';
$service = ( new ReflectionClass( 'SpringDevs\\Subscription\\Illuminate\\Cancellation' ) )->newInstanceWithoutConstructor();
$end = strtotime( '2026-11-01 06:30:00 UTC' );
$cases = array(
	'confirmed' => array( 'cancelled', (object) array( 'access_end' => $end, 'confirmed' => '1' ), 0, false ),
	'pending' => array( 'cancelled', (object) array( 'access_end' => $end, 'confirmed' => '0' ), 1, false ),
	'status_write_pending' => array( 'active', (object) array( 'access_end' => $end, 'confirmed' => '0' ), 1, false ),
	'legacy' => array( 'pe_cancelled', null, 0, false ),
	'no_record' => array( 'cancelled', null, 1, false ),
	'unavailable' => array( 'cancelled', null, 1, true ),
	'exception' => array( 'cancelled', null, 1, 'throw' ),
	'protected_access' => array( 'pe_cancelled', (object) array( 'access_end' => $end, 'confirmed' => '1' ), 0, false ),
);
$results = array();
foreach ( $cases as $name => $case ) {
	$GLOBALS['receipt_case'] = array_combine( array( 'status', 'row', 'cached_flag', 'error' ), $case );
	$GLOBALS['wpdb'] = new ReceiptDatabase();
	foreach ( array( 'first', 'reload' ) as $read ) {
		ob_start(); $service->display_pending_cancellation_notice( 99 ); $results[ $name ][ $read ] = ob_get_clean();
	}
}
echo json_encode( $results );

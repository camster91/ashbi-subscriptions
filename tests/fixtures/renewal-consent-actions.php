<?php
/**
 * Real account consent actions with offline WordPress authorization doubles.
 *
 * @package AshbiSubscriptions\Tests
 */

define( 'CONSENT_FIXTURE_BOOTSTRAP_ONLY', true );
require __DIR__ . '/renewal-consent.php';

/** Sanitize fixture input.
 *
 * @param mixed $value Value.
 * @return string
 */
function sanitize_text_field( $value ) {
	return (string) $value; }
/** Fixture unslash.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {
	return $value; }
/** Fixture login.
 *
 * @return bool
 */
function is_user_logged_in() {
	return 0 !== $GLOBALS['action_case']['user']; }
/** Fixture nonce check.
 *
 * @param string $nonce Nonce.
 * @param string $action Action.
 * @return bool
 */
function wp_verify_nonce( $nonce, $action ) {
	return 'fixture-valid' === $nonce && 'subscrpt_nonce' === $action; }
/** Fixture current user.
 *
 * @return int
 */
function get_current_user_id() {
	return $GLOBALS['action_case']['user']; }
/** Fixture admin capability.
 *
 * @param string $capability Capability.
 * @return bool
 */
function current_user_can( $capability ) {
	return $GLOBALS['action_case']['admin'] && 'manage_options' === $capability; }
/** Fixture notice.
 *
 * @param string $notice Notice.
 * @param string $type Type.
 */
function wc_add_notice( $notice, $type ) {}
/** Fixture account URL.
 *
 * @param string $page Page.
 * @return string
 */
function wc_get_page_permalink( $page ) {
	return 'https://example.test/' . $page; }
/** Fixture endpoint URL.
 *
 * @param string $endpoint Endpoint.
 * @param mixed  $id ID.
 * @param string $base Base.
 * @return string
 */
function wc_get_endpoint_url( $endpoint, $id, $base ) {
	return $base . '/' . $endpoint . '/' . $id; }
/** Capture a redirect without a real request.
 *
 * @param string $url URL.
 * @return bool
 */
function wp_safe_redirect( $url ) {
	$GLOBALS['fixture_redirects'][] = $url;
	return true; }
/** Fixture endpoint trimming.
 *
 * @param string $text Text.
 * @return string
 */
function untrailingslashit( $text ) {
	return rtrim( $text, '/' ); }
/** Fixture filter.
 *
 * @param string $hook Hook.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	return $value; }

require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Subscription/Subscription.php';
require dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/ActionController.php';
$GLOBALS['consent_options']  = array( 'wp_subscription_renewal_process' => 'auto' );
$GLOBALS['consent_meta'][99] = array( '_subscrpt_auto_renew' => $GLOBALS['action_case']['initial'] );
$GLOBALS['consent_writes']   = array();
$_GET                        = array(
	'subscrpt_id' => '99',
	'action'      => $GLOBALS['action_case']['action'],
	'wpnonce'     => $GLOBALS['action_case']['nonce'],
);
register_shutdown_function(
	function () {
		$data = SpringDevs\Subscription\Illuminate\Helper::get_subscription_data( 99 );
		echo json_encode(
			array(
				'consent' => $data['is_auto_renew'],
				'writes'  => count( $GLOBALS['consent_writes'] ),
				'raw'     => $GLOBALS['consent_meta'][99]['_subscrpt_auto_renew'],
			)
		);
	}
);
$controller = ( new ReflectionClass( SpringDevs\Subscription\Frontend\ActionController::class ) )->newInstanceWithoutConstructor();
$controller->control_action_subscrpt();

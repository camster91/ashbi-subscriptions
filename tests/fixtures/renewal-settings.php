<?php
/**
 * Renewal settings fixture: real registration and sanitizers, no WordPress DB.
 *
 * @package AshbiSubscriptions\Tests
 */

/** Install a controlled WordPress global in this isolated process only.
 *
 * @param string $name Global name.
 * @param mixed  $value Fixture value.
 */
function set_renewal_fixture_global( $name, $value ) {
	$GLOBALS[ $name ] = $value;
}

/** Capture registered callbacks.
 *
 * @param string $group Group.
 * @param string $name Name.
 * @param array  $args Arguments.
 */
function register_setting( $group, $name, $args ) {
	$GLOBALS['registered_settings'][ $name ] = $args;
}
/** Fixture actions.
 *
 * @param string $hook Hook.
 * @param mixed  ...$args Arguments.
 */
function do_action( $hook, ...$args ) {}
/** Read fixture options, preserving present false/empty values.
 *
 * @param string $name Name.
 * @param mixed  $fallback Default.
 * @return mixed
 */
function get_option( $name, $fallback = false ) {
	return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $fallback;
}
/** Sanitize role keys.
 *
 * @param string $key Key.
 * @return string
 */
function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
}
/** Translate fixture role.
 *
 * @param string $name Name.
 * @return string
 */
function translate_user_role( $name ) {
	return $name; }
/** Translate fixture text.
 *
 * @param string $text Text.
 * @param string $domain Domain.
 * @return string
 */
function __( $text, $domain ) {
	return 'subscription' === $domain ? $text : ''; }
/** Capture validation errors.
 *
 * @param string $setting Setting.
 * @param string $code Code.
 * @param string $message Message.
 * @param string $type Type.
 */
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['fixture_errors'][] = array( $message, $type );
}

$GLOBALS['options'] = array();
set_renewal_fixture_global(
	'wp_roles',
	(object) array(
		'roles' => array(
			'customer'      => array(
				'name'         => 'Customer',
				'capabilities' => array( 'read' => true ),
			),
			'subscriber'    => array(
				'name'         => 'Subscriber',
				'capabilities' => array( 'read' => true ),
			),
			'safe_fixture'  => array(
				'name'         => 'Safe customer',
				'capabilities' => array( 'manage_options' => false ),
			),
			'administrator' => array(
				'name'         => 'Admin',
				'capabilities' => array( 'manage_options' => true ),
			),
		),
	)
);
define( 'ABSPATH', '/' );
require dirname( __DIR__, 2 ) . '/plugin/includes/functions.php';
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/RoleManagement.php';
require dirname( __DIR__, 2 ) . '/plugin/includes/Admin/Settings.php';
$fixture_settings = ( new ReflectionClass( SpringDevs\Subscription\Admin\Settings::class ) )->newInstanceWithoutConstructor();
$fixture_settings->register_settings();
$callback = $GLOBALS['registered_settings']['wp_subscription_renewal_process']['sanitize_callback'];
$result   = array();
foreach ( array( 'auto', 'manual' ) as $renewal_mode ) {
	$result['saved'][ $renewal_mode ] = call_user_func( $callback, $renewal_mode );
}
$result['allowed_roles'] = array_keys( SpringDevs\Subscription\Illuminate\RoleManagement::get_allowed_customer_roles() );
$result['active_role']   = call_user_func( $GLOBALS['registered_settings']['wp_subscription_active_role']['sanitize_callback'], 'administrator' );
$result['invalid']       = array();

foreach ( array( 'customer', 'subscriber', '', false, null, array(), 'AUTO', 1 ) as $invalid ) {
	$result['invalid'][] = call_user_func( $callback, $invalid );
}
$result['errors'] = $GLOBALS['fixture_errors'] ?? array();
$cases            = array(
	'missing'        => array(),
	'legacy_manual'  => array( 'subscrpt_renewal_process' => 'manual' ),
	'legacy_auto'    => array( 'subscrpt_renewal_process' => 'auto' ),
	'current_manual' => array(
		'wp_subscription_renewal_process' => 'manual',
		'subscrpt_renewal_process'        => 'auto',
	),
	'corrupt'        => array(
		'wp_subscription_renewal_process' => 'customer',
		'subscrpt_renewal_process'        => 'auto',
	),
	'empty'          => array(
		'wp_subscription_renewal_process' => '',
		'subscrpt_renewal_process'        => 'auto',
	),
	'false'          => array(
		'wp_subscription_renewal_process' => false,
		'subscrpt_renewal_process'        => 'auto',
	),
);
foreach ( $cases as $name => $options ) {
	$GLOBALS['options']           = $options;
	$result['resolved'][ $name ]  = subscrpt_get_renewal_process();
	$result['unchanged'][ $name ] = $GLOBALS['options'] === $options;
}
echo json_encode( $result );

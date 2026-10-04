<?php
/**
 * Minimal WordPress harness for actual plugin bootstrap inclusion.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.GlobalVariablesOverride, Generic.NamingConventions.UpperCaseConstantName -- Isolated WordPress stubs, no production environment.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped

define( 'ABSPATH', __DIR__ . '/' );
$main         = dirname( __DIR__, 2 ) . '/plugin/subscription.php';
$scenario     = $argv[1];
$hooks        = array();
$activation   = array();
$deactivation = array();
function add_action( $hook, $callback, ...$args ) {
	$GLOBALS['hooks'][ $hook ][] = $callback;
}
function add_filter( ...$args ) {}
function register_activation_hook( $file, $callback ) {
	$GLOBALS['activation'][ $file ] = $callback;
}
function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['deactivation'][ $file ] = $callback;
}
function plugins_url( $path, $file ) {
	return '/plugins/subscription';
}
function get_option( $key, $fallback = false ) {
	return 'subscrpt_version' === $key ? '1.5.7-core.1' : $fallback;
}
function esc_html__( $text, $domain ) {
	return $text;
}
function wp_die( $message ) {
	throw new RuntimeException( $message );
}
function verify( $condition ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'Assertion failed' );
	} }
if ( 'class' === $scenario ) {
	require __DIR__ . '/foreign-subscription.php';
} elseif ( 'constant' === $scenario || 'subscrpt' === $scenario ) {
	define( 'constant' === $scenario ? 'WP_SUBSCRIPTION_FILE' : 'SUBSCRPT_FILE', __DIR__ . '/foreign-subscription.php' );
} elseif ( 'own' === $scenario ) {
	define( 'WP_SUBSCRIPTION_FILE', $main );
	define( 'SUBSCRPT_FILE', $main );
}
require $main;
$conflict = in_array( $scenario, array( 'class', 'constant', 'subscrpt' ), true );
verify( isset( $hooks['admin_notices'] ) === $conflict );
verify( isset( $activation[ $main ] ) );
verify( ashbi_subscriptions_detect_conflict( $main ) === $conflict );
if ( $conflict ) {
	verify( ! function_exists( 'sdevs_subscription' ) );
	try {
		$activation[ $main ]();
		throw new RuntimeException( 'Activation unexpectedly succeeded' );
	} catch ( RuntimeException $error ) {
		verify( 'Deactivate WP Subscription Core first, then activate Ashbi Subscriptions' === $error->getMessage() );
	}
} else {
	verify( ( new ReflectionClass( 'Sdevs_Subscription' ) )->getFileName() === dirname( $main ) . '/bootstrap.php' );
	verify( SUBSCRPT_FILE === $main && WP_SUBSCRIPTION_FILE === $main );
	verify( SUBSCRPT_PATH === dirname( $main ) );
	verify( isset( $deactivation[ $main ], $hooks['plugins_loaded'] ) );
}
echo "PASS\n";

<?php
/**
 * Capture real menu registrations and exercise render permission boundaries.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed -- Isolated WordPress fixture combines stubs and dependency class.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.GlobalVariablesOverride, Generic.NamingConventions.UpperCaseConstantName -- Isolated WordPress stubs, no production environment.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/plugin/vendor/autoload.php';
function add_action( ...$args ) {
	$GLOBALS['ashbi_test_hooks'][] = $args[0];
}
function __( $text, $domain ) {
	return $text;
}
function esc_html__( $text, $domain ) {
	return $text;
}
function add_menu_page( ...$args ) {
	$GLOBALS['parent'] = $args;
}
function add_submenu_page( ...$args ) {
	$GLOBALS['ashbi_test_pages'][ $args[4] ] = $args;
}
function current_user_can( $cap ) {
	return $cap === $GLOBALS['cap'];
}
function get_current_user_id() {
	if ( ! empty( $GLOBALS['ashbi_test_nonce'] ) ) {
		return 1;
	}
	throw new RuntimeException( 'Permission accepted' );
}
function wp_die( ...$args ) {
	throw new RuntimeException( 'Permission denied' );
}
$menu = new SpringDevs\Subscription\Admin\Menu();
$menu->create_admin_menu();
$page = new SpringDevs\Subscription\Admin\VariationMigration( $menu );
$page->menu();
if ( $GLOBALS['parent'][2] !== $GLOBALS['ashbi_test_pages']['wp-subscription-migration'][3] || 'manage_options' !== $GLOBALS['parent'][2] ) {
	throw new RuntimeException( 'Menu capabilities differ' );
}
foreach ( array( 'manage_options', 'manage_woocommerce', 'read' ) as $cap ) {
	$GLOBALS['cap'] = $cap;
	try {
		$page->render();
		throw new RuntimeException( 'Missing permission check' );
	} catch ( RuntimeException $error ) {
		$expected = 'read' === $cap ? 'Permission denied' : 'Permission accepted';
		if ( $expected !== $error->getMessage() ) {
			throw $error;
		}
	}
}
function absint( $value ) { return abs( (int) $value ); }
function wp_get_session_token() {
	return 'fixture-session';
}
function get_transient( $key ) {
	return false;
}
function get_option( $key, $fallback = false ) {
	return $fallback;
}
function sanitize_text_field( $text ) {
	return $text;
}
function sanitize_title( $text ) {
	return $text;
}
function check_admin_referer( $action ) {
	if ( 'ashbi_variation_migration' !== $action ) {
		throw new RuntimeException( 'Wrong nonce action' );
	}
	throw new RuntimeException( 'Nonce checked' );
}
$GLOBALS['ashbi_test_nonce'] = true;
$GLOBALS['cap']              = 'manage_options';
foreach ( array( 'scan', 'apply', 'rollback_scan', 'rollback_apply' ) as $action ) {
	$_POST['ashbi_migration_action'] = $action;
	try {
		$page->render();
		throw new RuntimeException( 'Nonce bypassed' );
	} catch ( RuntimeException $error ) {
		if ( 'Nonce checked' !== $error->getMessage() ) {
			throw $error;
		}
	}
}
/** WooCommerce loaded from an arbitrary plugin folder. */
class WooCommerce {}
$required = new SpringDevs\Subscription\Admin\Required();
$required->check_plugins();
if ( in_array( 'admin_notices', $GLOBALS['ashbi_test_hooks'], true ) ) {
	throw new RuntimeException( 'Loaded WooCommerce reported missing' );
}
echo "PASS\n";

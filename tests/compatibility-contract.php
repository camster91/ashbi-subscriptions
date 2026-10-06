<?php
/**
 * Dependency-free static compatibility contract.
 *
 * This is intentionally runnable before WordPress/PHPUnit infrastructure is
 * available. Runtime integration tests will supplement it, not replace it.
 *
 * @package AshbiSubscriptions\Tests
 */

$root                   = dirname( __DIR__ );
$ashbi_plugin_directory = $root . '/plugin';
$compatibility_errors   = array();

/**
 * Record a failed assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $message Failure description.
 * @return void
 */
function ashbi_assert( $condition, $message ) {
	global $compatibility_errors;
	if ( ! $condition ) {
		$compatibility_errors[] = $message;
	}
}

/**
 * Read a required source file.
 *
 * @param string $path Absolute path.
 * @return string
 */
function ashbi_read( $path ) {
	global $compatibility_errors;
	if ( ! is_file( $path ) ) {
		$compatibility_errors[] = 'Missing required file: ' . $path;
		return '';
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Dependency-free local source contract.
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		$compatibility_errors[] = 'Unable to read required file: ' . $path;
		return '';
	}

	return $contents;
}

$bootstrap   = ashbi_read( $ashbi_plugin_directory . '/subscription.php' ) . ashbi_read( $ashbi_plugin_directory . '/bootstrap.php' );
$composer    = json_decode( ashbi_read( $ashbi_plugin_directory . '/composer.json' ), true );
$installer   = ashbi_read( $ashbi_plugin_directory . '/includes/Installer.php' );
$post_source = ashbi_read( $ashbi_plugin_directory . '/includes/Illuminate/Post.php' );

ashbi_assert( false !== strpos( $bootstrap, 'License: GPLv2 or later' ), 'Plugin header must retain GPLv2-or-later.' );
ashbi_assert( is_array( $composer ) && 'GPL-2.0-or-later' === ( $composer['license'] ?? null ), 'Composer license must remain GPL-2.0-or-later.' );
ashbi_assert( is_file( $ashbi_plugin_directory . '/vendor/autoload.php' ), 'Bundled autoloader is required by the production ZIP.' );
ashbi_assert( ! is_dir( $ashbi_plugin_directory . '/subscription-pro' ), 'Pro source must never be imported.' );

ashbi_assert( false !== strpos( $post_source, "register_post_type( 'subscrpt_order'" ), 'Legacy subscrpt_order post type must remain registered.' );
ashbi_assert( false !== strpos( $post_source, "register_post_type( 'subscrpt_order_item'" ), 'Legacy subscrpt_order_item post type must remain registered.' );
ashbi_assert( false !== strpos( $installer, "'subscrpt_order_relation'" ), 'Legacy relation table name must remain compatible.' );
ashbi_assert( false !== strpos( $installer, "'subscrpt_renewal_claim'" ), 'Atomic renewal claim table must be installed additively.' );
ashbi_assert( false !== strpos( $installer, 'backfill_open_renewal_claims' ), 'Open legacy renewal orders must be claimed during migration.' );

$source = '';
$files  = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $ashbi_plugin_directory . '/includes', FilesystemIterator::SKIP_DOTS )
);
foreach ( $files as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$source .= "\n" . ashbi_read( $file->getPathname() );
	}
}

$required_meta = array(
	'_subscrpt_order_id',
	'_subscrpt_order_item_id',
	'_subscrpt_product_id',
	'_subscrpt_variation_id',
	'_subscrpt_variation_term_mode',
	'_subscrpt_start_date',
	'_subscrpt_next_date',
	'_subscrpt_trial',
	'_subscrpt_auto_renew',
	'_subscrpt_price',
);
foreach ( $required_meta as $key ) {
	ashbi_assert( false !== strpos( $source, "'{$key}'" ), "Missing compatibility-critical meta key: {$key}" );
}

$required_hooks = array(
	'subscrpt_subscription_expired',
	'subscrpt_subscription_activated',
	'subscrpt_after_create_renew_order',
	'subscrpt_subscription_payment_failed',
	'subscrpt_subscription_next_date',
);
foreach ( $required_hooks as $hook ) {
	ashbi_assert( false !== strpos( $source, "'{$hook}'" ), "Missing compatibility-critical hook: {$hook}" );
}

ashbi_assert( false === strpos( $source, 'str_starts_with(' ), 'Maintained code must remain compatible with PHP 7.4.' );
ashbi_assert( 0 === preg_match( '/(^|[^A-Za-z0-9_>])dd\\s*\\(/m', $source ), 'Debug dump calls must not ship in production code.' );
ashbi_assert( false === strpos( $source, 'catch ( Exception ' ), 'Namespaced code must catch global \\Exception explicitly.' );
ashbi_assert( false === strpos( $source, '$variation_id = $variation_id;' ), 'Manual renewal must not discard the stored variation ID.' );
ashbi_assert( false === strpos( $source, '$product_data->' ), 'PayPal product creation must not dereference undefined product data.' );

if ( $compatibility_errors ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Dependency-free CLI error output.
	fwrite( STDERR, "Compatibility contract failed:\n- " . implode( "\n- ", $compatibility_errors ) . "\n" );
	exit( 1 );
}

printf( "Compatibility contract passed (%d meta keys, %d hooks).\n", count( $required_meta ), count( $required_hooks ) );

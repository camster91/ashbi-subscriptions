<?php
/**
 * Dependency-free static compatibility contract.
 *
 * This is intentionally runnable before WordPress/PHPUnit infrastructure is
 * available. Runtime integration tests will supplement it, not replace it.
 */

$root   = dirname( __DIR__ );
$plugin = $root . '/plugin';
$errors = array();

/**
 * Record a failed assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $message Failure description.
 * @return void
 */
function ashbi_assert( $condition, $message ) {
	global $errors;
	if ( ! $condition ) {
		$errors[] = $message;
	}
}

/**
 * Read a required source file.
 *
 * @param string $path Absolute path.
 * @return string
 */
function ashbi_read( $path ) {
	global $errors;
	if ( ! is_file( $path ) ) {
		$errors[] = 'Missing required file: ' . $path;
		return '';
	}

	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		$errors[] = 'Unable to read required file: ' . $path;
		return '';
	}

	return $contents;
}

$bootstrap = ashbi_read( $plugin . '/subscription.php' );
$composer  = json_decode( ashbi_read( $plugin . '/composer.json' ), true );
$installer = ashbi_read( $plugin . '/includes/Installer.php' );
$post      = ashbi_read( $plugin . '/includes/Illuminate/Post.php' );

ashbi_assert( false !== strpos( $bootstrap, 'License: GPLv2 or later' ), 'Plugin header must retain GPLv2-or-later.' );
ashbi_assert( is_array( $composer ) && 'GPL-2.0-or-later' === ( $composer['license'] ?? null ), 'Composer license must remain GPL-2.0-or-later.' );
ashbi_assert( is_file( $plugin . '/vendor/autoload.php' ), 'Bundled autoloader is required by the production ZIP.' );
ashbi_assert( ! is_dir( $plugin . '/subscription-pro' ), 'Pro source must never be imported.' );

ashbi_assert( false !== strpos( $post, "register_post_type( 'subscrpt_order'" ), 'Legacy subscrpt_order post type must remain registered.' );
ashbi_assert( false !== strpos( $post, "register_post_type( 'subscrpt_order_item'" ), 'Legacy subscrpt_order_item post type must remain registered.' );
ashbi_assert( false !== strpos( $installer, "'subscrpt_order_relation'" ), 'Legacy relation table name must remain compatible.' );

$source = '';
$files  = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin . '/includes', FilesystemIterator::SKIP_DOTS )
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

if ( $errors ) {
	fwrite( STDERR, "Compatibility contract failed:\n- " . implode( "\n- ", $errors ) . "\n" );
	exit( 1 );
}

printf( "Compatibility contract passed (%d meta keys, %d hooks).\n", count( $required_meta ), count( $required_hooks ) );

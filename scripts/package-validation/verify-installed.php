<?php
/**
 * WP-CLI readback of the actual installed package, not source-tree files.
 *
 * @package AshbiSubscriptions
 */

$evidence_dir = isset( $args[0] ) ? $args[0] : '/ashbi-release';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the local, checksum-derived CI member manifest.
$manifest = json_decode( file_get_contents( $evidence_dir . '/members.json' ), true );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the local CI provenance file.
$provenance = json_decode( file_get_contents( $evidence_dir . '/provenance.json' ), true );
if ( ! is_array( $manifest ) || ! isset( $manifest['subscription/subscription.php'] ) ) {
	WP_CLI::error( 'Package member manifest is unavailable.' );
}
if ( 'local' !== wp_get_environment_type() || ! defined( 'SUBSCRPT_FILE' ) || 'subscription/subscription.php' !== plugin_basename( SUBSCRPT_FILE ) ) {
	WP_CLI::error( 'Canonical local package runtime identity mismatch.' );
}
if ( ! is_plugin_active( 'subscription/subscription.php' ) || is_plugin_active( 'plugin/subscription.php' ) ) {
	WP_CLI::error( 'Canonical package activation mismatch or competing source plugin.' );
}
$actual = array();
$root   = WP_PLUGIN_DIR . '/subscription';
$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	if ( $file->isLink() ) {
		WP_CLI::error( 'Installed package contains a symbolic link.' );
	}
	if ( $file->isFile() ) {
		$name            = 'subscription/' . str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		$actual[ $name ] = hash_file( 'sha256', $file->getPathname() );
	}
}
ksort( $manifest );
ksort( $actual );
if ( $manifest !== $actual ) {
	WP_CLI::error( 'Installed package bytes differ from the checksum-verified published ZIP.' );
}
$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
if ( ( 'on' === $provenance['hpos'] ) !== $hpos ) {
	WP_CLI::error( 'Actual WooCommerce order datastore does not match the requested HPOS mode.' );
}
$report = array(
	'basename'            => plugin_basename( SUBSCRPT_FILE ),
	'files_verified'      => count( $actual ),
	'hpos_enabled'        => $hpos,
	'php_version'         => PHP_VERSION,
	'wordpress_version'   => get_bloginfo( 'version' ),
	'woocommerce_version' => WC_VERSION,
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write the disposable local CI report without credential-dependent WordPress filesystem setup.
file_put_contents( $evidence_dir . '/installed-package.json', wp_json_encode( $report, JSON_PRETTY_PRINT ) );
WP_CLI::success( 'Exact installed package bytes and HPOS datastore verified.' );

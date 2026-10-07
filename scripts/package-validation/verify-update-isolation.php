<?php
/**
 * Verify update isolation after a real WordPress update-service refresh.
 * Disposable local environment only; never shipped in the plugin ZIP.
 *
 * @package AshbiSubscriptions
 */

if ( 'local' !== wp_get_environment_type() || ! defined( 'SUBSCRPT_FILE' ) || 'subscription/subscription.php' !== plugin_basename( SUBSCRPT_FILE ) ) {
	WP_CLI::error( 'Refusing update checks outside the canonical local package runtime.' );
}
$metadata = get_plugin_data( SUBSCRPT_FILE, false, false );
if ( 'false' !== ( $metadata['UpdateURI'] ?? '' ) ) {
	WP_CLI::error( 'The installed package does not opt out of upstream directory replacement.' );
}

// A same-version package replacement can leave a cached upstream offer behind.
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$updates = get_site_transient( 'update_plugins' );
if ( ! is_object( $updates ) || ! is_array( $updates->response ?? null ) || ! is_array( $updates->no_update ?? null ) ) {
	WP_CLI::error( 'The WordPress update-service refresh did not produce a valid result.' );
}
$basename = plugin_basename( SUBSCRPT_FILE );
if ( isset( $updates->response[ $basename ] ) ) {
	WP_CLI::error( 'An upstream replacement offer remains after refreshing update metadata.' );
}
$unrelated = array_values(
	array_filter(
		array_unique( array_merge( array_keys( $updates->response ), array_keys( $updates->no_update ) ) ),
		static function ( $file ) use ( $basename ) {
			return $file !== $basename;
		}
	)
);
if ( ! $unrelated ) {
	WP_CLI::error( 'No unrelated plugin update-service evidence was returned; refusing a false isolation pass.' );
}
$report = array(
	'basename'               => $basename,
	'update_uri'             => $metadata['UpdateURI'],
	'upstream_offer_present' => false,
	'unrelated_checked'      => $unrelated,
	'cache_refreshed'        => true,
);
$evidence_dir = $args[0] ?? '/var/www/html/ashbi-release';
if ( false === file_put_contents( $evidence_dir . '/update-isolation.json', wp_json_encode( $report, JSON_PRETTY_PRINT ) ) ) {
	WP_CLI::error( 'Could not save the update-isolation evidence.' );
}
WP_CLI::success( 'No upstream replacement offer after refresh; unrelated plugins still receive update-service results.' );

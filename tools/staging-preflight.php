<?php
/**
 * Verify that a WordPress clone is isolated before a migration rehearsal.
 *
 * Usage:
 *   ASHBI_EXPECTED_SITE_URL='https://staging.example.com' \
 *   ASHBI_HOST_CRON_DISABLED=yes \
 *   ASHBI_OUTBOUND_EMAIL_DISABLED=yes \
 *   ASHBI_PRODUCTION_CALLBACKS_DISABLED=yes \
 *   wp eval-file tools/staging-preflight.php
 *
 * This command is read-only. It emits only control status and gateway IDs;
 * credentials, settings values, webhook URLs, and customer data are omitted.
 *
 * @package AshbiSubscriptions
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Fail closed before WordPress is bootstrapped; WP_Filesystem is unavailable here.
	fwrite( STDERR, "Run this file with WP-CLI.\n" );
	exit( 2 );
}

require_once __DIR__ . '/lib/StagingPreflight.php';

use Ashbi\Subscriptions\Tools\StagingPreflight;

/** Treat only an explicit yes as an operator attestation. */
/**
 * Read one explicit host-control attestation.
 *
 * @param string $name Environment variable name.
 * @return bool
 */
function ashbi_staging_attestation( string $name ): bool {
	return 'yes' === strtolower( trim( (string) getenv( $name ) ) );
}

global $wpdb;

$active_webhook_count = null;
if ( function_exists( 'wc_get_webhooks' ) ) {
	$active_webhooks = wc_get_webhooks( array( 'status' => 'active' ) );
	if ( is_array( $active_webhooks ) ) {
		$active_webhook_count = count( $active_webhooks );
	}
}

// Some WooCommerce builds do not load the convenience helper in WP-CLI. The
// table fallback remains read-only, uses a prepared status value, and rejects
// an unexpected prefix so an unavailable table still fails closed.
if ( null === $active_webhook_count && isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
	$webhook_table = isset( $wpdb->prefix ) ? (string) $wpdb->prefix . 'wc_webhooks' : '';
	if ( '' !== $webhook_table && preg_match( '/^[A-Za-z0-9_]+$/', $webhook_table ) ) {
		$webhook_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $webhook_table, 'active' ) );
		if ( is_numeric( $webhook_count ) ) {
			$active_webhook_count = (int) $webhook_count;
		}
	}
}

$registered_gateways = null;
try {
	if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
		$registered_gateways = WC()->payment_gateways()->payment_gateways();
	}
} catch ( Throwable $error ) {
	$registered_gateways = null;
}

$gateway_discovery_complete = is_array( $registered_gateways );
$gateway_maps               = array();
if ( $gateway_discovery_complete ) {
	foreach ( $registered_gateways as $gateway_id => $gateway ) {
		if ( ! is_object( $gateway ) || ! property_exists( $gateway, 'enabled' ) || ! property_exists( $gateway, 'settings' ) ) {
			$gateway_discovery_complete = false;
			continue;
		}
		$settings                             = is_array( $gateway->settings ) ? $gateway->settings : array();
		$settings['enabled']                  = $gateway->enabled;
		$gateway_maps[ (string) $gateway_id ] = $settings;
	}
}

$state = array(
	'expected_site_url'                => (string) getenv( 'ASHBI_EXPECTED_SITE_URL' ),
	'site_url'                         => site_url(),
	'home_url'                         => home_url(),
	'environment_type'                 => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
	'wp_cron_disabled'                 => defined( 'DISABLE_WP_CRON' ) && true === DISABLE_WP_CRON,
	'action_scheduler_runner_disabled' => defined( 'ACTION_SCHEDULER_DISABLE_DEFAULT_QUEUE_RUNNER' ) && true === ACTION_SCHEDULER_DISABLE_DEFAULT_QUEUE_RUNNER,
	'active_webhook_count'             => $active_webhook_count,
	'gateway_discovery_complete'       => $gateway_discovery_complete,
	'gateways'                         => StagingPreflight::gateway_inventory( $gateway_maps ),
	'expected_gateway_ids'             => array_values(
		array_filter(
			array_map( 'trim', explode( ',', (string) getenv( 'ASHBI_EXPECTED_GATEWAYS' ) ) ),
			static fn( string $gateway_id ): bool => '' !== $gateway_id
		)
	),
	'attestations'                     => array(
		'host_cron_disabled'            => ashbi_staging_attestation( 'ASHBI_HOST_CRON_DISABLED' ),
		'outbound_email_disabled'       => ashbi_staging_attestation( 'ASHBI_OUTBOUND_EMAIL_DISABLED' ),
		'production_callbacks_disabled' => ashbi_staging_attestation( 'ASHBI_PRODUCTION_CALLBACKS_DISABLED' ),
	),
);

$report = StagingPreflight::evaluate( $state );
WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
if ( ! $report['passed'] ) {
	exit( 1 );
}

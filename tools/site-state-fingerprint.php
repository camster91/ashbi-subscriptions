<?php
/**
 * Emit a keyed, privacy-preserving migration-state fingerprint with WP-CLI.
 *
 * Usage:
 *   ASHBI_FINGERPRINT_KEY='<random 32+ byte secret>' \
 *   wp eval-file tools/site-state-fingerprint.php > /secure/preflight.json
 *
 * The key and source values are never emitted. Keep the report and key on the
 * authorized server, use the same key for both sides of a comparison, then
 * destroy the key after the migration evidence has been retained.
 *
 * @package AshbiSubscriptions
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run this file with WP-CLI.\n" );
	exit( 1 );
}

require_once __DIR__ . '/lib/SiteFingerprint.php';

use Ashbi\Subscriptions\Tools\SiteFingerprint;

$key = (string) getenv( 'ASHBI_FINGERPRINT_KEY' );
if ( strlen( $key ) < 32 ) {
	WP_CLI::error( 'ASHBI_FINGERPRINT_KEY must contain at least 32 bytes.' );
}

global $wpdb;

/**
 * Digest sensitive source rows without emitting their values.
 *
 * @param array<int,mixed> $rows Sensitive source rows.
 * @return array{count:int,digest:string,row_digests:string[]}
 */
function ashbi_fingerprint_rows( array $rows ): array {
	return SiteFingerprint::digest_rows( $rows, (string) getenv( 'ASHBI_FINGERPRINT_KEY' ) );
}

/**
 * Remove metadata that the activation migration is specifically allowed to add.
 *
 * @param array<int,mixed> $meta_data Order metadata objects or maps.
 * @return array<int,mixed>
 */
function ashbi_fingerprint_stable_order_meta( array $meta_data ): array {
	$ignored_keys = array(
		'_subscrpt_renewal_period_key',
		'_subscrpt_stripe_renewal_customer',
		'_subscrpt_renewal_quarantined',
	);
	return array_values(
		array_filter(
			$meta_data,
			static function ( $meta ) use ( $ignored_keys ): bool {
				$data = is_object( $meta ) && method_exists( $meta, 'get_data' ) ? $meta->get_data() : (array) $meta;
				return ! in_array( (string) ( $data['key'] ?? '' ), $ignored_keys, true );
			}
		)
	);
}

/**
 * Read all rows from one verified site table.
 *
 * @param string $table Exact database table name.
 * @return array<int,mixed>
 */
function ashbi_fingerprint_table_rows( string $table ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from SHOW TABLES under the fixed site prefix.
	return (array) $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
}

/**
 * Read the creation schema from one verified site table.
 *
 * @param string $table Exact database table name.
 * @return array<int,string>
 */
function ashbi_fingerprint_table_schema( string $table ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from SHOW TABLES under the fixed site prefix.
	$schema = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", 'ARRAY_N' );
	if ( ! is_array( $schema ) || ! isset( $schema[1] ) ) {
		WP_CLI::error( "Could not read the schema for {$table}." );
	}
	return array( SiteFingerprint::canonicalize_schema( (string) $schema[1] ) );
}

$subscription_posts = (array) $wpdb->get_results(
	$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID", 'subscrpt_order' ),
	ARRAY_A
);
$subscription_meta  = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s ORDER BY pm.meta_id",
		'subscrpt_order'
	),
	ARRAY_A
);

$relation_table  = $wpdb->prefix . 'subscrpt_order_relation';
$relation_exists = $relation_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relation_table ) );
$order_ids       = array();
foreach ( $subscription_meta as $meta ) {
	if ( '_subscrpt_order_id' === $meta['meta_key'] && (int) $meta['meta_value'] > 0 ) {
		$order_ids[] = (int) $meta['meta_value'];
	}
}
if ( $relation_exists ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed, prefixed table name.
	$order_ids = array_merge( $order_ids, array_map( 'intval', (array) $wpdb->get_col( "SELECT order_id FROM {$relation_table}" ) ) );
}
$order_ids = array_values( array_unique( array_filter( $order_ids ) ) );
sort( $order_ids, SORT_NUMERIC );
$orders      = array();
$full_orders = array();
foreach ( $order_ids as $order_id ) {
	$fingerprint_order = wc_get_order( $order_id );
	if ( ! $fingerprint_order ) {
		$orders[]      = array(
			'id'     => $order_id,
			'exists' => false,
		);
		$full_orders[] = array(
			'id'     => $order_id,
			'exists' => false,
		);
		continue;
	}
	$items = array();
	foreach ( $fingerprint_order->get_items( array( 'line_item', 'fee', 'shipping', 'coupon', 'tax' ) ) as $item ) {
		$items[] = array(
			'id'        => $item->get_id(),
			'type'      => $item->get_type(),
			'data'      => $item->get_data(),
			'meta_data' => $item->get_meta_data(),
		);
	}
	usort(
		$items,
		static function ( array $left, array $right ): int {
			return array( $left['type'], $left['id'] ) <=> array( $right['type'], $right['id'] );
		}
	);
	$full_order    = array(
		'id'        => $order_id,
		'exists'    => true,
		'data'      => $fingerprint_order->get_data(),
		'meta_data' => $fingerprint_order->get_meta_data(),
		'items'     => $items,
	);
	$full_orders[] = $full_order;
	$stable_order  = $full_order;
	unset( $stable_order['data']['date_modified'], $stable_order['data']['version'], $stable_order['data']['meta_data'] );
	$stable_order['meta_data'] = ashbi_fingerprint_stable_order_meta( $fingerprint_order->get_meta_data() );
	$orders[]                  = $stable_order;
}

$custom_tables        = array();
$custom_table_schemas = array();
$table_pattern        = $wpdb->esc_like( $wpdb->prefix . 'subscrpt_' ) . '%';
foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_pattern ) ) as $table ) {
	$table = (string) $table;
	if ( 0 !== strpos( $table, $wpdb->prefix . 'subscrpt_' ) ) {
		continue;
	}
	$name                          = substr( $table, strlen( $wpdb->prefix ) );
	$custom_tables[ $name ]        = ashbi_fingerprint_rows( ashbi_fingerprint_table_rows( $table ) );
	$custom_table_schemas[ $name ] = ashbi_fingerprint_rows( ashbi_fingerprint_table_schema( $table ) );
}
ksort( $custom_tables );
ksort( $custom_table_schemas );

$token_table      = $wpdb->prefix . 'woocommerce_payment_tokens';
$token_meta_table = $wpdb->prefix . 'woocommerce_payment_tokenmeta';
$token_rows       = $token_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $token_table ) ) ? ashbi_fingerprint_table_rows( $token_table ) : array();
$token_meta_rows  = $token_meta_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $token_meta_table ) ) ? ashbi_fingerprint_table_rows( $token_meta_table ) : array();

$ignored_options         = array(
	'subscrpt_version',
	'subscrpt_db_version',
	'subscrpt_renewal_claim_backfill_1',
	'subscrpt_overdue_renewal_quarantine_1_completed_at',
	'subscrpt_renewal_claim_quarantine_1',
	'subscrpt_overdue_renewal_quarantine_1',
	'subscrpt_renewal_operator_hold_1',
	'subscrpt_renewal_migration_blocked',
);
$subscrpt_option_pattern = $wpdb->esc_like( 'subscrpt_' ) . '%';
$legacy_option_pattern   = $wpdb->esc_like( 'wp_subscription_' ) . '%';
$option_rows             = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name",
		$subscrpt_option_pattern,
		$legacy_option_pattern
	),
	ARRAY_A
);
$stable_options          = array_values(
	array_filter(
		$option_rows,
		static function ( array $row ) use ( $ignored_options ): bool {
			return ! in_array( (string) $row['option_name'], $ignored_options, true );
		}
	)
);

$report               = array(
	'schema_version' => SiteFingerprint::SCHEMA_VERSION,
	'generated_at'   => gmdate( 'c' ),
	'site_url'       => site_url(),
	'key_id'         => substr( hash( 'sha256', $key ), 0, 16 ),
	'runtime'        => array(
		'wordpress'   => get_bloginfo( 'version' ),
		'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
		'php'         => PHP_VERSION,
		'hpos'        => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() : null,
	),
	'state'          => array(
		'subscription_posts'   => ashbi_fingerprint_rows( $subscription_posts ),
		'subscription_meta'    => ashbi_fingerprint_rows( $subscription_meta ),
		'related_orders'       => ashbi_fingerprint_rows( $orders ),
		'related_orders_full'  => ashbi_fingerprint_rows( $full_orders ),
		'payment_tokens'       => ashbi_fingerprint_rows( $token_rows ),
		'payment_token_meta'   => ashbi_fingerprint_rows( $token_meta_rows ),
		'stable_options'       => ashbi_fingerprint_rows( $stable_options ),
		'custom_tables'        => $custom_tables,
		'custom_table_schemas' => $custom_table_schemas,
	),
);
$report['report_mac'] = SiteFingerprint::seal_report( $report, $key );

WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

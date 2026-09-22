<?php
/**
 * Emit privacy-preserving field hashes for subscription-related orders.
 *
 * @package AshbiSubscriptions
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is unavailable before this guard.
	fwrite( STDERR, "Run this file with WP-CLI.\n" );
	exit( 2 );
}

$key = (string) getenv( 'ASHBI_FINGERPRINT_KEY' );
if ( strlen( $key ) < 32 ) {
	WP_CLI::error( 'ASHBI_FINGERPRINT_KEY must contain at least 32 bytes.' );
}

global $wpdb;

/**
 * Hash one value without disclosing it.
 *
 * @param mixed  $value Value to hash.
 * @param string $key HMAC key.
 */
function ashbi_order_field_hash( $value, string $key ): string {
	return hash_hmac( 'sha256', maybe_serialize( $value ), $key );
}

/**
 * Hash top-level fields while retaining only their names.
 *
 * @param array<string,mixed> $data Field values.
 * @param string              $key HMAC key.
 */
function ashbi_order_field_map( array $data, string $key ): array {
	$map = array();
	foreach ( $data as $name => $value ) {
		$map[ (string) $name ] = ashbi_order_field_hash( $value, $key );
	}
	ksort( $map, SORT_STRING );

	return $map;
}

/**
 * Hash metadata values grouped by visible metadata key.
 *
 * @param array<int,mixed> $metadata Metadata objects or arrays.
 * @param string           $key HMAC key.
 */
function ashbi_order_meta_map( array $metadata, string $key ): array {
	$map = array();
	foreach ( $metadata as $meta ) {
		$data               = is_object( $meta ) && method_exists( $meta, 'get_data' ) ? $meta->get_data() : (array) $meta;
		$meta_key           = (string) ( $data['key'] ?? '' );
		$map[ $meta_key ][] = ashbi_order_field_hash( $data['value'] ?? null, $key );
	}
	foreach ( $map as &$hashes ) {
		sort( $hashes, SORT_STRING );
	}
	unset( $hashes );
	ksort( $map, SORT_STRING );

	return $map;
}

$subscription_ids = (array) $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID",
		'subscrpt_order'
	)
);
$order_ids        = array();
if ( $subscription_ids ) {
	$placeholders = implode( ',', array_fill( 0, count( $subscription_ids ), '%d' ) );
	$order_ids    = (array) $wpdb->get_col(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder count is derived from validated integer IDs.
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders}) AND meta_key = %s",
			array_merge( array_map( 'intval', $subscription_ids ), array( '_subscrpt_order_id' ) )
		)
	);
}
$relation_table = $wpdb->prefix . 'subscrpt_order_relation';
if ( $relation_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relation_table ) ) ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the validated WordPress prefix plus a fixed suffix.
	$order_ids = array_merge( $order_ids, (array) $wpdb->get_col( "SELECT order_id FROM {$relation_table}" ) );
}
$order_ids = array_values( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ) );
sort( $order_ids, SORT_NUMERIC );

$report = array();
foreach ( $order_ids as $order_id ) {
	$fingerprint_order = wc_get_order( $order_id );
	if ( ! $fingerprint_order ) {
		$report[ (string) $order_id ] = array( 'exists' => false );
		continue;
	}
	$items = array();
	foreach ( $fingerprint_order->get_items( array( 'line_item', 'fee', 'shipping', 'coupon', 'tax' ) ) as $item ) {
		$items[ (string) $item->get_id() ] = array(
			'type' => $item->get_type(),
			'data' => ashbi_order_field_map( $item->get_data(), $key ),
			'meta' => ashbi_order_meta_map( $item->get_meta_data(), $key ),
		);
	}
	ksort( $items, SORT_NUMERIC );
	$report[ (string) $order_id ] = array(
		'exists' => true,
		'data'   => ashbi_order_field_map( $fingerprint_order->get_data(), $key ),
		'meta'   => ashbi_order_meta_map( $fingerprint_order->get_meta_data(), $key ),
		'items'  => $items,
	);
}

WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

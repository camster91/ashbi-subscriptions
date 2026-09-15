<?php
/**
 * Read-only Ashbi Subscriptions migration audit for WP-CLI.
 *
 * Usage:
 *   wp eval-file tools/site-audit.php
 *
 * The report intentionally contains aggregate counts and configuration flags
 * only. It must never emit customer identities, order contents, credentials,
 * payment-token values, webhook secrets, or raw metadata.
 *
 * @package AshbiSubscriptions
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run this file with WP-CLI: wp eval-file tools/site-audit.php\n" );
	exit( 1 );
}

global $wpdb;

if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

/**
 * Return a database count without leaking row contents.
 *
 * @param string $sql Prepared, constant SQL.
 * @return int
 */
function ashbi_audit_count( $sql ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers use constant table names and clauses.
	return (int) $wpdb->get_var( $sql );
}

/**
 * Determine whether a table exists.
 *
 * @param string $table Fully-prefixed table name.
 * @return bool
 */
function ashbi_audit_table_exists( $table ) {
	global $wpdb;

	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

/**
 * List active plugins relevant to commerce and subscriptions.
 *
 * @return array<string,array<string,string>>
 */
function ashbi_audit_plugins() {
	$all_plugins    = get_plugins();
	$active_plugins = (array) get_option( 'active_plugins', array() );
	$wanted         = array();

	foreach ( $active_plugins as $plugin_file ) {
		$slug = dirname( $plugin_file );
		if ( '.' === $slug ) {
			$slug = basename( $plugin_file, '.php' );
		}

		if ( ! preg_match( '/subscription|woocommerce|stripe|paypal|surecart|member/i', $slug ) ) {
			continue;
		}

		$data            = isset( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : array();
		$wanted[ $slug ] = array(
			'file'    => $plugin_file,
			'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : '',
			'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
		);
	}

	ksort( $wanted );
	return $wanted;
}

/**
 * Return subscription post counts by raw database status.
 *
 * Direct aggregate SQL avoids depending on whether a particular plugin family
 * has registered its custom statuses during this WP-CLI invocation.
 *
 * @return array<string,int>
 */
function ashbi_audit_subscription_statuses() {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_status, COUNT(*) AS total
			FROM {$wpdb->posts}
			WHERE post_type = %s
			GROUP BY post_status
			ORDER BY post_status",
			'subscrpt_order'
		),
		ARRAY_A
	);
	$out  = array();

	foreach ( (array) $rows as $row ) {
		$out[ (string) $row['post_status'] ] = (int) $row['total'];
	}

	return (object) $out;
}

/**
 * Return relation-row counts grouped by the non-sensitive relation type.
 *
 * @param string $table Relation table name.
 * @return object
 */
function ashbi_audit_relation_types( $table ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed, prefixed table name.
	$rows = $wpdb->get_results( "SELECT type, COUNT(*) AS total FROM {$table} GROUP BY type ORDER BY type", ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ (string) $row['type'] ] = (int) $row['total'];
	}

	return (object) $out;
}

/**
 * Count subscription records carrying each compatibility-critical meta key.
 *
 * @return array<string,int>
 */
function ashbi_audit_meta_coverage() {
	global $wpdb;

	$keys = array(
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
	$out  = array();

	foreach ( $keys as $key ) {
		$out[ $key ] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.post_id)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND pm.meta_key = %s",
				'subscrpt_order',
				$key
			)
		);
	}

	return $out;
}

/**
 * Return a coarse age bucket without exposing dates or record identifiers.
 *
 * @param int $seconds Non-negative age in seconds.
 * @return string
 */
function ashbi_audit_age_bucket( $seconds ) {
	$days = max( 0, (int) floor( $seconds / DAY_IN_SECONDS ) );
	if ( $days <= 7 ) {
		return '0_7_days';
	}
	if ( $days <= 30 ) {
		return '8_30_days';
	}
	if ( $days <= 90 ) {
		return '31_90_days';
	}
	if ( $days <= 365 ) {
		return '91_365_days';
	}

	return 'over_365_days';
}

/**
 * Increment an aggregate report bucket.
 *
 * @param array  $buckets Aggregate map, passed by reference.
 * @param string $key Bucket key.
 * @return void
 */
function ashbi_audit_increment( &$buckets, $key ) {
	$key             = '' !== (string) $key ? (string) $key : 'unknown';
	$buckets[ $key ] = isset( $buckets[ $key ] ) ? $buckets[ $key ] + 1 : 1;
}

/**
 * Reconcile overdue subscriptions against their aggregate payment history.
 *
 * Only category counts leave the server. Subscription/order IDs, dates,
 * customer details, token values, and raw metadata remain local.
 *
 * @param int    $now Current Unix timestamp.
 * @param string $relation_table Fully-prefixed relation table name.
 * @param bool   $has_relations Whether the relation table exists.
 * @return array
 */
function ashbi_audit_overdue_reconciliation( $now, $relation_table, $has_relations ) {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, CAST(next_date.meta_value AS UNSIGNED) AS next_date
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} next_date
				ON next_date.post_id = p.ID AND next_date.meta_key = %s
			WHERE p.post_type = %s
				AND p.post_status IN ('active','pe_cancelled')
				AND CAST(next_date.meta_value AS UNSIGNED) <= %d",
			'_subscrpt_next_date',
			'subscrpt_order',
			$now
		),
		ARRAY_A
	);

	$report = array(
		'total'                       => count( (array) $rows ),
		'due_age_buckets'             => array(),
		'auto_renew'                  => array(),
		'payment_failure_history'     => array(),
		'latest_paid_gateway'         => array(),
		'last_paid_age_buckets'       => array(),
		'open_renewal_order_statuses' => array(),
	);

	foreach ( (array) $rows as $row ) {
		$subscription_id = (int) $row['ID'];
		$next_date       = (int) $row['next_date'];
		ashbi_audit_increment( $report['due_age_buckets'], ashbi_audit_age_bucket( $now - $next_date ) );

		$auto_renew = get_post_meta( $subscription_id, '_subscrpt_auto_renew', true );
		$auto_group = '' === (string) $auto_renew ? 'unset' : ( in_array( $auto_renew, array( 1, '1', 'yes', true ), true ) ? 'enabled' : 'disabled' );
		ashbi_audit_increment( $report['auto_renew'], $auto_group );
		ashbi_audit_increment(
			$report['payment_failure_history'],
			(int) get_post_meta( $subscription_id, '_subscrpt_payment_failure_count', true ) > 0 ? 'recorded' : 'none_recorded'
		);

		$order_ids = array();
		if ( $has_relations ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed, prefixed table name.
			$relations = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, type FROM {$relation_table} WHERE subscription_id = %d ORDER BY id DESC", $subscription_id ), ARRAY_A );
			foreach ( (array) $relations as $relation_row ) {
				$order_id = (int) $relation_row['order_id'];
				if ( $order_id > 0 ) {
					$order_ids[ $order_id ] = (string) $relation_row['type'];
				}
			}
		}
		$parent_order_id = (int) get_post_meta( $subscription_id, '_subscrpt_order_id', true );
		if ( $parent_order_id > 0 && ! isset( $order_ids[ $parent_order_id ] ) ) {
			$order_ids[ $parent_order_id ] = 'new';
		}

		$latest_paid_timestamp = 0;
		$latest_paid_gateway   = 'none';
		foreach ( $order_ids as $order_id => $relation_type ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			if ( in_array( $relation_type, array( 'renew', 'early-renew' ), true ) && ! $order->is_paid() ) {
				ashbi_audit_increment( $report['open_renewal_order_statuses'], (string) $order->get_status() );
			}

			if ( ! $order->is_paid() ) {
				continue;
			}
			$date_paid = $order->get_date_paid();
			$paid_at   = $date_paid ? (int) $date_paid->getTimestamp() : 0;
			if ( $paid_at >= $latest_paid_timestamp ) {
				$latest_paid_timestamp = $paid_at;
				$latest_paid_gateway   = (string) $order->get_payment_method();
			}
		}

		ashbi_audit_increment( $report['latest_paid_gateway'], $latest_paid_timestamp > 0 ? $latest_paid_gateway : 'no_paid_order' );
		ashbi_audit_increment(
			$report['last_paid_age_buckets'],
			$latest_paid_timestamp > 0 ? ashbi_audit_age_bucket( $now - $latest_paid_timestamp ) : 'no_paid_order'
		);
	}

	foreach ( $report as $key => $value ) {
		if ( is_array( $value ) ) {
			ksort( $report[ $key ] );
		}
	}

	return $report;
}

$now            = time();
$relation_table = $wpdb->prefix . 'subscrpt_order_relation';
$token_table    = $wpdb->prefix . 'woocommerce_payment_tokens';
$relation       = array( 'exists' => false );
$tokens         = array( 'table_exists' => false );
$hpos           = null;

if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) ) {
	$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

if ( ashbi_audit_table_exists( $relation_table ) ) {
	$order_table = true === $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
	$relation = array(
		'exists'                => true,
		'rows'                  => ashbi_audit_count( "SELECT COUNT(*) FROM {$relation_table}" ),
		'type_counts'           => ashbi_audit_relation_types( $relation_table ),
		'duplicate_links'       => ashbi_audit_count( "SELECT COUNT(*) FROM (SELECT subscription_id, order_id, order_item_id, type, COUNT(*) c FROM {$relation_table} GROUP BY subscription_id, order_id, order_item_id, type HAVING c > 1) duplicates" ),
		'missing_subscriptions' => ashbi_audit_count( "SELECT COUNT(*) FROM {$relation_table} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.subscription_id WHERE p.ID IS NULL" ),
		'missing_orders'        => ashbi_audit_count( "SELECT COUNT(*) FROM {$relation_table} r LEFT JOIN {$order_table} orders ON orders.id = r.order_id WHERE orders.id IS NULL" ),
	);
}

if ( ashbi_audit_table_exists( $token_table ) ) {
	$token_rows = $wpdb->get_results(
		$wpdb->prepare( 'SELECT gateway_id, COUNT(*) AS total FROM %i GROUP BY gateway_id ORDER BY gateway_id', $token_table ),
		ARRAY_A
	);
	$by_gateway = array();
	foreach ( (array) $token_rows as $row ) {
		$by_gateway[ (string) $row['gateway_id'] ] = (int) $row['total'];
	}
	$tokens = array(
		'table_exists' => true,
		'total'        => array_sum( $by_gateway ),
		'by_gateway'   => $by_gateway,
	);
}

$report = array(
	'schema_version' => 2,
	'generated_at'   => gmdate( 'c' ),
	'site'           => array(
		'url'         => site_url(),
		'wp_version'  => get_bloginfo( 'version' ),
		'php_version' => PHP_VERSION,
		'timezone'    => wp_timezone_string(),
	),
	'commerce'       => array(
		'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
		'hpos_enabled'         => $hpos,
		'active_plugins'       => ashbi_audit_plugins(),
	),
	'subscriptions'  => array(
		'status_counts'        => ashbi_audit_subscription_statuses(),
		'meta_coverage'        => ashbi_audit_meta_coverage(),
		'overdue_active'       => (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = %s AND p.post_status IN ('active','pe_cancelled') AND CAST(pm.meta_value AS UNSIGNED) <= %d",
				'_subscrpt_next_date',
				'subscrpt_order',
				$now
			)
		),
		'due_within_7_days'    => (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = %s AND p.post_status IN ('active','pe_cancelled') AND CAST(pm.meta_value AS UNSIGNED) BETWEEN %d AND %d",
				'_subscrpt_next_date',
				'subscrpt_order',
				$now,
				$now + WEEK_IN_SECONDS
			)
		),
		'next_date_min'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(CAST(pm.meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value <> ''", 'subscrpt_order', '_subscrpt_next_date' ) ),
		'next_date_max'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(CAST(pm.meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value <> ''", 'subscrpt_order', '_subscrpt_next_date' ) ),
		'overdue_reconciliation' => ashbi_audit_overdue_reconciliation( $now, $relation_table, ! empty( $relation['exists'] ) ),
	),
	'relations'      => $relation,
	'payment_tokens' => $tokens,
	'schedules'      => array(
		'subscrpt_hourly_cron'        => wp_next_scheduled( 'subscrpt_hourly_cron' ) ?: null,
		'subscrpt_daily_cron'         => wp_next_scheduled( 'subscrpt_daily_cron' ) ?: null,
		'subscrpt_renew_reminder_cron'=> wp_next_scheduled( 'subscrpt_renew_reminder_cron' ) ?: null,
	),
	'options'        => array(
		'subscrpt_version'           => (string) get_option( 'subscrpt_version', '' ),
		'subscrpt_auto_renew'        => (string) get_option( 'subscrpt_auto_renew', '' ),
		'subscrpt_stripe_auto_renew' => (string) get_option( 'subscrpt_stripe_auto_renew', '' ),
	),
);

WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

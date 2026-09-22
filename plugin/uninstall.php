<?php
/**
 * Ashbi Subscriptions uninstall handler.
 *
 * Uninstall is deliberately non-destructive by default. The subscription CPT,
 * relation tables, WooCommerce orders, customer records, and payment tokens
 * are business history and must survive an accidental plugin removal. A site
 * owner may opt in to complete removal by setting the documented option before
 * uninstalling from WordPress.
 *
 * @package SpringDevs\Subscription
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'subscrpt_hourly_cron' );
wp_clear_scheduled_hook( 'subscrpt_daily_cron' );
wp_clear_scheduled_hook( 'subscrpt_scheduled_grace_end' );
wp_clear_scheduled_hook( 'subscrpt_retry_renewal_payment' );
wp_clear_scheduled_hook( 'subscrpt_retry_subscription_schedule' );
wp_clear_scheduled_hook( 'subscrpt_queue_trial_order_autocomplete' );
wp_clear_scheduled_hook( 'subscrpt_send_delayed_expired_email' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach (
		array(
			'subscrpt_scheduled_grace_end',
			'subscrpt_retry_renewal_payment',
			'subscrpt_retry_subscription_schedule',
			'subscrpt_queue_trial_order_autocomplete',
		) as $hook
	) {
		as_unschedule_all_actions( $hook, array(), 'ashbi-subscriptions' );
	}
}

// Safe uninstall: Subscriptions and order history are retained by default.
if ( ! get_option( 'subscrpt_remove_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;

$subscription_ids = get_posts(
	array(
		'post_type'      => 'subscrpt_order',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $subscription_ids as $subscription_id ) {
	wp_delete_post( (int) $subscription_id, true );
}

$tables = array(
	$wpdb->prefix . 'subscrpt_order_relation',
	$wpdb->prefix . 'subscrpt_stats_snapshot',
	$wpdb->prefix . 'subscrpt_cancellation_feedback',
	$wpdb->prefix . 'subscrpt_recovery_event',
	$wpdb->prefix . 'subscrpt_plan_group',
	$wpdb->prefix . 'subscrpt_plan',
	$wpdb->prefix . 'subscrpt_plan_relation',
	$wpdb->prefix . 'subscrpt_renewal_claim',
	$wpdb->prefix . 'subscrpt_paypal_map',
);

foreach ( $tables as $table ) {
	// Table names are built exclusively from the trusted WordPress table prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

$options = array(
	'subscrpt_installed',
	'subscrpt_version',
	'subscrpt_db_version',
	'subscrpt_onboarding_seen',
	'subscrpt_stats_last_snapshot',
	'subscrpt_renewal_migration_blocked',
	'subscrpt_renewal_claim_quarantine_1',
	'subscrpt_overdue_renewal_quarantine_1',
	'subscrpt_renewal_operator_hold_1',
	'subscrpt_renewal_claim_backfill_1',
	'subscrpt_overdue_renewal_quarantine_1_completed_at',
	'subscrpt_recovery_event_retention_days',
	'subscrpt_recovery_event_last_purge',
	'subscrpt_remove_data_on_uninstall',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

<?php

namespace SpringDevs\Subscription;

use SpringDevs\Subscription\Illuminate\Gateways\Paypal\PaypalDB;
use SpringDevs\Subscription\Illuminate\RenewalClaim;

/**
 * Class Installer
 *
 * @package SpringDevs\Subscription
 */
class Installer {

	/**
	 * Database schema version. Bump whenever a custom table changes.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.4.0';

	/**
	 * Run the installer
	 *
	 * @return void
	 */
	public function run() {
		$this->add_version();
		$this->register_schedules();
		$this->create_tables();
	}

	/**
	 * Create/upgrade custom tables when the stored schema version is outdated.
	 *
	 * Lets existing installs pick up new tables on update without a manual
	 * deactivate/reactivate. A single option read short-circuits once current.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if (
			get_option( 'subscrpt_db_version' ) === self::DB_VERSION
			&& get_option( 'subscrpt_renewal_claim_backfill_1' )
		) {
			return;
		}

		( new self() )->create_tables();
	}

	/**
	 * Add time and version on DB
	 */
	public function add_version() {
		$installed = get_option( 'subscrpt_installed' );

		if ( ! $installed ) {
			update_option( 'subscrpt_installed', time() );
		}

		update_option( 'subscrpt_version', SUBSCRPT_VERSION );

		update_option( 'subscrpt_manual_renew_cart_notice', 'Subscriptional product added to cart. Please complete the checkout to renew subscription.' );
	}

	/**
	 * Register cron events.
	 *
	 * @return void
	 */
	public function register_schedules() {
		if ( ! wp_next_scheduled( 'subscrpt_hourly_cron' ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ), 'hourly', 'subscrpt_hourly_cron' );
		}
	}

	/**
	 * Create necessary database tables
	 *
	 * @return void
	 */
	public function create_tables() {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$this->create_histories_table();
		$this->create_stats_snapshot_table();
		$this->create_cancellation_feedback_table();
		$this->create_plan_group_table();
		$this->create_plan_table();
		$this->create_plan_relation_table();
		$this->create_renewal_claim_table();
		PaypalDB::maybe_create_tables();
		$this->backfill_open_renewal_claims();

		update_option( 'subscrpt_db_version', self::DB_VERSION );
	}

	/**
	 * Claim open renewal orders created before the claim table existed.
	 *
	 * Completed renewals are intentionally excluded: their subscription schedule
	 * already points at a later billing period, which must remain unclaimed.
	 *
	 * @return void
	 */
	private function backfill_open_renewal_claims() {
		if ( get_option( 'subscrpt_renewal_claim_backfill_1' ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		global $wpdb;
		$relation_table = $wpdb->prefix . 'subscrpt_order_relation';
		$rows           = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT subscription_id, order_id
				 FROM %i
				 WHERE type = 'renew'
				 ORDER BY subscription_id ASC, id DESC",
				array( $relation_table )
			)
		);
		if ( ! is_array( $rows ) ) {
			subscrpt_write_log( 'Renewal claim backfill could not read legacy renewal orders; it will be retried.' );
			return;
		}

		$open_orders = array();
		foreach ( $rows as $row ) {
			$subscription_id = (int) $row->subscription_id;
			$order_id        = (int) $row->order_id;
			$order = wc_get_order( $order_id );
			if ( ! $order || ! in_array( $order->get_status(), array( 'pending', 'failed', 'on-hold' ), true ) ) {
				continue;
			}
			$open_orders[ $subscription_id ][ $order_id ] = $order;
		}

		$blocked = array();
		foreach ( $open_orders as $subscription_id => $orders ) {
			$period_anchor = (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
			$order         = 1 === count( $orders ) ? reset( $orders ) : false;
			$date_created  = $order ? $order->get_date_created() : false;
			$created_at    = $date_created ? $date_created->getTimestamp() : 0;
			$unambiguous   = $order && $period_anchor > 0 && $created_at >= ( $period_anchor - DAY_IN_SECONDS );
			$stripe_customer = $order ? (string) $order->get_meta( '_stripe_customer_id' ) : '';
			$stripe_intent   = $order ? (string) $order->get_meta( '_stripe_intent_id' ) : '';
			$is_stripe       = $order && (
				in_array( $order->get_payment_method(), array( 'stripe', 'stripe_ideal', 'stripe_sepa', 'sepa_debit', 'stripe_bancontact' ), true )
				|| '' !== $stripe_customer
			);
			if ( $is_stripe && ( '' === $stripe_customer || 0 !== strpos( $stripe_intent, 'pi_' ) ) ) {
				// A pre-migration worker may have created a remote PaymentIntent before
				// saving its ID. Without both historical values, automatic redispatch
				// cannot conclusively rule out a charge under a prior customer.
				$unambiguous = false;
			}

			if ( $unambiguous && RenewalClaim::claim_order( (int) $subscription_id, (int) $order->get_id(), $period_anchor ) ) {
				$period_key = RenewalClaim::period_key( (int) $subscription_id, $period_anchor );
				$order->update_meta_data( '_subscrpt_renewal_period_key', $period_key );
				if ( $is_stripe ) {
					$order->update_meta_data( '_subscrpt_stripe_renewal_customer', $stripe_customer );
				}
				$order->save();
				$persisted_order = wc_get_order( $order->get_id() );
				if (
					! $persisted_order
					|| ! hash_equals( $period_key, (string) $persisted_order->get_meta( '_subscrpt_renewal_period_key' ) )
					|| ( $is_stripe && ! hash_equals( $stripe_customer, (string) $persisted_order->get_meta( '_subscrpt_stripe_renewal_customer' ) ) )
				) {
					$blocked[] = (int) $subscription_id;
					$order->update_meta_data( '_subscrpt_renewal_quarantined', 1 );
					$order->add_order_note( __( 'Payment blocked because the canonical renewal identity could not be persisted during migration. Reconcile this order before accepting payment.', 'subscription' ) );
					$order->save();
					subscrpt_write_log( "Renewal migration blocked for subscription #{$subscription_id}; canonical order metadata could not be persisted." );
				}
				continue;
			}

			$blocked[] = (int) $subscription_id;
			foreach ( $orders as $open_order ) {
				if ( $open_order->get_meta( '_subscrpt_renewal_quarantined' ) ) {
					continue;
				}

				$open_order->update_meta_data( '_subscrpt_renewal_quarantined', 1 );
				$open_order->add_order_note( __( 'Payment blocked during Ashbi Subscriptions migration because the renewal period is ambiguous. Reconcile this order before accepting payment.', 'subscription' ) );
				$open_order->save();
			}
			subscrpt_write_log( "Renewal migration blocked for subscription #{$subscription_id}; open orders require reconciliation." );
		}

		if ( empty( $blocked ) ) {
			delete_option( 'subscrpt_renewal_migration_blocked' );
			update_option( 'subscrpt_renewal_claim_backfill_1', current_time( 'mysql', true ), false );
		} else {
			update_option( 'subscrpt_renewal_migration_blocked', array_values( array_unique( $blocked ) ), false );
		}
	}

	/**
	 * Create the daily stats snapshot table.
	 *
	 * One row per calendar day holding the subscription status counts and the
	 * total monthly recurring revenue (MRR) at snapshot time. Powers MRR/
	 * subscription "over time" charts in reports and the recovery report.
	 *
	 * @return void
	 */
	public function create_stats_snapshot_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'subscrpt_stats_snapshot';

		$schema = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
                      `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                      `snapshot_date` DATE NOT NULL,
                      `active_count` INT(11) NOT NULL DEFAULT 0,
                      `pending_count` INT(11) NOT NULL DEFAULT 0,
                      `on_hold_count` INT(11) NOT NULL DEFAULT 0,
                      `cancelled_count` INT(11) NOT NULL DEFAULT 0,
                      `expired_count` INT(11) NOT NULL DEFAULT 0,
                      `pe_cancelled_count` INT(11) NOT NULL DEFAULT 0,
                      `active_mrr` DECIMAL(14,2) NOT NULL DEFAULT 0,
                      `created_at` DATETIME NOT NULL,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `snapshot_date` (`snapshot_date`)
                    ) $charset_collate";

		dbDelta( $schema );
	}

	/**
	 * Create the cancellation survey table.
	 *
	 * One row per subscription — the latest cancellation survey (a re-cancel after
	 * reactivation overwrites the previous row rather than accumulating a log, so
	 * churn-by-reason reports never double-count a subscription). Stores the
	 * customer's stated reason (key + a label snapshot that survives later reason
	 * edits/deletes) and optional comment. Consumed for churn tracking — the recovery
	 * plugin joins its recovery log to this table on subscription_id.
	 *
	 * @return void
	 */
	public function create_cancellation_feedback_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'subscrpt_cancellation_feedback';

		$schema = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
                      `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                      `subscription_id` BIGINT(20) UNSIGNED NOT NULL,
                      `customer_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                      `reason_key` VARCHAR(60) NOT NULL DEFAULT '',
                      `reason_label` VARCHAR(191) NOT NULL DEFAULT '',
                      `comment` TEXT NULL,
                      `created_at` DATETIME NOT NULL,
                      PRIMARY KEY (`id`),
                      KEY `subscription_id` (`subscription_id`),
                      KEY `created_at` (`created_at`)
                    ) $charset_collate";

		dbDelta( $schema );
	}

	/**
	 * Create the subscription plan group table.
	 *
	 * A plan group is a named, reusable subscription plan (e.g. "Premium
	 * Membership") attached to one or more products. This is the free base
	 * schema; Pro adds columns on top via versioned migrations keyed on
	 * `subscrpt_db_version`.
	 *
	 * type:         1 = Subscribe & Save, 2 = Recurring, 3 = Installment (free writes 2).
	 * product_type: 1 = specific products, 2 = taxonomy (free writes 1).
	 * data:         JSON (group-level settings).
	 *
	 * @return void
	 */
	public function create_plan_group_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'subscrpt_plan_group';

		$schema = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
                      `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                      `type` TINYINT(2) NOT NULL DEFAULT 2,
                      `product_type` TINYINT(2) NOT NULL DEFAULT 1,
                      `title` VARCHAR(255) NOT NULL DEFAULT '',
                      `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                      `data` LONGTEXT NULL,
                      `created_at` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                      `updated_at` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                      PRIMARY KEY (`id`),
                      KEY `type` (`type`),
                      KEY `status` (`status`)
                    ) $charset_collate";

		dbDelta( $schema );
	}

	/**
	 * Create the subscription plan (term) table.
	 *
	 * A plan is one billing term inside a group (e.g. "every 1 month"). There is
	 * no price column — pricing lives per product on the relation.
	 *
	 * billing_interval: 1 = day, 2 = week, 3 = month, 4 = year.
	 * billing_length:   expiry in cycles, 0 = unlimited.
	 * price_mode:       snapshot (default) | live (free always snapshot).
	 *
	 * @return void
	 */
	public function create_plan_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'subscrpt_plan';

		$schema = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
                      `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                      `plan_group_id` BIGINT(20) UNSIGNED NOT NULL,
                      `title` VARCHAR(255) NOT NULL DEFAULT '',
                      `type` TINYINT(2) NOT NULL DEFAULT 2,
                      `billing_frequency` INT(11) NOT NULL DEFAULT 1,
                      `billing_interval` TINYINT(2) NOT NULL DEFAULT 3,
                      `billing_length` INT(11) NOT NULL DEFAULT 0,
                      `signup_fee` LONGTEXT NULL,
                      `free_trial` VARCHAR(50) NOT NULL DEFAULT '',
                      `prepaid` TINYINT(1) NOT NULL DEFAULT 0,
                      `offer` LONGTEXT NULL,
                      `price_mode` VARCHAR(20) NOT NULL DEFAULT 'snapshot',
                      `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                      `data` LONGTEXT NULL,
                      `created_at` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                      `updated_at` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                      PRIMARY KEY (`id`),
                      KEY `plan_group_id` (`plan_group_id`),
                      KEY `status` (`status`)
                    ) $charset_collate";

		dbDelta( $schema );
	}

	/**
	 * Create the subscription plan relation table.
	 *
	 * The many-to-many join between a plan term and a product, carrying the
	 * per-product price in its `data` JSON.
	 *
	 * oid:     product id (type 1) or term id (type 2).
	 * vid:     variation id; 0 for simple products (free always 0).
	 * type:    1 = product, 2 = taxonomy.
	 * data:    JSON price override (regular_price, sale_price, discount_type,
	 *          discount_value).
	 * exclude: per-row toggle to hide one term for one product.
	 *
	 * @return void
	 */
	public function create_plan_relation_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'subscrpt_plan_relation';

		$schema = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
                      `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                      `plan_id` BIGINT(20) UNSIGNED NOT NULL,
                      `oid` BIGINT(20) UNSIGNED NOT NULL,
                      `vid` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                      `type` TINYINT(2) NOT NULL DEFAULT 1,
                      `data` LONGTEXT NULL,
                      `exclude` TINYINT(1) NOT NULL DEFAULT 0,
                      `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                      PRIMARY KEY (`id`),
                      KEY `plan_id` (`plan_id`),
                      KEY `plan_lookup` (`plan_id`,`type`,`oid`,`vid`),
                      KEY `product_lookup` (`type`,`oid`,`vid`)
                    ) $charset_collate";

		dbDelta( $schema );
	}

	/**
	 * Create the atomic renewal-period claim table.
	 *
	 * This table deliberately remains separate from the legacy order relation
	 * history. Existing history rows are immutable compatibility data, while a
	 * claim can temporarily exist before its canonical WooCommerce order does.
	 *
	 * @return void
	 */
	public function create_renewal_claim_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'subscrpt_renewal_claim';

		$schema = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
                      `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                      `subscription_id` BIGINT(20) UNSIGNED NOT NULL,
                      `period_key` CHAR(64) NOT NULL,
					  `period_anchor` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                      `order_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                      `state` VARCHAR(20) NOT NULL DEFAULT 'creating',
                      `claim_token` CHAR(36) NOT NULL,
                      `lease_expires_at` DATETIME NOT NULL,
					  `schedule_state` VARCHAR(20) NOT NULL DEFAULT 'waiting',
					  `schedule_attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
					  `schedule_next_attempt` DATETIME NULL,
					  `schedule_last_error` VARCHAR(191) NOT NULL DEFAULT '',
					  `payment_state` VARCHAR(20) NOT NULL DEFAULT 'waiting',
					  `payment_attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
					  `payment_next_attempt` DATETIME NULL,
					  `payment_last_error` VARCHAR(191) NOT NULL DEFAULT '',
                      `created_at` DATETIME NOT NULL,
                      `updated_at` DATETIME NOT NULL,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `subscription_period` (`subscription_id`,`period_key`),
                      KEY `order_id` (`order_id`),
                      KEY `lease_expires_at` (`lease_expires_at`),
					  KEY `schedule_repair` (`schedule_state`,`schedule_next_attempt`),
					  KEY `payment_repair` (`payment_state`,`payment_next_attempt`)
                    ) $charset_collate";

		dbDelta( $schema );
	}

	/**
	 * Create histories table
	 *
	 * @return void
	 */
	public function create_histories_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'subscrpt_order_relation';

		$schema = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
                      `id` INT(255) NOT NULL AUTO_INCREMENT,
                      `subscription_id` INT(100) NOT NULL,
                      `order_id` INT(100) NOT NULL,
                      `order_item_id` INT(100) NOT NULL,
                      `type` VARCHAR(50) NOT NULL,
                      PRIMARY KEY (`id`)
                    ) $charset_collate";

		dbDelta( $schema );
	}
}

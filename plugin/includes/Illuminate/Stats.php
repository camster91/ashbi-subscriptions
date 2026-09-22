<?php
/**
 * Aggregate subscription statistics.
 *
 * @package SpringDevs\Subscription\Illuminate
 */

// Legacy class path is part of the public compatibility contract.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Illuminate;

use SpringDevs\Subscription\Installer;

/**
 * Collects subscription statistics over time.
 *
 * Computes monthly recurring revenue (MRR) and status counts, and writes one
 * snapshot per calendar day into {prefix}subscrpt_stats_snapshot so the
 * standalone reports can chart MRR and subscription state over time.
 *
 * The snapshot runs at most once per day, triggered by the hourly cron and,
 * as a low-traffic-site safety net, on admin page loads. The heavy aggregation
 * therefore happens only once daily regardless of trigger.
 *
 * @package SpringDevs\Subscription\Illuminate
 */
class Stats {

	/**
	 * Option key holding the last snapshot date (Y-m-d, UTC).
	 *
	 * @var string
	 */
	const LAST_SNAPSHOT_OPTION = 'subscrpt_stats_last_snapshot';

	/**
	 * Months represented by one unit of each billing period (for MRR).
	 *
	 * @var array<string,float>
	 */
	const MONTHS_PER_UNIT = array(
		'day'   => 0.03333333333,
		'week'  => 0.23333333333,
		'month' => 1.0,
		'year'  => 12.0,
	);

	/**
	 * Initialize the class.
	 */
	public function __construct() {
		// Self-heal the snapshot table for installs that updated without reactivating.
		Installer::maybe_upgrade();

		add_action( 'subscrpt_hourly_cron', array( $this, 'maybe_take_daily_snapshot' ) );
		add_action( 'admin_init', array( $this, 'maybe_take_daily_snapshot' ) );

		// A cached monthly total that ignores the sale that just happened is.
		// worse than no cache: the figure is wrong and nothing says so. Any.
		// order changing status can move a month's revenue in or out.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush_monthly_revenue' ) );
	}

	/**
	 * Drop the cached monthly revenue.
	 *
	 * @return void
	 */
	public static function flush_monthly_revenue() {
		for ( $months = 1; $months <= 24; $months++ ) {
			delete_transient( 'subscrpt_monthly_revenue_' . $months );
		}
	}

	/**
	 * Normalize a recurring amount to a monthly figure (MRR).
	 *
	 * MRR = amount / (interval * months_per_unit[period]). Unknown periods are
	 * treated as monthly; a zero cycle returns 0 to avoid division by zero.
	 *
	 * @param float  $amount   Raw recurring price.
	 * @param string $period   Billing period: day|week|month|year.
	 * @param int    $interval Billing interval (the "every N").
	 * @return float Monthly-normalized amount.
	 */
	public static function normalize_mrr( $amount, $period, $interval ) {
		$months_per_unit = isset( self::MONTHS_PER_UNIT[ $period ] ) ? self::MONTHS_PER_UNIT[ $period ] : 1.0;
		$cycle_in_months = $interval * $months_per_unit;

		if ( $cycle_in_months <= 0 ) {
			return 0.0;
		}

		return round( $amount / $cycle_in_months, 2 );
	}

	/**
	 * Calculate a bounded percentage for a report metric.
	 *
	 * Counts are deliberately clamped: an unavailable or inconsistent source
	 * must not make the admin dashboard display a negative or impossible rate.
	 *
	 * @param int $numerator   Count represented by the metric.
	 * @param int $denominator Population the metric is measured against.
	 * @return float Percentage rounded to two decimals.
	 */
	public static function calculate_percentage( int $numerator, int $denominator ): float {
		if ( $denominator <= 0 || $numerator <= 0 ) {
			return 0.0;
		}

		return round( min( 100, ( $numerator / $denominator ) * 100 ), 2 );
	}

	/**
	 * Calculate churn among subscriptions reaching a churned state in a period.
	 *
	 * @param int $churned Count that reached cancelled or expired.
	 * @param int $base    Starting active population.
	 * @return float Percentage.
	 */
	public static function calculate_churn_rate( int $churned, int $base ): float {
		return self::calculate_percentage( $churned, $base );
	}

	/**
	 * Calculate the share of failed renewals subsequently recovered.
	 *
	 * @param int $recovered Failed renewals later paid successfully.
	 * @param int $failed    Failed renewals in the same reporting window.
	 * @return float Percentage.
	 */
	public static function calculate_recovery_rate( int $recovered, int $failed ): float {
		return self::calculate_percentage( $recovered, $failed );
	}

	/**
	 * Calculate the share of a signup cohort still retained.
	 *
	 * @param int $retained Cohort subscriptions not in a terminal churn state.
	 * @param int $cohort   Total subscriptions in the cohort.
	 * @return float Percentage.
	 */
	public static function calculate_retention_rate( int $retained, int $cohort ): float {
		return self::calculate_percentage( $retained, $cohort );
	}

	/**
	 * Total monthly recurring revenue across all active subscriptions.
	 *
	 * @return float
	 */
	public static function calculate_active_mrr() {
		$ids = Helper::get_subscriptions(
			array(
				'status'         => 'active',
				'user_id'        => -1,
				'return'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$total = 0.0;

		foreach ( (array) $ids as $id ) {
			$total += self::subscription_mrr( (int) $id );
		}

		return round( $total, 2 );
	}

	/**
	 * Calculate monthly recurring value for one subscription snapshot.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @return float
	 */
	private static function subscription_mrr( int $subscription_id ): float {
		$amount       = (float) get_post_meta( $subscription_id, '_subscrpt_price', true );
		$product_id   = (int) get_post_meta( $subscription_id, '_subscrpt_product_id', true );
		$variation_id = (int) get_post_meta( $subscription_id, '_subscrpt_variation_id', true );
		$fallback_id  = $variation_id ? $variation_id : $product_id;

		$period = get_post_meta( $subscription_id, '_subscrpt_timing_option', true );
		$period = $period ? (string) $period : get_post_meta( $fallback_id, '_subscrpt_timing_option', true );
		$period = $period ? $period : 'month';

		$interval = get_post_meta( $subscription_id, '_subscrpt_timing_per', true );
		$interval = ! empty( $interval ) ? $interval : get_post_meta( $fallback_id, '_subscrpt_timing_per', true );
		$interval = max( 1, (int) $interval );

		return self::normalize_mrr( $amount, $period, $interval );
	}

	/**
	 * Count subscriptions per status.
	 *
	 * @return array<string,int> Keyed by status (active, pending, ...).
	 */
	public static function get_status_counts() {
		$counts   = wp_count_posts( 'subscrpt_order' );
		$statuses = array( 'active', 'pending', 'on_hold', 'cancelled', 'expired', 'completed', 'pe_cancelled' );
		$out      = array();

		foreach ( $statuses as $status ) {
			$out[ $status ] = isset( $counts->$status ) ? (int) $counts->$status : 0;
		}

		return $out;
	}

	/**
	 * Query arguments for active subscriptions whose next payment falls within
	 * the next N days.
	 *
	 * The one definition of "renewals due": the Overview counts with it and the
	 * subscriptions list filters with it, so the figure and the rows it opens
	 * cannot disagree. The meta clause is named so the list can sort by it.
	 *
	 * `_subscrpt_next_date` holds a Unix timestamp, so the window is compared
	 * numerically rather than as a date string.
	 *
	 * @param int $days Number of days ahead to look.
	 * @return array<string,mixed> WP_Query arguments.
	 */
	public static function renewals_due_args( int $days = 7 ): array {
		$now = time();

		return array(
			'post_type'   => 'subscrpt_order',
			'post_status' => 'active',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'subscrpt_next_date' => array(
					'key'     => '_subscrpt_next_date',
					'value'   => array( $now, $now + ( max( 1, $days ) * DAY_IN_SECONDS ) ),
					'compare' => 'BETWEEN',
					'type'    => 'NUMERIC',
				),
			),
		);
	}

	/**
	 * Count active subscriptions whose next payment falls inside a window.
	 *
	 * @param int $days Number of days ahead to look.
	 * @return int
	 */
	public static function count_renewals_due_within( int $days = 7 ): int {
		$query = new \WP_Query(
			array_merge(
				self::renewals_due_args( $days ),
				array(
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Count renewal orders that failed recently.
	 *
	 * Renewal orders are identified from the subscription relation table rather
	 * than from order meta, then looked up through `wc_get_orders()` — reading
	 * the posts table directly would return nothing on a store using HPOS.
	 *
	 * @param int $hours How far back to look.
	 * @return int
	 */
	public static function count_failed_renewals_since( int $hours = 24 ): int {
		global $wpdb;

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$since = time() - ( max( 1, $hours ) * HOUR_IN_SECONDS );

		/*
		 * Ask WooCommerce first, not the relation table.
		 *
		 * The relation table holds every renewal order ever created, so starting
		 * there means pulling an unbounded id list out of a store's whole
		 * history and handing it to wc_get_orders(). Starting from the orders
		 * side bounds the set by the time window before anything else runs —
		 * usually a handful of rows — and only those ids reach the second query.
		 *
		 * wc_get_orders() rather than SQL against posts, because a store on HPOS
		 * keeps orders in their own tables and a posts query returns nothing.
		 */
		$failed = wc_get_orders(
			array(
				'status'        => array( 'failed' ),
				'date_modified' => '>' . $since,
				'limit'         => -1,
				'return'        => 'ids',
			)
		);

		$failed = array_filter( array_map( 'intval', (array) $failed ) );

		if ( empty( $failed ) ) {
			return 0;
		}

		$table        = $wpdb->prefix . 'subscrpt_order_relation';
		$placeholders = implode( ',', array_fill( 0, count( $failed ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the prefix; ids are placeheld below.
		$sql = "SELECT COUNT( DISTINCT order_id ) FROM {$table} WHERE type = 'renew' AND order_id IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $failed ) );
	}

	/**
	 * Count subscriptions that reached a terminal churn state recently.
	 *
	 * This is a state-transition proxy based on the subscription post's modified
	 * timestamp. It does not pretend to reconstruct historical state changes that
	 * were never stored by the upstream schema.
	 *
	 * @param int $days How far back to look.
	 * @return int
	 */
	public static function count_churned_since( int $days = 30 ): int {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts}
				 WHERE post_type = 'subscrpt_order'
				   AND post_status IN ( 'cancelled', 'expired' )
				   AND post_modified_gmt >= %s",
				$since
			)
		);
	}

	/**
	 * Calculate monthly recurring revenue exposed to cancellation or recovery.
	 *
	 * Pending-cancellation subscriptions retain access until their configured
	 * end date, while on-hold subscriptions are at risk of being lost. Their
	 * values are normalized with the same schedule logic as active MRR.
	 *
	 * @return float
	 */
	public static function calculate_revenue_at_risk(): float {
		$ids = Helper::get_subscriptions(
			array(
				'status'         => array( 'on_hold', 'pe_cancelled' ),
				'user_id'        => -1,
				'return'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$total = 0.0;
		foreach ( (array) $ids as $id ) {
			$total += self::subscription_mrr( (int) $id );
		}

		return round( $total, 2 );
	}

	/**
	 * Count renewal orders marked as recovered after a prior failed/on-hold state.
	 *
	 * A failure marker is retained on the renewal order before the later paid
	 * transition, which keeps failed -> pending -> paid recoveries attributable.
	 * The recovered marker keeps this query HPOS-safe and prevents an ordinary
	 * successful renewal from being misreported as recovery.
	 *
	 * @param int $hours How far back to look.
	 * @return int
	 */
	public static function count_recovered_renewals_since( int $hours = 24 ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = wc_get_orders(
			array(
				'status'        => array( 'processing', 'completed' ),
				'date_modified' => '>' . ( time() - ( max( 1, $hours ) * HOUR_IN_SECONDS ) ),
				'limit'         => -1,
				'return'        => 'ids',
				'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_subscrpt_renewal_recovered',
						'value' => '1',
					),
				),
			)
		);

		return count( array_filter( array_map( 'intval', (array) $orders ) ) );
	}

	/**
	 * Aggregate cancellation reasons without returning customer comments or IDs.
	 *
	 * @param int $days Optional lookback window; zero includes all retained rows.
	 * @return array{reasons:array<int,array{reason:string,count:int,percent:float}>,total:int}
	 */
	public static function get_cancellation_reason_counts( int $days = 0 ): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'subscrpt_cancellation_feedback';
		$cutoff = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ) : '1970-01-01 00:00:00';
		$sql    = $wpdb->prepare(
			'SELECT reason_label, COUNT(1) AS reason_count FROM %i
				WHERE created_at >= %s GROUP BY reason_label ORDER BY reason_count DESC, reason_label ASC',
			array( $table, $cutoff )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above; table is prefixed.
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		$total = 0;
		foreach ( (array) $rows as $row ) {
			$total += (int) ( $row['reason_count'] ?? 0 );
		}

		$reasons = array();
		foreach ( (array) $rows as $row ) {
			$count     = (int) ( $row['reason_count'] ?? 0 );
			$label     = trim( (string) ( $row['reason_label'] ?? '' ) );
			$reasons[] = array(
				'reason'  => $label ? $label : __( 'Not specified', 'subscription' ),
				'count'   => $count,
				'percent' => self::calculate_percentage( $count, $total ),
			);
		}

		return array(
			'reasons' => $reasons,
			'total'   => $total,
		);
	}

	/**
	 * Aggregate local recovery-campaign outcomes without exposing identifiers.
	 *
	 * @param int $days Lookback window.
	 * @return array{campaigns:array<int,array<string,mixed>>,totals:array<string,int>}
	 */
	public static function get_recovery_campaign_counts( int $days = 90 ): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'subscrpt_recovery_event';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$sql    = $wpdb->prepare(
			'SELECT campaign_key, event_type, COUNT(1) AS event_count FROM %i
				WHERE created_at >= %s GROUP BY campaign_key, event_type ORDER BY campaign_key ASC, event_type ASC',
			array( $table, $cutoff )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above.
		$rows      = $wpdb->get_results( $sql, ARRAY_A );
		$totals    = array(
			'offer_issued'   => 0,
			'offer_accepted' => 0,
			'save'           => 0,
			'win_back'       => 0,
		);
		$campaigns = array();

		foreach ( (array) $rows as $row ) {
			$campaign_key = (string) ( $row['campaign_key'] ?? '' );
			$event_type   = (string) ( $row['event_type'] ?? '' );
			$count        = (int) ( $row['event_count'] ?? 0 );
			if ( '' === $campaign_key ) {
				$campaign_key = 'uncategorized';
			}
			if ( ! isset( $campaigns[ $campaign_key ] ) ) {
				$campaigns[ $campaign_key ] = array(
					'campaign'        => $campaign_key,
					'offer_issued'    => 0,
					'offer_accepted'  => 0,
					'save'            => 0,
					'win_back'        => 0,
					'acceptance_rate' => 0.0,
					'win_back_rate'   => 0.0,
				);
			}
			if ( array_key_exists( $event_type, $totals ) ) {
				$totals[ $event_type ] += $count;
			}
			if ( array_key_exists( $event_type, $campaigns[ $campaign_key ] ) ) {
				$campaigns[ $campaign_key ][ $event_type ] += $count;
			}
		}

		foreach ( $campaigns as &$campaign ) {
			$campaign['acceptance_rate'] = self::calculate_percentage( $campaign['offer_accepted'], $campaign['offer_issued'] );
			$campaign['win_back_rate']   = self::calculate_percentage( $campaign['win_back'], $campaign['offer_accepted'] );
		}
		unset( $campaign );

		return array(
			'campaigns' => array_values( $campaigns ),
			'totals'    => $totals,
		);
	}

	/**
	 * Summarize signup cohorts by calendar month.
	 *
	 * Cohorts use the subscription post creation month and classify current
	 * status. This is intentionally a current-state cohort view, not a claim of
	 * full historical event sourcing.
	 *
	 * @param int $months Number of calendar cohorts, including the current month.
	 * @return array<int,array{month:string,label:string,cohort:int,retained:int,churned:int,retention_rate:float}>
	 */
	public static function get_cohort_summary( int $months = 6 ): array {
		$months      = max( 1, min( 24, $months ) );
		$this_month  = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of this month' )->setTime( 0, 0 );
		$cohorts     = array();
		$retained_in = array( 'active', 'pending', 'on_hold', 'pe_cancelled' );
		$churned_in  = array( 'cancelled', 'expired' );

		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$start = $this_month->modify( "-{$i} months" );
			$end   = $start->modify( '+1 month' )->modify( '-1 second' );
			$posts = get_posts(
				array(
					'post_type'      => 'subscrpt_order',
					'post_status'    => 'any',
					'fields'         => 'all',
					'posts_per_page' => -1,
					'date_query'     => array(
						array(
							'after'     => $start->format( 'Y-m-d H:i:s' ),
							'before'    => $end->format( 'Y-m-d H:i:s' ),
							'inclusive' => true,
						),
					),
				)
			);

			$cohort   = count( (array) $posts );
			$retained = 0;
			$churned  = 0;
			foreach ( (array) $posts as $post ) {
				$status = (string) ( $post->post_status ?? '' );
				if ( in_array( $status, $retained_in, true ) ) {
					++$retained;
				} elseif ( in_array( $status, $churned_in, true ) ) {
					++$churned;
				}
			}

			$cohorts[] = array(
				'month'          => $start->format( 'Y-m' ),
				'label'          => wp_date( 'M Y', $start->getTimestamp() ),
				'cohort'         => $cohort,
				'retained'       => $retained,
				'churned'        => $churned,
				'retention_rate' => self::calculate_retention_rate( $retained, $cohort ),
			);
		}

		return $cohorts;
	}

	/**
	 * Count subscriptions created within the last N days.
	 *
	 * @param int $days Number of days back to look.
	 * @return int
	 */
	public static function count_new_since( int $days = 7 ): int {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts}
				 WHERE post_type = 'subscrpt_order'
				   AND post_status NOT IN ( 'trash', 'auto-draft' )
				   AND post_date_gmt >= %s",
				$since
			)
		);
	}

	/**
	 * Count subscriptions created in one calendar month.
	 *
	 * Asks exactly what the subscriptions list asks when filtered to that month
	 * — the same statuses, the same local post date — so a figure that links to
	 * the filtered list always matches the rows it opens.
	 *
	 * @param \DateTimeInterface $month Any moment in the month, in the store's timezone.
	 * @return int
	 */
	public static function count_new_in_month( \DateTimeInterface $month ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => 'subscrpt_order',
				'post_status'    => 'any',
				'date_query'     => array(
					array(
						'year'  => (int) $month->format( 'Y' ),
						'month' => (int) $month->format( 'n' ),
					),
				),
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Revenue from subscription orders, grouped by month.
	 *
	 * Read from real orders rather than the snapshot table: snapshots record
	 * counts and MRR from the day this plugin started taking them, so a store
	 * that installed last week has no history to chart. Orders go back as far
	 * as the store does.
	 *
	 * Cached, because this is the one figure on the dashboard that does not
	 * change minute to minute and the only one whose cost grows with the size
	 * of the store.
	 *
	 * @param int $months How many months to return, including the current one.
	 * @return array<int,array{label:string,month:string,total:float}> Oldest first.
	 */
	public static function get_monthly_revenue( int $months = 6 ): array {
		global $wpdb;

		$months = max( 1, min( 24, $months ) );
		$key    = 'subscrpt_monthly_revenue_' . $months;
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Every month in the window, so a month with no sales is a gap in the.
		// chart rather than a missing bar that shifts everything along.
		//
		// The store's months, not UTC's. WooCommerce dates an order in the store.
		// timezone, so UTC buckets have no bar for an order placed between UTC.
		// and local midnight on the 1st, and it silently drops out of the chart.
		$this_month = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of this month' )->setTime( 0, 0 );
		$first      = $this_month->modify( '-' . ( $months - 1 ) . ' months' );

		$buckets = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$month = $this_month->modify( "-{$i} months" );

			$buckets[ $month->format( 'Y-m' ) ] = array(
				'label' => wp_date( 'M', $month->getTimestamp() ),
				'month' => $month->format( 'Y-m' ),
				'total' => 0.0,
			);
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array_values( $buckets );
		}

		$table = $wpdb->prefix . 'subscrpt_order_relation';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the prefix.
		$order_ids = $wpdb->get_col( "SELECT DISTINCT order_id FROM {$table}" );
		$order_ids = array_filter( array_map( 'intval', (array) $order_ids ) );

		if ( ! empty( $order_ids ) ) {
			$orders = wc_get_orders(
				array(
					'post__in'     => $order_ids,
					'status'       => array( 'completed', 'processing' ),
					// A timestamp, not a date string: WooCommerce keeps only the day.
					// of a string and reads it in the store timezone, while a.
					// timestamp is compared to the second.
					'date_created' => '>=' . $first->getTimestamp(),
					'limit'        => -1,
				)
			);

			foreach ( (array) $orders as $order ) {
				$created = $order->get_date_created();

				if ( ! $created ) {
					continue;
				}

				$bucket = $created->date( 'Y-m' );

				if ( isset( $buckets[ $bucket ] ) ) {
					$buckets[ $bucket ]['total'] += (float) $order->get_total();
				}
			}
		}

		$out = array_values( $buckets );

		set_transient( $key, $out, 6 * HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Take today's snapshot unless one already exists for today.
	 *
	 * @return void
	 */
	public function maybe_take_daily_snapshot() {
		$today = gmdate( 'Y-m-d' );

		if ( get_option( self::LAST_SNAPSHOT_OPTION ) === $today ) {
			return;
		}

		$this->take_snapshot( $today );
		update_option( self::LAST_SNAPSHOT_OPTION, $today );
	}

	/**
	 * Compute and persist a snapshot row for the given date.
	 *
	 * @param string $date Snapshot date (Y-m-d, UTC). Defaults to today.
	 * @return void
	 */
	public function take_snapshot( $date = '' ) {
		global $wpdb;

		$date   = $date ? $date : gmdate( 'Y-m-d' );
		$counts = self::get_status_counts();

		$wpdb->replace(
			$wpdb->prefix . 'subscrpt_stats_snapshot',
			array(
				'snapshot_date'      => $date,
				'active_count'       => $counts['active'],
				'pending_count'      => $counts['pending'],
				'on_hold_count'      => $counts['on_hold'],
				'cancelled_count'    => $counts['cancelled'],
				'expired_count'      => $counts['expired'],
				'pe_cancelled_count' => $counts['pe_cancelled'],
				'active_mrr'         => self::calculate_active_mrr(),
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%s' )
		);
	}

	/**
	 * Read snapshot rows within a date range (inclusive), oldest first.
	 *
	 * @param string $from Start date (Y-m-d). Empty for no lower bound.
	 * @param string $to   End date (Y-m-d). Empty for no upper bound.
	 * @return array<int,object> Snapshot rows.
	 */
	public static function get_snapshots( $from = '', $to = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_stats_snapshot';
		$where = '1=1';
		$args  = array();

		if ( $from ) {
			$where .= ' AND snapshot_date >= %s';
			$args[] = $from;
		}

		if ( $to ) {
			$where .= ' AND snapshot_date <= %s';
			$args[] = $to;
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY snapshot_date ASC";

		if ( $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql );
	}
}

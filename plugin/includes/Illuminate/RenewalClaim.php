<?php
/**
 * Atomic renewal-period claims.
 *
 * @package SpringDevs\Subscription\Illuminate
 */

// PSR-4 class filename is retained for the public renewal compatibility path.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Illuminate;

/**
 * Ensures one canonical WooCommerce order exists for a subscription period.
 */
final class RenewalClaim {

	/**
	 * Time allowed for a worker to attach an order before another may recover it.
	 */
	const LEASE_SECONDS = 300;

	/**
	 * Atomically acquire the current subscription period.
	 *
	 * @param int      $subscription_id Subscription post ID.
	 * @param int      $order_id        Existing order ID for checkout renewals.
	 * @param int|null $period_anchor   Captured due-date timestamp.
	 * @return array|false Claim details, or false when storage is unavailable.
	 */
	public static function acquire( int $subscription_id, int $order_id = 0, ?int $period_anchor = null ) {
		global $wpdb;

		if ( null === $period_anchor ) {
			$period_anchor = (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
			if ( $period_anchor <= 0 ) {
				$period_anchor = (int) get_post_meta( $subscription_id, '_subscrpt_start_date', true );
			}
		}
		$period_key = self::period_key( $subscription_id, $period_anchor );
		$token      = wp_generate_uuid4();
		$table      = $wpdb->prefix . 'subscrpt_renewal_claim';
		$created_at = current_time( 'mysql', true );
		$lease_end  = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );
		$state      = $order_id > 0 ? 'ready' : 'creating';

		// The unique subscription/period key serializes every renewal entry point.
		// An abandoned pre-order claim can be reclaimed only after its bounded lease.
		$sql = $wpdb->prepare(
			'INSERT INTO %i
				(subscription_id, period_key, period_anchor, order_id, state, claim_token, lease_expires_at, created_at, updated_at)
			 VALUES (%d, %s, %d, %d, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE
				state = IF(order_id = 0 AND lease_expires_at < UTC_TIMESTAMP(), VALUES(state), state),
				claim_token = IF(order_id = 0 AND lease_expires_at < UTC_TIMESTAMP(), VALUES(claim_token), claim_token),
				lease_expires_at = IF(order_id = 0 AND lease_expires_at < UTC_TIMESTAMP(), VALUES(lease_expires_at), lease_expires_at),
				updated_at = IF(order_id = 0 AND lease_expires_at < UTC_TIMESTAMP(), VALUES(updated_at), updated_at),
				order_id = IF(order_id = 0 AND lease_expires_at < UTC_TIMESTAMP(), VALUES(order_id), order_id)',
			array( $table, $subscription_id, $period_key, (int) $period_anchor, $order_id, $state, $token, $lease_end, $created_at, $created_at )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above, including the table identifier.
		if ( false === $wpdb->query( $sql ) ) {
			subscrpt_write_log( "Could not acquire renewal claim for subscription #{$subscription_id}." );
			return false;
		}

		$row = self::get( $subscription_id, $period_key );
		if ( ! $row ) {
			return false;
		}

		return array(
			'acquired'   => hash_equals( (string) $row->claim_token, $token ),
			'token'      => $token,
			'period_key' => $period_key,
			'order_id'   => (int) $row->order_id,
			'state'      => (string) $row->state,
		);
	}

	/**
	 * Bind a newly created order to the acquired period claim.
	 *
	 * @param int    $subscription_id Subscription post ID.
	 * @param string $period_key      Canonical period key.
	 * @param string $token           Claim owner token.
	 * @param int    $order_id        New WooCommerce order ID.
	 * @return bool
	 */
	public static function finalize( int $subscription_id, string $period_key, string $token, int $order_id ): bool {
		global $wpdb;

		$table   = $wpdb->prefix . 'subscrpt_renewal_claim';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET order_id = %d, state = 'ready', updated_at = UTC_TIMESTAMP()
				 WHERE subscription_id = %d AND period_key = %s AND claim_token = %s AND order_id = 0",
				array( $table, $order_id, $subscription_id, $period_key, $token )
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Claim the period for an order already created by checkout.
	 *
	 * @param int      $subscription_id Subscription post ID.
	 * @param int      $order_id        WooCommerce order ID.
	 * @param int|null $period_anchor Captured due-date timestamp.
	 * @return bool
	 */
	public static function claim_order( int $subscription_id, int $order_id, ?int $period_anchor = null ): bool {
		$claim = self::acquire( $subscription_id, $order_id, $period_anchor );
		if ( ! $claim ) {
			return false;
		}

		return ( ! empty( $claim['acquired'] ) || $order_id === (int) $claim['order_id'] );
	}

	/**
	 * Determine whether an order owns any durable claim for the subscription.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $order_id        WooCommerce order ID.
	 * @return bool
	 */
	public static function is_claimed_order( int $subscription_id, int $order_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_renewal_claim';
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE subscription_id = %d AND order_id = %d AND state = 'ready'",
				array( $table, $subscription_id, $order_id )
			)
		);

		return 1 === (int) $count;
	}

	/**
	 * Get the immutable due-date anchor attached to a canonical order.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $order_id        WooCommerce order ID.
	 * @return int
	 */
	public static function period_anchor_for_order( int $subscription_id, int $order_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_renewal_claim';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT period_anchor FROM %i WHERE subscription_id = %d AND order_id = %d AND state = 'ready' LIMIT 1",
				array( $table, $subscription_id, $order_id )
			)
		);
	}

	/**
	 * Durably record that an exact canonical order still needs schedule repair.
	 *
	 * @param int    $subscription_id Subscription post ID.
	 * @param int    $order_id Canonical renewal order ID.
	 * @param string $error Safe operational reason, without customer data.
	 * @return bool
	 */
	public static function mark_schedule_pending( int $subscription_id, int $order_id, string $error = '' ): bool {
		global $wpdb;

		$table      = $wpdb->prefix . 'subscrpt_renewal_claim';
		$next_retry = gmdate( 'Y-m-d H:i:s', time() + self::schedule_retry_delay( $subscription_id, $order_id ) );
		$updated    = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				 SET schedule_state = 'pending',
				     schedule_attempts = schedule_attempts + 1,
				     schedule_next_attempt = %s,
				     schedule_last_error = %s,
				     updated_at = UTC_TIMESTAMP()
				 WHERE subscription_id = %d AND order_id = %d AND state = 'ready'",
				array( $table, $next_retry, substr( sanitize_text_field( $error ), 0, 191 ), $subscription_id, $order_id )
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Mark a canonical renewal schedule as persisted, with activation still due.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $order_id Canonical renewal order ID.
	 * @return bool
	 */
	public static function mark_schedule_complete( int $subscription_id, int $order_id ): bool {
		global $wpdb;

		$table   = $wpdb->prefix . 'subscrpt_renewal_claim';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				 SET schedule_state = 'scheduled', schedule_last_error = '',
				     schedule_next_attempt = COALESCE(schedule_next_attempt, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND)),
				     updated_at = UTC_TIMESTAMP()
				 WHERE subscription_id = %d AND order_id = %d AND state = 'ready'",
				array( $table, $subscription_id, $order_id )
			)
		);

		if ( 1 === (int) $updated ) {
			return true;
		}

		return in_array(
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT schedule_state FROM %i WHERE subscription_id = %d AND order_id = %d AND state = \'ready\' LIMIT 1',
					array( $table, $subscription_id, $order_id )
				)
			),
			array( 'scheduled', 'complete' ),
			true
		);
	}

	/**
	 * Mark schedule, activation, and renewal side effects fully reconciled.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $order_id Canonical renewal order ID.
	 * @return bool
	 */
	public static function mark_renewal_complete( int $subscription_id, int $order_id ): bool {
		global $wpdb;

		$table   = $wpdb->prefix . 'subscrpt_renewal_claim';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET schedule_state = 'complete', schedule_next_attempt = NULL,
				 schedule_last_error = '', updated_at = UTC_TIMESTAMP()
				 WHERE subscription_id = %d AND order_id = %d AND state = 'ready'",
				array( $table, $subscription_id, $order_id )
			)
		);

		if ( 1 === (int) $updated ) {
			return true;
		}

		return 'complete' === (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT schedule_state FROM %i WHERE subscription_id = %d AND order_id = %d AND state = \'ready\' LIMIT 1',
				array( $table, $subscription_id, $order_id )
			)
		);
	}

	/**
	 * Get the persisted retry timestamp for queue parity with the hourly sweep.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $order_id Canonical renewal order ID.
	 * @return int Unix timestamp, or zero when unavailable.
	 */
	public static function schedule_next_attempt( int $subscription_id, int $order_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_renewal_claim';
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT schedule_next_attempt FROM %i
				 WHERE subscription_id = %d AND order_id = %d AND state = 'ready' LIMIT 1",
				array( $table, $subscription_id, $order_id )
			)
		);

		return $value ? (int) strtotime( (string) $value . ' UTC' ) : 0;
	}

	/**
	 * Mark canonical payment dispatch pending with capped backoff.
	 *
	 * @param int    $subscription_id Subscription post ID.
	 * @param int    $order_id Canonical renewal order ID.
	 * @param string $error Safe operational reason.
	 * @return bool
	 */
	public static function mark_payment_pending( int $subscription_id, int $order_id, string $error = '' ): bool {
		global $wpdb;

		$table    = $wpdb->prefix . 'subscrpt_renewal_claim';
		$attempts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT payment_attempts FROM %i WHERE subscription_id = %d AND order_id = %d AND state = 'ready' LIMIT 1",
				array( $table, $subscription_id, $order_id )
			)
		);
		$delay    = min( 3600, 300 * ( 2 ** min( 4, max( 0, $attempts ) ) ) );
		$updated  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET payment_state = 'pending', payment_attempts = payment_attempts + 1,
				 payment_next_attempt = %s, payment_last_error = %s, updated_at = UTC_TIMESTAMP()
				 WHERE subscription_id = %d AND order_id = %d AND state = 'ready'",
				array( $table, gmdate( 'Y-m-d H:i:s', time() + $delay ), substr( sanitize_text_field( $error ), 0, 191 ), $subscription_id, $order_id )
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Mark canonical payment dispatch complete or no longer payable.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $order_id Canonical renewal order ID.
	 * @return bool
	 */
	public static function mark_payment_complete( int $subscription_id, int $order_id ): bool {
		global $wpdb;

		$table   = $wpdb->prefix . 'subscrpt_renewal_claim';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET payment_state = 'complete', payment_next_attempt = NULL,
				 payment_last_error = '', updated_at = UTC_TIMESTAMP()
				 WHERE subscription_id = %d AND order_id = %d AND state = 'ready'",
				array( $table, $subscription_id, $order_id )
			)
		);

		return 1 === (int) $updated || 'complete' === (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT payment_state FROM %i WHERE subscription_id = %d AND order_id = %d AND state = \'ready\' LIMIT 1',
				array( $table, $subscription_id, $order_id )
			)
		);
	}

	/**
	 * Mark a canonical payment as deterministically failed and stop automatic retry.
	 *
	 * The pending WooCommerce order remains payable by the customer. Its normal
	 * paid-order callback transitions this durable state to complete.
	 *
	 * @param int    $subscription_id Subscription post ID.
	 * @param int    $order_id Canonical renewal order ID.
	 * @param string $error Safe operational reason.
	 * @return bool
	 */
	public static function mark_payment_failed( int $subscription_id, int $order_id, string $error = '' ): bool {
		global $wpdb;

		$table   = $wpdb->prefix . 'subscrpt_renewal_claim';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET payment_state = 'failed', payment_next_attempt = NULL,
				 payment_last_error = %s, updated_at = UTC_TIMESTAMP()
				 WHERE subscription_id = %d AND order_id = %d AND state = 'ready'",
				array( $table, substr( sanitize_text_field( $error ), 0, 191 ), $subscription_id, $order_id )
			)
		);

		return 1 === (int) $updated || 'failed' === (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT payment_state FROM %i WHERE subscription_id = %d AND order_id = %d AND state = \'ready\' LIMIT 1',
				array( $table, $subscription_id, $order_id )
			)
		);
	}

	/**
	 * Get due canonical payment-dispatch retries.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function due_payment_retries( int $limit = 100 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_renewal_claim';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT subscription_id, order_id FROM %i
				 WHERE state = 'ready' AND payment_state = 'pending'
				 AND payment_next_attempt <= UTC_TIMESTAMP()
				 ORDER BY payment_next_attempt ASC LIMIT %d",
				array( $table, max( 1, min( 500, $limit ) ) )
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get persisted payment retry time.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $order_id Canonical renewal order ID.
	 * @return int Unix timestamp.
	 */
	public static function payment_next_attempt( int $subscription_id, int $order_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_renewal_claim';
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT payment_next_attempt FROM %i WHERE subscription_id = %d AND order_id = %d AND state = 'ready' LIMIT 1",
				array( $table, $subscription_id, $order_id )
			)
		);

		return $value ? (int) strtotime( (string) $value . ' UTC' ) : 0;
	}

	/**
	 * Load due durable schedule repairs for the hourly safety sweep.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array
	 */
	public static function due_schedule_repairs( int $limit = 100 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_renewal_claim';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT subscription_id, order_id
				 FROM %i
				 WHERE state = 'ready' AND schedule_state IN ('pending', 'scheduled')
				   AND schedule_next_attempt <= UTC_TIMESTAMP()
				 ORDER BY schedule_next_attempt ASC LIMIT %d",
				array( $table, max( 1, min( 500, $limit ) ) )
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Calculate a capped exponential retry delay from persisted attempt count.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @param int $order_id Canonical renewal order ID.
	 * @return int Delay in seconds.
	 */
	private static function schedule_retry_delay( int $subscription_id, int $order_id ): int {
		global $wpdb;

		$table    = $wpdb->prefix . 'subscrpt_renewal_claim';
		$attempts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT schedule_attempts FROM %i WHERE subscription_id = %d AND order_id = %d AND state = 'ready' LIMIT 1",
				array( $table, $subscription_id, $order_id )
			)
		);

		return min( 3600, 60 * ( 2 ** min( 6, max( 0, $attempts ) ) ) );
	}

	/**
	 * Release a claim only while it has no canonical order.
	 *
	 * @param int    $subscription_id Subscription post ID.
	 * @param string $period_key      Canonical period key.
	 * @param string $token           Claim owner token.
	 * @return void
	 */
	public static function release( int $subscription_id, string $period_key, string $token ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_renewal_claim';
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE subscription_id = %d AND period_key = %s AND claim_token = %s AND order_id = 0',
				array( $table, $subscription_id, $period_key, $token )
			)
		);
	}

	/**
	 * Generate a stable identity for the currently due billing period.
	 *
	 * @param int      $subscription_id Subscription post ID.
	 * @param int|null $period_anchor   Captured due-date timestamp.
	 * @return string
	 */
	public static function period_key( int $subscription_id, ?int $period_anchor = null ): string {
		$anchor = null === $period_anchor ? (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true ) : $period_anchor;
		if ( $anchor <= 0 ) {
			$anchor = (int) get_post_meta( $subscription_id, '_subscrpt_start_date', true );
		}

		return hash( 'sha256', 'scheduled:' . $anchor );
	}

	/**
	 * Load one period claim.
	 *
	 * @param int    $subscription_id Subscription post ID.
	 * @param string $period_key      Canonical period key.
	 * @return object|null
	 */
	private static function get( int $subscription_id, string $period_key ) {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_renewal_claim';
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT period_anchor, order_id, state, claim_token FROM %i WHERE subscription_id = %d AND period_key = %s',
				array( $table, $subscription_id, $period_key )
			)
		);
	}
}

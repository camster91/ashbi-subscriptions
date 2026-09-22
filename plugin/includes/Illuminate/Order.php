<?php
/**
 * WooCommerce order and subscription schedule integration.
 *
 * @package SpringDevs\Subscription\Illuminate
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName -- Legacy class path is part of the public plugin compatibility contract.

namespace SpringDevs\Subscription\Illuminate;

/**
 * Class Order
 *
 * @package SpringDevs\Subscription\Illuminate
 */
class Order {

	/**
	 * Initialize the class.
	 */
	public function __construct() {
		add_action( 'woocommerce_admin_order_item_headers', array( $this, 'register_custom_column' ) );
		add_action( 'woocommerce_admin_order_item_values', array( $this, 'add_column_value' ), 10, 2 );
		add_action( 'woocommerce_before_order_itemmeta', array( $this, 'add_order_item_data' ), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( $this, 'payment_complete' ), 30 );
		add_filter( 'woocommerce_order_needs_payment', array( $this, 'block_quarantined_renewal_payment' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'delete_the_subscription' ) );
		add_action( 'subscrpt_subscription_activated', array( $this, 'generate_dates_for_subscription' ) );
		add_action( 'subscrpt_retry_subscription_schedule', array( $this, 'retry_renewal_schedule' ), 10, 2 );
		add_action( 'subscrpt_hourly_cron', array( $this, 'repair_pending_schedules' ), 20 );

		add_action( 'subscrpt_queue_trial_order_autocomplete', array( $this, 'auto_complete_subscription_trial_order' ) );
	}

	/**
	 * Prevent payment of ambiguous legacy renewal orders pending reconciliation.
	 *
	 * @param bool      $needs_payment Whether WooCommerce considers payment due.
	 * @param \WC_Order $order         Order being evaluated.
	 * @return bool
	 */
	public function block_quarantined_renewal_payment( $needs_payment, $order ) {
		if ( $order instanceof \WC_Order && $order->get_meta( '_subscrpt_renewal_quarantined' ) ) {
			return false;
		}

		return $needs_payment;
	}

	/**
	 * Reconcile paid renewal orders when a gateway marks payment complete.
	 *
	 * Some gateways fire `woocommerce_payment_complete` before changing the
	 * order status, so relying only on `woocommerce_order_status_changed` would
	 * leave a successfully paid renewal unapplied.
	 *
	 * @param int $order_id Paid order ID.
	 * @return void
	 */
	public function payment_complete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'subscrpt_order_relation';
		$renewal = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE order_id = %d AND type IN (%s, %s) LIMIT 1',
				array( $table, (int) $order_id, 'early-renew', 'renew' )
			)
		);
		if ( $renewal ) {
			$this->order_status_changed( $order_id );
		}
	}

	/**
	 * Generate start, next and trial dates.
	 *
	 * @param int $subscription_id Subscription Id.
	 *
	 * @return void
	 */
	public function generate_dates_for_subscription( $subscription_id ) {
		$order_item_id = absint( get_post_meta( $subscription_id, '_subscrpt_order_item_id', true ) );
		if ( ! $order_item_id ) {
			return;
		}

		$subscription_history = Helper::get_subscription_from_order_item_id( $order_item_id );
		$order_item_meta      = wc_get_order_item_meta( $order_item_id, '_subscrpt_meta' );
		if ( ! is_object( $subscription_history ) || empty( $subscription_history->type ) || ! is_array( $order_item_meta ) || empty( $order_item_meta['type'] ) ) {
			return;
		}

		$type          = Helper::get_typos( 1, $order_item_meta['type'] );
		$trial         = get_post_meta( $subscription_id, '_subscrpt_trial', true );
		$recurr_timing = ( $order_item_meta['time'] ?? 1 ) . ' ' . $type;
		$next_date     = null;

		if ( 'new' === $subscription_history->type ) {
			$start_date = time();
			$next_date  = sdevs_wp_strtotime( $recurr_timing, $start_date );

			if ( $trial ) {
				$trial_started = get_post_meta( $subscription_id, '_subscrpt_trial_started', true );
				$trial_ended   = get_post_meta( $subscription_id, '_subscrpt_trial_ended', true );

				if ( empty( $trial_started ) && empty( $trial_ended ) ) {
					$start_date = sdevs_wp_strtotime( $trial );
					$next_date  = $start_date;

					update_post_meta( $subscription_id, '_subscrpt_trial_started', time() );
					update_post_meta( $subscription_id, '_subscrpt_trial_ended', $start_date );
					update_post_meta( $subscription_id, '_subscrpt_trial_mode', 'on' );
				}

				if ( ! empty( $trial_ended ) ) {
					$start_date = $trial_ended;
					$next_date  = $start_date;
				}
			}

			update_post_meta( $subscription_id, '_subscrpt_start_date', $start_date );

		} elseif ( 'renew' === $subscription_history->type ) {
			$this->persist_renewal_schedule(
				(int) $subscription_id,
				(int) $subscription_history->order_id,
				(int) $order_item_id,
				(string) $subscription_history->type
			);
			return;

		} elseif ( 'early-renew' === $subscription_history->type ) {
			if ( $trial ) {
				delete_post_meta( $subscription_id, '_subscrpt_trial' );
				delete_post_meta( $subscription_id, '_subscrpt_trial_mode' );
				delete_post_meta( $subscription_id, '_subscrpt_trial_started' );
				delete_post_meta( $subscription_id, '_subscrpt_trial_ended' );
			}

			$next_date = sdevs_wp_strtotime( $recurr_timing, time() );
		}

		// Split payment: no next date after the final installment.
		if (
			in_array( $subscription_history->type, array( 'renew', 'early-renew' ), true )
			&& function_exists( 'subscrpt_is_max_payments_reached' )
			&& subscrpt_is_max_payments_reached( $subscription_id )
		) {
			return;
		}
		if ( false === $next_date ) {
			return;
		}

		/**
		 * Filter the subscription next payment date before it is saved.
		 *
		 * General-purpose hook (not scoped to split payment) for adjusting the
		 * computed next renewal date. Note `$next_date` may be null for history
		 * types other than 'new'/'renew'/'early-renew' (e.g. switch orders).
		 *
		 * The deprecated `subscrpt_split_payment_next_due_date` filter is bridged
		 * onto this hook in LegacyCompat.php for backward compatibility.
		 *
		 * @param int|null $next_date       Computed next payment timestamp, or null.
		 * @param int      $subscription_id Subscription ID.
		 * @param string   $recurr_timing   Recurring timing string (e.g. "1 month").
		 * @param string   $type            Subscription history type.
		 */
		$next_date = apply_filters( 'subscrpt_subscription_next_date', $next_date, $subscription_id, $recurr_timing, $subscription_history->type );

		update_post_meta( $subscription_id, '_subscrpt_next_date', $next_date );
	}

	/**
	 * Persist one renewal schedule against an immutable order relation.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param int    $renewal_order_id Renewal order ID.
	 * @param int    $order_item_id Exact renewal order item ID.
	 * @param string $history_type Relation type supplied to compatibility filters.
	 * @return bool
	 */
	private function persist_renewal_schedule( int $subscription_id, int $renewal_order_id, int $order_item_id, string $history_type = 'renew' ): bool {
		if ( (int) get_post_meta( $subscription_id, '_subscrpt_next_date_set_by_order', true ) === $renewal_order_id ) {
			return RenewalClaim::mark_schedule_complete( $subscription_id, $renewal_order_id );
		}

		if ( ! RenewalClaim::mark_schedule_pending( $subscription_id, $renewal_order_id, 'Schedule persistence started.' ) ) {
			subscrpt_write_log( "CRITICAL: Could not persist schedule-repair state for subscription #{$subscription_id}, renewal order #{$renewal_order_id}. Activation remains blocked." );
			return false;
		}

		$order_item_meta = wc_get_order_item_meta( $order_item_id, '_subscrpt_meta' );
		if ( ! is_array( $order_item_meta ) || empty( $order_item_meta['type'] ) ) {
			subscrpt_write_log( "Renewal order #{$renewal_order_id} has no usable cadence metadata for subscription #{$subscription_id}." );
			$this->schedule_renewal_retry( $subscription_id, $renewal_order_id );
			return false;
		}

		if ( get_post_meta( $subscription_id, '_subscrpt_trial', true ) ) {
			delete_post_meta( $subscription_id, '_subscrpt_trial' );
			delete_post_meta( $subscription_id, '_subscrpt_trial_mode' );
			delete_post_meta( $subscription_id, '_subscrpt_trial_started' );
			delete_post_meta( $subscription_id, '_subscrpt_trial_ended' );
		}

		if ( function_exists( 'subscrpt_is_max_payments_reached' ) && subscrpt_is_max_payments_reached( $subscription_id ) ) {
			return RenewalClaim::mark_schedule_complete( $subscription_id, $renewal_order_id );
		}

		$type          = Helper::get_typos( 1, $order_item_meta['type'] );
		$recurr_timing = ( $order_item_meta['time'] ?? 1 ) . ' ' . $type;

		if ( false === $this->get_anchored_next_date( $subscription_id, $recurr_timing, $renewal_order_id, $history_type ) ) {
			return false;
		}

		if ( ! RenewalClaim::mark_schedule_complete( $subscription_id, $renewal_order_id ) ) {
			subscrpt_write_log( "Could not mark schedule repair complete for subscription #{$subscription_id}, renewal order #{$renewal_order_id}." );
			$this->schedule_renewal_retry( $subscription_id, $renewal_order_id );
			return false;
		}

		return true;
	}

	/**
	 * Calculate a renewal's next payment date, anchored to the previous due date.
	 *
	 * Computing from time() instead makes every cycle inherit however late the
	 * renewal was actually processed — with an hourly cron a due date of 02:00:05
	 * is missed by the 02:00:03 run and lands at 03:00, and that hour is carried
	 * into every following cycle until a whole billing period is skipped. Anchoring
	 * to the stored due date keeps the billing time-of-day stable instead.
	 *
	 * When payment arrives more than one period late (manual renewal, cron outage),
	 * the anchor is stepped forward period by period so the returned date is always
	 * in the future — otherwise the subscription would be due again immediately.
	 *
	 * Because this runs on every activating status transition of the same renewal
	 * order (pending → processing → completed), the order that last moved the date
	 * is recorded so repeat transitions do not advance the cycle again.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $recurr_timing   Recurring timing string (e.g. "1 month").
	 * @param int    $renewal_order_id Renewal order driving this activation.
	 * @param string $history_type     Subscription history type supplied to the compatibility filter.
	 *
	 * @return int|false Next payment timestamp, or false when persistence is unavailable.
	 */
	private function get_anchored_next_date( $subscription_id, $recurr_timing, $renewal_order_id = 0, $history_type = 'renew' ) {
		global $wpdb;
		$lock_name = 'ashbi_subscrpt_schedule_' . (int) $subscription_id;
		$locked    = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( ! $locked ) {
			subscrpt_write_log( "Could not acquire schedule lock for subscription #{$subscription_id}; due date was not advanced." );
			$this->schedule_renewal_retry( (int) $subscription_id, (int) $renewal_order_id );
			return false;
		}

		try {
			$now            = time();
			$claimed_anchor = RenewalClaim::period_anchor_for_order( (int) $subscription_id, (int) $renewal_order_id );
			$anchor         = $claimed_anchor > 0 ? $claimed_anchor : (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
			if ( $anchor <= 0 ) {
				$anchor = $now;
			}

			// This renewal order already advanced the date; keep it where it is.
			$dated_by = (int) get_post_meta( $subscription_id, '_subscrpt_next_date_set_by_order', true );
			if ( $renewal_order_id && $renewal_order_id === $dated_by ) {
				return (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
			}

			$next_date = sdevs_wp_strtotime( $recurr_timing, $anchor );
			$guard     = 0;
			while ( $next_date <= $now && $guard < 1000 ) {
				$stepped = sdevs_wp_strtotime( $recurr_timing, $next_date );
				if ( $stepped <= $next_date ) {
					break;
				}
				$next_date = $stepped;
				++$guard;
			}

			if ( $next_date <= $now ) {
				$next_date = sdevs_wp_strtotime( $recurr_timing, $now );
			}

			$next_date = apply_filters( 'subscrpt_subscription_next_date', $next_date, $subscription_id, $recurr_timing, $history_type );

			// Save the date before its marker while holding the same database lock.
			// Concurrent callbacks then either compute the same value or observe both.
			update_post_meta( $subscription_id, '_subscrpt_next_date', $next_date );
			if ( (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true ) !== (int) $next_date ) {
				subscrpt_write_log( "Could not persist the next due date for subscription #{$subscription_id}." );
				$this->schedule_renewal_retry( (int) $subscription_id, (int) $renewal_order_id );
				return false;
			}
			if ( $renewal_order_id ) {
				update_post_meta( $subscription_id, '_subscrpt_next_date_set_by_order', $renewal_order_id );
				if ( (int) get_post_meta( $subscription_id, '_subscrpt_next_date_set_by_order', true ) !== (int) $renewal_order_id ) {
					subscrpt_write_log( "Could not persist the due-date marker for subscription #{$subscription_id}." );
					$this->schedule_renewal_retry( (int) $subscription_id, (int) $renewal_order_id );
					return false;
				}
			}

			return $next_date;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Queue one bounded retry when an activated renewal cannot persist its schedule.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $renewal_order_id Renewal order ID.
	 * @return void
	 */
	private function schedule_renewal_retry( int $subscription_id, int $renewal_order_id ): void {
		if ( $renewal_order_id <= 0 ) {
			return;
		}

		$args      = array( $subscription_id, $renewal_order_id );
		$run_at    = RenewalClaim::schedule_next_attempt( $subscription_id, $renewal_order_id );
		$run_at    = max( time() + 1, ! empty( $run_at ) ? $run_at : time() + 60 );
		$scheduled = false;
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			$scheduled = (bool) as_has_scheduled_action( 'subscrpt_retry_subscription_schedule', $args, 'ashbi-subscriptions' );
			if ( ! $scheduled ) {
				$scheduled = 0 < (int) as_schedule_single_action( $run_at, 'subscrpt_retry_subscription_schedule', $args, 'ashbi-subscriptions' );
			}
		} elseif ( wp_next_scheduled( 'subscrpt_retry_subscription_schedule', $args ) ) {
			$scheduled = true;
		} else {
			$result    = wp_schedule_single_event( $run_at, 'subscrpt_retry_subscription_schedule', $args, true );
			$scheduled = true === $result;
		}

		if ( ! $scheduled ) {
			subscrpt_write_log( "CRITICAL: Could not queue schedule repair for subscription #{$subscription_id}, renewal order #{$renewal_order_id}. Subscription activation remains blocked." );
		}
	}

	/**
	 * Retry the schedule half of a successfully activated canonical renewal.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $renewal_order_id Renewal order ID.
	 * @return void
	 */
	public function retry_renewal_schedule( $subscription_id, $renewal_order_id ) {
		$subscription_id  = (int) $subscription_id;
		$renewal_order_id = (int) $renewal_order_id;
		$next_attempt     = RenewalClaim::schedule_next_attempt( $subscription_id, $renewal_order_id );
		if ( $next_attempt > time() ) {
			$this->schedule_renewal_retry( $subscription_id, $renewal_order_id );
			return;
		}

		$order = wc_get_order( $renewal_order_id );
		if ( ! $order || ! $order->is_paid() || ! RenewalClaim::is_claimed_order( $subscription_id, $renewal_order_id ) ) {
			RenewalClaim::mark_schedule_pending( $subscription_id, $renewal_order_id, 'Canonical paid order was temporarily unavailable.' );
			$this->schedule_renewal_retry( $subscription_id, $renewal_order_id );
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'subscrpt_order_relation';
		$relation   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT order_item_id FROM %i WHERE subscription_id = %d AND order_id = %d AND type = 'renew' LIMIT 1",
				array( $table_name, $subscription_id, $renewal_order_id )
			)
		);
		if ( ! $relation ) {
			RenewalClaim::mark_schedule_pending( $subscription_id, $renewal_order_id, 'Canonical renewal relation was temporarily unavailable.' );
			$this->schedule_renewal_retry( $subscription_id, $renewal_order_id );
			return;
		}

		$this->order_status_changed( $renewal_order_id );
	}

	/**
	 * Safety sweep for durable repairs whose one-shot queue event was lost.
	 *
	 * @return void
	 */
	public function repair_pending_schedules() {
		foreach ( RenewalClaim::due_schedule_repairs( 100 ) as $repair ) {
			$this->retry_renewal_schedule( (int) $repair->subscription_id, (int) $repair->order_id );
		}
	}

	/**
	 * Add custom column on order item.
	 *
	 * @return void
	 */
	public function register_custom_column() {
		?>
		<th class="item_recurring sortable" data-sort="float"><?php esc_html_e( 'Recurring', 'subscription' ); ?></th>
		<?php
	}

	/**
	 * Display data for custom column.
	 *
	 * @param \WC_Product    $product Product Object.
	 * @param \WC_Order_Item $item Order Item.
	 *
	 * @return void
	 */
	public function add_column_value( $product, $item ) {
		if ( ! method_exists( $item, 'get_id' ) || ! method_exists( $item, 'get_subtotal' ) ) {
			return;
		}

		$subtotal        = '-';
		$subscription_id = Helper::get_subscription_from_order_item_id( $item->get_id() );

		if ( ! $subscription_id ) {
			echo "<td class='item_recurring' width='15%'>-</td>";
			return;
		}
		$subscription_id = $subscription_id->subscription_id;

		// Strikes the original amount when a discount carries into renewals.
		$subtotal = Helper::get_subscription_recurring_price_html( $subscription_id, $item );
		?>
		<td class="item_recurring" width="15%">
			<div class="view">
				<?php echo wp_kses_post( $subtotal ); ?>
			</div>
		</td>
		<?php
	}

	/**
	 * Render subscription metadata below an admin order item.
	 *
	 * @param int        $item_id Order item ID.
	 * @param object     $item Order item object.
	 * @param \WC_Product $product Product object.
	 * @return bool|null
	 */
	public function add_order_item_data( $item_id, $item, $product ) {
		if ( ! $product ) {
			return;
		}

		$item_meta = wc_get_order_item_meta( $item_id, '_subscrpt_meta', true );

		if ( ! $item_meta || ! is_array( $item_meta ) ) {
			return false;
		}

		$trial     = $item_meta['trial'];
		$has_trial = isset( $item_meta['trial'] ) && strlen( $item_meta['trial'] ) > 2;

		if ( $has_trial ) {
			echo '<br/><small> + Got ' . esc_html( $trial ) . ' free trial!</small>';
		}
	}

	/**
	 * Take some actions based on order status changed.
	 *
	 * @param int    $order_id    Order Id.
	 * @param string $from_status Previous order status.
	 */
	public function order_status_changed( $order_id, $from_status = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_subscrpt_renewal_quarantined' ) ) {
			return;
		}
		$post_status = 'active';

		switch ( $order->get_status() ) {
			case 'on-hold':
			case 'pending':
				$post_status = 'pending';
				break;

			case 'refunded':
			case 'failed':
			case 'cancelled':
				$post_status = 'cancelled';
				break;

			default:
				$post_status = 'active';
				break;
		}
		$post_status = apply_filters( 'subscript_order_status_to_post_status', $post_status, $order );

		global $wpdb;
		$table_name = $wpdb->prefix . 'subscrpt_order_relation';
		// @phpcs:ignore
		$histories = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', array( $table_name, $order_id ) ) );

		foreach ( $histories as $history ) {
			// Early renewal is a prepaid order for the next period. A failed or.
			// cancelled early checkout must not cancel an otherwise active.
			// subscription; only a verified payment advances its schedule.
			if ( 'early-renew' === $history->type ) {
				if ( $order->is_paid() ) {
					$this->process_early_renewal( $order, $history );
				}
				continue;
			}
			if ( 'new' === $history->type || 'renew' === $history->type ) {
				$subscription_id      = $history->subscription_id;
				$renewal_meta_changed = false;
				if (
					'renew' === $history->type
					&& in_array( (string) $order->get_status(), array( 'failed', 'on-hold' ), true )
					&& ! $order->get_meta( '_subscrpt_renewal_payment_failed' )
				) {
					$order->update_meta_data( '_subscrpt_renewal_payment_failed', 1 );
					$renewal_meta_changed = true;
				}
				// Attribute the first subsequent paid renewal to a previously.
				// accepted retention campaign. The event key makes repeated order.
				// callbacks and webhook replays harmless.
				if (
					$order->is_paid()
					&& Cancellation::has_recovery_event( (int) $subscription_id, 'offer_accepted' )
					&& ! Cancellation::has_recovery_event( (int) $subscription_id, 'win_back' )
				) {
					Cancellation::record_recovery_event(
						(int) $subscription_id,
						'win_back',
						Cancellation::RECOVERY_CAMPAIGN,
						'',
						(int) $order->get_id(),
						(int) $order->get_customer_id()
					);
				}
				// A renewal with a recorded failed/on-hold state that later becomes.
				// paid is a recovery, not an ordinary renewal. Store the fact on the.
				// Woo order so the report remains HPOS-safe and can count it without.
				// customer data. The durable failure marker covers failed -> pending.
				// -> paid sequences where the final transition's source is pending.
				if (
					'renew' === $history->type
					&& $order->is_paid()
					&& $order->get_meta( '_subscrpt_renewal_payment_failed' )
					&& ! $order->get_meta( '_subscrpt_renewal_recovered' )
				) {
					$order->update_meta_data( '_subscrpt_renewal_recovered', 1 );
					$renewal_meta_changed = true;
				}
				if ( $renewal_meta_changed ) {
					$order->save();
				}
				if ( 'renew' === $history->type && $order->is_paid() ) {
					RenewalClaim::mark_payment_complete( (int) $subscription_id, (int) $order_id );
				}

				// Capture the status before the max-payments check below may flip it.
				$current_status = get_post_status( $subscription_id );

				// Split payment: complete instead of re-activate after the final installment.
				$target_status = $post_status;
				if (
					'active' === $target_status
					&& function_exists( 'subscrpt_is_max_payments_reached' )
					&& subscrpt_is_max_payments_reached( $subscription_id )
				) {
					$target_status = 'completed';
				}

				// Retry only the missing schedule side effect on a repeated paid-renewal.
				// callback; notes, counts, roles, and other activation effects stay idempotent.
				if ( $current_status === $target_status ) {
					if ( 'renew' === $history->type && in_array( $target_status, array( 'active', 'completed' ), true ) ) {
						if (
							'active' === $target_status
							&& ! $this->persist_renewal_schedule( (int) $subscription_id, (int) $order_id, (int) $history->order_item_id, (string) $history->type )
						) {
							continue;
						}
						if (
							'completed' === $target_status
							&& ! RenewalClaim::mark_schedule_complete( (int) $subscription_id, (int) $order_id )
						) {
							continue;
						}
						if ( 'completed' === $target_status ) {
							if ( ! subscrpt_finalize_split_payment_completion( (int) $subscription_id ) ) {
								$this->defer_renewal_activation( (int) $subscription_id, (int) $order_id, 'Final-installment completion needs reconciliation.' );
								continue;
							}
						}
						$this->complete_renewal_activation( $order, $history, $post_status, $target_status );
					}
					continue;
				}

				// A paid renewal is not allowed to activate until its exact relation has.
				// durably advanced the billing schedule.
				if (
					'renew' === $history->type
					&& 'active' === $target_status
					&& ! $this->persist_renewal_schedule( (int) $subscription_id, (int) $order_id, (int) $history->order_item_id, (string) $history->type )
				) {
					continue;
				}
				if (
					'renew' === $history->type
					&& 'completed' === $target_status
					&& ! RenewalClaim::mark_schedule_complete( (int) $subscription_id, (int) $order_id )
				) {
					continue;
				}

				wp_update_post(
					array(
						'ID'          => $subscription_id,
						'post_status' => $target_status,
					)
				);
				if ( get_post_status( $subscription_id ) !== $target_status ) {
					if ( 'renew' === $history->type && in_array( $target_status, array( 'active', 'completed' ), true ) ) {
						$this->schedule_renewal_retry( (int) $subscription_id, (int) $order_id );
					}
					continue;
				}

				if ( 'renew' === $history->type && in_array( $target_status, array( 'active', 'completed' ), true ) ) {
					if ( 'completed' === $target_status ) {
						if ( ! subscrpt_finalize_split_payment_completion( (int) $subscription_id ) ) {
							$this->defer_renewal_activation( (int) $subscription_id, (int) $order_id, 'Final-installment completion needs reconciliation.' );
							continue;
						}
					}
					$this->complete_renewal_activation( $order, $history, $post_status, $target_status );
					continue;
				}

				// If possible change order status to completed if it has a trial subscription.
				$this->maybe_trigger_auto_complete_trial_order( $order_id, $subscription_id );

				// Add enhanced split payment activity logging.
				$this->add_split_payment_activity_note( $history->subscription_id, $history->type, $post_status, $order );

				Action::write_comment( $target_status, $history->subscription_id );
			} else {
				do_action( 'subscrpt_order_status_changed', $order, $history );
			}
		}
	}

	/**
	 * Apply a paid early-renewal order and consume the stored next period.
	 *
	 * The subscription remains active while the order is pending. A database
	 * lock plus order-scoped markers makes payment-complete/status callbacks
	 * replay-safe without treating a failed early checkout as a cancellation.
	 *
	 * @param \WC_Order $order   Paid early-renewal order.
	 * @param object    $history Relation row.
	 * @return bool
	 */
	private function process_early_renewal( \WC_Order $order, $history ): bool {
		$subscription_id = (int) ( $history->subscription_id ?? 0 );
		if ( ! $subscription_id || 'active' !== get_post_status( $subscription_id ) ) {
			subscrpt_write_log( "Paid early renewal #{$order->get_id()} ignored because subscription #{$subscription_id} is not active." );
			return false;
		}

		global $wpdb;
		$lock_name = 'ashbi_subscrpt_early_apply_' . $subscription_id . '_' . (int) $order->get_id();
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ) ) {
			subscrpt_write_log( "Could not acquire early renewal application lock for subscription #{$subscription_id}." );
			return false;
		}

		try {
			$fresh_order = wc_get_order( $order->get_id() );
			if ( ! $fresh_order || ! $fresh_order->is_paid() ) {
				return false;
			}
			if ( in_array( $fresh_order->get_meta( '_subscrpt_early_renewal_applied' ), array( true, 1, '1' ), true ) ) {
				return true;
			}

			$order_item = $fresh_order->get_item( (int) ( $history->order_item_id ?? 0 ) );
			if ( ! $order_item instanceof \WC_Order_Item_Product ) {
				return false;
			}
			$item_meta = $order_item->get_meta( '_subscrpt_meta', true );
		$item_meta = is_array( $item_meta ) ? $item_meta : array();
		$time_meta = $item_meta['time'] ?? get_post_meta( $subscription_id, '_subscrpt_timing_per', true );
		$unit_meta = $item_meta['type'] ?? get_post_meta( $subscription_id, '_subscrpt_timing_option', true );
		$time      = max( 1, (int) ( ! empty( $time_meta ) ? $time_meta : 1 ) );
		$unit      = (string) ( ! empty( $unit_meta ) ? $unit_meta : 'months' );
		$timing    = $time . ' ' . Helper::get_typos( $time, $unit );
		$anchor    = (int) $fresh_order->get_meta( '_subscrpt_early_renewal_anchor', true );
		if ( ! $anchor ) {
			$anchor = (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
		}
			if ( $anchor <= 0 ) {
				return false;
			}

			$next_date = sdevs_wp_strtotime( $timing, $anchor );
			$guard     = 0;
			while ( false !== $next_date && $next_date <= time() && $guard < 1000 ) {
				$stepped = sdevs_wp_strtotime( $timing, $next_date );
				if ( $stepped <= $next_date ) {
					break;
				}
				$next_date = $stepped;
				++$guard;
			}
			if ( false === $next_date ) {
				return false;
			}
			$filtered_next_date = apply_filters( 'subscrpt_subscription_next_date', $next_date, $subscription_id, $timing, 'early-renew' );
			if ( ! is_numeric( $filtered_next_date ) || (int) $filtered_next_date <= 0 ) {
				return false;
			}
			$next_date = (int) $filtered_next_date;
			update_post_meta( $subscription_id, '_subscrpt_next_date', $next_date );
			update_post_meta( $subscription_id, '_subscrpt_next_date_set_by_order', $order->get_id() );
			if (
				(int) get_post_meta( $subscription_id, '_subscrpt_next_date', true ) !== $next_date
				|| (int) get_post_meta( $subscription_id, '_subscrpt_next_date_set_by_order', true ) !== (int) $order->get_id()
			) {
				return false;
			}

			// A paid early renewal consumes the trial state, if a legacy record still.
			// carried one, and makes the paid order the new canonical source.
			foreach ( array( '_subscrpt_trial', '_subscrpt_trial_mode', '_subscrpt_trial_started', '_subscrpt_trial_ended' ) as $trial_key ) {
				delete_post_meta( $subscription_id, $trial_key );
			}
			update_post_meta( $subscription_id, '_subscrpt_order_id', $order->get_id() );
			update_post_meta( $subscription_id, '_subscrpt_order_item_id', $order_item->get_id() );

			if ( ! $fresh_order->get_meta( '_subscrpt_early_renewal_activity_done' ) ) {
				$existing_activity = get_comments(
					array(
						'post_id'    => $subscription_id,
						'type'       => 'order_note',
						'number'     => 1,
						'meta_key'   => '_subscrpt_early_renewal_order_id',
						'meta_value' => $order->get_id(),
					)
				);
				if ( empty( $existing_activity ) ) {
					$comment_id = wp_insert_comment(
						array(
							'comment_author'  => 'Ashbi Subscriptions',
							// translators: %d: early-renewal order ID.
							'comment_content' => sprintf( __( 'Early renewal payment received. Order #%d is now the active subscription source.', 'subscription' ), $order->get_id() ),
							'comment_post_ID' => $subscription_id,
							'comment_type'    => 'order_note',
						)
					);
					if ( ! $comment_id ) {
						return false;
					}
					update_comment_meta( $comment_id, '_subscrpt_early_renewal_order_id', $order->get_id() );
					update_comment_meta( $comment_id, '_subscrpt_activity', 'Early Renewal Payment' );
					update_comment_meta( $comment_id, '_subscrpt_activity_type', 'early_renewal_payment' );
				}
				$fresh_order->update_meta_data( '_subscrpt_early_renewal_activity_done', 1 );
			}

			$fresh_order->update_meta_data( '_subscrpt_early_renewal_applied', 1 );
			$fresh_order->update_meta_data( '_subscrpt_early_renewal_applied_at', time() );
			if ( ! $fresh_order->save() ) {
				return false;
			}
			if ( ! $fresh_order->get_meta( '_subscrpt_early_renewal_applied' ) ) {
				return false;
			}
			delete_post_meta( $subscription_id, '_subscrpt_early_renewal_order_id' );
			delete_post_meta( $subscription_id, '_subscrpt_early_renewal_anchor' );
			do_action( 'subscrpt_early_renewal_applied', $subscription_id, $order->get_id(), $order_item->get_id() );
			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Finish replay-safe activation effects, then close the durable claim phase.
	 *
	 * Payment-limit enforcement is relation-derived by
	 * subscrpt_is_max_payments_reached(). Compatibility effects are at-least-once:
	 * their completion marker is written only after each effect returns.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param object    $history Exact renewal relation row.
	 * @param string    $post_status Mapped active status before limit completion.
	 * @param string    $target_status Final subscription status.
	 * @return void
	 */
	private function complete_renewal_activation( $order, $history, string $post_status, string $target_status ): void {
		$subscription_id = (int) $history->subscription_id;
		$order_id        = (int) $order->get_id();

		if ( ! $order->get_meta( '_subscrpt_renewal_trial_effect_done' ) ) {
			$trial_result = $this->maybe_trigger_auto_complete_trial_order( $order_id, $subscription_id );
			if ( true !== $trial_result ) {
				$reason = null === $trial_result ? 'Trial-order completion is queued and awaiting verification.' : 'Trial-order completion could not be queued.';
				$this->defer_renewal_activation( $subscription_id, $order_id, $reason );
				return;
			}
			if ( ! $this->persist_renewal_effect_marker( $order, '_subscrpt_renewal_trial_effect_done' ) ) {
				$this->defer_renewal_activation( $subscription_id, $order_id, 'Trial-order completion marker could not be saved.' );
				return;
			}
		}

		if ( ! $order->get_meta( '_subscrpt_renewal_activity_note_done' ) ) {
			if ( ! $this->add_split_payment_activity_note( $subscription_id, (string) $history->type, $post_status, $order ) ) {
				$this->defer_renewal_activation( $subscription_id, $order_id, 'Split-payment activity note could not be saved.' );
				return;
			}
			if ( ! $this->persist_renewal_effect_marker( $order, '_subscrpt_renewal_activity_note_done' ) ) {
				$this->defer_renewal_activation( $subscription_id, $order_id, 'Activity-note completion marker could not be saved.' );
				return;
			}
		}

		if ( ! $order->get_meta( '_subscrpt_renewal_comment_done' ) ) {
			if ( ! Action::write_comment( $target_status, $subscription_id ) ) {
				$this->defer_renewal_activation( $subscription_id, $order_id, 'Subscription lifecycle comment could not be saved.' );
				return;
			}
			if ( ! $this->persist_renewal_effect_marker( $order, '_subscrpt_renewal_comment_done' ) ) {
				$this->defer_renewal_activation( $subscription_id, $order_id, 'Lifecycle-comment completion marker could not be saved.' );
				return;
			}
		}

		if ( ! $this->persist_renewal_effect_marker( $order, '_subscrpt_renewal_effects_complete' ) ) {
			$this->defer_renewal_activation( $subscription_id, $order_id, 'Activation completion marker could not be saved.' );
			return;
		}
		if ( ! RenewalClaim::mark_renewal_complete( $subscription_id, $order_id ) ) {
			$this->defer_renewal_activation( $subscription_id, $order_id, 'Activation side effects need reconciliation.' );
		}
	}

	/**
	 * Persist and verify one order-scoped activation-effect marker.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param string    $key Order meta key.
	 * @return bool
	 */
	private function persist_renewal_effect_marker( $order, string $key ): bool {
		$order->update_meta_data( $key, 1 );
		if ( ! $order->save() ) {
			return false;
		}

		$fresh_order = wc_get_order( $order->get_id() );
		return $fresh_order && (bool) $fresh_order->get_meta( $key );
	}

	/**
	 * Keep the durable schedule phase open when an activation effect is incomplete.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param int    $order_id Renewal order ID.
	 * @param string $reason Safe operational reason.
	 * @return void
	 */
	protected function defer_renewal_activation( int $subscription_id, int $order_id, string $reason ): void {
		subscrpt_write_log( "Could not complete renewal activation for subscription #{$subscription_id}, order #{$order_id}: {$reason}" );
		RenewalClaim::mark_schedule_pending( $subscription_id, $order_id, $reason );
		$this->schedule_renewal_retry( $subscription_id, $order_id );
	}

	/**
	 * Delete the subscription.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return void
	 */
	public function delete_the_subscription( $order_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'subscrpt_order_relation';

		$histories = Helper::get_subscriptions_from_order( $order_id );
		foreach ( (array) $histories as $history ) {
			$subscription_order_id = get_post_meta( $history->subscription_id, '_subscrpt_order_id', true );
			if ( (int) $subscription_order_id === $order_id ) {
				wp_delete_post( $history->subscription_id, true );
			}
		}

		// phpcs:ignore
		$wpdb->delete( $table_name, array( 'order_id' => $order_id ), array( '%d' ) );
	}

	/**
	 * Add enhanced split payment activity note with payment progress and access information.
	 *
	 * @param int       $subscription_id Subscription ID.
	 * @param string    $history_type    History type (new, renew, etc.).
	 * @param string    $post_status     Post status.
	 * @param \WC_Order $order           WooCommerce order object.
	 */
	private function add_split_payment_activity_note( $subscription_id, $history_type, $post_status, $order ) {
		// Only add enhanced notes for active subscriptions.
		if ( 'active' !== $post_status ) {
			return true;
		}

		// Check if this is a split payment subscription.
		if ( ! function_exists( 'subscrpt_get_payment_type' ) ) {
			return true;
		}

		$payment_type = subscrpt_get_payment_type( $subscription_id );
		if ( 'split_payment' !== $payment_type ) {
			return true;
		}

		// Get payment progress information.
		$max_payments       = function_exists( 'subscrpt_get_max_payments' ) ? subscrpt_get_max_payments( $subscription_id ) : 0;
		$payments_made      = function_exists( 'subscrpt_count_payments_made' ) ? subscrpt_count_payments_made( $subscription_id ) : 0;
		$remaining_payments = function_exists( 'subscrpt_get_remaining_payments' ) ? subscrpt_get_remaining_payments( $subscription_id ) : 0;

		// Determine payment number for this order.
		$payment_number = $payments_made;
		$order_total    = $order->get_total();
		$order_currency = $order->get_currency();

		// Create enhanced activity note.
		$comment_content = '';
		$activity_type   = '';

		if ( 'new' === $history_type ) {
			$comment_content = sprintf(
				/* translators: %1$d: payment number, %2$d: total payments, %3$s: amount, %4$s: currency */
				__( 'Split payment %1$d of %2$d received (%3$s %4$s). Initial access granted.', 'subscription' ),
				$payment_number,
				$max_payments,
				$order_total,
				$order_currency
			);
			$activity_type = __( 'Split Payment - Initial', 'subscription' );
		} elseif ( 'renew' === $history_type ) {
			$comment_content = sprintf(
				/* translators: %1$d: payment number, %2$d: total payments, %3$s: amount, %4$s: currency, %5$d: remaining */
				__( 'Split payment %1$d of %2$d received (%3$s %4$s). %5$d payments remaining.', 'subscription' ),
				$payment_number,
				$max_payments,
				$order_total,
				$order_currency,
				$remaining_payments
			);
			$activity_type = __( 'Split Payment - Installment', 'subscription' );
		}

		// Add the enhanced activity note.
		if ( $comment_content ) {
			$comment_id = wp_insert_comment(
				array(
					'comment_author'  => 'Ashbi Subscriptions',
					'comment_content' => $comment_content,
					'comment_post_ID' => $subscription_id,
					'comment_type'    => 'order_note',
				)
			);
			if ( ! $comment_id ) {
				return false;
			}
			if ( false === update_comment_meta( $comment_id, '_subscrpt_activity', $activity_type ) ) {
				return false;
			}
			if ( false === update_comment_meta( $comment_id, '_subscrpt_activity_type', 'split_payment' ) ) {
				return false;
			}

			// Add order note with split payment context.
			$order_note = sprintf(
				/* translators: %1$d: payment number, %2$d: total payments, %3$d: subscription id */
				__( 'Split payment %1$d of %2$d received for subscription #%3$d', 'subscription' ),
				$payment_number,
				$max_payments,
				$subscription_id
			);
			return (bool) $order->add_order_note( $order_note );
		}

		return true;
	}

	/**
	 * Maybe complete the order if it has a trial subscription and is still in processing status.
	 *
	 * @param int $order_id Order ID.
	 * @param int $subscription_id Subscription ID.
	 * @return bool|null True when complete/not applicable, null when queued, false on queue failure.
	 */
	public function maybe_trigger_auto_complete_trial_order( $order_id, $subscription_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		$order_items = $order->get_items();

		// Only attempt to complete if order is still in processing status.
		if ( 'processing' !== $order->get_status() ) {
			return true;
		}

		$is_subs_trial_order = false;
		foreach ( $order_items as $order_item ) {
			$subscrpt_meta = $order_item->get_meta( '_subscrpt_meta', true );

			if ( ! empty( $subscrpt_meta ) && isset( $subscrpt_meta['trial'] ) ) {
				$is_subs_trial_order = true;
			}
		}

		if ( $is_subs_trial_order ) {
			$args = array( 'order_id' => $order_id );
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'subscrpt_queue_trial_order_autocomplete', $args, 'ashbi-subscriptions' ) ) {
				return null;
			}
			$queued = function_exists( 'as_enqueue_async_action' )
				? 0 < (int) as_enqueue_async_action( 'subscrpt_queue_trial_order_autocomplete', $args, 'ashbi-subscriptions' )
				: true === wp_schedule_single_event( time() + 1, 'subscrpt_queue_trial_order_autocomplete', $args, true );
			if ( ! $queued ) {
				return false;
			}

			$log_message = "Queued auto complete task for free trial order [ID: {$order_id}]";
			subscrpt_write_log( $log_message );
			subscrpt_write_debug_log( $log_message );
			return null;
		}

		return true;
	}

	/**
	 * Autocomplete subscription trial order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function auto_complete_subscription_trial_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_status( 'completed', __( 'Subscription order with trial.', 'subscription' ) );
		$fresh_order = wc_get_order( $order_id );
		if ( $fresh_order && $fresh_order->has_status( 'completed' ) ) {
			return;
		}

		foreach ( $this->renewal_histories_for_order( (int) $order_id ) as $history ) {
			if ( 'renew' === ( $history->type ?? '' ) && $this->is_claimed_renewal_order( (int) $history->subscription_id, (int) $order_id ) ) {
				$this->defer_renewal_activation( (int) $history->subscription_id, (int) $order_id, 'Queued trial-order completion did not persist.' );
			}
		}
	}

	/**
	 * Load renewal relations for trial-worker reconciliation.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	protected function renewal_histories_for_order( int $order_id ): array {
		return (array) Helper::get_subscriptions_from_order( $order_id );
	}

	/**
	 * Check whether the renewal relation owns the canonical period claim.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $order_id WooCommerce order ID.
	 * @return bool
	 */
	protected function is_claimed_renewal_order( int $subscription_id, int $order_id ): bool {
		return RenewalClaim::is_claimed_order( $subscription_id, $order_id );
	}
}

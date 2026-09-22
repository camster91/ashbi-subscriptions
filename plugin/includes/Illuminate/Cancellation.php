<?php
/**
 * Subscription cancellation handler.
 *
 * Owns the conversion of a pending-cancellation (`pe_cancelled`) subscription
 * into a fully `cancelled` one. This is intentionally kept separate from the
 * hourly *expiry* check in {@see Cron} so cancellation-related behaviour can grow
 * here independently.
 *
 * Default flow: when a subscription enters `pe_cancelled` it is scheduled
 * to be cancelled 24 hours later. The exact moment is filterable via
 * `subscrpt_cancellation_time`, with the selected Ashbi timing mode applied by
 * the built-in filter.
 *
 * @package SpringDevs\Subscription\Illuminate
 */

// Legacy class path is part of the public compatibility contract.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Illuminate;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Cancellation
 *
 * @package SpringDevs\Subscription\Illuminate
 */
class Cancellation {

	/**
	 * Post meta storing the timestamp at which a pending cancellation becomes final.
	 *
	 * @var string
	 */
	const CANCEL_AT_META = '_subscrpt_cancel_at';

	/**
	 * Key of the "Other" reason, which the survey adds by itself.
	 *
	 * Reserved: it is never one of the store's own reasons. See get_reasons().
	 *
	 * @var string
	 */
	const OTHER_KEY = 'other';

	/**
	 * Subscription meta storing the idempotent retention coupon code.
	 *
	 * @var string
	 */
	const OFFER_CODE_META = '_subscrpt_cancellation_offer_code';

	/**
	 * Built-in campaign key for the cancellation retention offer.
	 *
	 * @var string
	 */
	const RECOVERY_CAMPAIGN = 'cancellation-retention';

	/**
	 * Initialize the class.
	 */
	public function __construct() {
		add_action( 'subscrpt_subscription_pending_cancellation', array( $this, 'schedule_cancellation' ) );
		add_action( 'subscrpt_hourly_cron', array( $this, 'process_due_cancellations' ) );
		add_action( 'subscrpt_subscription_resumed', array( $this, 'clear_scheduled_cancellation' ) );
		add_action( 'before_single_subscrpt_content', array( $this, 'display_pending_cancellation_notice' ) );
		add_action( 'before_single_subscrpt_content', array( $this, 'maybe_render_feedback_modal' ) );
		add_action( 'wp_ajax_subscrpt_record_cancellation_feedback', array( $this, 'record_feedback' ) );
		add_action( 'wp_ajax_subscrpt_record_cancellation_save', array( $this, 'record_save' ) );
		add_action( 'wp_ajax_subscrpt_claim_cancellation_offer', array( $this, 'claim_offer' ) );
		add_action( 'subscrpt_details_side_bottom', array( $this, 'render_admin_feedback_card' ) );
		add_action( 'subscrpt_hourly_cron', array( $this, 'purge_recovery_events' ), 30 );
		add_filter( 'subscrpt_cancellation_time', array( $this, 'resolve_cancellation_time' ), 10, 2 );
		add_filter( 'subscrpt_cancellation_offer', array( $this, 'create_retention_offer' ), 10, 3 );
	}

	/**
	 * Fetch the most recent cancellation-feedback row for a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array|null Associative row, or null when none exists.
	 */
	public static function get_feedback( $subscription_id ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT reason_label, comment, created_at FROM {$wpdb->prefix}subscrpt_cancellation_feedback WHERE subscription_id = %d ORDER BY id DESC LIMIT 1",
				(int) $subscription_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Record an idempotent local recovery-campaign event.
	 *
	 * The event key is a digest rather than a concatenation of identifiers, so
	 * logs and unique indexes never expose customer data. Duplicate callbacks
	 * are successful no-ops and do not inflate campaign totals.
	 *
	 * @param int    $subscription_id Subscription post ID.
	 * @param string $event_type      Event name: offer_issued, offer_accepted, save, win_back.
	 * @param string $campaign_key    Campaign identifier.
	 * @param string $offer_code      Optional coupon code.
	 * @param int    $order_id        Optional attributed WooCommerce order ID.
	 * @param int    $customer_id     Optional internal customer ID.
	 * @return bool
	 */
	public static function record_recovery_event( int $subscription_id, string $event_type, string $campaign_key = self::RECOVERY_CAMPAIGN, string $offer_code = '', int $order_id = 0, int $customer_id = 0 ): bool {
		global $wpdb;

		$event_type   = sanitize_key( $event_type );
		$campaign_key = sanitize_key( $campaign_key );
		$offer_code   = sanitize_text_field( $offer_code );
		$key_parts    = array( $subscription_id, $event_type, $campaign_key, $offer_code, $order_id );
		if ( 'save' === $event_type ) {
			// record_save is throttled once per day; keep one event per day while.
			// still absorbing duplicate requests for that same day.
			$key_parts[] = gmdate( 'Y-m-d' );
		}
		$event_key = hash( 'sha256', implode( '|', $key_parts ) );
		$table     = $wpdb->prefix . 'subscrpt_recovery_event';
		$inserted  = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (event_key, subscription_id, customer_id, order_id, campaign_key, event_type, offer_code, created_at)
				 VALUES (%s, %d, %d, %d, %s, %s, %s, %s)',
				array(
					$table,
					$event_key,
					$subscription_id,
					$customer_id,
					$order_id,
					$campaign_key,
					$event_type,
					$offer_code,
					current_time( 'mysql', true ),
				)
			)
		);

		return false !== $inserted;
	}

	/**
	 * Check whether an idempotent recovery event exists for a subscription.
	 *
	 * @param int    $subscription_id Subscription post ID.
	 * @param string $event_type      Event name.
	 * @return bool
	 */
	public static function has_recovery_event( int $subscription_id, string $event_type ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'subscrpt_recovery_event';
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM %i WHERE subscription_id = %d AND event_type = %s',
				array( $table, $subscription_id, sanitize_key( $event_type ) )
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Remove recovery events outside the configured privacy retention window.
	 *
	 * The hourly hook is guarded to run this maintenance at most once per day.
	 * Only the plugin-owned event ledger is touched; subscription and order data
	 * remain the canonical business records.
	 *
	 * @return void
	 */
	public function purge_recovery_events(): void {
		$last_run = (int) get_option( 'subscrpt_recovery_event_last_purge', 0 );
		if ( $last_run > time() - DAY_IN_SECONDS ) {
			return;
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'subscrpt_recovery_event';
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( \SpringDevs\Subscription\Admin\CancellationFlow::recovery_event_retention_days() * DAY_IN_SECONDS ) );
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				array( $table, $cutoff )
			)
		);

		if ( false === $deleted ) {
			subscrpt_write_log( 'Recovery-event retention cleanup could not complete; it will retry.' );
			return;
		}

		update_option( 'subscrpt_recovery_event_last_purge', time(), false );
	}

	/**
	 * Show the customer's cancellation reason on the admin subscription details page
	 * (bottom of the right-hand column).
	 *
	 * Renders only for cancelled / pending-cancellation subscriptions that have a
	 * recorded feedback row.
	 *
	 * @param array $ctx Subscription details context.
	 * @return void
	 */
	public function render_admin_feedback_card( $ctx ) {
		$subscription_id = (int) ( $ctx['subscription_id'] ?? 0 );
		$status          = $ctx['status'] ?? '';

		if ( ! in_array( $status, array( 'cancelled', 'pe_cancelled' ), true ) ) {
			return;
		}

		$feedback = self::get_feedback( $subscription_id );
		if ( ! $feedback ) {
			return;
		}

		$reason  = (string) ( $feedback['reason_label'] ?? '' );
		$comment = (string) ( $feedback['comment'] ?? '' );
		$when    = '';
		if ( ! empty( $feedback['created_at'] ) ) {
			$when = date_i18n(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( get_date_from_gmt( $feedback['created_at'] ) )
			);
		}
		?>
		<div class="subscrpt-card">
			<div class="subscrpt-card__head"><?php esc_html_e( 'Cancellation Reason', 'subscription' ); ?></div>
			<div class="subscrpt-card__body">
				<div style="display:flex;gap:10px;align-items:flex-start;">
					<span style="flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;background:var(--wpsubs-brand-light);color:var(--wpsubs-brand);">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
					</span>
					<div style="flex:1 1 auto;min-width:0;">
						<p style="margin:0;font-weight:600;color:var(--wpsubs-text);word-break:break-word;">
							<?php echo '' !== $reason ? esc_html( $reason ) : esc_html__( 'No reason selected', 'subscription' ); ?>
						</p>
						<?php if ( '' !== $when ) : ?>
							<p style="margin:2px 0 0;font-size:12px;color:var(--wpsubs-text-subtle);">
								<?php
								printf(
									/* translators: %s: date and time the feedback was submitted. */
									esc_html__( 'Submitted %s', 'subscription' ),
									esc_html( $when )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( '' !== $comment ) : ?>
					<blockquote style="margin:12px 0 0;padding:8px 12px;border-left:3px solid var(--wpsubs-border-strong);background:var(--wpsubs-surface-muted);border-radius:6px;color:var(--wpsubs-text-muted);font-size:13px;line-height:1.5;word-break:break-word;">
						<?php echo wp_kses( nl2br( esc_html( $comment ) ), array( 'br' => array() ) ); ?>
					</blockquote>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the cancellation-feedback modal on the single-subscription page.
	 *
	 * Only renders when the feature is enabled and the subscription is in a state
	 * that shows a Cancel button (pending/active/on_hold with user cancellation
	 * allowed). The markup is hidden by default; the frontend script intercepts the
	 * Cancel link click, opens it, and on confirm records feedback before following
	 * the original secure cancel URL.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return void
	 */
	public function maybe_render_feedback_modal( $subscription_id ) {
		if ( ! self::is_feedback_enabled() ) {
			return;
		}

		$subscription_id = (int) $subscription_id;
		$status          = get_post_status( $subscription_id );

		if ( ! in_array( $status, array( 'pending', 'active', 'on_hold' ), true ) ) {
			return;
		}

		if ( 'no' === get_post_meta( $subscription_id, '_subscrpt_user_cancel', true ) ) {
			return;
		}

		$reasons = self::get_reasons();
		if ( empty( $reasons ) ) {
			return;
		}

		wp_enqueue_style( 'subscrpt_cancellation_css', SUBSCRPT_ASSETS . '/css/cancellation.css', array(), SUBSCRPT_VERSION );
		wp_enqueue_script( 'subscrpt_cancellation_feedback', SUBSCRPT_ASSETS . '/js/frontend/cancellation-feedback.js', array(), SUBSCRPT_VERSION, true );
		wp_localize_script(
			'subscrpt_cancellation_feedback',
			'subscrptCancellationFeedback',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'subscrpt_cancellation_feedback' ),
				'doneLabel' => __( 'Done', 'subscription' ),
			)
		);
		?>
		<div class="subscrpt-feedback-modal" id="subscrpt-feedback-modal" data-subscription="<?php echo esc_attr( $subscription_id ); ?>" hidden>
			<div class="subscrpt-feedback-modal__overlay" data-subscrpt-feedback-dismiss></div>
			<div class="subscrpt-feedback-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="subscrpt-feedback-title" aria-describedby="subscrpt-feedback-intro">
				<div class="subscrpt-feedback-modal__header">
					<h3 class="subscrpt-feedback-modal__title" id="subscrpt-feedback-title"><?php esc_html_e( 'Before you go', 'subscription' ); ?></h3>
					<button type="button" class="subscrpt-feedback-modal__close" data-subscrpt-feedback-dismiss aria-label="<?php esc_attr_e( 'Close', 'subscription' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
					</button>
				</div>
				<?php if ( \SpringDevs\Subscription\Admin\CancellationFlow::offer_enabled() ) : ?>
					<?php $subscrpt_offer_percent = \SpringDevs\Subscription\Admin\CancellationFlow::offer_percent(); ?>
					<div class="subscrpt-feedback-modal__body" data-subscrpt-offer-step>
						<p class="subscrpt-feedback-modal__intro">
							<?php
							printf(
								/* translators: %s: discount percentage. */
								esc_html__( 'Stay with us and take %s%% off your next order.', 'subscription' ),
								esc_html( (string) $subscrpt_offer_percent )
							);
							?>
						</p>
						<p class="subscrpt-feedback-modal__offer-note" data-subscrpt-offer-result hidden></p>
					</div>
					<div class="subscrpt-feedback-modal__footer" data-subscrpt-offer-step>
						<button type="button" class="subscrpt-feedback-modal__btn subscrpt-feedback-modal__offer-decline" data-subscrpt-offer-decline><?php esc_html_e( 'No thanks, continue', 'subscription' ); ?></button>
						<button type="button" class="subscrpt-feedback-modal__btn subscrpt-feedback-modal__offer-claim" data-subscrpt-offer-claim>
							<?php esc_html_e( 'Claim discount', 'subscription' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<div class="subscrpt-feedback-modal__body"<?php echo \SpringDevs\Subscription\Admin\CancellationFlow::offer_enabled() ? ' data-subscrpt-reason-step hidden' : ''; ?>>
					<p class="subscrpt-feedback-modal__intro" id="subscrpt-feedback-intro"><?php esc_html_e( 'Please let us know why you are cancelling. Your feedback helps us improve.', 'subscription' ); ?></p>
					<ul class="subscrpt-feedback-modal__reasons">
						<?php foreach ( $reasons as $index => $reason ) : ?>
							<?php
							$reason_key   = isset( $reason['key'] ) ? (string) $reason['key'] : '';
							$reason_label = isset( $reason['label'] ) ? (string) $reason['label'] : '';
							if ( '' === $reason_key || '' === $reason_label ) {
								continue;
							}
							$input_id = 'subscrpt-feedback-reason-' . $index;
							?>
							<li class="subscrpt-feedback-modal__reason">
								<label class="subscrpt-feedback-modal__reason-label" for="<?php echo esc_attr( $input_id ); ?>">
									<input type="radio" class="subscrpt-feedback-modal__radio" id="<?php echo esc_attr( $input_id ); ?>" name="subscrpt_feedback_reason" value="<?php echo esc_attr( $reason_key ); ?>" data-label="<?php echo esc_attr( $reason_label ); ?>" />
									<span class="subscrpt-feedback-modal__reason-text"><?php echo esc_html( $reason_label ); ?></span>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( self::is_feedback_comment_enabled() ) : ?>
						<textarea class="subscrpt-feedback-modal__comment" id="subscrpt-feedback-comment" rows="3" placeholder="<?php esc_attr_e( 'Additional comments (optional)', 'subscription' ); ?>"></textarea>
					<?php endif; ?>
				</div>
				<div class="subscrpt-feedback-modal__footer"<?php echo \SpringDevs\Subscription\Admin\CancellationFlow::offer_enabled() ? ' data-subscrpt-reason-step hidden' : ''; ?>>
					<button type="button" class="subscrpt-feedback-modal__btn subscrpt-feedback-modal__confirm" id="subscrpt-feedback-confirm"><?php esc_html_e( 'Confirm cancellation', 'subscription' ); ?></button>
					<button type="button" class="subscrpt-feedback-modal__btn subscrpt-feedback-modal__keep" data-subscrpt-feedback-dismiss><?php esc_html_e( 'Keep subscription', 'subscription' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: record a cancellation-feedback submission.
	 *
	 * Best-effort — the frontend proceeds with cancellation regardless of the
	 * outcome. Verifies the nonce and that the current user owns the subscription
	 * (or is an admin), snapshots the reason label so historic rows survive later
	 * reason edits, stores the row, and fires `subscrpt_cancellation_feedback_recorded`.
	 *
	 * @return void
	 */
	public function record_feedback() {
		check_ajax_referer( 'subscrpt_cancellation_feedback', 'nonce' );

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
		if ( $subscription_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'invalid_subscription' ) );
		}

		$subs_post = get_post( $subscription_id );
		if ( ! $subs_post || 'subscrpt_order' !== $subs_post->post_type ) {
			wp_send_json_error( array( 'message' => 'invalid_subscription' ) );
		}

		$author_id = (int) $subs_post->post_author;
		if ( ! current_user_can( 'manage_options' ) && get_current_user_id() !== $author_id ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}

		$reason_key = isset( $_POST['reason_key'] ) ? sanitize_key( wp_unslash( $_POST['reason_key'] ) ) : '';
		$comment    = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';

		// Snapshot the label from the current reason set so it survives later edits.
		$reason_label = '';
		foreach ( self::get_reasons() as $reason ) {
			if ( isset( $reason['key'] ) && (string) $reason['key'] === $reason_key ) {
				$reason_label = isset( $reason['label'] ) ? (string) $reason['label'] : '';
				break;
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'subscrpt_cancellation_feedback';
		$data  = array(
			'subscription_id' => $subscription_id,
			'customer_id'     => $author_id,
			'reason_key'      => $reason_key,
			'reason_label'    => $reason_label,
			'comment'         => $comment,
			'created_at'      => current_time( 'mysql', true ),
		);

		// One row per subscription: overwrite any previous feedback (e.g. after a.
		// reactivate → cancel-again cycle) rather than accumulating a log, so the.
		// row always reflects the latest cancellation reason.
		$existing_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}subscrpt_cancellation_feedback WHERE subscription_id = %d ORDER BY id DESC LIMIT 1",
				$subscription_id
			)
		);

		if ( $existing_id ) {
			// Clear any stray duplicates from earlier writes, keep the one we update.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}subscrpt_cancellation_feedback WHERE subscription_id = %d AND id <> %d",
					$subscription_id,
					$existing_id
				)
			);
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				$data,
				array( 'id' => $existing_id ),
				array( '%d', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			$data['id'] = $existing_id;
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				$data,
				array( '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			$data['id'] = (int) $wpdb->insert_id;
		}

		/**
		 * Fires after a cancellation-feedback row is stored.
		 *
		 * @param int   $subscription_id Subscription ID.
		 * @param array $data            Stored feedback row.
		 */
		do_action( 'subscrpt_cancellation_feedback_recorded', $subscription_id, $data );

		wp_send_json_success( array( 'id' => $data['id'] ) );
	}

	/**
	 * AJAX: the customer accepted the retention offer.
	 *
	 * Ashbi owns the request, coupon creation, nonce, ownership, and throttle.
	 *
	 * @return void
	 */
	public function claim_offer() {
		check_ajax_referer( 'subscrpt_cancellation_feedback', 'nonce' );

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
		if ( $subscription_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'invalid_subscription' ) );
		}

		$subs_post = get_post( $subscription_id );
		if ( ! $subs_post || 'subscrpt_order' !== $subs_post->post_type ) {
			wp_send_json_error( array( 'message' => 'invalid_subscription' ) );
		}

		$author_id = (int) $subs_post->post_author;
		if ( ! current_user_can( 'manage_options' ) && get_current_user_id() !== $author_id ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}

		/**
		 * Filters the retention offer handed to a cancelling customer.
		 *
		 * Return an array with a `code` to make the offer; anything falsy means no
		 * offer was issued and the customer continues to the reasons step.
		 *
		 * @param array|null $offer           The offer, or null when none is issued.
		 * @param int        $subscription_id Subscription ID.
		 * @param int        $customer_id     Subscription owner.
		 */
		$status = get_post_status( $subscription_id );
		if ( ! in_array( $status, array( 'pending', 'active', 'on_hold' ), true ) ) {
			wp_send_json_error( array( 'message' => 'invalid_subscription_state' ) );
		}

		$offer = apply_filters( 'subscrpt_cancellation_offer', null, $subscription_id, $author_id );

		if ( empty( $offer['code'] ) ) {
			wp_send_json_error( array( 'message' => 'no_offer' ) );
		}

		self::record_recovery_event(
			$subscription_id,
			'offer_accepted',
			self::RECOVERY_CAMPAIGN,
			(string) $offer['code'],
			0,
			$author_id
		);

		// Accepting the offer is a save, and the strongest kind - report it even.
		// if the customer already dismissed the modal once today.
		delete_transient( self::save_throttle_key( $subscription_id ) );
		set_transient( self::save_throttle_key( $subscription_id ), 1, DAY_IN_SECONDS );

		$reason_key = isset( $_POST['reason_key'] ) ? sanitize_key( wp_unslash( $_POST['reason_key'] ) ) : '';

		$reason_label = '';
		foreach ( self::get_reasons() as $reason ) {
			if ( isset( $reason['key'] ) && (string) $reason['key'] === $reason_key ) {
				$reason_label = isset( $reason['label'] ) ? (string) $reason['label'] : '';
				break;
			}
		}

		do_action(
			'subscrpt_subscription_saved',
			$subscription_id,
			array(
				'subscription_id' => $subscription_id,
				'customer_id'     => $author_id,
				'reason_key'      => $reason_key,
				'reason_label'    => $reason_label,
				'offer_accepted'  => true,
				'offer_code'      => (string) $offer['code'],
			)
		);

		wp_send_json_success(
			array(
				'code'    => (string) $offer['code'],
				'message' => isset( $offer['message'] ) ? (string) $offer['message'] : '',
			)
		);
	}

	/**
	 * Resolve the configured cancellation timing mode into a timestamp.
	 *
	 * @param int $cancel_at       Existing default timestamp.
	 * @param int $subscription_id Subscription ID.
	 * @return int
	 */
	public function resolve_cancellation_time( $cancel_at, $subscription_id ) {
		$mode = self::get_settings( 'subscrpt_cancellation_delay' );

		if ( 'instant' === $mode ) {
			return time();
		}

		if ( 'period' === $mode ) {
			$next_date = (int) get_post_meta( (int) $subscription_id, '_subscrpt_next_date', true );
			if ( $next_date > 0 ) {
				return $next_date;
			}
		}

		return (int) $cancel_at;
	}

	/**
	 * Issue one customer-specific, single-use WooCommerce retention coupon.
	 *
	 * The stored code makes the filter idempotent when the browser retries the
	 * claim request. A race that loses the unique post-meta write removes its
	 * unused coupon and returns the winner's code instead.
	 *
	 * @param array|null $offer           Existing offer from an integration.
	 * @param int        $subscription_id Subscription ID.
	 * @param int        $customer_id     Customer/user ID.
	 * @return array|null
	 */
	public function create_retention_offer( $offer, $subscription_id, $customer_id ) {
		if ( ! empty( $offer['code'] ) ) {
			return $offer;
		}

		if ( ! \SpringDevs\Subscription\Admin\CancellationFlow::offer_enabled() || ! class_exists( '\WC_Coupon' ) || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return null;
		}

		$existing_code = (string) get_post_meta( (int) $subscription_id, self::OFFER_CODE_META, true );
		if ( '' !== $existing_code ) {
			self::record_recovery_event( (int) $subscription_id, 'offer_issued', self::RECOVERY_CAMPAIGN, $existing_code, 0, (int) $customer_id );
			return array(
				'code'    => $existing_code,
				'message' => __( 'Your retention offer is ready to use.', 'subscription' ),
			);
		}

		$user  = get_userdata( (int) $customer_id );
		$email = $user ? sanitize_email( $user->user_email ) : '';
		$code  = '';
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$candidate = 'ASHBI-' . strtoupper( wp_generate_password( 12, false, false ) );
			if ( ! wc_get_coupon_id_by_code( $candidate ) ) {
				$code = $candidate;
				break;
			}
		}

		if ( '' === $code ) {
			return null;
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( (string) \SpringDevs\Subscription\Admin\CancellationFlow::offer_percent() );
		$coupon->set_date_expires( gmdate( 'Y-m-d', time() + ( \SpringDevs\Subscription\Admin\CancellationFlow::offer_days() * DAY_IN_SECONDS ) ) );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( true );
		$coupon->set_description( __( 'Ashbi Subscriptions retention offer', 'subscription' ) );
		if ( '' !== $email ) {
			$coupon->set_email_restrictions( array( $email ) );
		}
		$coupon->save();

		if ( ! $coupon->get_id() ) {
			return null;
		}

		if ( ! add_post_meta( (int) $subscription_id, self::OFFER_CODE_META, $code, true ) ) {
			$coupon->delete( true );
			$winner = (string) get_post_meta( (int) $subscription_id, self::OFFER_CODE_META, true );
			return '' !== $winner ? array( 'code' => $winner ) : null;
		}

		self::record_recovery_event( (int) $subscription_id, 'offer_issued', self::RECOVERY_CAMPAIGN, $code, 0, (int) $customer_id );

		return array(
			'code'    => $code,
			'message' => __( 'Your retention offer is ready to use.', 'subscription' ),
		);
	}

	/**
	 * Transient guarding one save report per subscription per day.
	 *
	 * Every way out of the modal counts as a save - Keep subscription, the X, the
	 * overlay, Escape - so without this a customer idly opening and closing it
	 * would mail the store owner each time.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @return string
	 */
	protected static function save_throttle_key( $subscription_id ) {
		return 'subscrpt_save_reported_' . (int) $subscription_id;
	}

	/**
	 * AJAX: the customer backed out of cancelling.
	 *
	 * Does not write cancellation feedback because the customer did not complete
	 * cancellation, but records a deduplicated save event for retention reporting.
	 * Throttled to once a day per subscription.
	 *
	 * @return void
	 */
	public function record_save() {
		check_ajax_referer( 'subscrpt_cancellation_feedback', 'nonce' );

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
		if ( $subscription_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'invalid_subscription' ) );
		}

		$subs_post = get_post( $subscription_id );
		if ( ! $subs_post || 'subscrpt_order' !== $subs_post->post_type ) {
			wp_send_json_error( array( 'message' => 'invalid_subscription' ) );
		}

		$author_id = (int) $subs_post->post_author;
		if ( ! current_user_can( 'manage_options' ) && get_current_user_id() !== $author_id ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}

		$throttle = self::save_throttle_key( $subscription_id );
		if ( get_transient( $throttle ) ) {
			wp_send_json_success( array( 'throttled' => true ) );
		}
		set_transient( $throttle, 1, DAY_IN_SECONDS );

		$reason_key = isset( $_POST['reason_key'] ) ? sanitize_key( wp_unslash( $_POST['reason_key'] ) ) : '';

		$reason_label = '';
		foreach ( self::get_reasons() as $reason ) {
			if ( isset( $reason['key'] ) && (string) $reason['key'] === $reason_key ) {
				$reason_label = isset( $reason['label'] ) ? (string) $reason['label'] : '';
				break;
			}
		}

		$data = array(
			'subscription_id' => $subscription_id,
			'customer_id'     => $author_id,
			'reason_key'      => $reason_key,
			'reason_label'    => $reason_label,
			'offer_accepted'  => false,
		);
		self::record_recovery_event( $subscription_id, 'save', self::RECOVERY_CAMPAIGN, '', 0, $author_id );

		/**
		 * Fires when a customer opens the cancellation modal and backs out.
		 *
		 * Throttled to once a day per subscription, so a listener may treat each
		 * call as a distinct retention event.
		 *
		 * @param int   $subscription_id Subscription ID.
		 * @param array $data            Save context: the reason that had been
		 *                               selected (may be empty) and whether a
		 *                               retention offer was accepted.
		 */
		do_action( 'subscrpt_subscription_saved', $subscription_id, $data );

		wp_send_json_success( array( 'saved' => true ) );
	}

	/**
	 * Get Settings
	 *
	 * @param string $id Setting ID.
	 */
	public static function get_settings( $id = '' ) {
		$settings = array(
			'subscrpt_cancellation_delay'            => get_option( 'subscrpt_cancellation_delay', '24h' ),
			'subscrpt_cancellation_feedback_enabled' => get_option( 'subscrpt_cancellation_feedback_enabled', '1' ),
			'subscrpt_cancellation_feedback_comment' => get_option( 'subscrpt_cancellation_feedback_comment', '1' ),
		);
		return ! empty( $id ) ? $settings[ $id ] ?? false : $settings;
	}

	/**
	 * Whether the cancellation-feedback prompt is enabled.
	 *
	 * A free feature — works with or without Pro.
	 *
	 * @return bool
	 */
	public static function is_feedback_enabled() {
		return '1' === self::get_settings( 'subscrpt_cancellation_feedback_enabled' );
	}

	/**
	 * Whether the optional comment box is shown in the feedback form. Defaults to on.
	 *
	 * @return bool
	 */
	public static function is_feedback_comment_enabled() {
		return '1' === self::get_settings( 'subscrpt_cancellation_feedback_comment' );
	}

	/**
	 * Built-in default cancellation reasons.
	 *
	 * Used when the store has not saved its own list. Written for a store
	 * selling goods on a subscription — the reasons a customer stops a product
	 * subscription — and without "Other", which get_reasons() adds by itself.
	 * Filterable so Pro and integrations can adjust the defaults.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public static function default_reasons() {
		$reasons = array(
			array(
				'key'   => 'too_expensive',
				'label' => __( 'Too expensive', 'subscription' ),
			),
			array(
				'key'   => 'too_much_product',
				'label' => __( 'I have more than I need', 'subscription' ),
			),
			array(
				'key'   => 'quality_issues',
				'label' => __( 'Not happy with the quality', 'subscription' ),
			),
			array(
				'key'   => 'delivery_issues',
				'label' => __( 'Delivery took too long', 'subscription' ),
			),
			array(
				'key'   => 'found_better_deal',
				'label' => __( 'Found a better deal elsewhere', 'subscription' ),
			),
			array(
				'key'   => 'taking_a_break',
				'label' => __( 'Just taking a break', 'subscription' ),
			),
		);

		/**
		 * Filter the built-in default cancellation reasons.
		 *
		 * @param array $reasons List of { key, label } reason entries.
		 */
		return apply_filters( 'subscrpt_cancellation_default_reasons', $reasons );
	}

	/**
	 * The store's own reasons: the saved list, or the defaults when none is saved.
	 *
	 * This is what the Reasons editor edits. It is read with or without Pro —
	 * editing reasons is a free feature. An "other" entry saved before "Other"
	 * became automatic is dropped, so it can never show twice.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public static function get_configured_reasons() {
		$reasons = get_option( 'subscrpt_cancellation_reasons', array() );
		if ( empty( $reasons ) || ! is_array( $reasons ) ) {
			$reasons = self::default_reasons();
		}

		return array_values(
			array_filter(
				$reasons,
				static function ( $reason ) {
					return is_array( $reason ) && self::OTHER_KEY !== ( $reason['key'] ?? '' );
				}
			)
		);
	}

	/**
	 * The reasons customers are offered.
	 *
	 * The store's reasons, then "Other" when the comment box is on — an answer
	 * that is none of the listed reasons needs the comment to say what it is,
	 * so without the box there is no "Other". Everything customer-facing reads
	 * this, including the label snapshot taken when feedback is recorded.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public static function get_reasons() {
		$reasons = self::get_configured_reasons();

		if ( self::is_feedback_comment_enabled() ) {
			$reasons[] = array(
				'key'   => self::OTHER_KEY,
				'label' => __( 'Other', 'subscription' ),
			);
		}

		return $reasons;
	}

	/**
	 * Record when a pending cancellation should become final.
	 *
	 * Runs whenever a subscription enters `pe_cancelled` (frontend, admin, or REST).
	 * If the resolved time is already due, the subscription is cancelled immediately.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return void
	 */
	public function schedule_cancellation( $subscription_id ) {
		$subscription_id = (int) $subscription_id;

		/**
		 * Filter the timestamp at which a pending cancellation becomes a full cancellation.
		 *
		 * Return a Unix timestamp. A value at or before the current time cancels the
		 * subscription immediately. Defaults to 24 hours from now.
		 *
		 * @param int $cancel_at       Unix timestamp for final cancellation.
		 * @param int $subscription_id Subscription ID.
		 */
		$cancel_at = (int) apply_filters( 'subscrpt_cancellation_time', time() + DAY_IN_SECONDS, $subscription_id );

		if ( $cancel_at <= time() ) {
			$this->cancel( $subscription_id );
			return;
		}

		update_post_meta( $subscription_id, self::CANCEL_AT_META, $cancel_at );
	}

	/**
	 * Hourly sweep: finalise any pending cancellations whose time has come.
	 *
	 * Picks up subscriptions whose `_subscrpt_cancel_at` is due, plus legacy
	 * `pe_cancelled` subscriptions (created before this meta existed) whose billing
	 * period has ended.
	 *
	 * @return void
	 */
	public function process_due_cancellations() {
		$subscriptions = get_posts(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => array( 'pe_cancelled' ),
				'fields'      => 'ids',
				'numberposts' => -1,
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'     => self::CANCEL_AT_META,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => self::CANCEL_AT_META,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_subscrpt_next_date',
							'value'   => time(),
							'compare' => '<=',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);

		if ( empty( $subscriptions ) ) {
			return;
		}

		// Ensure the mailer is ready so the cancellation email can be sent.
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			foreach ( $subscriptions as $subscription_id ) {
				$this->cancel( (int) $subscription_id );
			}
		}
	}

	/**
	 * Finalise the cancellation of a single subscription.
	 *
	 * Guards against subscriptions that are no longer pending (e.g. reactivated).
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return void
	 */
	public function cancel( $subscription_id ) {
		$subscription_id = (int) $subscription_id;

		if ( 'pe_cancelled' === get_post_status( $subscription_id ) ) {
			Action::status( 'cancelled', $subscription_id );
		}

		delete_post_meta( $subscription_id, self::CANCEL_AT_META );
	}

	/**
	 * Drop a scheduled cancellation when a subscription is reactivated.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return void
	 */
	public function clear_scheduled_cancellation( $subscription_id ) {
		delete_post_meta( (int) $subscription_id, self::CANCEL_AT_META );
	}

	/**
	 * Show a notice on the subscription details page when a cancellation is pending.
	 *
	 * Uses `_subscrpt_cancel_at` (the resolved final-cancellation time), falling back
	 * to the next renewal date for legacy subscriptions.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return void
	 */
	public function display_pending_cancellation_notice( $subscription_id ) {
		if ( 'pe_cancelled' !== get_post_status( $subscription_id ) ) {
			return;
		}

		$cancel_at = (int) get_post_meta( $subscription_id, self::CANCEL_AT_META, true );
		if ( ! $cancel_at ) {
			$cancel_at = (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
		}

		wp_enqueue_style( 'subscrpt_cancellation_css', SUBSCRPT_ASSETS . '/css/cancellation.css', array(), SUBSCRPT_VERSION );
		?>
		<div class="subscrpt-pending-cancel-notice" role="status">
			<span class="subscrpt-pending-cancel-notice__icon" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
			</span>
			<div class="subscrpt-pending-cancel-notice__body">
				<p class="subscrpt-pending-cancel-notice__text">
					<?php
					if ( $cancel_at ) {
						$effective = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cancel_at );
						printf(
							/* translators: %s: cancellation date and time. */
							esc_html__( 'This subscription is scheduled to be cancelled on %s. You can continue accessing it until then.', 'subscription' ),
							'<strong>' . esc_html( $effective ) . '</strong>'
						);
					} else {
						esc_html_e( 'This subscription is scheduled to be cancelled at the end of the current billing period. You can continue accessing it until then.', 'subscription' );
					}
					?>
				</p>
			</div>
		</div>
		<?php
	}
}

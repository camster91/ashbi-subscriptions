<?php

namespace SpringDevs\Subscription\Illuminate;

/**
 * Action [ helper class ]
 *
 * @package SpringDevs\Subscription\Illuminate
 */
class Action {

	/**
	 * Did when status changes.
	 *
	 * @param string $status Status.
	 * @param int    $subscription_id Subscription ID.
	 * @param bool   $write_comment Write comment?.
	 */
	public static function status( string $status, int $subscription_id, bool $write_comment = true ) {
		// A split-payment subscription whose final installment is paid is terminal:
		// never (re)activate it. Any renewal-payment path that tries to set it active
		// after completion is coerced to `completed` so the status can't flip back.
		if (
			'active' === $status
			&& function_exists( 'subscrpt_is_max_payments_reached' )
			&& subscrpt_is_max_payments_reached( $subscription_id )
		) {
			$status = 'completed';
		}

		$old_status = get_post_status( $subscription_id );

		wp_update_post(
			array(
				'ID'          => $subscription_id,
				'post_status' => $status,
			)
		);

		if ( 'completed' === $status && function_exists( 'subscrpt_finalize_split_payment_completion' ) ) {
			subscrpt_finalize_split_payment_completion( $subscription_id );
		}

		// Only note a real transition — never re-log the same status.
		if ( $write_comment && $old_status !== $status ) {
			self::write_comment( $status, $subscription_id );
		}

		// Trigger status change action
		do_action( 'subscrpt_subscription_status_changed', $subscription_id, $old_status, $status );

		// Trigger resumption event if subscription is being activated from cancelled or pending cancellation
		if ( $status === 'active' && in_array( $old_status, array( 'cancelled', 'pe_cancelled' ) ) ) {
			do_action( 'subscrpt_subscription_resumed', $subscription_id, $old_status );
		}
	}

	/**
	 * Write Comment based on status.
	 *
	 * @param string $status Status.
	 * @param Int    $subscription_id Subscription ID.
	 */
	public static function write_comment( string $status, int $subscription_id ) {
		switch ( $status ) {
			case 'expired':
				return self::expired( $subscription_id );
			case 'active':
				return self::active( $subscription_id );
			case 'pending':
				return self::pending( $subscription_id );
			case 'cancelled':
				return self::cancelled( $subscription_id );
			case 'pe_cancelled':
				return self::pe_cancelled( $subscription_id );
			case 'on-hold':
				return self::on_hold( $subscription_id );
			case 'completed':
				return self::completed( $subscription_id );
		}

		return true;
	}

	/**
	 * Insert and verify a subscription lifecycle activity comment.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $content Comment content.
	 * @param string $activity Activity label.
	 * @param string $activity_type Activity type key.
	 * @return bool
	 */
	private static function record_activity( int $subscription_id, string $content, string $activity, string $activity_type ): bool {
		$comment_id = wp_insert_comment(
			array(
				'comment_author'  => 'Subscription for WooCommerce',
				'comment_content' => $content,
				'comment_post_ID' => $subscription_id,
				'comment_type'    => 'order_note',
			)
		);
		if ( ! $comment_id ) {
			return false;
		}

		return false !== update_comment_meta( $comment_id, '_subscrpt_activity', $activity )
			&& false !== update_comment_meta( $comment_id, '_subscrpt_activity_type', $activity_type );
	}

	/**
	 * Write Comment About Completed Subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private static function completed( int $subscription_id ) {
		if ( ! self::record_activity( $subscription_id, 'Subscription completed. All payments made.', 'Subscription Completed', 'subs_completed' ) ) {
			return false;
		}

		do_action( 'subscrpt_subscription_completed', $subscription_id );
		return true;
	}

	/**
	 * Write Comment About expired Subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private static function expired( int $subscription_id ) {
		if ( ! self::record_activity( $subscription_id, 'Subscription is Expired', 'Subscription Expired', 'subs_expired' ) ) {
			return false;
		}

		do_action( 'subscrpt_subscription_expired', $subscription_id );
		return true;
	}

	/**
	 * Write Comment About Active Subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private static function active( int $subscription_id ) {
		if ( ! self::record_activity( $subscription_id, 'Subscription activated. Next payment due date set.', 'Subscription Activated', 'subs_activated' ) ) {
			return false;
		}

		do_action( 'subscrpt_subscription_activated', $subscription_id );
		return true;
	}

	/**
	 * Write Comment About Subscription Pending.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private static function pending( int $subscription_id ) {
		if ( ! self::record_activity( $subscription_id, 'Subscription is pending.', 'Subscription Pending', 'subs_pending' ) ) {
			return false;
		}

		do_action( 'subscrpt_subscription_pending', $subscription_id );
		return true;
	}

	/**
	 * Write Comment About cancelled Subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private static function cancelled( int $subscription_id ) {
		if ( ! self::record_activity( $subscription_id, 'Subscription is Cancelled.', 'Subscription Cancelled', 'subs_cancelled' ) ) {
			return false;
		}

		WC()->mailer();
		do_action( 'subscrpt_subscription_cancelled_email_notification', $subscription_id );
		do_action( 'subscrpt_subscription_cancelled', $subscription_id );

		// Fire split payment cancelled action
		do_action( 'subscrpt_split_payment_cancelled', $subscription_id );
		return true;
	}

	/**
	 * Write Comment About On-Hold Subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private static function on_hold( int $subscription_id ) {
		if ( ! self::record_activity( $subscription_id, 'Subscription is On Hold. Access suspended after payment failure.', 'Subscription On Hold', 'subs_on_hold' ) ) {
			return false;
		}

		do_action( 'subscrpt_subscription_on_hold', $subscription_id );
		return true;
	}

	/**
	 * Write Comment About Pending Cancellation.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private static function pe_cancelled( int $subscription_id ) {
		if ( ! self::record_activity( $subscription_id, 'Subscription is Pending Cancellation.', 'Subscription Pending Cancellation', 'subs_pe_cancel' ) ) {
			return false;
		}

		// WC_Email classes only exist once the mailer has been built, and they
		// attach their own listeners from their constructors. Without this, an
		// email listening for a pending cancellation is simply not registered yet
		// when the action fires - the same reason cancelled() calls it.
		WC()->mailer();

		do_action( 'subscrpt_subscription_pending_cancellation', $subscription_id );
		return true;
	}
}

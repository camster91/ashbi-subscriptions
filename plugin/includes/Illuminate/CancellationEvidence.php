<?php
/**
 * Durable cancellation intent and append-only local evidence.
 *
 * @package SpringDevs\Subscription\Illuminate
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- Namespace autoloading preserves the public class path.

namespace SpringDevs\Subscription\Illuminate;

/** Cancellation barriers must survive status changes and code rollback. */
final class CancellationEvidence {

	/**
	 * Append a bounded event; never retain request bodies or credentials.
	 *
	 * @param string $event Allowlisted event type.
	 * @param int    $subscription_id Authorized subscription or zero.
	 * @param int    $actor_id Authorized actor or zero.
	 * @param string $request_id Correlation ID, stored hashed.
	 * @param array  $details Allowlisted details.
	 */
	public static function record( string $event, int $subscription_id = 0, int $actor_id = 0, string $request_id = '', array $details = array() ): bool {
		global $wpdb;
		$allowed = array( 'request_received', 'request_rejected', 'cancel_authorized', 'cancel_pending', 'cancel_confirmed', 'cancel_failed', 'dispatch_review', 'provider_pending', 'provider_confirmed' );
		if ( ! in_array( $event, $allowed, true ) ) {
			return false;
		}
		$clean = array();
		foreach ( array( 'code', 'status', 'provider_state' ) as $key ) {
			if ( isset( $details[ $key ] ) && is_string( $details[ $key ] ) ) {
				$clean[ $key ] = substr( sanitize_key( $details[ $key ] ), 0, 40 );
			}
		}
		foreach ( array( 'order_id', 'access_end' ) as $key ) {
			if ( isset( $details[ $key ] ) ) {
				$clean[ $key ] = max( 0, (int) $details[ $key ] );
			}
		}
		if ( isset( $details['audit_complete'] ) ) {
			$clean['audit_complete'] = true === $details['audit_complete'];
		}
		return false !== $wpdb->insert(
			$wpdb->prefix . 'subscrpt_evidence_event',
			array(
				'event_type'      => $event,
				'subscription_id' => max( 0, $subscription_id ),
				'actor_id'        => max( 0, $actor_id ),
				'request_id'      => hash( 'sha256', $request_id ),
				'details'         => wp_json_encode( $clean ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Read directly from storage, failing closed on missing/unreadable schema.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function blocked( int $subscription_id ): bool {
		global $wpdb;
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT subscription_id FROM %i WHERE subscription_id = %d', $wpdb->prefix . 'subscrpt_cancellation_barrier', $subscription_id ) );
		return ! empty( $wpdb->last_error ) || null !== $row;
	}

	/**
	 * Shared subscription mutex used by cancellation and provider dispatch.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function lock( int $subscription_id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $subscription_id ) ) );
	}

	/**
	 * Release only this subscription's connection-owned lock.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function unlock( int $subscription_id ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $subscription_id ) ) );
	}

	/**
	 * Keep lock identity scoped to the site's table prefix.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private static function lock_name( int $subscription_id ): string {
		global $wpdb;
		return 'ashbi_subscrpt_cancel_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 12 ) . '_' . $subscription_id;
	}

	/**
	 * Persist authorized intent before changing lifecycle state.
	 * Nonce validation belongs to the entry controller; ownership is rechecked here.
	 * A barrier is immutable. Reopening requires a future explicit reviewed workflow.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param int    $actor_id Current authorized actor.
	 * @param string $request_id Correlation ID.
	 */
	public static function request( int $subscription_id, int $actor_id, string $request_id ): array {
		global $wpdb;
		$post = get_post( $subscription_id );
		if ( ! is_user_logged_in() || 0 >= $actor_id || $actor_id !== (int) get_current_user_id() || ! $post || 'subscrpt_order' !== $post->post_type || ( ! current_user_can( 'manage_options' ) && (int) $post->post_author !== $actor_id ) ) {
			self::record( 'request_rejected', 0, 0, $request_id, array( 'code' => 'unauthorized' ) );
			return array(
				'state'   => 'failed',
				'barrier' => false,
			);
		}
		if ( 'no' === get_post_meta( $subscription_id, '_subscrpt_user_cancel', true ) || ! in_array( get_post_status( $subscription_id ), array( 'active', 'pending', 'on_hold', 'pe_cancelled', 'cancelled' ), true ) ) {
			return array(
				'state'   => 'failed',
				'barrier' => self::blocked( $subscription_id ),
			);
		}
		if ( ! self::lock( $subscription_id ) ) {
			return array(
				'state'   => 'pending',
				'barrier' => self::blocked( $subscription_id ),
			);
		}
		try {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d', $wpdb->prefix . 'subscrpt_cancellation_barrier', $subscription_id ) );
			if ( ! empty( $wpdb->last_error ) ) {
				return array(
					'state'   => 'failed',
					'barrier' => true,
				);
			}
			$status     = get_post_status( $subscription_id );
			$access_end = $existing ? (int) $existing->access_end : ( 'active' === $status ? (int) apply_filters( 'subscrpt_cancellation_time', time() + DAY_IN_SECONDS, $subscription_id ) : time() );
			$insert     = $existing ? 0 : $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (subscription_id, actor_id, request_id, requested_at, access_end) VALUES (%d, %d, %s, %s, %d)', array( $wpdb->prefix . 'subscrpt_cancellation_barrier', $subscription_id, $actor_id, hash( 'sha256', $request_id ), current_time( 'mysql', true ), $access_end ) ) );
			if ( false === $insert || ! self::blocked( $subscription_id ) || ! empty( $wpdb->last_error ) ) {
				return array(
					'state'   => 'failed',
					'barrier' => self::blocked( $subscription_id ),
				);
			}
			$audit = self::record(
				$existing ? 'cancel_pending' : 'cancel_authorized',
				$subscription_id,
				$actor_id,
				$request_id,
				array(
					'access_end' => $access_end,
					'code'       => $existing ? 'repair_requested' : 'authorized',
				)
			);
			update_post_meta( $subscription_id, '_subscrpt_auto_renew', 0 );
			$target = $access_end > time() && in_array( $status, array( 'active', 'pe_cancelled' ), true ) ? 'pe_cancelled' : 'cancelled';
			if ( $target !== $status ) {
				Action::status( $target, $subscription_id );
			}
			// A retry preserves the originally recorded access end, including after
			// lifecycle hooks attempt to calculate a new relative date.
			update_post_meta( $subscription_id, '_subscrpt_cancel_at', $access_end );
			$persisted = get_post_status( $subscription_id ) === $target && (int) get_post_meta( $subscription_id, '_subscrpt_cancel_at', true ) === $access_end && 0 === (int) get_post_meta( $subscription_id, '_subscrpt_auto_renew', true );
			// Provider-managed billing is pending until the provider acknowledges it.
			$order   = Helper::get_parent_order( $subscription_id );
			$remote  = $order && 'wp_subscription_paypal' === $order->get_payment_method();
			$state   = $persisted && $audit && $order && ! $remote ? 'confirmed' : 'pending';
			$outcome = self::record(
				'confirmed' === $state ? 'cancel_confirmed' : 'cancel_pending',
				$subscription_id,
				$actor_id,
				$request_id,
				array(
					'status'         => get_post_status( $subscription_id ),
					'access_end'     => $access_end,
					'audit_complete' => $audit,
					'provider_state' => $remote ? 'pending' : 'local_billing_blocked',
				)
			);
			if ( ! $outcome ) {
				$audit = false;
				$state = 'pending';
			}
			return array(
				'state'          => $state,
				'barrier'        => true,
				'access_end'     => $access_end,
				'audit_complete' => $audit,
			);
		} catch ( \Throwable $error ) {
			// Mailer/feedback/hook failure cannot remove durable cancellation intent.
			self::record( 'cancel_pending', $subscription_id, $actor_id, $request_id, array( 'code' => 'side_effect_failed' ) );
			return array(
				'state'   => 'pending',
				'barrier' => self::blocked( $subscription_id ),
			);
		} finally {
			self::unlock( $subscription_id );
		}
	}
}

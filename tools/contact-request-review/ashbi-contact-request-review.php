<?php
/**
 * Plugin Name: Ashbi Contact Request Review
 * Description: Keep validated contact requests visible until a staff member records their outcome.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Update URI: false
 *
 * @package AshbiContactReview
 */

// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Standalone WordPress plugin entrypoint, not a library class file.

defined( 'ABSPATH' ) || exit;

/** Optional, separately installed adapter. Never changes subscriptions or payments. */
final class Ashbi_Contact_Request_Review {
	const TYPE = 'ashbi_contact_review';
	const CAP  = 'manage_options';

	/** Register the optional adapter's hooks. */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'elementor_pro/forms/process', array( __CLASS__, 'process' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_ashbi_contact_resolve', array( __CLASS__, 'handle' ) );
	}

	/** Register administrator-only private storage. */
	public static function register(): void {
		$caps                 = array_fill_keys( array( 'read_post', 'edit_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts' ), self::CAP );
		$caps['create_posts'] = 'do_not_allow';
		register_post_type(
			self::TYPE,
			array(
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'can_export'          => false,
				'rewrite'             => false,
				'query_var'           => false,
				'map_meta_cap'        => true,
				'capability_type'     => 'ashbi_contact_review',
				'capabilities'        => $caps,
			)
		);
	}

	/** Configuration is opt-in and site-specific; installing alone captures nothing. */
	public static function config(): array {
		$c = apply_filters( 'ashbi_contact_review_config', array() );
		if ( ! is_array( $c ) || true !== ( $c['enabled'] ?? false ) || empty( $c['form_name'] ) || ! is_string( $c['form_name'] ) || empty( $c['owner_user_id'] ) || ! user_can( (int) $c['owner_user_id'], self::CAP ) ) {
			return array();
		}
		$c = array_merge(
			array(
				'email_field'   => 'email',
				'message_field' => 'message',
				'name_field'    => 'name',
			),
			$c
		);
		foreach ( array( 'email_field', 'message_field', 'name_field' ) as $key ) {
			if ( ! is_string( $c[ $key ] ) || '' === $c[ $key ] || strlen( $c[ $key ] ) > 100 ) {
				return array(); }
		}
		return $c;
	}

	/**
	 * Run after Elementor validation and before notification actions.
	 *
	 * @param object $record Validated Elementor form record.
	 * @param object $handler Elementor AJAX response handler.
	 */
	public static function process( $record, $handler ): void {
		$c = self::config();
		if ( ! $c || $record->get_form_settings( 'form_name' ) !== $c['form_name'] ) {
			return; }
		$fields = (array) $record->get( 'fields' );
		$read   = static function ( $key ) use ( $fields ) {
			return isset( $fields[ $key ]['value'] ) && is_string( $fields[ $key ]['value'] ) ? $fields[ $key ]['value'] : '';
		};
		$id     = self::capture( $read( $c['email_field'] ), $read( $c['message_field'] ), $read( $c['name_field'] ) );
		if ( is_wp_error( $id ) ) {
			$handler->add_error_message( 'We could not record your message. Please try again or contact support directly. Your subscription has not been cancelled through this form.' );
			return;
		}
		$handler->add_response_data( 'ashbi_request_reference', 'R-' . $id );
	}

	/**
	 * Queue every message without interpreting the customer's intent.
	 *
	 * @param string $email Submitted address, not proof of identity.
	 * @param string $message Submitted message.
	 * @param string $name Submitted name.
	 * @return int|WP_Error Verified receipt or failure.
	 */
	public static function capture( string $email, string $message, string $name = '' ) {
		global $wpdb;
		if ( ! self::config() ) {
			return new WP_Error( 'disabled' ); }
		$email   = strtolower( trim( $email ) );
		$message = trim( wp_strip_all_tags( $message ) );
		$name    = trim( wp_strip_all_tags( $name ) );
		if ( ! is_email( $email ) || strlen( $email ) > 254 || '' === $message || strlen( $message ) > 10000 || strlen( $name ) > 200 ) {
			return new WP_Error( 'invalid_request' ); }
		$hash = hash( 'sha256', $email . "\0" . $message );
		$lock = 'acr_' . substr( hash( 'sha256', $wpdb->prefix . $hash ), 0, 50 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return new WP_Error( 'busy' ); }
		try {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'private' AND post_title = %s ORDER BY ID LIMIT 1", self::TYPE, $hash ) );
			if ( $wpdb->last_error ) {
				return new WP_Error( 'storage_unavailable' ); }
			if ( $existing ) {
				$saved   = get_post( $existing );
				$payload = $saved ? json_decode( $saved->post_content, true ) : null;
				return is_array( $payload ) && ( $payload['email'] ?? '' ) === $email && ( $payload['message'] ?? '' ) === $message ? (int) $existing : new WP_Error( 'storage_unverified' );
			}
			$body = wp_json_encode(
				array(
					'email'        => $email,
					'name'         => $name,
					'message'      => $message,
					'received_utc' => gmdate( 'c' ),
				)
			);
			$id   = wp_insert_post(
				wp_slash(
					array(
						'post_type'      => self::TYPE,
						'post_status'    => 'private',
						'post_title'     => $hash,
						'post_content'   => $body,
						'post_author'    => 0,
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					)
				),
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				return new WP_Error( 'storage_failed' ); }
			$saved = get_post( $id );
			if ( ! $saved || self::TYPE !== $saved->post_type || 'private' !== $saved->post_status || $body !== $saved->post_content ) {
				return new WP_Error( 'storage_unverified' ); }
			return (int) $id;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** Add the private review page. */
	public static function menu(): void {
		add_management_page( 'Contact requests', 'Contact requests', self::CAP, 'ashbi-contact-review', array( __CLASS__, 'page' ) );
	}

	/** Persistent dashboard warning: an email failure cannot hide the queue. */
	public static function notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return; }
		$c = self::config();
		if ( ! $c ) {
			echo '<div class="notice notice-warning"><p>Contact request review is not configured. Choose a unique form name and a responsible site administrator before enabling intake.</p></div>';
			return; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, MIN(post_date_gmt) AS oldest FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'private'", self::TYPE ) );
		if ( $wpdb->last_error || ! $row ) {
			echo '<div class="notice notice-error"><p>Contact request queue could not be checked. Review support messages directly.</p></div>';
			return; }
		if ( ! $row->total ) {
			return; }
		$late = strtotime( $row->oldest . ' UTC' ) <= time() - DAY_IN_SECONDS;
		echo '<div class="notice ' . ( $late ? 'notice-error' : 'notice-warning' ) . '"><p>' . esc_html( $row->total ) . ' contact request(s) need review.' . ( $late ? ' At least one has waited 24 hours.' : '' ) . ' <a href="' . esc_url( admin_url( 'tools.php?page=ashbi-contact-review' ) ) . '">Review requests</a></p></div>';
	}

	/**
	 * Close only after staff have checked the exact account separately.
	 *
	 * @param int $id Request ID.
	 * @param string $outcome Explicit staff disposition.
	 * @param int $subscription_id Independently verified subscription ID.
	 * @param string $note Staff evidence and reply reference.
	 * @param bool $notified Staff attestation, not proof of mail delivery.
	 * @return bool|WP_Error Closure readback or failure.
	 */
	public static function resolve( int $id, string $outcome, int $subscription_id, string $note, bool $notified ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new WP_Error( 'forbidden' ); }
		$note = trim( sanitize_textarea_field( $note ) );
		$p    = get_post( $id );
		if ( ! $p || self::TYPE !== $p->post_type || 'private' !== $p->post_status ) {
			return new WP_Error( 'not_open' ); }
		global $wpdb;
		$lock = 'acr_' . substr( hash( 'sha256', $wpdb->prefix . $p->post_title ), 0, 50 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return new WP_Error( 'busy' ); }
		try {
			$p = get_post( $id );
			if ( ! $p || 'private' !== $p->post_status ) {
				return new WP_Error( 'not_open' ); }
			if ( ! in_array( $outcome, array( 'cancellation_confirmed', 'other_request' ), true ) || ! $notified || strlen( trim( $note ) ) < 12 || strlen( $note ) > 2000 ) {
				return new WP_Error( 'review_required' ); }
			if ( 'cancellation_confirmed' === $outcome && ! self::confirmed( $subscription_id ) ) {
				return new WP_Error( 'cancellation_not_confirmed' ); }
			$outcome_data = wp_json_encode(
				array(
					'outcome'                    => $outcome,
					'subscription_id'            => 'cancellation_confirmed' === $outcome ? $subscription_id : 0,
					'note'                       => sanitize_textarea_field( $note ),
					'actor_id'                   => get_current_user_id(),
					'customer_notified_attested' => true,
					'closed_utc'                 => gmdate( 'c' ),
				)
			);
			$result       = wp_update_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_status'  => 'draft',
						'post_excerpt' => $outcome_data,
					)
				),
				true
			);
			$saved        = get_post( $id );
			return ! is_wp_error( $result ) && $saved && 'draft' === $saved->post_status && $outcome_data === $saved->post_excerpt && $p->post_content === $saved->post_content ? true : new WP_Error( 'closure_unverified' );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Read confirmation without invoking cancellation, a gateway, or repair.
	 *
	 * @param int $id Exact subscription ID.
	 */
	public static function confirmed( int $id ): bool {
		global $wpdb;
		$p = get_post( $id );
		if ( ! $p || 'subscrpt_order' !== $p->post_type || ! in_array( $p->post_status, array( 'cancelled', 'pe_cancelled' ), true ) || '1' !== (string) get_post_meta( $id, '_ashbi_cancellation_confirmed', true ) ) {
			return false; }
		$barrier = $wpdb->get_var( $wpdb->prepare( 'SELECT subscription_id FROM %i WHERE subscription_id = %d', $wpdb->prefix . 'subscrpt_cancellation_barrier', $id ) );
		return empty( $wpdb->last_error ) && $id === (int) $barrier;
	}

	/** Check the request-specific nonce before recording a staff outcome. */
	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
	 // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below with a request-specific action.
		$id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( 'ashbi_contact_resolve_' . $id );
		$outcome = isset( $_POST['outcome'] ) && is_string( $_POST['outcome'] ) ? sanitize_key( wp_unslash( $_POST['outcome'] ) ) : '';
		$sid     = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		$note    = isset( $_POST['note'] ) && is_string( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$ok      = self::resolve( $id, $outcome, $sid, $note, isset( $_POST['notified'] ) && '1' === $_POST['notified'] );
		if ( is_wp_error( $ok ) ) {
			wp_die( 'Request remains open. Verify cancellation and record the customer reply before closing it.', '', array( 'response' => 400 ) ); }
		wp_safe_redirect( admin_url( 'tools.php?page=ashbi-contact-review' ) );
		exit;
	}

	/** Render the authorized review queue without changing source records. */
	public static function page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
	 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page = isset( $_GET['review_page'] ) ? max( 1, absint( $_GET['review_page'] ) ) : 1;
	 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only archive selection.
		$closed = isset( $_GET['closed'] ) && '1' === $_GET['closed'];
		$query  = new WP_Query(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => $closed ? 'draft' : 'private',
				'posts_per_page' => 20,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$c      = self::config();
		$owner  = $c ? get_userdata( (int) $c['owner_user_id'] ) : false;
		echo '<div class="wrap"><h1>Contact requests</h1><p>Owner: ' . esc_html( $owner ? $owner->display_name : 'Not configured' ) . '</p><p>Check this queue every business day and before processing renewals. Every message needs review. This page does not change subscriptions or send email.</p><p><a href="' . esc_url( admin_url( 'tools.php?page=ashbi-contact-review' . ( $closed ? '' : '&closed=1' ) ) ) . '">' . ( $closed ? 'Open requests' : 'Closed requests' ) . '</a></p>';
		if ( ! $query->posts ) {
			echo '<p>No requests in this view.</p>'; }
		foreach ( $query->posts as $p ) {
			$d = json_decode( $p->post_content, true );
			echo '<section class="card"><h2>R-' . esc_html( $p->ID ) . '</h2><p>Received UTC: ' . esc_html( $d['received_utc'] ?? $p->post_date_gmt ) . '</p><p>' . esc_html( $d['name'] ?? '' ) . ' &lt;' . esc_html( $d['email'] ?? '' ) . '&gt;</p><pre style="white-space:pre-wrap">' . esc_html( $d['message'] ?? 'Source record unavailable; investigate before closing.' ) . '</pre>';
			if ( $closed ) {
				echo '<p>Recorded staff outcome:</p><pre style="white-space:pre-wrap">' . esc_html( $p->post_excerpt ) . '</pre></section>';
				continue; }
			echo '<p>Verify the customer and exact subscription separately. For cancellations, use the subscription controls, verify the stop, then reply to the customer. A matching email alone does not prove identity.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ashbi_contact_resolve"><input type="hidden" name="request_id" value="' . esc_attr( $p->ID ) . '">';
			wp_nonce_field( 'ashbi_contact_resolve_' . $p->ID );
			echo '<p><label>Outcome <select name="outcome"><option value="cancellation_confirmed">Cancellation confirmed</option><option value="other_request">Other request resolved — explain below</option></select></label></p><p><label>Subscription ID <input type="number" min="1" name="subscription_id"></label></p><p><label>What was checked and done? Include the customer reply reference.<br><textarea name="note" rows="4" cols="55" minlength="12" maxlength="2000" required></textarea></label></p><p><label><input type="checkbox" name="notified" value="1" required> I verified the outcome and have replied to the customer.</label></p><button class="button" type="submit">Record outcome and close request</button></form></section>';
		}
		foreach ( array( $page - 1 => 'Previous page', $page + 1 => 'Next page' ) as $i => $label ) {
			if ( $i >= 1 && $i <= $query->max_num_pages ) {
				echo '<a class="button" href="' . esc_url( admin_url( 'tools.php?page=ashbi-contact-review&review_page=' . $i . ( $closed ? '&closed=1' : '' ) ) ) . '">' . esc_html( $label ) . '</a> ';
			}
		}
		echo '</div>';
	}
}

Ashbi_Contact_Request_Review::boot();

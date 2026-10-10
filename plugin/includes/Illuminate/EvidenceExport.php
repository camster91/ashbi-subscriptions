<?php
/** Scoped, read-only evidence summaries for authorized store administrators.
 *
 * @package SpringDevs\Subscription\Illuminate
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- Canonical namespace autoload path.

namespace SpringDevs\Subscription\Illuminate;

defined( 'ABSPATH' ) || exit;

/** Export stored provenance without inventing historical acceptance or outcomes. */
final class EvidenceExport {
	/** Register a nonce-protected download and a link on subscription details. */
	public function __construct() {
		add_action( 'admin_post_subscrpt_evidence_export', array( $this, 'download' ) );
		add_action( 'subscrpt_details_side_bottom', array( $this, 'link' ) );
	}

	/**
	 * Show only to staff authorized to manage WooCommerce.
	 * @param int $subscription_id Selected subscription.
	 */
	public function link( $subscription_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$id  = (int) $subscription_id;
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'          => 'subscrpt_evidence_export',
					'subscription_id' => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'subscrpt_evidence_export_' . $id
		);
		echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Download subscription evidence summary', 'subscription' ) . '</a></p>';
	}

	/**
	 * Build bounded exact-subscription evidence; no addresses, cards or tokens.
	 * @param int $subscription_id Selected subscription.
	 * @throws \RuntimeException When scoped source records are unreadable.
	 */
	public static function build( int $subscription_id ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			throw new \RuntimeException( 'Access denied.' );
		}
		global $wpdb;
		$post = get_post( $subscription_id );
		if ( ! $post || 'subscrpt_order' !== $post->post_type ) {
			throw new \RuntimeException( 'Invalid subscription.' );
		}
		$barrier = $wpdb->get_row( $wpdb->prepare( 'SELECT subscription_id, actor_id, request_id, requested_at, access_end FROM %i WHERE subscription_id = %d', $wpdb->prefix . 'subscrpt_cancellation_barrier', $subscription_id ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Cancellation storage is unavailable.' );
		}
		$events = $wpdb->get_results( $wpdb->prepare( 'SELECT id, event_type, actor_id, request_id, details, created_at FROM %i WHERE subscription_id = %d ORDER BY id ASC LIMIT 1001', $wpdb->prefix . 'subscrpt_evidence_event', $subscription_id ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Evidence storage is unavailable.' );
		}
		foreach ( $events as &$event ) {
			$event            = array_intersect_key( $event, array_flip( array( 'id', 'event_type', 'actor_id', 'request_id', 'details', 'created_at' ) ) );
			$details          = json_decode( (string) ( $event['details'] ?? '' ), true );
			$event['details'] = array_intersect_key( (array) $details, array_flip( array( 'code', 'status', 'provider_state', 'order_id', 'access_end', 'audit_complete' ) ) );
		}
		unset( $event );
		$relations = $wpdb->get_results( $wpdb->prepare( 'SELECT order_id, type FROM %i WHERE subscription_id = %d ORDER BY order_id ASC LIMIT 101', $wpdb->prefix . 'subscrpt_order_relation', $subscription_id ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Order relation storage is unavailable.' );
		}
		$orders = array();
		foreach ( array_slice( (array) $relations, 0, 100 ) as $relation ) {
			$order_id = (int) $relation['order_id'];
			$order    = wc_get_order( $order_id );
			$raw      = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE order_id = %d', $wpdb->prefix . 'subscrpt_contract_acceptance', $order_id ) );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException( 'Acceptance storage is unavailable.' );
			}
			$payload = is_string( $raw ) ? json_decode( $raw, true ) : null;
			// Explicit allowlist avoids exporting arbitrary metadata or untrusted fields.
			$acceptance = null;
			if ( is_array( $payload ) ) {
				$acceptance             = array_intersect_key( $payload, array_flip( array( 'document', 'snapshot', 'accepted_at', 'actor_id', 'payment_outcome' ) ) );
				$acceptance['document'] = array_intersect_key( (array) ( $payload['document'] ?? array() ), array_flip( array( 'version', 'text', 'hash', 'approval_ref', 'policy_url' ) ) );
				$acceptance['snapshot'] = self::scrub_snapshot( (array) ( $payload['snapshot'] ?? array() ) );
			}
			$orders[] = array(
				'order_id'             => $order_id,
				'relation'             => $relation['type'],
				'current_order_status' => $order ? $order->get_status() : 'unavailable',
				'acceptance'           => $acceptance,
				'acceptance_source'    => 'subscrpt_contract_acceptance',
				'acceptance_verified_against_current_order' => $order && is_array( $payload ) && \SpringDevs\Subscription\Frontend\ContractConsent::validate_frozen_acceptance( $payload, \SpringDevs\Subscription\Frontend\ContractConsent::order_snapshot( $order ) ),
				'missing'              => $acceptance ? array() : array( 'stored_explicit_contract_acceptance' ),
			);
		}
		return array(
			'schema'               => 1,
			'generated_at_utc'     => current_time( 'mysql', true ),
			'subscription_id'      => $subscription_id,
			'current_status'       => get_post_status( $subscription_id ),
			'cancellation_barrier' => $barrier,
			'events'               => array_slice( (array) $events, 0, 1000 ),
			'orders'               => $orders,
			'truncated'            => count( (array) $events ) > 1000 || count( (array) $relations ) > 100,
			'limitations'          => array( 'No stored record does not prove no historical cancellation attempt.', 'Acceptance, payment and fulfillment are separate evidence.', 'Current order status is a read-time observation, not immutable payment proof.', 'No external delivery, communication or dispute evidence is fetched or submitted.' ),
			'sources'              => array( 'subscrpt_cancellation_barrier', 'subscrpt_evidence_event', 'subscrpt_order_relation', 'subscrpt_contract_acceptance', 'WooCommerce CRUD' ),
		);
	}

	/**
	 * Keep nested order snapshots limited to contractual fields.
	 * @param array $snapshot Stored contractual snapshot.
	 */
	private static function scrub_snapshot( array $snapshot ): array {
		$clean          = array_intersect_key( $snapshot, array_flip( array( 'currency', 'total', 'shipping', 'discount' ) ) );
		$clean['items'] = array();
		foreach ( array_slice( (array) ( $snapshot['items'] ?? array() ), 0, 100 ) as $item ) {
			$line             = array_intersect_key( (array) $item, array_flip( array( 'product_id', 'variation_id', 'plan_id', 'quantity', 'initial_total', 'tax', 'signup_fee', 'payment_count' ) ) );
			$line['plan']     = array_intersect_key( (array) ( $item['plan'] ?? array() ), array_flip( array( 'price', 'time', 'type', 'trial' ) ) );
			$clean['items'][] = $line;
		}
		return $clean;
	}

	/** Staff must deliberately download an exact authorized subscription. */
	public function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'subscription' ), '', array( 'response' => 403 ) );
		}
		// Nonce action is bound to the exact scoped subscription ID below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0;
		check_admin_referer( 'subscrpt_evidence_export_' . $id );
		try {
			$report = self::build( $id );
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'Evidence could not be read safely. No records were changed.', 'subscription' ), '', array( 'response' => 503 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="subscription-' . $id . '-evidence.json"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP );
		exit;
	}
}

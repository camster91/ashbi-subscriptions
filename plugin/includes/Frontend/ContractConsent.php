<?php
/**
 * Versioned contractual consent, separate from automatic-renewal authorization.
 *
 * @package SpringDevs\Subscription\Frontend
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- Namespace autoloading preserves the public class path.

namespace SpringDevs\Subscription\Frontend;

/** No contract or historical acceptance is invented by installing this class. */
final class ContractConsent {

	/** Register classic checkout gates. Unsupported routes fail closed when enabled. */
	public function __construct() {
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic' ), 100, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'snapshot_order' ), 100 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'persist_acceptance' ), -100 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'guard_store_api' ), -100, 2 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'guard_order_pay' ), -100 );
	}

	/** Return only explicitly enabled, approved and internally consistent wording. */
	public static function approved_document(): ?array {
		$document = get_option( 'wp_subscription_contract_revision', array() );
		if ( ! is_array( $document ) || ! isset( $document['enabled'] ) || true !== $document['enabled'] ) {
			return null;
		}
		foreach ( array( 'version', 'text', 'hash', 'approval_ref' ) as $field ) {
			if ( ! isset( $document[ $field ] ) || ! is_string( $document[ $field ] ) || '' === trim( $document[ $field ] ) ) {
				return null;
			}
		}
		return hash_equals( hash( 'sha256', $document['text'] ), $document['hash'] ) ? $document : null;
	}

	/**
	 * Strict validation of a server-built snapshot and the displayed revision.
	 *
	 * @param mixed $accepted Explicit checkbox value.
	 * @param mixed $revision_hash Displayed document hash.
	 * @param array $snapshot Server-built plan snapshot.
	 * @param mixed $snapshot_hash Displayed snapshot hash.
	 */
	public static function validate_acceptance( $accepted, $revision_hash, array $snapshot, $snapshot_hash = '' ): bool {
		$document = self::approved_document();
		if ( ! $document || '1' !== $accepted || ! is_string( $revision_hash ) || ! hash_equals( $document['hash'], $revision_hash ) || ! is_string( $snapshot_hash ) || ! hash_equals( hash( 'sha256', wp_json_encode( $snapshot ) ), $snapshot_hash ) ) {
			return false;
		}
		if ( ! isset( $snapshot['currency'], $snapshot['items'] ) || ! is_string( $snapshot['currency'] ) || ! preg_match( '/^[A-Z]{3}$/', $snapshot['currency'] ) || ! is_array( $snapshot['items'] ) || ! $snapshot['items'] ) {
			return false;
		}
		foreach ( $snapshot['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				return false;
			}
			foreach ( array( 'product_id', 'variation_id', 'plan_id', 'quantity' ) as $field ) {
				if ( ! isset( $item[ $field ] ) || ! is_int( $item[ $field ] ) || $item[ $field ] < ( in_array( $field, array( 'product_id', 'quantity' ), true ) ? 1 : 0 ) ) {
					return false;
				}
			}
			$plan = $item['plan'] ?? null;
			if ( ! is_array( $plan ) || ! isset( $plan['price'], $plan['time'], $plan['type'] ) || ! is_numeric( $plan['price'] ) || ! is_finite( (float) $plan['price'] ) || (float) $plan['price'] < 0 || ! is_int( $plan['time'] ) || $plan['time'] < 1 || ! in_array( $plan['type'], array( 'days', 'weeks', 'months', 'years' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/** Installing the feature never enables it; malformed enabled config fails closed. */
	private static function enabled(): bool {
		$config = get_option( 'wp_subscription_contract_revision', array() );
		return is_array( $config ) && true === ( $config['enabled'] ?? false );
	}

	/** Freeze canonical cart plan data, not customer-supplied contract text. */
	public static function cart_snapshot(): array {
		$items = array();
		foreach ( WC()->cart->get_cart() as $line ) {
			if ( ! isset( $line['subscription'] ) ) {
				continue;
			}
			$terms   = $line['subscription'];
			$plan    = array(
				'price' => $terms['per_cost'] ?? null,
				'time'  => (int) ( $terms['time'] ?? 1 ),
				'type'  => $terms['type'] ?? '',
				'trial' => $terms['trial'] ?? null,
			);
			$items[] = array(
				'product_id'    => (int) $line['product_id'],
				'variation_id'  => (int) ( $line['variation_id'] ?? 0 ),
				'plan_id'       => (int) ( $line['subscrpt_plan_id'] ?? 0 ),
				'quantity'      => (int) $line['quantity'],
				'plan'          => $plan,
				'initial_total' => (string) ( $line['line_total'] ?? '' ),
				'tax'           => (string) ( $line['line_tax'] ?? '' ),
				'signup_fee'    => (string) ( $line['subscrpt_signup_fee'] ?? $terms['signup_fee'] ?? 0 ),
				'payment_count' => (int) ( $line['subscrpt_max_no_payment'] ?? $terms['max_no_payment'] ?? $line['data']->get_meta( '_subscrpt_max_no_payment' ) ),
			);
		}
		return array(
			'currency' => get_woocommerce_currency(),
			'items'    => $items,
			'total'    => (string) WC()->cart->get_total( 'edit' ),
			'shipping' => (string) WC()->cart->get_shipping_total(),
			'discount' => (string) WC()->cart->get_discount_total(),
		);
	}

	/** Exact text is rendered as plain text; no hidden HTML clauses or precheck. */
	public function render(): void {
		$snapshot = self::cart_snapshot();
		if ( ! self::enabled() || ! $snapshot['items'] ) {
			return;
		}
		$document = self::approved_document();
		if ( ! $document ) {
			echo '<p>' . esc_html__( 'Subscription terms are awaiting store review. Checkout is unavailable for this subscription.', 'subscription' ) . '</p>';
			return;
		}
		echo '<section aria-labelledby="ashbi-contract-title"><h3 id="ashbi-contract-title">' . esc_html__( 'Subscription terms', 'subscription' ) . '</h3>';
		echo '<pre style="white-space:pre-wrap">' . esc_html( $document['text'] ) . '</pre>';
		// translators: %s: reviewed terms revision identifier.
		echo '<p>' . esc_html( sprintf( __( 'Terms version: %s', 'subscription' ), $document['version'] ) ) . '</p>';
		echo '<label><input type="checkbox" name="ashbi_contract_accepted" value="1" required> ' . esc_html__( 'I accept the subscription terms shown above.', 'subscription' ) . '</label>';
		echo '<input type="hidden" name="ashbi_contract_hash" value="' . esc_attr( $document['hash'] ) . '">';
		echo '<input type="hidden" name="ashbi_contract_snapshot" value="' . esc_attr( hash( 'sha256', wp_json_encode( $snapshot ) ) ) . '"></section>';
	}

	/**
	 * Gate before classic order/payment commitment, including direct POST attempts.
	 *
	 * @param array     $data WooCommerce checkout data.
	 * @param \WP_Error $errors Checkout validation errors.
	 */
	public function validate_classic( $data, $errors ): void {
		$snapshot = self::cart_snapshot();
		if ( self::enabled() && $snapshot['items'] && ! $this->posted_acceptance( $snapshot ) ) {
			$errors->add( 'ashbi_contract_consent', __( 'Review the current subscription terms and explicitly accept them before placing your order.', 'subscription' ) );
		}
	}

	/**
	 * Read consent only after WooCommerce's own authenticated checkout validation.
	 *
	 * @param array $snapshot Server-built plan snapshot.
	 */
	private function posted_acceptance( array $snapshot ): bool {
		// WooCommerce validates checkout session/nonce before invoking these hooks.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return self::validate_acceptance( isset( $_POST['ashbi_contract_accepted'] ) ? wp_unslash( $_POST['ashbi_contract_accepted'] ) : '', isset( $_POST['ashbi_contract_hash'] ) ? wp_unslash( $_POST['ashbi_contract_hash'] ) : '', $snapshot, isset( $_POST['ashbi_contract_snapshot'] ) ? wp_unslash( $_POST['ashbi_contract_snapshot'] ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Persist the accepted revision with the order before a gateway is invoked.
	 *
	 * @param \WC_Order $order Checkout order.
	 * @throws \Exception When explicit acceptance is unavailable.
	 */
	public function snapshot_order( $order ): void {
		$snapshot = self::cart_snapshot();
		if ( ! self::enabled() || ! $snapshot['items'] ) {
			return;
		}
		if ( ! $this->posted_acceptance( $snapshot ) ) {
			throw new \Exception( esc_html__( 'Subscription terms acceptance is missing or out of date.', 'subscription' ) );
		}
		$order->update_meta_data(
			'_ashbi_contract_consent',
			array(
				'document'        => self::approved_document(),
				'snapshot'        => $snapshot,
				'accepted_at'     => current_time( 'mysql', true ),
				'actor_id'        => get_current_user_id(),
				'payment_outcome' => 'not_confirmed',
			)
		);
	}

	/**
	 * Append immutable acceptance before the existing subscription/payment callbacks.
	 *
	 * @param int $order_id Checkout order ID.
	 * @throws \Exception When immutable acceptance cannot be verified or saved.
	 */
	public function persist_acceptance( $order_id ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! \SpringDevs\Subscription\Illuminate\Helper::order_has_subscription_item( $order ) ) {
			return;
		}
		$payload = $order->get_meta( '_ashbi_contract_consent' );
		if ( ! is_array( $payload ) || empty( $payload['document']['hash'] ) || ! self::validate_acceptance( '1', $payload['document']['hash'], $payload['snapshot'], hash( 'sha256', wp_json_encode( $payload['snapshot'] ) ) ) ) {
			throw new \Exception( esc_html__( 'Subscription terms acceptance could not be verified.', 'subscription' ) );
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'subscrpt_contract_acceptance';
		$json   = wp_json_encode( $payload );
		$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (order_id, actor_id, accepted_at, revision_hash, payload) VALUES (%d, %d, %s, %s, %s)', array( $table, (int) $order_id, (int) $payload['actor_id'], $payload['accepted_at'], $payload['document']['hash'], $json ) ) );
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE order_id = %d', $table, (int) $order_id ) );
		if ( false === $result || ! is_string( $stored ) || ! hash_equals( $json, $stored ) ) {
			throw new \Exception( esc_html__( 'Subscription terms acceptance could not be saved safely. No payment was requested by this checkout.', 'subscription' ) );
		}
	}

	/**
	 * No unsupported accelerated/Blocks route can silently bypass the gate.
	 *
	 * @param \WC_Order $order Store API order.
	 * @throws \Exception When an unsupported subscription checkout is attempted.
	 */
	public function guard_store_api( $order ): void {
		if ( self::enabled() && ( self::cart_snapshot()['items'] || \SpringDevs\Subscription\Illuminate\Helper::order_has_subscription_item( $order ) ) ) {
			throw new \Exception( esc_html__( 'This subscription requires reviewed terms through the standard checkout. This checkout route has not been enabled by the store.', 'subscription' ) );
		}
	}

	/**
	 * Old orders must never acquire inferred consent through a payment retry.
	 *
	 * @param \WC_Order $order Order being paid.
	 */
	public function guard_order_pay( $order ): void {
		if ( self::enabled() && $order && \SpringDevs\Subscription\Illuminate\Helper::order_has_subscription_item( $order ) ) {
			$this->persist_acceptance( $order->get_id() );
		}
	}
}

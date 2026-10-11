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
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'stamp_line' ), 100, 3 );
		add_filter( 'woocommerce_order_needs_payment', array( $this, 'order_can_pay' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'persist_acceptance' ), -100 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'guard_store_api' ), -100, 2 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'guard_order_pay' ), -100 );
		add_filter( 'wc_stripe_show_payment_request_on_cart', array( $this, 'express_allowed' ), 100 );
		add_filter( 'wc_stripe_show_payment_request_on_checkout', array( $this, 'express_allowed' ), 100 );
		add_filter( 'wc_stripe_hide_payment_request_on_product_page', array( $this, 'hide_express_product' ), 100 );
		add_filter( 'wc_stripe_generate_create_intent_request', array( $this, 'guard_stripe_intent' ), 100, 3 );
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
		return self::valid_policy_link( $document ) && hash_equals( self::document_hash( $document ), $document['hash'] ) ? $document : null;
	}

	/**
	 * Bind an optional reviewed policy link to the wording shown before acceptance.
	 *
	 * @param array $document Reviewed wording and optional policy URL.
	 */
	public static function document_hash( array $document ): string {
		$text = (string) ( $document['text'] ?? '' );
		return hash( 'sha256', array_key_exists( 'policy_url', $document ) ? (string) wp_json_encode( array( 'text' => $text, 'policy_url' => $document['policy_url'] ) ) : $text );
	}

	/**
	 * Preserve legacy documents; reject unsafe or ambiguous new policy links.
	 *
	 * @param array $document Reviewed wording and optional policy URL.
	 */
	private static function valid_policy_link( array $document ): bool {
		if ( ! array_key_exists( 'policy_url', $document ) ) {
			return true;
		}
		$url = $document['policy_url'];
		if ( ! is_string( $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$parts = parse_url( $url );
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
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
		return self::valid_snapshot( $snapshot );
	}

	/**
	 * Validate preserved acceptance against actual unchanged order terms.
	 * The caller must additionally verify this payload against its immutable ledger.
	 *
	 * @param array $payload Previously stored acceptance payload.
	 * @param array $actual_snapshot Actual order terms before payment.
	 */
	public static function validate_frozen_acceptance( array $payload, array $actual_snapshot ): bool {
		$document = $payload['document'] ?? null;
		if ( ! is_array( $document ) || ! isset( $payload['snapshot'] ) || ! is_array( $payload['snapshot'] ) ) {
			return false;
		}
		foreach ( array( 'version', 'text', 'hash', 'approval_ref' ) as $field ) {
			if ( ! isset( $document[ $field ] ) || ! is_string( $document[ $field ] ) || '' === trim( $document[ $field ] ) ) {
				return false;
			}
		}
		return self::valid_policy_link( $document ) && hash_equals( self::document_hash( $document ), $document['hash'] ) && self::valid_snapshot( $actual_snapshot ) && hash_equals( hash( 'sha256', wp_json_encode( $payload['snapshot'] ) ), hash( 'sha256', wp_json_encode( $actual_snapshot ) ) );
	}

	/**
	 * Validate a normalized purchased plan snapshot without reading live revisions.
	 *
	 * @param array $snapshot Purchased terms.
	 */
	private static function valid_snapshot( array $snapshot ): bool {
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

	/** Reviewed contract checkout currently uses the standard consent form. */
	public function express_allowed( $allowed ): bool {
		return self::enabled() ? false : (bool) $allowed;
	}

	/** Disable product wallets while consent is enabled, before plan selection. */
	public function hide_express_product( $hidden ): bool {
		return self::enabled() || (bool) $hidden;
	}

	/** Guard supported Stripe intent creation independently of wallet visibility. */
	public function guard_stripe_intent( $request, $order, $source ) {
		if ( ! $order ) {
			return $request;
		}
		$required = '1' === $order->get_meta( '_ashbi_contract_required' ) || $order->get_meta( '_ashbi_contract_consent' );
		$new_cart = self::enabled() && WC()->cart && self::cart_snapshot()['items'];
		if ( ( $new_cart && ! $required ) || ( $required && ! $this->order_can_pay( true, $order ) ) ) {
			throw new \Exception( esc_html__( 'The subscription payment is missing verified acceptance of its final order terms.', 'subscription' ) );
		}
		return $request;
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
				'price' => isset( $terms['per_cost'] ) ? self::money( $terms['per_cost'] ) : null,
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
				'initial_total' => self::money( $line['line_total'] ?? 0 ),
				'tax'           => self::money( $line['line_tax'] ?? 0 ),
				'signup_fee'    => self::money( $line['subscrpt_signup_fee'] ?? $terms['signup_fee'] ?? 0 ),
				'payment_count' => (int) ( $line['subscrpt_max_no_payment'] ?? $terms['max_no_payment'] ?? $line['data']->get_meta( '_subscrpt_max_no_payment' ) ),
				'payment_type' => (string) ( $line['subscrpt_payment_type'] ?? ( isset( $line['data'] ) ? $line['data']->get_meta( '_subscrpt_payment_type' ) : '' ) ) ?: 'recurring',
				'billing_length' => (int) ( $line['subscrpt_billing_length'] ?? 0 ),
				'plan_total' => self::money( $line['subscrpt_plan_total'] ?? 0 ),
			);
		}
		return array(
			'currency' => get_woocommerce_currency(),
			'items'    => $items,
			'total'    => self::money( WC()->cart->get_total( 'edit' ) ),
			'shipping' => self::money( WC()->cart->get_shipping_total() ),
			'discount' => self::money( WC()->cart->get_discount_total() ),
		);
	}

	/** Normalize monetary serialization for cart and WooCommerce CRUD values. */
	private static function money( $value ): string {
		return wc_format_decimal( $value, wc_get_price_decimals() );
	}

	/** Freeze server cart terms on each line before gateway callbacks. */
	public function stamp_line( $item, $cart_key, $line ): void {
		if ( ! isset( $line['subscription'] ) || ! self::enabled() ) {
			return;
		}
		$terms = $line['subscription'];
		$item->update_meta_data( '_ashbi_contract_plan', array(
			'plan_id' => (int) ( $line['subscrpt_plan_id'] ?? 0 ),
			'plan' => array( 'price' => isset( $terms['per_cost'] ) ? self::money( $terms['per_cost'] ) : null, 'time' => (int) ( $terms['time'] ?? 1 ), 'type' => $terms['type'] ?? '', 'trial' => $terms['trial'] ?? null ),
			'signup_fee' => self::money( $line['subscrpt_signup_fee'] ?? $terms['signup_fee'] ?? 0 ),
			'payment_count' => (int) ( $line['subscrpt_max_no_payment'] ?? $terms['max_no_payment'] ?? $line['data']->get_meta( '_subscrpt_max_no_payment' ) ),
			'payment_type' => (string) ( $line['subscrpt_payment_type'] ?? ( isset( $line['data'] ) ? $line['data']->get_meta( '_subscrpt_payment_type' ) : '' ) ) ?: 'recurring',
			'billing_length' => (int) ( $line['subscrpt_billing_length'] ?? 0 ),
			'plan_total' => self::money( $line['subscrpt_plan_total'] ?? 0 ),
		) );
		if ( empty( $line['subscrpt_plan_id'] ) ) {
			$item->update_meta_data( '_subscrpt_meta', array( 'time' => (int) ( $terms['time'] ?? 1 ), 'type' => $terms['type'] ?? '', 'trial' => $terms['trial'] ?? null ) );
			$item->update_meta_data( '_subscrpt_plan_price', self::money( $terms['per_cost'] ?? 0 ) );
			$item->update_meta_data( '_subscrpt_signup_fee', self::money( $line['subscrpt_signup_fee'] ?? $terms['signup_fee'] ?? 0 ) );
			$item->update_meta_data( '_subscrpt_max_no_payment', (int) ( $line['subscrpt_max_no_payment'] ?? $terms['max_no_payment'] ?? $line['data']->get_meta( '_subscrpt_max_no_payment' ) ) );
			$item->update_meta_data( '_subscrpt_payment_type', (string) ( $line['subscrpt_payment_type'] ?? ( isset( $line['data'] ) ? $line['data']->get_meta( '_subscrpt_payment_type' ) : '' ) ) ?: 'recurring' );
			$item->update_meta_data( '_subscrpt_billing_length', (int) ( $line['subscrpt_billing_length'] ?? 0 ) );
			$item->update_meta_data( '_subscrpt_plan_total', self::money( $line['subscrpt_plan_total'] ?? 0 ) );
		}
	}

	/** Build payment terms from the final order, not its acceptance payload. */
	public static function order_snapshot( $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$stamp = $item->get_meta( '_ashbi_contract_plan' );
			if ( ! is_array( $stamp ) ) {
				continue;
			}
			$terms = $item->get_meta( '_subscrpt_plan_terms' );
			$plan = $stamp['plan'];
			$plan_id = (int) $stamp['plan_id'];
			$fee = $stamp['signup_fee'];
			$count = (int) $stamp['payment_count'];
			if ( $plan_id > 0 ) {
				// PlanCheckout persists these canonical terms independently of our stamp.
				$plan_id = (int) $item->get_meta( '_subscrpt_plan_id' );
				$plan = array( 'price' => self::money( $item->get_meta( '_subscrpt_plan_price' ) ), 'time' => (int) ( $terms['time'] ?? 0 ), 'type' => $terms['type'] ?? '', 'trial' => $terms['trial'] ?? null );
				$fee = self::money( $item->get_meta( '_subscrpt_signup_fee' ) );
				$count = (int) $item->get_meta( '_subscrpt_max_no_payment' );
			} else {
				$terms = $item->get_meta( '_subscrpt_meta' );
				$plan = array( 'price' => self::money( $item->get_meta( '_subscrpt_plan_price' ) ), 'time' => (int) ( $terms['time'] ?? 0 ), 'type' => $terms['type'] ?? '', 'trial' => $terms['trial'] ?? null );
				$fee = self::money( $item->get_meta( '_subscrpt_signup_fee' ) );
				$count = (int) $item->get_meta( '_subscrpt_max_no_payment' );
			}
			$items[] = array( 'product_id' => (int) $item->get_product_id(), 'variation_id' => (int) $item->get_variation_id(), 'plan_id' => $plan_id, 'quantity' => (int) $item->get_quantity(), 'plan' => $plan, 'initial_total' => self::money( $item->get_total() ), 'tax' => self::money( $item->get_total_tax() ), 'signup_fee' => $fee, 'payment_count' => $count, 'payment_type' => (string) $item->get_meta( '_subscrpt_payment_type' ) ?: 'recurring', 'billing_length' => (int) $item->get_meta( '_subscrpt_billing_length' ), 'plan_total' => self::money( $item->get_meta( '_subscrpt_plan_total' ) ) );
		}
		return array( 'currency' => $order->get_currency(), 'items' => $items, 'total' => self::money( $order->get_total() ), 'shipping' => self::money( $order->get_shipping_total() ), 'discount' => self::money( $order->get_discount_total() ) );
	}

	/** Read the immutable ledger; eligibility checks never write evidence. */
	private static function stored_payload( $order_id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE order_id = %d', $wpdb->prefix . 'subscrpt_contract_acceptance', (int) $order_id ) );
	}

	/** Existing required orders remain protected even after feature deactivation. */
	public function order_can_pay( $needs_payment, $order ): bool {
		if ( ! $needs_payment || ! $order ) {
			return false;
		}
		$payload = $order->get_meta( '_ashbi_contract_consent' );
		if ( '1' !== $order->get_meta( '_ashbi_contract_required' ) && ! $payload ) {
			return (bool) $needs_payment;
		}
		$stored = self::stored_payload( $order->get_id() );
		return is_array( $payload ) && is_string( $stored ) && hash_equals( wp_json_encode( $payload ), $stored ) && self::validate_frozen_acceptance( $payload, self::order_snapshot( $order ) );
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
		if ( isset( $document['policy_url'] ) ) {
			echo '<p><a href="' . esc_url( $document['policy_url'] ) . '">' . esc_html__( 'Shipping / Refund Policy', 'subscription' ) . '</a></p>';
		}
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
		$order->update_meta_data( '_ashbi_contract_required', '1' );
		$existing = $order->get_meta( '_ashbi_contract_consent' );
		if ( is_array( $existing ) ) {
			if ( wp_json_encode( $existing['document'] ?? null ) !== wp_json_encode( self::approved_document() ) || ! self::validate_frozen_acceptance( $existing, $snapshot ) ) {
				throw new \Exception( esc_html__( 'The order changed after terms acceptance. Please start a new checkout.', 'subscription' ) );
			}
			return;
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
		$order = wc_get_order( $order_id );
		if ( ! $order || ( '1' !== $order->get_meta( '_ashbi_contract_required' ) && ! $order->get_meta( '_ashbi_contract_consent' ) ) ) {
			return;
		}
		$payload = $order->get_meta( '_ashbi_contract_consent' );
		if ( ! is_array( $payload ) || ! self::validate_frozen_acceptance( $payload, self::order_snapshot( $order ) ) ) {
			throw new \Exception( esc_html__( 'Subscription terms acceptance could not be verified.', 'subscription' ) );
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'subscrpt_contract_acceptance';
		$json   = wp_json_encode( $payload );
		$stored = self::stored_payload( $order_id );
		if ( is_string( $stored ) && hash_equals( $json, $stored ) ) {
			return;
		}
		$approved = self::approved_document();
		if ( $stored || ! $approved || wp_json_encode( $approved ) !== wp_json_encode( $payload['document'] ) || '1' !== $order->get_meta( '_ashbi_contract_required' ) ) {
			throw new \Exception( esc_html__( 'Subscription acceptance is not an approved unchanged purchase.', 'subscription' ) );
		}
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
		if ( $order && ! $this->order_can_pay( true, $order ) ) {
			wc_add_notice( __( 'The subscription order no longer matches its accepted terms. Contact the store before payment.', 'subscription' ), 'error' );
		}
	}
}

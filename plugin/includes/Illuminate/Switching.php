<?php
/**
 * Subscription plan switching.
 *
 * A switch is a new WooCommerce order whose line item carries the requested
 * target. The existing subscription is changed only after that order is paid;
 * the line-item marker makes the application safe when WooCommerce fires both
 * status and payment-complete hooks for the same order.
 *
 * @package SpringDevs\Subscription
 */

// PSR-4 class filename is retained for the existing plugin autoloader.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Illuminate;

use SpringDevs\Subscription\Admin\PlanPresenter;
use SpringDevs\Subscription\Illuminate\Plans\PlanRepository;
use SpringDevs\Subscription\Illuminate\Subscription\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Own the customer switch flow and paid-order reconciliation.
 */
class Switching {

	/**
	 * Register switch hooks.
	 */
	public function __construct() {
		add_action( 'before_single_subscrpt_content', array( $this, 'handle_request' ), 1 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_switch_fee' ), 25 );
		add_action( 'woocommerce_payment_complete', array( $this, 'apply_order' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'apply_order' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'apply_order' ), 20 );
	}

	/**
	 * Whether switching is enabled by the store owner.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return in_array( get_option( 'subscrpt_switch_enabled', '0' ), array( 1, '1', true, 'yes' ), true );
	}

	/**
	 * Whether the current user may access switching for a subscription.
	 *
	 * Guest-owned records deliberately have no customer owner. Only an
	 * authenticated owner or an authenticated administrator with the explicit
	 * store-management capability may use the switching surface.
	 *
	 * @param int $subscription_id Subscription id.
	 * @return bool
	 */
	public static function can_switch_subscription( int $subscription_id ): bool {
		$post = get_post( $subscription_id );
		if ( ! $post || 'subscrpt_order' !== $post->post_type ) {
			return false;
		}

		$owner_id = (int) $post->post_author;
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! is_user_logged_in() || $owner_id <= 0 ) {
			return false;
		}

		return $owner_id === (int) get_current_user_id();
	}

	/**
	 * Return available subscription targets for the account form.
	 *
	 * @param int $subscription_id Current subscription.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_options( int $subscription_id ): array {
		if ( ! self::can_switch_subscription( $subscription_id ) || ! self::is_enabled() || 'active' !== get_post_status( $subscription_id ) || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$current_product   = (int) get_post_meta( $subscription_id, '_subscrpt_product_id', true );
		$current_variation = (int) get_post_meta( $subscription_id, '_subscrpt_variation_id', true );
		$current_plan      = (int) get_post_meta( $subscription_id, '_subscrpt_plan_id', true );
		$options           = array();

		$products = wc_get_products(
			array(
				'limit'  => 100,
				'status' => 'publish',
				'type'   => array( 'simple', 'variable' ),
			)
		);

		foreach ( $products as $product ) {
			if ( ! $product ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation ) {
						self::append_option( $options, $product, (int) $variation_id, $subscription_id, $current_product, $current_variation, $current_plan, $variation );
					}
				}
				continue;
			}
			self::append_option( $options, $product, 0, $subscription_id, $current_product, $current_variation, $current_plan );
		}

		return array_slice( $options, 0, 100 );
	}

	/**
	 * Append one simple product, variation, or plan target.
	 *
	 * @param array       $options           Options by reference.
	 * @param object      $product            Parent/simple product.
	 * @param int         $variation_id       Variation id.
	 * @param int         $subscription_id    Current subscription id.
	 * @param int         $current_product    Current parent product id.
	 * @param int         $current_variation  Current variation id.
	 * @param int         $current_plan       Current plan id.
	 * @param object|null $variation     Variation object.
	 * @return void
	 */
	private static function append_option( array &$options, $product, int $variation_id, int $subscription_id, int $current_product, int $current_variation, int $current_plan, $variation = null ): void {
		$entity       = $variation ? $variation : $product;
		$parent_id    = (int) $product->get_id();
		$plan_rows    = function_exists( 'subscrpt_plan_offered' ) && subscrpt_plan_offered( $parent_id, $variation_id )
			? PlanRepository::resolve_for_product( $parent_id, $variation_id )
			: array();

		if ( ! empty( $plan_rows ) ) {
			foreach ( $plan_rows as $row ) {
				$plan_id = (int) $row['plan_id'];
				if ( $parent_id === $current_product && $variation_id === $current_variation && $plan_id === $current_plan ) {
					continue;
				}

				$target = self::target_from_row( $parent_id, $variation_id, $row );
				$options[] = array(
					'label'          => self::target_label( $entity, $row['plan_title'] ?? '', $target['price'] ),
					'product_id'     => $parent_id,
					'variation_id'   => $variation_id,
					'plan_id'        => $plan_id,
					'price'          => $target['price'],
				);
			}
			return;
		}

		$wrapped = Subscription::get_subs_product( $entity );
		if ( ! $wrapped || ! $wrapped->is_enabled() || ( $parent_id === $current_product && $variation_id === $current_variation && 0 === $current_plan ) ) {
			return;
		}

		$options[] = array(
			'label'        => self::target_label( $entity, '', (float) $entity->get_price() ),
			'product_id'   => $parent_id,
			'variation_id' => $variation_id,
			'plan_id'      => 0,
			'price'        => (float) $entity->get_price(),
		);
	}

	/**
	 * Build a human-readable target label.
	 *
	 * @param object $product Product or variation.
	 * @param string $plan_title Plan title.
	 * @param float  $price Recurring price.
	 * @return string
	 */
	private static function target_label( $product, string $plan_title, float $price ): string {
		$label = $product->get_name();
		if ( $plan_title ) {
			$label .= ' — ' . $plan_title;
		}
		if ( function_exists( 'wc_price' ) ) {
			$label .= ' (' . wp_strip_all_tags( wc_price( $price ) ) . ')';
		}
		return $label;
	}

	/**
	 * Resolve and validate a requested target.
	 *
	 * @param int $product_id Parent/simple product id.
	 * @param int $variation_id Variation id, or 0.
	 * @param int $plan_id Plan id, or 0 for classic product data.
	 * @return array<string,mixed>|false
	 */
	public static function resolve_target( int $product_id, int $variation_id = 0, int $plan_id = 0 ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return false;
		}

		if ( $variation_id ) {
			if ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $product_id ) {
				return false;
			}
		} elseif ( $product->is_type( 'variable' ) ) {
			return false;
		}

		$rows = function_exists( 'subscrpt_plan_offered' ) && subscrpt_plan_offered( $product_id, $variation_id )
			? PlanRepository::resolve_for_product( $product_id, $variation_id )
			: array();
		if ( ! empty( $rows ) ) {
			if ( ! $plan_id ) {
				$plan_id = (int) $rows[0]['plan_id'];
			}
			foreach ( $rows as $row ) {
				if ( (int) $row['plan_id'] === $plan_id ) {
					$target = self::target_from_row( $product_id, $variation_id, $row );
					$target['product'] = $product;
					return $target;
				}
			}
			return false;
		}

		$wrapped = Subscription::get_subs_product( $product );
		if ( $plan_id || ! $wrapped || ! $wrapped->is_enabled() ) {
			return false;
		}

		return array(
			'product'      => $product,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'plan_id'      => 0,
			'group_id'     => 0,
			'price'        => (float) $product->get_price(),
			'payment_type' => (string) ( get_post_meta( $product->get_id(), '_subscrpt_payment_type', true ) ? get_post_meta( $product->get_id(), '_subscrpt_payment_type', true ) : 'recurring' ),
			'max_payments' => (int) get_post_meta( $product->get_id(), '_subscrpt_max_no_payment', true ),
			'billing_length' => (int) get_post_meta( $product->get_id(), '_subscrpt_billing_length', true ),
			'terms'        => array(
				'time'  => max( 1, (int) get_post_meta( $product->get_id(), '_subscrpt_timing_per', true ) ),
				'type'  => (string) ( get_post_meta( $product->get_id(), '_subscrpt_timing_option', true ) ? get_post_meta( $product->get_id(), '_subscrpt_timing_option', true ) : 'months' ),
				'trial' => get_post_meta( $product->get_id(), '_subscrpt_trial_timing_per', true )
					? (int) get_post_meta( $product->get_id(), '_subscrpt_trial_timing_per', true ) . ' ' . (string) ( get_post_meta( $product->get_id(), '_subscrpt_trial_timing_option', true ) ? get_post_meta( $product->get_id(), '_subscrpt_trial_timing_option', true ) : 'days' )
					: null,
			),
			'plan_data'    => array(),
		);
	}

	/**
	 * Convert a plan row to the recurring price snapshot used by a switch.
	 *
	 * @param int   $product_id Parent product id.
	 * @param int   $variation_id Variation id.
	 * @param array $row Plan row.
	 * @return array<string,mixed>
	 */
	private static function target_from_row( int $product_id, int $variation_id, array $row ): array {
		$data       = is_array( $row['relation_data'] ?? null ) ? $row['relation_data'] : array();
		$regular    = (string) ( $data['regular_price'] ?? '' );
		$selling    = (string) ( $data['sale_price'] ?? '' );
		$discount   = (string) ( $data['discount_type'] ?? 'percentage' );
		$discount_v = (string) ( $data['discount_value'] ?? '0' );
		$total      = (float) PlanPresenter::offer_price( $regular, $selling, $discount, $discount_v );
		$type       = PlanRepository::type_to_string( (int) ( $row['group_type'] ?? 0 ) );
		$max        = 'installments' === $type ? max( 2, (int) ( $row['plan_data']['installment_count'] ?? 2 ) ) : 0;
		$price      = $max > 1 && function_exists( 'subscrpt_split_amounts' )
			? (float) subscrpt_split_amounts( $total, $max )['per_installment']
			: $total;
		$terms      = array(
			'time'  => max( 1, (int) ( $row['billing_frequency'] ?? 1 ) ),
			'type'  => PlanRepository::interval_to_option( (int) ( $row['billing_interval'] ?? 0 ) ),
			'trial' => ! empty( $row['free_trial'] ) ? (int) $row['free_trial'] . ' ' . ( $row['plan_data']['free_trial_interval'] ?? 'days' ) : null,
		);

		return array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'plan_id'      => (int) $row['plan_id'],
			'group_id'     => (int) $row['plan_group_id'],
			'price'        => $price,
			'payment_type' => 'installments' === $type ? 'split_payment' : 'recurring',
			'max_payments' => $max,
			'billing_length' => (int) ( $row['billing_length'] ?? 0 ),
			'terms'        => $terms,
			'plan_data'    => is_array( $row['plan_data'] ?? null ) ? $row['plan_data'] : array(),
			'signup_fee'  => (float) ( $row['signup_fee']['amount'] ?? 0 ),
		);
	}

	/**
	 * Handle the account switch form before the subscription template renders.
	 *
	 * @return void
	 */
	public function handle_request(): void {
		if ( empty( $_POST['subscrpt_switch_submit'] ) ) {
			return;
		}

		$subscription_id = absint( $_POST['subscrpt_id'] ?? 0 );
		$nonce            = sanitize_text_field( wp_unslash( $_POST['subscrpt_switch_nonce'] ?? '' ) );
		if ( ! $subscription_id || ! wp_verify_nonce( $nonce, 'subscrpt_switch_' . $subscription_id ) ) {
			wc_add_notice( __( 'This switch request could not be verified.', 'subscription' ), 'error' );
			return;
		}

		if ( ! self::can_switch_subscription( $subscription_id ) ) {
			wc_add_notice( __( 'You are not allowed to change this subscription.', 'subscription' ), 'error' );
			return;
		}
		if ( ! self::is_enabled() || 'active' !== get_post_status( $subscription_id ) ) {
			wc_add_notice( __( 'This subscription cannot be switched right now.', 'subscription' ), 'error' );
			return;
		}

		$target_parts = explode( ':', sanitize_text_field( wp_unslash( $_POST['switch_target'] ?? '' ) ) );
		$product_id   = absint( $_POST['switch_product_id'] ?? ( $target_parts[0] ?? 0 ) );
		$variation_id = absint( $_POST['switch_variation_id'] ?? ( $target_parts[1] ?? 0 ) );
		$plan_id      = absint( $_POST['switch_plan_id'] ?? ( $target_parts[2] ?? 0 ) );
		$target       = self::resolve_target( $product_id, $variation_id, $plan_id );
		if ( ! $target ) {
			wc_add_notice( __( 'The selected subscription target is no longer available.', 'subscription' ), 'error' );
			return;
		}

		$old_price = (float) get_post_meta( $subscription_id, '_subscrpt_price', true );
		$type      = $target['price'] >= $old_price ? 'upgrade' : 'downgrade';
		if ( 'downgrade' === $type && ! in_array( get_option( 'subscrpt_downgrade_allowed', '1' ), array( 1, '1', true, 'yes' ), true ) ) {
			wc_add_notice( __( 'Downgrades are not enabled for this store.', 'subscription' ), 'error' );
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wc_add_notice( __( 'The checkout is not available right now.', 'subscription' ), 'error' );
			return;
		}

		WC()->cart->empty_cart();
		$cart_data = array(
			'wp_subs_switch'  => true,
			'switch_context'  => array(
				'switch_type'      => $type,
				'subscription_id'  => $subscription_id,
				'product_id'      => $product_id,
				'old_variation_id' => (int) get_post_meta( $subscription_id, '_subscrpt_variation_id', true ),
				'new_variation_id' => $variation_id,
				'plan_id'          => $plan_id,
				'target_price'     => (float) $target['price'],
			),
		);
		if ( $plan_id ) {
			$cart_data['subscrpt_plan_id'] = $plan_id;
		}

		$added = WC()->cart->add_to_cart( $product_id, 1, $variation_id, array(), $cart_data );
		if ( ! $added ) {
			wc_add_notice( __( 'The selected subscription could not be added to checkout.', 'subscription' ), 'error' );
			return;
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Add the configured one-time switch fee to the switch cart.
	 *
	 * @param \WC_Cart $cart Cart object.
	 * @return void
	 */
	public function add_switch_fee( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$target_total = 0.0;
		$has_switch   = false;
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['wp_subs_switch'] ) ) {
				$has_switch   = true;
				$target_total += (float) ( $item['subscrpt_plan_price'] ?? $item['data']->get_price() ) * max( 1, (int) $item['quantity'] );
			}
		}
		if ( ! $has_switch ) {
			return;
		}

		$amount = (float) get_option( 'subscrpt_switch_fee_amount', '0' );
		if ( $amount <= 0 ) {
			return;
		}
		if ( 'percent' === get_option( 'subscrpt_switch_fee_type', 'flat' ) ) {
			$amount = $target_total * $amount / 100;
		}
		$cart->add_fee( __( 'Subscription switch fee', 'subscription' ), $amount, false );
	}

	/**
	 * Apply every paid switch item in an order.
	 *
	 * @param int|\WC_Order $order Order id or object.
	 * @return void
	 */
	public function apply_order( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! in_array( $item->get_meta( '_wp_subs_switch' ), array( true, 1, '1' ), true ) ) {
				continue;
			}
			$this->apply_item( $order, $item );
		}
	}

	/**
	 * Apply one switch item exactly once.
	 *
	 * @param \WC_Order              $order Paid order.
	 * @param \WC_Order_Item_Product $item Switch line item.
	 * @return bool
	 */
	private function apply_item( \WC_Order $order, $item ): bool {
		if ( in_array( $item->get_meta( '_subscrpt_switch_applied' ), array( true, 1, '1' ), true ) ) {
			return true;
		}

		$context         = $item->get_meta( '_wp_subs_switch_context' );
		$subscription_id = absint( is_array( $context ) ? ( $context['subscription_id'] ?? 0 ) : 0 );
		$product_id      = absint( is_array( $context ) ? ( $context['product_id'] ?? 0 ) : $item->get_product_id() );
		$variation_id    = absint( is_array( $context ) ? ( $context['new_variation_id'] ?? 0 ) : $item->get_variation_id() );
		$plan_id         = absint( is_array( $context ) ? ( $context['plan_id'] ?? 0 ) : $item->get_meta( '_subscrpt_plan_id' ) );
		$target          = self::resolve_target( $product_id, $variation_id, $plan_id );
		$post            = get_post( $subscription_id );
		$owner_id        = $post ? (int) $post->post_author : 0;
		$order_customer_id = (int) $order->get_customer_id();

		if ( ! $subscription_id || ! $target || ! $post || 'subscrpt_order' !== $post->post_type || 'active' !== get_post_status( $subscription_id ) || $owner_id <= 0 || $order_customer_id <= 0 || $owner_id !== $order_customer_id ) {
			return false;
		}

		$old_price = (float) get_post_meta( $subscription_id, '_subscrpt_price', true );
		$old_product_id = (int) get_post_meta( $subscription_id, '_subscrpt_product_id', true );
		$new_price = (float) ( $item->get_meta( '_subscrpt_plan_price' ) ? $item->get_meta( '_subscrpt_plan_price' ) : $target['price'] );
		$already_applied = (int) get_post_meta( $subscription_id, '_subscrpt_switch_order_id', true ) === (int) $order->get_id();
		$type      = $new_price >= $old_price ? 'upgrade' : 'downgrade';
		if ( 'downgrade' === $type && ! in_array( get_option( 'subscrpt_downgrade_allowed', '1' ), array( 1, '1', true, 'yes' ), true ) ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'subscrpt_order_relation';
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE subscription_id = %d AND order_id = %d AND type = %s LIMIT 1',
				array( $table, $subscription_id, $order->get_id(), 'switch' )
			)
		);
		if ( ! $exists && false === $wpdb->insert(
			$table,
			array(
				'subscription_id' => $subscription_id,
				'order_id' => $order->get_id(),
				'order_item_id' => $item->get_id(),
				'type' => 'switch',
			)
		) ) {
			return false;
		}

		$terms = $item->get_meta( '_subscrpt_plan_terms' );
		$terms = is_array( $terms ) ? $terms : $target['terms'];
		update_post_meta( $subscription_id, '_subscrpt_product_id', $product_id );
		update_post_meta( $subscription_id, '_subscrpt_variation_id', $variation_id );
		update_post_meta( $subscription_id, '_subscrpt_price', $new_price );
		update_post_meta( $subscription_id, '_subscrpt_payment_type', (string) ( $item->get_meta( '_subscrpt_payment_type' ) ? $item->get_meta( '_subscrpt_payment_type' ) : $target['payment_type'] ) );
		update_post_meta( $subscription_id, '_subscrpt_max_no_payment', (int) ( $item->get_meta( '_subscrpt_max_no_payment' ) ? $item->get_meta( '_subscrpt_max_no_payment' ) : $target['max_payments'] ) );
		update_post_meta( $subscription_id, '_subscrpt_timing_per', max( 1, (int) ( $terms['time'] ?? 1 ) ) );
		update_post_meta( $subscription_id, '_subscrpt_timing_option', (string) ( $terms['type'] ?? 'months' ) );
		update_post_meta( $subscription_id, '_subscrpt_trial', $terms['trial'] ?? null );
		update_post_meta( $subscription_id, '_subscrpt_plan_id', $plan_id );
		update_post_meta( $subscription_id, '_subscrpt_plan_group_id', (int) ( $item->get_meta( '_subscrpt_plan_group_id' ) ? $item->get_meta( '_subscrpt_plan_group_id' ) : $target['group_id'] ) );
		update_post_meta( $subscription_id, '_subscrpt_plan_data', $item->get_meta( '_subscrpt_plan_data' ) ? $item->get_meta( '_subscrpt_plan_data' ) : $target['plan_data'] );
		update_post_meta( $subscription_id, '_subscrpt_billing_length', (int) ( $item->get_meta( '_subscrpt_billing_length' ) ? $item->get_meta( '_subscrpt_billing_length' ) : ( $target['billing_length'] ?? 0 ) ) );
		update_post_meta( $subscription_id, '_subscrpt_order_id', $order->get_id() );
		update_post_meta( $subscription_id, '_subscrpt_order_item_id', $item->get_id() );
		update_post_meta( $subscription_id, '_subscrpt_switch_order_id', $order->get_id() );
		update_post_meta( $subscription_id, '_subscrpt_switch_from_product_id', $old_product_id );
		update_post_meta( $subscription_id, '_subscrpt_switch_at', time() );

		if ( ! $already_applied ) {
			/* translators: 1: new product or plan name, 2: paid switch order ID. */
			$comment_id = wp_insert_comment(
				array(
					'comment_author'  => 'Ashbi Subscriptions',
					'comment_content' => sprintf( __( 'Subscription switched to %1$s. Order #%2$d.', 'subscription' ), $target['product']->get_name(), $order->get_id() ),
					'comment_post_ID' => $subscription_id,
					'comment_type'    => 'order_note',
				)
			);
			if ( $comment_id ) {
				update_comment_meta( $comment_id, '_subscrpt_activity', __( 'Subscription Switch', 'subscription' ) );
				update_comment_meta( $comment_id, '_subscrpt_activity_type', 'switch' );
			}
		}

		$item->update_meta_data( '_subscrpt_switch_applied', 1 );
		$item->save();
		do_action( 'subscrpt_subscription_switched', $subscription_id, $order, $item, $type );
		return true;
	}
}

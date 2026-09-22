<?php
/**
 * Upgrade routines for legacy subscription metadata.
 *
 * @package AshbiSubscriptions
 */
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription;

use SpringDevs\Subscription\Installer;

// HPOS: This file uses direct SQL on postmeta for migration/upgrade tasks only.
// Do NOT use direct SQL for live WooCommerce order data access—use WooCommerce CRUD for HPOS compatibility.
// All live order data access must use WooCommerce CRUD methods.

/**
 * Upgrade class
 */
class Upgrade {

	/**
	 * Run pending schema and metadata upgrades.
	 *
	 * @return void
	 */
	public function run() {
		if ( version_compare( '1.1.0', get_option( 'subscrpt_version' ), '>' ) ) {

			// create histories table.
			$installer = new Installer();
			$installer->create_tables();

			// move product meta.
			$this->move_product_meta();
			// update subscription meta.
			$this->update_subscription_meta();
			$this->update_comment_meta();
			update_option( 'subscrpt_rewrite_flush_pending', 1 );
		}
	}

	/**
	 * Move legacy product metadata into the current storage format.
	 *
	 * @return void
	 */
	public function move_product_meta() {
		global $wpdb;
		$products_meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE meta_key = %s", 'subscrpt_general' ) );
		foreach ( $products_meta as $product_meta ) {
			$decoded_meta = $this->decode_legacy_value( $product_meta->meta_value );
			if ( false === $decoded_meta ) {
				continue;
			}

			update_post_meta( $product_meta->post_id, '_subscrpt_meta', $decoded_meta );
			update_post_meta(
				$product_meta->post_id,
				'_subscrpt_user_cancel',
				get_post_meta(
					$product_meta->post_id,
					'_subscrpt_user_cancell',
					true
				)
			);
			delete_post_meta( $product_meta->post_id, '_subscrpt_user_cancell' );
			delete_post_meta( $product_meta->post_id, 'subscrpt_general' );
		}
	}

	/**
	 * Move legacy subscription and history metadata into current relations.
	 *
	 * @return void
	 */
	public function update_subscription_meta() {
		global $wpdb;

		$subscriptions_meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE meta_key = %s", '_subscrpt_order_general' ) );

		$histories = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE meta_key = %s", '_subscrpt_order_history' ) );

		foreach ( $subscriptions_meta as $subscription_meta ) {
			$subscription_meta_value = $this->decode_legacy_value( $subscription_meta->meta_value );
			if (
				false === $subscription_meta_value
				|| ! isset(
					$subscription_meta_value['order_id'],
					$subscription_meta_value['product_id'],
					$subscription_meta_value['trial'],
					$subscription_meta_value['start_date'],
					$subscription_meta_value['next_date']
				)
			) {
				continue;
			}

			$order_item_id = 0;
			$order         = wc_get_order( $subscription_meta_value['order_id'] );

			if ( $order ) {
				foreach ( $order->get_items() as $order_item ) {
					if ( $order_item->get_product_id() == $subscription_meta_value['product_id'] ) {
						$order_item_id = $order_item->get_id();
					}
				}
			}

			update_post_meta(
				$subscription_meta->post_id,
				'_order_subscrpt_meta',
				array(
					'order_id'      => $subscription_meta_value['order_id'],
					'order_item_id' => $order_item_id,
					'trial'         => $subscription_meta_value['trial'],
					'start_date'    => $subscription_meta_value['start_date'],
					'next_date'     => $subscription_meta_value['next_date'],
				)
			);

			delete_post_meta( $subscription_meta->post_id, '_subscrpt_order_general' );
		}

		foreach ( $histories as $history ) {
			$histories_meta = $this->decode_legacy_value( $history->meta_value );
			if ( false === $histories_meta ) {
				continue;
			}

			$history_migrated = true;
			foreach ( $histories_meta as $history_meta ) {
				if (
					! is_array( $history_meta )
					|| ! isset(
						$history_meta['order_id'],
						$history_meta['product_id'],
						$history_meta['post_id'],
						$history_meta['trial'],
						$history_meta['start_date'],
						$history_meta['next_date'],
						$history_meta['stats']
					)
				) {
					$history_migrated = false;
					continue;
				}

				$entry_migrated = false;
				$order        = wc_get_order( $history_meta['order_id'] );
				$product_meta = get_post_meta( $history_meta['product_id'], '_subscrpt_meta', true );
				if ( ! is_array( $product_meta ) || ! isset( $product_meta['time'], $product_meta['type'] ) ) {
					$history_migrated = false;
					continue;
				}
				$order = wc_get_order( $history_meta['order_id'] );

				if ( $order ) {
					$order_item_id = 0;
					$item_meta_updated = false;
					foreach ( $order->get_items() as $order_item ) {
						if ( $order_item->get_product_id() == $history_meta['product_id'] ) {
							$order_item_id = $order_item->get_id();
							$item_meta_updated = wc_update_order_item_meta(
								$order_item->get_id(),
								'_subscrpt_meta',
								array(
									'time'       => $product_meta['time'],
									'type'       => $product_meta['type'],
									'trial'      => $history_meta['trial'],
									'start_date' => $history_meta['start_date'],
									'next_date'  => $history_meta['next_date'],
								)
							);
						}
					}

					if ( $order_item_id > 0 && false !== $item_meta_updated ) {
						$history_table = $wpdb->prefix . 'subscrpt_order_relation';
						$relation_type = 'Parent Order' === $history_meta['stats'] ? 'new' : 'renew';
						$existing_relation = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT id FROM {$history_table} WHERE subscription_id = %d AND order_id = %d AND order_item_id = %d AND type = %s LIMIT 1",
								$history_meta['post_id'],
								$history_meta['order_id'],
								$order_item_id,
								$relation_type
							)
						);

						if ( $existing_relation ) {
							$entry_migrated = true;
						} else {
							$inserted = $wpdb->insert(
								$history_table,
								array(
									'subscription_id' => $history_meta['post_id'],
									'order_id'        => $history_meta['order_id'],
									'order_item_id'   => $order_item_id,
									'type'            => $relation_type,
								)
							);
							$entry_migrated = false !== $inserted;
						}
					}
				}

				if ( ! $entry_migrated ) {
					$history_migrated = false;
				}
			}

			if ( $history_migrated ) {
				delete_post_meta( $history->post_id, '_subscrpt_order_history' );
			}
		}
	}

	/**
	 * Decode legacy serialized metadata without instantiating stored classes.
	 *
	 * Invalid or unexpected records remain in place for operator inspection.
	 *
	 * @param mixed $value Legacy serialized value.
	 * @return array|false
	 */
	private function decode_legacy_value( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );

		return is_array( $decoded ) ? $decoded : false;
	}

	/**
	 * Move legacy activity metadata to the current key.
	 *
	 * @return void
	 */
	public function update_comment_meta() {
		global $wpdb;
		$comments_meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->commentmeta} WHERE meta_key = %s", 'subscrpt_activity' ) );
		foreach ( $comments_meta as $comment_meta ) {
			update_comment_meta( $comment_meta->comment_id, '_subscrpt_activity', $comment_meta->meta_value );
			delete_comment_meta( $comment_meta->comment_id, 'subscrpt_activity' );
		}
	}
}

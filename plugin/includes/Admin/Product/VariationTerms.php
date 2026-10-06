<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Native WooCommerce variation-panel term mapping.
 *
 * @package SpringDevs\Subscription
 */

namespace SpringDevs\Subscription\Admin\Product;

use SpringDevs\Subscription\Illuminate\Plans\PlanRepository as Repository;
use SpringDevs\Subscription\Illuminate\Migration\VariationPlanMigrator;

/** A single existing term per variation, using its WooCommerce price. */
class VariationTerms {
	/** Register native editor hooks. */
	public function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_mode' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_mode' ), 30 );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save' ), 20, 1 );
	}

	/** Render the parent mode toggle using WooCommerce's native field. */
	public function render_mode() {
		woocommerce_wp_checkbox(
			array(
				'id'            => '_subscrpt_variation_term_mode',
				'label'         => __( 'Map variations to subscription terms', 'subscription' ),
				'wrapper_class' => 'show_if_variable',
				'description'   => __( 'Use one term and the live variation price. Unmapped variations are one-time. Turning this off restores inherited product-level plans.', 'subscription' ),
			)
		);
		wp_nonce_field( 'ashbi_variation_mode', 'ashbi_variation_mode_nonce' );
	}

	/**
	 * Save an explicit mode toggle without renaming legacy storage.
	 *
	 * @param int $id Parent product id.
	 * @return void
	 */
	public function save_mode( $id ) {
		$nonce = isset( $_POST['ashbi_variation_mode_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ashbi_variation_mode_nonce'] ) ) : '';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $id ) || ! wp_verify_nonce( $nonce, 'ashbi_variation_mode' ) ) {
			return;
		}
		$product = wc_get_product( $id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}
		$lock = '';
		try {
			$lock = VariationPlanMigrator::acquire_lock();
			if ( isset( $_POST['_subscrpt_variation_term_mode'] ) ) {
				$seen = array();
				foreach ( Repository::get_product_connections( $id ) as $row ) {
					$vid = (int) $row['vid'];
					if ( ! $vid ) {
						continue;
					}
					if ( isset( $seen[ $vid ] ) || 'variation' !== ( $row['relation_data']['price_source'] ?? '' ) ) {
						\WC_Admin_Meta_Boxes::add_error( __( 'Review existing variation connections in Migration before enabling mapping mode.', 'subscription' ) );
						return;
					}
					$seen[ $vid ] = true;
				}
			}
			$product->update_meta_data( '_subscrpt_variation_term_mode', isset( $_POST['_subscrpt_variation_term_mode'] ) ? 'yes' : 'no' );
			$product->save_meta_data();
			Repository::flush_cache( $id );
		} catch ( \Throwable $error ) {
			\WC_Admin_Meta_Boxes::add_error( $error->getMessage() );
		} finally {
			if ( $lock ) {
				VariationPlanMigrator::release_lock( $lock );
			}
		}
	}

	/**
	 * Render a native select in each variation panel.
	 *
	 * @param int      $loop Loop index.
	 * @param array    $data Variation data.
	 * @param \WP_Post $variation Variation post.
	 * @return void
	 */
	public function render( $loop, $data, $variation ) {
		$id       = (int) $variation->ID;
		$parent   = (int) $variation->post_parent;
		$selected = 0;
		foreach ( Repository::get_product_connections( $parent, $id ) as $row ) {
			if ( 'variation' === ( $row['relation_data']['price_source'] ?? '' ) ) {
				$selected = (int) $row['plan_id'];
			}
		}
		?>
		<p class="form-row form-row-full">
			<label for="ashbi-term-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Subscription term (variation price)', 'subscription' ); ?></label>
			<select id="ashbi-term-<?php echo esc_attr( $id ); ?>" name="ashbi_variation_term[<?php echo esc_attr( $id ); ?>]">
				<option value="0"><?php esc_html_e( 'None — one-time', 'subscription' ); ?></option>
				<?php foreach ( Repository::get_groups() as $group ) : ?>
					<?php
					if ( 'active' !== $group['status'] ) {
						continue;
					}
					?>
					<?php foreach ( Repository::get_plans( $group['id'] ) as $term ) : ?>
						<?php
						if ( 'active' !== $term['status'] ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $term['id'] ); ?>" <?php selected( $selected, $term['id'] ); ?>><?php echo esc_html( $group['title'] . ' — ' . $term['title'] ); ?></option>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</select>
			<input type="hidden" name="ashbi_variation_term_nonce[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( wp_create_nonce( 'ashbi_variation_term_' . $id ) ); ?>">
			<span class="description"><?php esc_html_e( 'Selecting a term enables variation mapping for the parent. Unmapped variations are one-time; product-level plans are suppressed. Existing subscriptions keep their snapshots.', 'subscription' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Save only an authenticated, owned variation's explicit mapping choice.
	 *
	 * @param int $id Variation id.
	 * @return void
	 */
	public function save( $id ) {
		$nonce = isset( $_POST['ashbi_variation_term_nonce'][ $id ] ) ? sanitize_text_field( wp_unslash( $_POST['ashbi_variation_term_nonce'][ $id ] ) ) : '';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $id ) || ! wp_verify_nonce( $nonce, 'ashbi_variation_term_' . $id ) || ! isset( $_POST['ashbi_variation_term'][ $id ] ) ) {
			return;
		}
		$variation = wc_get_product( $id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return;
		}
		$parent = (int) $variation->get_parent_id();
		if ( ! current_user_can( 'edit_post', $parent ) ) {
			return;
		}
		$term_id = absint( wp_unslash( $_POST['ashbi_variation_term'][ $id ] ) );
		$term    = $term_id ? Repository::get_plan( $term_id ) : null;
		$group   = $term ? Repository::get_group( $term['plan_group_id'] ) : null;
		if ( $term_id && ( ! $term || ! $group || 'active' !== $term['status'] || 'active' !== $group['status'] ) ) {
			\WC_Admin_Meta_Boxes::add_error( __( 'Choose an active subscription term.', 'subscription' ) );
			return;
		}
		$lock = '';
		try {
			$lock     = VariationPlanMigrator::acquire_lock();
			$migrator = new VariationPlanMigrator();
			$migrator->atomic(
				function () use ( $parent, $id, $term_id, $variation ) {
					if ( $term_id ) {
						foreach ( Repository::get_product_connections( $parent ) as $existing ) {
							if ( (int) $existing['vid'] > 0 && 'variation' !== ( $existing['relation_data']['price_source'] ?? '' ) ) {
								throw new \RuntimeException( 'Review all existing variation connections in Migration before enabling mapping mode.' );
							}
						}
					}
					$connections = Repository::get_product_connections( $parent, $id );
					foreach ( $connections as $row ) {
						if ( 'variation' !== ( $row['relation_data']['price_source'] ?? '' ) ) {
							if ( ! $term_id ) {
								return;
							}
							throw new \RuntimeException( 'Existing typed variation plans need review in Migration before mapping.' );
						}
					}
					if ( $term_id ) {
						// Insert before removing the old mapping: persistence failures keep it intact.
						$relation_id = Repository::insert_relation(
							array(
								'plan_id' => $term_id,
								'oid'     => $parent,
								'vid'     => $id,
								'type'    => 1,
								'status'  => 'active',
								'exclude' => 0,
								'data'    => array( 'price_source' => 'variation' ),
							)
						);
						if ( ! $relation_id ) {
								throw new \RuntimeException( 'Could not save the variation term.' );
						}
						foreach ( array( $variation, wc_get_product( $parent ) ) as $product ) {
							$product->update_meta_data( '_subscrpt_enabled', 'yes' );
							$product->update_meta_data( '_subscrpt_plan_connected_before', 'yes' );
							if ( $product->get_id() === $parent ) {
								$product->update_meta_data( '_subscrpt_variation_term_mode', 'yes' );
							}
							$product->save_meta_data();
						}
					}
					foreach ( $connections as $row ) {
						if ( (int) $row['plan_id'] !== $term_id && ! Repository::delete_relation( $row['relation_id'] ) ) {
							throw new \RuntimeException( 'Could not remove the previous variation mapping. Review connections before selling.' );
						}
					}
				}
			);
			Repository::flush_cache( $parent );
		} catch ( \Throwable $error ) {
			\WC_Admin_Meta_Boxes::add_error( $error->getMessage() );
		} finally {
			Repository::flush_cache( $parent );
			wp_cache_delete( $parent, 'post_meta' );
			wp_cache_delete( $id, 'post_meta' );
			wc_delete_product_transients( $parent );
			foreach ( array( $parent, $id ) as $entity_id ) {
				$product = wc_get_product( $entity_id );
				if ( $product ) {
					$product->read_meta_data( true );
				}
			}
			if ( $lock ) {
				VariationPlanMigrator::release_lock( $lock );
			}
		}
	}
}

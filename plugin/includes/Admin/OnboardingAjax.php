<?php
/**
 * AJAX handlers for the onboarding wizard.
 *
 * @package SpringDevs\Subscription\Admin
 */

// This filename is part of the imported public compatibility surface.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Admin;

/**
 * AJAX handlers for the onboarding wizard.
 *
 * The wizard creates a plan + duration and connects it to a product entirely
 * through the Plans REST API (wpsubscription/v1/plans). The one thing REST does
 * not offer is creating a WooCommerce product, so that single step lives here;
 * everything else — group, term, relation — is done client-side against REST.
 *
 * @package SpringDevs\Subscription\Admin
 */
class OnboardingAjax {

	/**
	 * Initialize the class.
	 */
	public function __construct() {
		add_action( 'wp_ajax_subscrpt_create_wizard_product', array( $this, 'create_wizard_product' ) );
		add_action( 'wp_ajax_subscrpt_publish_wizard_product', array( $this, 'publish_wizard_product' ) );
		add_action( 'wp_ajax_subscrpt_reset_wizard', array( $this, 'reset_wizard' ) );
	}

	/**
	 * Create a bare simple product (name + price) for the wizard to connect a
	 * plan to. No subscription meta is written here — connecting the plan (the
	 * REST relation) is what makes the product subscribable.
	 *
	 * @return void Sends JSON.
	 */
	public function create_wizard_product() {
		check_ajax_referer( 'subscrpt_onboarding_wizard', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'subscription' ) ), 403 );
		}

		$product_name  = isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['product_name'] ) ) : '';
		$product_price = isset( $_POST['product_price'] ) ? sanitize_text_field( wp_unslash( $_POST['product_price'] ) ) : '';

		if ( '' === trim( $product_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Product name is required.', 'subscription' ), 'safe_retry' => true ) );
		}

		$product = new \WC_Product_Simple();
		$product->set_name( $product_name );
		if ( '' !== trim( $product_price ) ) {
			$product->set_regular_price( wc_format_decimal( $product_price ) );
		}
		// Creation is always staged, even for older wizard clients.
		$product->set_status( 'draft' );
		$product->update_meta_data( '_subscrpt_wizard_created_by', get_current_user_id() );
		$product_id = $product->save();

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create product. Please try again.', 'subscription' ) ) );
		}

		wp_send_json_success(
			array(
				'product_id'    => $product_id,
				'product_name'  => $product_name,
				'product_price' => $product_price,
				'product_status' => wc_get_product( $product_id )->get_status(),
			)
		);
	}

	/**
	 * Publish an explicitly confirmed new wizard product, never a linked existing one.
	 *
	 * @return void Sends JSON.
	 */
	public function publish_wizard_product() {
		check_ajax_referer( 'subscrpt_onboarding_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'subscription' ) ), 403 );
		}
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$confirmed  = isset( $_POST['publish_confirmed'] ) ? sanitize_text_field( wp_unslash( $_POST['publish_confirmed'] ) ) : '';
		$product    = $product_id ? wc_get_product( $product_id ) : false;
		if ( 'yes' !== $confirmed || ! $product || (int) $product->get_meta( '_subscrpt_wizard_created_by' ) !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'Only an explicitly confirmed new wizard product may be published.', 'subscription' ), 'safe_retry' => true ), 400 );
		}
		$product->set_status( 'publish' );
		if ( ! $product->save() ) {
			wp_send_json_error( array( 'message' => __( 'Publication could not be confirmed. Check the product before retrying.', 'subscription' ) ), 500 );
		}
		$saved = wc_get_product( $product_id );
		wp_send_json_success( array( 'product_id' => $product_id, 'product_status' => $saved ? $saved->get_status() : '' ) );
	}

	/**
	 * Reset wizard session (for "Add another" / "Start over" actions).
	 *
	 * @return void Sends JSON.
	 */
	public function reset_wizard() {
		check_ajax_referer( 'subscrpt_onboarding_wizard', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'subscription' ) ), 403 );
		}

		if ( ! session_id() ) {
			session_start();
		}

		unset( $_SESSION['subscrpt_onboarding_wizard'] );

		wp_send_json_success();
	}
}

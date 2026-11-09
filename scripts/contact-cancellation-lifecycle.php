<?php
/**
 * Actual core cancellation and optional request review, on disposable data only.
 *
 * @package AshbiSubscriptions\Tests
 */

if ( 'local' !== wp_get_environment_type() || ! in_array( DB_NAME, array( 'ashbi_contact_ci', 'ashbi_receipt_on', 'ashbi_receipt_off' ), true ) || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) || ! current_user_can( 'manage_options' ) || ! class_exists( 'WooCommerce' ) || ! class_exists( '\SpringDevs\Subscription\Illuminate\CancellationEvidence' ) ) {
	throw new RuntimeException( 'Disposable WooCommerce and administrator context required.' );
}

$checks     = 0;
$check      = static function ( $pass, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $pass ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$http_calls = 0;
$block_http = static function () use ( &$http_calls ) {
	++$http_calls;
	return new WP_Error( 'fabricated_no_network' );
};
add_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
require_once dirname( __DIR__ ) . '/tools/contact-request-review/ashbi-contact-request-review.php';
Ashbi_Contact_Request_Review::register();
$actor     = get_current_user_id();
$form_name = 'Fabricated lifecycle ' . wp_generate_uuid4();
$config    = static function () use ( $actor, $form_name ) {
	return array(
		'enabled'       => true,
		'form_name'     => $form_name,
		'owner_user_id' => $actor,
	);
};
add_filter( 'ashbi_contact_review_config', $config );
$ids      = array();
$requests = array();
$orders   = array();
global $wpdb;
try {
	$fixture_hpos_mode = get_option( 'woocommerce_custom_orders_table_enabled' );
	$expected_mode     = getenv( 'ASHBI_EXPECT_HPOS' );
	if ( false === $expected_mode ) {
		$expected_mode = $fixture_hpos_mode;
	}
	$check( in_array( $expected_mode, array( 'yes', 'no' ), true ) && $fixture_hpos_mode === $expected_mode && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() === ( 'yes' === $expected_mode ), 'The expected HPOS data store is not active.' );
	foreach ( array(
		'fabricated_unavailable' => 'pending',
		'stripe'                 => 'confirmed',
	) as $method => $expected ) {
		$fixture_order = wc_create_order();
		$orders[]      = $fixture_order;
		$fixture_order->set_payment_method( $method );
		$fixture_order->save();
		$subscription_fixture_id = wp_insert_post(
			array(
				'post_type'   => 'subscrpt_order',
				'post_status' => 'active',
				'post_author' => $actor,
				'post_title'  => 'Fabricated support cancellation lifecycle',
			)
		);
		$ids[]                   = $subscription_fixture_id;
		update_post_meta( $subscription_fixture_id, '_subscrpt_order_id', $fixture_order->get_id() );
		update_post_meta( $subscription_fixture_id, '_subscrpt_auto_renew', 1 );
		update_post_meta( $subscription_fixture_id, '_subscrpt_user_cancel', 'no' );
		$wpdb->insert(
			$wpdb->prefix . 'subscrpt_order_relation',
			array(
				'subscription_id' => $subscription_fixture_id,
				'order_id'        => $fixture_order->get_id(),
				'order_item_id'   => 0,
				'type'            => 'new',
			)
		);
		$before  = wp_json_encode( array( get_post( $subscription_fixture_id ), get_post_meta( $subscription_fixture_id ), wc_get_order( $fixture_order->get_id() )->get_data() ) );
		$record  = new class( $form_name, $method ) {
			/**
			 * Fabricated form name.
			 *
			 * @var string
			 */
			private $form;
			/**
			 * Fabricated customer message.
			 *
			 * @var string
			 */
			private $message;
			/**
			 * Supply the validated-record interface without installing Elementor.
			 *
			 * @param string $form Unique fixture form.
			 * @param string $method Fixture billing route.
			 */
			public function __construct( $form, $method ) {
				$this->form    = $form;
				$this->message = 'Please cancel future renewals. Fabricated ' . $method . ' ' . wp_generate_uuid4();
			}
			/**
			 * Return the fabricated form setting.
			 *
			 * @param string $key Setting name.
			 * @return string
			 */
			public function get_form_settings( $key ) {
				return 'form_name' === $key ? $this->form : '';
			}
			/**
			 * Return fabricated validated fields.
			 *
			 * @param string $key Record property.
			 * @return array
			 */
			public function get( $key ) {
				return 'fields' === $key ? array(
					'email'   => array( 'value' => 'lifecycle@example.invalid' ),
					'message' => array( 'value' => $this->message ),
				) : array();
			}
		};
		$handler = new class() {
			/**
			 * Captured validation errors.
			 *
			 * @var array
			 */
			public $errors = array();
			/**
			 * Captured response fields.
			 *
			 * @var array
			 */
			public $data = array();
			/**
			 * Capture a validation failure.
			 *
			 * @param string $message Error text.
			 */
			public function add_error_message( $message ) {
				$this->errors[] = $message;
			}
			/**
			 * Capture the customer reference.
			 *
			 * @param string $key Response field.
			 * @param string $value Response value.
			 */
			public function add_response_data( $key, $value ) {
				$this->data[ $key ] = $value;
			}
		};
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Exercise Elementor's documented hook exactly.
		do_action( 'elementor_pro/forms/process', $record, $handler );
		$reference  = $handler->data['ashbi_request_reference'] ?? '';
		$request    = (int) substr( $reference, 2 );
		$requests[] = $request;
		$check( ! $handler->errors && 0 === strpos( $reference, 'R-' ) && $request > 0, 'Validated-form adapter did not save a receipt.' );
		$check( wp_json_encode( array( get_post( $subscription_fixture_id ), get_post_meta( $subscription_fixture_id ), wc_get_order( $fixture_order->get_id() )->get_data() ) ) === $before, 'Free-text intake changed the subscription or order.' );
		$check( ! \SpringDevs\Subscription\Illuminate\CancellationEvidence::blocked( $subscription_fixture_id ), 'Free-text intake fabricated cancellation intent.' );
		$source = get_post( $request )->post_content;
		$check( is_wp_error( Ashbi_Contact_Request_Review::resolve( $request, 'cancellation_confirmed', $subscription_fixture_id, 'Fabricated reply attestation; no email sent.', true ) ), 'A saved message closed as a cancellation before explicit action.' );
		$result = \SpringDevs\Subscription\Admin\Subscriptions::process_status_change( $subscription_fixture_id, 'cancelled' );
		$check( $expected === $result['state'] && 'cancelled' === get_post_status( $subscription_fixture_id ) && 0 === (int) get_post_meta( $subscription_fixture_id, '_subscrpt_auto_renew', true ), 'Explicit merchant cancellation lost billing protection or overstated provider proof.' );
		$check( false === \SpringDevs\Subscription\Illuminate\Helper::create_renewal_order( $subscription_fixture_id ), 'Support cancellation allowed another renewal.' );
		$barrier = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d', $wpdb->prefix . 'subscrpt_cancellation_barrier', $subscription_fixture_id ), ARRAY_A );
		$state   = wp_json_encode( array( get_post( $subscription_fixture_id ), get_post_meta( $subscription_fixture_id ), wc_get_order( $fixture_order->get_id() )->get_data() ) );
		if ( 'pending' === $expected ) {
			$check( is_wp_error( Ashbi_Contact_Request_Review::resolve( $request, 'cancellation_confirmed', $subscription_fixture_id, 'Fabricated reply attestation; no email sent.', true ) ) && 'private' === get_post_status( $request ), 'Unavailable-provider cancellation disappeared from the open queue.' );
		} else {
			$check( is_wp_error( Ashbi_Contact_Request_Review::resolve( $request, 'cancellation_confirmed', $subscription_fixture_id, 'Fabricated note without reply attestation.', false ) ), 'Verified billing stop bypassed the reply requirement.' );
			$check( true === Ashbi_Contact_Request_Review::resolve( $request, 'cancellation_confirmed', $subscription_fixture_id, 'Fabricated reply attestation; no email sent.', true ) && 'draft' === get_post_status( $request ), 'Verified merchant cancellation could not close the reviewed request.' );
			$outcome = get_post( $request )->post_excerpt;
			$check( is_wp_error( Ashbi_Contact_Request_Review::resolve( $request, 'other_request', 0, 'Attempted fabricated overwrite of closed outcome.', true ) ) && get_post( $request )->post_excerpt === $outcome, 'Repeated resolution overwrote the closed outcome.' );
		}
		$check( get_post( $request )->post_content === $source && wp_json_encode( array( get_post( $subscription_fixture_id ), get_post_meta( $subscription_fixture_id ), wc_get_order( $fixture_order->get_id() )->get_data() ) ) === $state, 'Request closure changed source text, subscription or order.' );
		$check( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d', $wpdb->prefix . 'subscrpt_cancellation_barrier', $subscription_fixture_id ), ARRAY_A ) === $barrier, 'Review rewrote immutable cancellation intent.' );
	}
	$check( 0 === $http_calls, 'The local support workflow attempted an outbound HTTP request.' );
} finally {
	foreach ( $requests as $request ) {
		wp_delete_post( $request, true );
	}
	foreach ( $ids as $subscription_fixture_id ) {
		foreach ( array( 'subscrpt_cancellation_barrier', 'subscrpt_evidence_event', 'subscrpt_order_relation' ) as $table ) {
			$wpdb->delete( $wpdb->prefix . $table, array( 'subscription_id' => $subscription_fixture_id ) );
		}
		as_unschedule_all_actions( 'subscrpt_repair_cancellation', array( $subscription_fixture_id ), 'ashbi-subscriptions' );
		wp_delete_post( $subscription_fixture_id, true );
	}
	foreach ( $orders as $fixture_order ) {
		$fixture_order->delete( true );
	}
	remove_filter( 'ashbi_contact_review_config', $config );
	remove_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
}
echo 'CONTACT_CANCELLATION_LIFECYCLE_PASS ' . esc_html(
	wp_json_encode(
		array(
			'checks' => $checks,
			'hpos'   => get_option( 'woocommerce_custom_orders_table_enabled' ),
		)
	)
) . "\n";

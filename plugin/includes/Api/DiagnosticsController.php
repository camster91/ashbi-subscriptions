<?php
/**
 * Ashbi Subscriptions support diagnostics REST controller.
 *
 * The endpoint is intentionally read-only and aggregate-only. It is for an
 * authenticated WooCommerce manager diagnosing a local installation, not for
 * customer-facing monitoring or remote analytics.
 *
 * @package SpringDevs\Subscription\Api
 */

// PSR-4 class filenames are retained for the existing plugin autoloader.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Api;

use SpringDevs\Subscription\Illuminate\Stats;
use SpringDevs\Subscription\Installer;
use WP_REST_Request;
use WP_Error;
use WP_REST_Server;

/**
 * Read-only support diagnostics controller.
 */
class DiagnosticsController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NS = 'wpsubscription/v1';

	/**
	 * Register the diagnostics route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_diagnostics' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Require the same WooCommerce management capability as the admin plan API.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to view subscription diagnostics.', 'subscription' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /diagnostics - return aggregate, local installation diagnostics.
	 *
	 * No customer, order, subscription, payment-token, webhook-secret, or
	 * administrator-provided URL values are returned by this endpoint.
	 *
	 * @param WP_REST_Request $request Request (unused; keeps the REST callback contract explicit).
	 * @return \WP_REST_Response
	 */
	public function get_diagnostics( WP_REST_Request $request ) {
		unset( $request );

		$blocked = get_option( 'subscrpt_renewal_migration_blocked', array() );
		$counts  = Stats::get_status_counts();

		return rest_ensure_response(
			array(
				'generated_at_gmt' => gmdate( 'c' ),
				'plugin'           => array(
					'version'       => defined( 'SUBSCRPT_VERSION' ) ? (string) SUBSCRPT_VERSION : '',
					'schema_stored' => (string) get_option( 'subscrpt_db_version', '' ),
					'schema_target' => Installer::DB_VERSION,
				),
				'wordpress'        => array(
					'version'          => (string) get_bloginfo( 'version' ),
					'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
					'php_version'      => PHP_VERSION,
					'woocommerce'      => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
				),
				'subscriptions'    => array(
					'status_counts'               => $counts,
					'active_mrr'                  => Stats::calculate_active_mrr(),
					'revenue_at_risk'             => Stats::calculate_revenue_at_risk(),
					'renewals_due_7_days'         => Stats::count_renewals_due_within( 7 ),
					'failed_renewals_24_hours'    => Stats::count_failed_renewals_since( 24 ),
					'recovered_renewals_24_hours' => Stats::count_recovered_renewals_since( 24 ),
				),
				'migration'        => array(
					'blocked'            => ! empty( $blocked ),
					'blocked_count'      => is_array( $blocked ) ? count( array_filter( array_map( 'absint', $blocked ) ) ) : ( empty( $blocked ) ? 0 : 1 ),
					'claim_backfill'     => (bool) get_option( 'subscrpt_renewal_claim_backfill_1', false ),
					'overdue_quarantine' => (bool) get_option( 'subscrpt_overdue_renewal_quarantine_1_completed_at', false ),
				),
				'capabilities'     => array(
					'hpos_declaration' => class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ),
					'blocks'           => class_exists( '\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry' ),
					'action_scheduler' => function_exists( 'as_enqueue_async_action' ),
					// Optional gateway classes must already be loaded by the gateway.
					// bootstrap. Avoid autoloading a class whose third-party parent may.
					// not be available during an early or partial plugin load.
					'stripe_adapter'   => class_exists( 'SpringDevs\\Subscription\\Illuminate\\Gateways\\Stripe\\Stripe', false ),
					'paypal_adapter'   => class_exists( 'SpringDevs\\Subscription\\Illuminate\\Gateways\\Paypal\\Paypal', false ),
					'api_enabled'      => 'off' !== get_option( 'wpsubscription_api_enabled', 'on' ),
				),
				'gateways'         => $this->gateway_inventory(),
			)
		);
	}

	/**
	 * Return gateway IDs and enabled state without exposing gateway settings.
	 *
	 * @return array<int,array{id:string,enabled:bool}>
	 */
	private function gateway_inventory() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'payment_gateways' ) ) {
			return array();
		}

		$manager = WC()->payment_gateways();
		if ( ! $manager || ! method_exists( $manager, 'payment_gateways' ) ) {
			return array();
		}

		$inventory = array();
		foreach ( (array) $manager->payment_gateways() as $id => $gateway ) {
			$inventory[] = array(
				'id'      => sanitize_key( $id ),
				'enabled' => method_exists( $gateway, 'is_enabled' ) && $gateway->is_enabled(),
			);
		}

		return $inventory;
	}
}

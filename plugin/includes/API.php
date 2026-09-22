<?php
/**
 * REST API bootstrap.
 *
 * @package SpringDevs\Subscription
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- Legacy class filename is part of the public plugin compatibility contract.

namespace SpringDevs\Subscription;

use SpringDevs\Subscription\Api\PlanController;
use SpringDevs\Subscription\Api\DiagnosticsController;

/**
 * API Class
 */
class API {


	/**
	 * Initialize the class
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_api' ) );
	}

	/**
	 * Register the API
	 *
	 * @return void
	 */
	public function register_api() {
		if ( 'off' === (string) get_option( 'wpsubscription_api_enabled', 'on' ) ) {
			return;
		}

		( new PlanController() )->register_routes();
		( new DiagnosticsController() )->register_routes();
	}
}

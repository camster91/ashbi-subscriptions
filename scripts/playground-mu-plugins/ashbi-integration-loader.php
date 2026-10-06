<?php
/**
 * Load the disposable Ashbi integration callback independently of plugin
 * activation timing in the local single-worker Playground harness.
 *
 * @package AshbiSubscriptions
 */

$integration_file = '/ashbi-integration/security-boundaries.php';
if ( ! file_exists( $integration_file ) ) {
	$integration_file = WP_PLUGIN_DIR . '/plugin/tests/integration/security-boundaries.php';
}
if ( ! file_exists( $integration_file ) ) {
	$integration_file = ABSPATH . 'ashbi-integration/security-boundaries.php';
}

// Give the authenticated runner a session-bound REST nonce in both harnesses.
add_action(
	'admin_enqueue_scripts',
	static function () {
		wp_enqueue_script( 'wp-api' );
	}
);

if ( ! function_exists( 'ashbi_run_security_boundary_integration_checks' ) && file_exists( $integration_file ) ) {
	require_once $integration_file;
}

// The Playground admin-post dispatcher can return its generic 400 response
// while a mounted plugin is transitioning between activation workers. Expose
// the same disposable callback through a local-only REST route so the runner
// can use WordPress's normal nonce/capability path without production code.
add_action(
	'rest_api_init',
	static function () {
		if ( ! function_exists( 'ashbi_run_security_boundary_integration_checks' ) ) {
			return;
		}

		$routes = rest_get_server()->get_routes();
		if ( isset( $routes['/ashbi-local/v1/security-boundaries'] ) ) {
			return;
		}

		register_rest_route(
			'ashbi-local/v1',
			'/security-boundaries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => 'ashbi_run_security_boundary_integration_checks',
			)
		);
	}
);

<?php
/** Isolated real bootstrap/account controller regression; no store or network.
 *
 * @package AshbiSubscriptions\Tests
 */
// phpcs:disable -- Isolated WordPress and unrelated module doubles.
namespace SpringDevs\Subscription {
	class Admin {}
	class Illuminate {}
	class Frontend {
		public function __construct() {
			new \SpringDevs\Subscription\Frontend\MyAccount();
		}
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'SUBSCRPT_FILE', dirname( __DIR__, 2 ) . '/plugin/subscription.php' );
	define( 'EP_ROOT', 64 );
	define( 'EP_PAGES', 4096 );
	$scenario = $argv[1] ?? 'admin';
	if ( 'ajax' === $scenario ) {
		define( 'DOING_AJAX', true );
	}
	function is_admin() { return 'frontend' !== $GLOBALS['scenario']; }
	function add_action( $hook, $callback, $priority = 10, ...$args ) {
		$GLOBALS['hooks'][ $hook ][ $priority ][] = $callback;
	}
	function add_filter( ...$args ) {}
	function register_activation_hook( ...$args ) {}
	function register_deactivation_hook( ...$args ) {}
	function plugins_url( ...$args ) { return '/plugins/subscription'; }
	function get_option( $key, $fallback = false ) {
		$options = array(
			'wpsubs_custom_subscriptions_endpoint' => 'my-plans',
			'wpsubs_custom_view_subscription_endpoint' => 'my-plan',
		);
		return $options[ $key ] ?? $fallback;
	}
	function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
	function add_rewrite_endpoint( $slug, $mask ) { $GLOBALS['endpoints'][] = array( $slug, $mask ); }
	function flush_rewrite_rules( ...$args ) { throw new \RuntimeException( 'Unexpected rewrite flush' ); }
	require dirname( __DIR__, 2 ) . '/plugin/bootstrap.php';
	sdevs_subscription()->includes();
	$callbacks = $GLOBALS['hooks']['init'] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as $callback ) {
			if ( is_array( $callback ) && $callback[0] instanceof \SpringDevs\Subscription\Frontend\MyAccount ) {
				call_user_func( $callback );
			}
		}
	}
	echo json_encode( $GLOBALS['endpoints'] ?? array() );
}

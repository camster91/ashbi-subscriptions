<?php
/** Render real customer screens with fabricated orders and cancellation storage. */
// phpcs:disable -- Isolated WordPress/WooCommerce doubles; no network or real data.
namespace SpringDevs\Subscription\Illuminate {
	class Helper {
		public static function get_subscription_data( $id ) { return array( 'status' => $GLOBALS['screen_status'], 'order' => array( 'order_id' => 1, 'order_item_id' => 2 ), 'next_date' => 'Tue, 01 Dec 2026 12:00:00 +0000', 'can_user_cancel' => true, 'is_auto_renew' => false ); }
		public static function get_related_orders( $id ) { return array(); }
		public static function get_verbose_status( $status ) { return 'pe_cancelled' === $status ? 'Pending Cancellation' : ucfirst( $status ); }
		public static function get_subscription_display_totals( ...$args ) { return array( 'total' => 12, 'full_excl' => 12, 'tax' => 0, 'discount' => 0 ); }
		public static function format_price_with_order_item( ...$args ) { return '$12 / month'; }
		public static function get_typos( ...$args ) { return 'month'; }
	}
	class Switching { public static function get_options( $id ) { return array(); } }
}
namespace SpringDevs\Subscription\Frontend {
	class PaymentMethodController { public static function get_subscription_context( ...$args ) { return null; } }
}
namespace SpringDevs\Subscription\Illuminate\Subscription {
	class Subscription { public static function get_user_endpoint( $key ) { return 'view_subs' === $key ? 'my-plan' : 'my-plans'; } }
}
namespace {
	define( 'ABSPATH', '/' );
	define( 'SUBSCRPT_TEMPLATES', dirname( __DIR__, 2 ) . '/plugin/templates/' );
	date_default_timezone_set( 'UTC' );
	function __( $text, $domain = '' ) { return $text; }
	function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
	function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $text ) { return esc_html( $text ); }
	function esc_url( $text ) { return esc_html( $text ); }
	function wp_kses_post( $text ) { return $text; }
	function add_action( ...$args ) {}
	function do_action( ...$args ) {}
	function apply_filters( $hook, $value, ...$args ) { return $value; }
	function get_current_user_id() { return 7; }
	function is_user_logged_in() { return true; }
	function current_user_can( $cap ) { return false; }
	function get_post( $id ) { return (object) array( 'post_type' => 'subscrpt_order', 'post_author' => 7 ); }
	function get_post_status( $id ) { return $GLOBALS['screen_status']; }
	function get_post_meta( $id, $key, $single = true ) {
		return array( '_subscrpt_next_date' => 1796126400, '_subscrpt_cancel_at' => time() + 86400, '_subscrpt_price' => 12, '_subscrpt_trial' => 'off' )[ $key ] ?? '';
	}
	function absint( $value ) { return abs( (int) $value ); }
	function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
	function wp_create_nonce( ...$args ) { return 'fixture'; }
	function get_option( $key, $default = false ) { return 'subscrpt_early_renew' === $key ? '0' : $default; }
	function get_permalink( ...$args ) { return '/fixture/'; }
	function get_the_title( ...$args ) { return 'Fixture'; }
	function get_the_ID() { return 99; }
	function the_ID() { echo '99'; }
	function wp_reset_postdata() {}
	function wc_get_page_permalink( ...$args ) { return '/my-account/'; }
	function wc_get_endpoint_url( $endpoint, $id = '', $base = '' ) { return $base . $endpoint . '/' . $id; }
	function subscrpt_get_action_url( $action, $nonce, $id ) { return '/my-plan/99?action=' . $action; }
	function subscrpt_is_max_payments_reached( $id ) { return false; }
	function subscrpt_get_max_payments( $id ) { return 0; }
	function subscrpt_count_payments_made( $id ) { return 1; }
	function subscrpt_get_remaining_payments( $id ) { return 'unlimited'; }
	function subscrpt_get_typos( ...$args ) { return 'month'; }
	function wc_wp_theme_get_element_class_name( ...$args ) { return ''; }
	function wc_price( $price, $args = array() ) { return '$' . $price; }
	function wc_get_order( $id ) { return new ScreenOrder(); }
	function wc_get_template( $path, $args, $unused, $base ) { extract( $args ); include $base . $path; }
	class ScreenOrder {
		public function get_item( $id ) { return new ScreenItem(); }
		public function get_status() { return 'completed'; }
		public function get_currency() { return 'USD'; }
		public function get_id() { return 1; }
		public function __call( $name, $args ) { return ''; }
	}
	class ScreenItem {
		public function get_meta( ...$args ) { return array( 'time' => '1', 'type' => 'months' ); }
		public function get_name() { return 'Fixture subscription'; }
		public function get_quantity() { return 1; }
		public function get_id() { return 2; }
		public function get_variation_id() { return 0; }
		public function get_product_id() { return 3; }
	}
	class ScreenPosts {
		public $max_num_pages = 1;
		private $seen = false;
		public function have_posts() { return ! $this->seen; }
		public function the_post() { $this->seen = true; }
	}
	class ScreenDatabase {
		public $prefix = 'wp_';
		public $last_error = '';
		public function prepare( $sql, ...$args ) { return $sql; }
		public function get_var( $sql ) { $this->last_error = $GLOBALS['screen_storage_error'] ? 'unavailable' : ''; return $GLOBALS['screen_barrier'] ? 99 : null; }
	}
	$GLOBALS['wpdb'] = new ScreenDatabase();
	require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/CancellationEvidence.php';
	require dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/ActionController.php';
	require dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/MyAccount.php';
	$account = ( new ReflectionClass( 'SpringDevs\\Subscription\\Frontend\\MyAccount' ) )->newInstanceWithoutConstructor();
	$results = array();
	foreach ( array( 'active', 'pe_cancelled', 'cancelled', 'expired', 'completed' ) as $status ) {
		foreach ( array( 'legacy', 'barrier', 'storage_error' ) as $storage ) {
			$GLOBALS['screen_status'] = $status;
			$GLOBALS['screen_barrier'] = 'barrier' === $storage;
			$GLOBALS['screen_storage_error'] = 'storage_error' === $storage;
			ob_start(); $account->view_subscrpt_content( 99 ); $single = ob_get_clean();
			$postslist = new ScreenPosts(); $current_page = 1; $wp_button_class = '';
			ob_start(); include SUBSCRPT_TEMPLATES . 'myaccount/subscriptions.php'; $list = ob_get_clean();
			$results[ $status ][ $storage ] = array( 'single' => $single, 'list' => $list, 'can_reactivate' => \SpringDevs\Subscription\Frontend\ActionController::can_reactivate_subscription( 99 ) );
		}
	}
	echo json_encode( $results );
}

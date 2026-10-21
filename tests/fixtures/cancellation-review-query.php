<?php
/**
 * Exercise the actual review query's scope and error cleanup without WordPress.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable -- Isolated query/filter/database doubles; SQL membership is tested in WordPress integration.
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function current_user_can( $capability ) { return $GLOBALS['review_authorized'] && 'manage_options' === $capability; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function wp_verify_nonce( $value, $action ) { return false; }
function add_filter( $name, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['review_filters'][] = $callback; }
function remove_filter( $name, $callback, $priority = 10 ) {
	$GLOBALS['review_filters'] = array_filter( $GLOBALS['review_filters'], static function ( $item ) use ( $callback ) { return $callback !== $item; } );
}
class ReviewDatabase {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $last_error = '';
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace_callback( '/%[is]/', static function ( $match ) use ( $arg ) { return '%i' === $match[0] ? '`' . $arg . '`' : "'" . $arg . "'"; }, $query, 1 );
		}
		return $query;
	}
}
class WP_Query {
	public $args;
	public $where;
	public function __construct( $args = null ) { if ( null !== $args ) { $this->query( $args ); } }
	public function query( $args ) {
		++$GLOBALS['review_query_count'];
		$this->args = $args;
		if ( $GLOBALS['review_nested'] ) {
			$GLOBALS['review_nested'] = false;
			$nested = new self( array( 'post_type' => 'post' ) );
			$GLOBALS['review_nested_where'] = $nested->where;
		}
		$this->where = ' WHERE wp_posts.ID > 0';
		foreach ( $GLOBALS['review_filters'] as $callback ) { $this->where = $callback( $this->where, $this ); }
		if ( 'exception' === $GLOBALS['review_failure'] ) { throw new RuntimeException( 'fabricated_query_exception' ); }
		$GLOBALS['wpdb']->last_error = 'storage' === $GLOBALS['review_failure'] ? 'fabricated private SQL failure' : '';
	}
}
require dirname( __DIR__, 2 ) . '/plugin/includes/Admin/CancellationReview.php';
$result = array();
$GLOBALS['review_query_count'] = 0;
foreach ( array( 'success', 'storage', 'exception' ) as $case ) {
	$GLOBALS['wpdb'] = new ReviewDatabase();
	$GLOBALS['review_filters'] = array();
	$GLOBALS['review_nested'] = true;
	$GLOBALS['review_failure'] = $case;
	$error = null;
	$query = null;
	try { $query = \SpringDevs\Subscription\Admin\CancellationReview::query( array( 'post_type' => 'post', 'posts_per_page' => 20, 'paged' => 2, 'p' => 99 ) ); }
	catch ( RuntimeException $exception ) { $error = $exception->getMessage(); }
	$result[ $case ] = array( 'error' => $error, 'nested' => $GLOBALS['review_nested_where'], 'filters' => count( $GLOBALS['review_filters'] ), 'args' => $query ? $query->args : null, 'where' => $query ? $query->where : null );
}
require dirname( __DIR__, 2 ) . '/plugin/includes/Admin/Menu.php';
class ReviewMenu extends \SpringDevs\Subscription\Admin\Menu {
	public function render_admin_header( string $title = '', string $subtitle = '', array $breadcrumbs = array() ) {}
}
foreach ( array( 'permission', 'nonce' ) as $case ) {
	$GLOBALS['review_authorized'] = 'nonce' === $case;
	$_GET = array();
	$_POST = array( 'filter_action' => 'filter', 'cancellation_review' => '1', '_wpnonce' => 'invalid' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$before = $GLOBALS['review_query_count'];
	$menu = ( new ReflectionClass( 'ReviewMenu' ) )->newInstanceWithoutConstructor();
	try { $menu->render_subscriptions_page(); $denied = false; }
	catch ( RuntimeException $error ) { $denied = true; }
	$result['access'][ $case ] = array( 'denied' => $denied, 'queries' => $GLOBALS['review_query_count'] - $before );
}
echo json_encode( $result );

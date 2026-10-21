<?php
/** Isolated real-adapter regressions. No WordPress, mail or gateway connections. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['records']     = array();
$GLOBALS['cfg']         = array(
	'enabled'       => true,
	'form_name'     => 'Support review',
	'owner_user_id' => 1,
);
$GLOBALS['admin']       = true;
$GLOBALS['nonce']       = true;
$GLOBALS['fail_insert'] = false;
$GLOBALS['fail_update'] = false;
$GLOBALS['hooks']       = array();
class WP_Error {
	public $code;
	public function __construct( $code ) {
		$this->code = $code; }
}
class Review_Test_Die extends Exception {}
class Review_Test_Redirect extends Exception {}
function is_wp_error( $v ) {
	return $v instanceof WP_Error; }
function apply_filters( $name, $value ) {
	return $GLOBALS['cfg']; }
function add_action( $name, $callback, ...$args ) {
	$GLOBALS['hooks'][ $name ] = $callback; }
function current_user_can( $cap ) {
	return $GLOBALS['admin']; }
function user_can( $id, $cap ) {
	return 1 === $id; }
function get_current_user_id() {
	return $GLOBALS['admin'] ? 1 : 9; }
function wp_strip_all_tags( $s ) {
	return strip_tags( $s ); }
function is_email( $s ) {
	return filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function wp_json_encode( $v ) {
	return json_encode( $v ); }
function wp_slash( $v ) {
	return is_array( $v ) ? array_map( 'wp_slash', $v ) : addslashes( $v ); }
function wp_unslash( $v ) {
	return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( $v ); }
function get_post( $id ) {
	return isset( $GLOBALS['records'][ $id ] ) ? clone $GLOBALS['records'][ $id ] : null; }
function wp_insert_post( $data, $error = false ) {
	if ( $GLOBALS['fail_insert'] ) {
		return new WP_Error( 'insert_failed' ); }
	$data                      = wp_unslash( $data );
	$id                        = count( $GLOBALS['records'] ) + 1;
	$GLOBALS['records'][ $id ] = (object) array_merge(
		array(
			'ID'            => $id,
			'post_excerpt'  => '',
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ),
		),
		$data
	);
	return $id;
}
function wp_update_post( $data, $error = false ) {
	if ( $GLOBALS['fail_update'] ) {
		return new WP_Error( 'update_failed' ); }
	$data = wp_unslash( $data );
	$id   = $data['ID'];
	foreach ( $data as $key => $value ) {
		$GLOBALS['records'][ $id ]->$key = $value; }
	return $id;
}
function get_post_meta( $id, $key, $single ) {
	return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function sanitize_textarea_field( $s ) {
	return strip_tags( $s ); }
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function absint( $s ) {
	return abs( (int) $s ); }
function wp_die( $s, ...$args ) {
	throw new Review_Test_Die( $s ); }
function check_admin_referer( $action ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This fixture implements the nonce verifier itself.
	if ( ! $GLOBALS['nonce'] || $action !== 'ashbi_contact_resolve_' . ( $_POST['request_id'] ?? '' ) ) {
		wp_die( 'bad_nonce' ); } }
function admin_url( $s ) {
	return 'https://example.invalid/wp-admin/' . $s; }
function wp_safe_redirect( $s ) {
	throw new Review_Test_Redirect( $s ); }
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) {
	return esc_html( $s ); }
function esc_url( $s ) {
	return esc_html( $s ); }
function get_userdata( $id ) {
	return (object) array( 'display_name' => 'Test reviewer' ); }
function wp_nonce_field( $s ) {
	echo '<input name="_wpnonce" value="fixture">'; }
class WP_Query {
	public $posts;
	public $max_num_pages = 1;
	public function __construct( $args ) {
		$this->posts = array_values(
			array_filter(
				$GLOBALS['records'],
				static function ( $p ) use ( $args ) {
					return $p->post_type === $args['post_type'] && $p->post_status === $args['post_status'];
				}
			)
		); }
}
class Review_DB {
	public $prefix      = 'fixture_';
	public $posts       = 'fixture_posts';
	public $postmeta    = 'fixture_postmeta';
	public $last_error  = '';
	public $busy        = false;
	public $fail_lookup = false;
	public $barrier     = false;
	public $released    = 0;
	public function prepare( $q, ...$args ) {
		return array( $q, $args ); }
	public function get_var( $q ) {
		$this->last_error   = '';
		list( $sql, $args ) = $q;
		if ( strpos( $sql, 'GET_LOCK' ) !== false ) {
			return $this->busy ? 0 : 1; }
		if ( strpos( $sql, 'RELEASE_LOCK' ) !== false ) {
			++$this->released;
			return 1; }
		if ( $this->fail_lookup ) {
			$this->last_error = 'simulated unavailable storage';
			return null; }
		if ( strpos( $args[0] ?? '', 'subscrpt_cancellation_barrier' ) !== false ) {
			return $this->barrier ? $args[1] : null; }
		foreach ( $GLOBALS['records'] as $p ) {
			if ( $p->post_type === $args[0] && 'private' === $p->post_status && $p->post_title === $args[1] ) {
					return $p->ID; }
		}
		return null;
	}
	public function get_row( $q ) {
		$this->last_error = $this->fail_lookup ? 'unavailable' : '';
		if ( strpos( $q[0], 'MIN(CASE' ) !== false ) {
			if ( ! $this->barrier ) {
				return null;
			}
			$flags = $GLOBALS['stored_flags'] ?? array( $GLOBALS['meta'][ $q[1][4] ]['_ashbi_cancellation_confirmed'] ?? '' );
			$confirmed = count( $flags ) > 0;
			foreach ( $flags as $flag ) {
				$confirmed = $confirmed && '1' === (string) $flag;
			}
			return (object) array( 'access_end' => 0, 'confirmed' => $confirmed ? '1' : '0' );
		}
		$rows             = array_filter(
			$GLOBALS['records'],
			static function ( $p ) {
				return 'ashbi_contact_review' === $p->post_type && 'private' === $p->post_status;
			}
		);
		return (object) array(
			'total'  => count( $rows ),
			'oldest' => $rows ? min( array_column( $rows, 'post_date_gmt' ) ) : null,
		);
	}
}
$wpdb = new Review_DB();
require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/CancellationEvidence.php';
require dirname( __DIR__, 2 ) . '/tools/contact-request-review/ashbi-contact-request-review.php';
$checks = array();
function verify_review( $truth, $label ) {
	global $checks;
	if ( ! $truth ) {
		throw new RuntimeException( $label );
	} $checks[] = $label; }
function err_review( $v, $code ) {
	return is_wp_error( $v ) && $v->code === $code; }
$adapter                   = 'Ashbi_Contact_Request_Review';
$initial                   = count( $GLOBALS['records'] );
$GLOBALS['cfg']['enabled'] = false;
verify_review( err_review( $adapter::capture( 'owner@example.invalid', 'Please stop billing.' ), 'disabled' ), 'disabled intake stores nothing' );
$GLOBALS['cfg']['enabled']       = true;
$GLOBALS['cfg']['owner_user_id'] = 9;
verify_review( ! $adapter::config(), 'owner must be an authorized site manager' );
$GLOBALS['cfg']['owner_user_id'] = 1;
$id                              = $adapter::capture( 'Owner@Example.invalid', 'Please cancel my subscription. Thank you.', 'Synthetic owner' );
verify_review( is_int( $id ), 'explicit cancellation is durably queued' );
verify_review( $id === $adapter::capture( 'owner@example.invalid', 'Please cancel my subscription. Thank you.' ), 'open duplicate does not create another request' );
verify_review( is_int( $adapter::capture( 'second@example.invalid', 'I need to stop the next shipment.' ) ), 'wording without cancel is still queued' );
verify_review( is_int( $adapter::capture( 'third@example.invalid', 'Where is my parcel?' ) ), 'ordinary messages are never silently filtered out' );
verify_review( err_review( $adapter::capture( 'bad', 'cancel' ), 'invalid_request' ), 'invalid address refused' );
verify_review( err_review( $adapter::capture( 'ok@example.invalid', str_repeat( 'x', 10001 ) ), 'invalid_request' ), 'oversized message refused' );
verify_review( err_review( $adapter::capture( 'ok@example.invalid', '<b></b>' ), 'invalid_request' ), 'empty stripped message refused' );
$wpdb->busy = true;
verify_review( err_review( $adapter::capture( 'ok@example.invalid', 'message' ), 'busy' ), 'busy duplicate lock does not claim receipt' );
$wpdb->busy        = false;
$wpdb->fail_lookup = true;
verify_review( err_review( $adapter::capture( 'ok@example.invalid', 'message' ), 'storage_unavailable' ), 'lookup failure does not claim receipt' );
$wpdb->fail_lookup      = false;
$GLOBALS['fail_insert'] = true;
verify_review( err_review( $adapter::capture( 'ok@example.invalid', 'message' ), 'storage_failed' ), 'insert failure does not claim receipt' );
$GLOBALS['fail_insert'] = false;
$note                   = 'Checked the exact account and replied; reference TEST-123.';
$GLOBALS['admin']       = false;
verify_review( err_review( $adapter::resolve( $id, 'other_request', 0, $note, true ), 'forbidden' ), 'customer cannot close a request' );
$GLOBALS['admin'] = true;
verify_review( err_review( $adapter::resolve( $id, 'cancellation_confirmed', 99, $note, true ), 'cancellation_not_confirmed' ), 'missing subscription cannot claim cancellation' );
$GLOBALS['records'][99]                               = (object) array(
	'ID'           => 99,
	'post_type'    => 'subscrpt_order',
	'post_status'  => 'active',
	'post_title'   => '',
	'post_content' => '',
	'post_excerpt' => '',
);
$GLOBALS['meta'][99]['_ashbi_cancellation_confirmed'] = 1;
$wpdb->barrier                                        = true;
verify_review( ! $adapter::confirmed( 99 ), 'active subscription is never confirmed cancelled' );
$GLOBALS['records'][99]->post_status = 'cancelled';
$wpdb->barrier                       = false;
verify_review( ! $adapter::confirmed( 99 ), 'status alone without durable barrier is insufficient' );
$wpdb->barrier     = true;
$wpdb->fail_lookup = true;
verify_review( ! $adapter::confirmed( 99 ), 'unreadable barrier is not cancellation proof' );
$wpdb->fail_lookup = false;
$GLOBALS['stored_flags'] = array( 0 );
verify_review( ! $adapter::confirmed( 99 ), 'stale cached confirmation cannot close a request' );
$GLOBALS['stored_flags'] = array( 0, 1 );
verify_review( ! $adapter::confirmed( 99 ), 'conflicting confirmation flags cannot close a request' );
unset( $GLOBALS['stored_flags'] );
verify_review( err_review( $adapter::resolve( $id, 'cancellation_confirmed', 99, $note, false ), 'review_required' ), 'customer reply attestation required' );
verify_review( err_review( $adapter::resolve( $id, 'other_request', 0, 'done', true ), 'review_required' ), 'unexplained dismissal rejected' );
$source                 = get_post( $id )->post_content;
$GLOBALS['fail_update'] = true;
verify_review( err_review( $adapter::resolve( $id, 'cancellation_confirmed', 99, $note, true ), 'closure_unverified' ) && 'private' === get_post( $id )->post_status, 'failed closure stays open' );
$GLOBALS['fail_update'] = false;
verify_review( true === $adapter::resolve( $id, 'cancellation_confirmed', 99, $note, true ), 'confirmed cancellation can be closed' );
verify_review( $source === get_post( $id )->post_content && 1 === json_decode( get_post( $id )->post_excerpt, true )['actor_id'], 'source preserved with staff outcome attribution' );
verify_review( err_review( $adapter::resolve( $id, 'other_request', 0, $note, true ), 'not_open' ), 'replay cannot rewrite closed outcome' );
verify_review( is_int( $again = $adapter::capture( 'owner@example.invalid', 'Please cancel my subscription. Thank you.' ) ) && $again !== $id, 'new request after closure is not swallowed' );
class Review_Record {
	public $name = 'Support review';
	public function get_form_settings( $s ) {
		return $this->name;
	} public function get( $s ) {
		return array(
			'email'   => array( 'value' => 'fourth@example.invalid' ),
			'message' => array( 'value' => 'Stop my next delivery, please.' ),
		); }
}
class Review_Handler {
	public $errors = array();
	public $data   = array();
	public function add_error_message( $s ) {
		$this->errors[] = $s;
	} public function add_response_data( $k, $v ) {
		$this->data[ $k ] = $v; }
}
$record       = new Review_Record();
$handler      = new Review_Handler();
$before       = count( $GLOBALS['records'] );
$record->name = 'Unrelated form';
$adapter::process( $record, $handler );
verify_review( $before === count( $GLOBALS['records'] ), 'unconfigured form ignored' );
$record->name           = 'Support review';
$GLOBALS['fail_insert'] = true;
$adapter::process( $record, $handler );
verify_review( count( $handler->errors ) === 1 && empty( $handler->data ), 'storage failure returns form error without receipt' );
$GLOBALS['fail_insert'] = false;
$handler                = new Review_Handler();
$adapter::process( $record, $handler );
verify_review( isset( $handler->data['ashbi_request_reference'] ), 'successful process returns durable request reference' );
// Simulated email failure after the documented pre-email hook leaves the receipt intact.
verify_review( $before + 1 === count( $GLOBALS['records'] ), 'later notification failure cannot remove queued request' );
$_POST            = array(
	'request_id' => $again,
	'outcome'    => 'other_request',
	'note'       => $note,
	'notified'   => '1',
);
$GLOBALS['nonce'] = false;
try {
	$adapter::handle();
	verify_review( false, 'bad nonce must reject' );
} catch ( Review_Test_Die $e ) {
	verify_review( 'private' === get_post( $again )->post_status, 'invalid nonce cannot close request' ); }
$GLOBALS['nonce'] = true;
$GLOBALS['admin'] = false;
try {
	$adapter::handle();
	verify_review( false, 'customer HTTP must reject' );
} catch ( Review_Test_Die $e ) {
	verify_review( 'private' === get_post( $again )->post_status, 'HTTP capability check precedes mutation' ); }
$GLOBALS['admin'] = true;
$attack           = $adapter::capture( 'safe@example.invalid', '<script>alert(1)</script> " & <tag>payload</tag>' );
ob_start();
$adapter::page();
$html = ob_get_clean();
verify_review( strpos( $html, '<script>' ) === false && strpos( $html, '&quot; &amp;' ) !== false, 'staff view escapes untrusted source content' );
$GLOBALS['records'][ $again ]->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
ob_start();
$adapter::notice();
$notice = ob_get_clean();
verify_review( strpos( $notice, '24 hours' ) !== false, 'overdue open request has persistent warning' );
verify_review( isset( $GLOBALS['hooks']['elementor_pro/forms/process'] ) && ! isset( $GLOBALS['hooks']['elementor_pro/forms/new_record'] ), 'intake hook runs before email actions' );
verify_review( $GLOBALS['records'][99]->post_status === 'cancelled' && count( $GLOBALS['meta'][99] ) === 1, 'review adapter does not modify subscription or payment state' );
echo json_encode(
	array(
		'passed' => count( $checks ),
		'checks' => $checks,
	)
);

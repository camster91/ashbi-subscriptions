<?php
/** Process fixture for the privileged overdue-disposition command. */

define( 'ABSPATH', '/' );
define( 'WP_CLI', true );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

final class WP_CLI {
	/** @var string[] */
	public static $messages = array();
	public static function line( $message ) { self::$messages[] = (string) $message; }
	public static function success( $message ) { self::$messages[] = (string) $message; }
	public static function error( $message ) { throw new RuntimeException( (string) $message ); }
}

final class Ashbi_Fake_Wpdb {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public function prepare( $query, ...$args ) { return $query; }
	public function get_var( $query ) { return false; }
}

$GLOBALS['wpdb'] = new Ashbi_Fake_Wpdb();
$GLOBALS['ashbi_fixture_meta'] = array(
	42 => array(
		'_subscrpt_next_date'            => strtotime( '2024-01-15T12:00:00+00:00' ),
		'_subscrpt_order_item_id'         => 7,
		'_subscrpt_order_id'              => 0,
		'_subscrpt_auto_renew'            => 'yes',
		'_subscrpt_payment_failure_count' => 0,
	),
);
$preexisting = 'preexisting' === getenv( 'ASHBI_FIXTURE_SCENARIO' );
$GLOBALS['ashbi_fixture_options'] = array(
	'subscrpt_renewal_claim_quarantine_1'   => array(),
	'subscrpt_overdue_renewal_quarantine_1' => array( 42 ),
	'subscrpt_renewal_operator_hold_1'      => $preexisting ? array( 42, 99 ) : array( 99 ),
	'subscrpt_renewal_migration_blocked'    => array( 42, 99 ),
);

function site_url() { return 'https://client.example'; }
function get_post( $id ) { return 42 === (int) $id ? (object) array( 'post_type' => 'subscrpt_order', 'post_status' => 'active' ) : null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['ashbi_fixture_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['ashbi_fixture_meta'][ $id ][ $key ] = $value; return true; }
function get_option( $key, $default = false ) { return $GLOBALS['ashbi_fixture_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['ashbi_fixture_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['ashbi_fixture_options'][ $key ] ); return true; }
function wc_get_order_item_meta( $id, $key ) { return array( 'time' => 1, 'type' => 'years' ); }
function wc_get_order( $id ) { return false; }
function subscrpt_is_max_payments_reached( $id ) { return false; }
function sdevs_wp_strtotime( $str, $base = null ) { return (int) strtotime( '+' . $str, (int) $base ); }

$repo = dirname( __DIR__, 2 );
require_once $repo . '/tools/lib/OverdueDispositionPlan.php';
require_once $repo . '/tools/lib/overdue-evidence.php';

$record = ashbi_overdue_evidence_record( 42, time() );
$record['operator_disposition'] = 'advance_without_charge';
$worksheet = array(
	'schema_version'       => 2,
	'generated_at'         => gmdate( 'c' ),
	'site_url'             => site_url(),
	'record_count'         => 1,
	'allowed_dispositions' => \Ashbi\Subscriptions\Tools\OverdueDispositionPlan::dispositions(),
	'records'              => array( $record ),
);
$path = tempnam( sys_get_temp_dir(), 'ashbi-worksheet-' );
file_put_contents( $path, json_encode( $worksheet ) );
putenv( 'ASHBI_DISPOSITION_WORKSHEET=' . $path );
putenv( 'ASHBI_EXPECTED_SITE_URL=' . site_url() );
if ( 'apply' === getenv( 'ASHBI_FIXTURE_MODE' ) ) {
	putenv( 'ASHBI_DISPOSITION_MODE=apply-no-charge' );
	putenv( 'ASHBI_DISPOSITION_CONFIRM=' . \Ashbi\Subscriptions\Tools\OverdueDispositionPlan::validate( $worksheet, site_url() ) );
}

$error = null;
try {
	require $repo . '/tools/apply-overdue-dispositions.php';
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
}
unlink( $path );
echo json_encode(
	array(
		'error'    => $error,
		'messages' => WP_CLI::$messages,
		'meta'     => $GLOBALS['ashbi_fixture_meta'][42],
		'options'  => $GLOBALS['ashbi_fixture_options'],
	)
);

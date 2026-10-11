<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple isolated doubles deliberately share this fixture.
/**
 * Isolated WordPress/WooCommerce doubles; runs actual wizard PHP handlers.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,Generic.Files.OneObjectStructurePerFile.MultipleFound,Universal.Files.SeparateFunctionsFromOO.Mixed,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.VariableComment.Missing,Generic.CodeAnalysis.UnusedFunctionParameter,WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Isolated API doubles keep compatible signatures and fabricate globals/JSON without WordPress. Nonce/SQL security sniffs remain enabled.
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
function current_time( $type, $gmt = false ) {
	return '2026-01-01 00:00:00'; }
function wp_cache_delete( $key, $group ) {}
function get_post_meta( $id, $key, $single ) {
	return $GLOBALS['metadata'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['metadata'][ $id ][ $key ] = $value; }
function rest_ensure_response( $value ) {
	return $value; }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
class WP_REST_Request implements ArrayAccess {
	public $params;
	public function __construct( $params ) {
		$this->params = $params; }
	public function get_json_params() {
		return $this->params; }
	public function get_params() {
		return $this->params; }
	public function offsetExists( $key ): bool {
		return isset( $this->params[ $key ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $key ) {
		return $this->params[ $key ] ?? null; }
	public function offsetSet( $key, $value ): void {
		$this->params[ $key ] = $value; }
	public function offsetUnset( $key ): void {
		unset( $this->params[ $key ] ); }
}
class FixtureDB {
	public $prefix    = 'wp_';
	public $rows      = array();
	public $insert_id = 0;
	public function prepare( $sql, ...$args ) {
		return json_encode( array( $sql, $args ) ); }
	public function insert( $table, $row ) {
		$row['id']                          = ++$this->insert_id;
		$this->rows[ $table ][ $row['id'] ] = $row + array(
			'data'         => '{}',
			'offer'        => '{}',
			'signup_fee'   => '{}',
			'product_type' => 1,
			'type'         => 2,
			'vid'          => 0,
			'exclude'      => 0,
		);
		return 1; }
	public function update( $table, $row, $where ) {
		$id                          = $where['id'];
		$this->rows[ $table ][ $id ] = array_replace( $this->rows[ $table ][ $id ], $row );
		return 1; }
	public function get_row( $query, $format ) {
		$rows = $this->get_results( $query, $format );
		return $rows[0] ?? null; }
	public function get_results( $query, $format ) {
		$parts = json_decode( $query, true );
		if ( ! $parts ) {
			return array();
		}
		list( $sql, $args ) = $parts;
		if ( ! preg_match( '/SELECT \* FROM (\w+) WHERE (\w+) = %d/', $sql, $match ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$this->rows[ $match[1] ] ?? array(),
				static function ( $row ) use ( $match, $args ) {
					return (int) $row[ $match[2] ] === (int) $args[0];
				}
			)
		);
	}
	public function get_var( $query ) {
		return null; }
	public function get_col( $query ) {
		return array(); }
}
function __( $text, $domain = '' ) {
	return $text; }
function sanitize_text_field( $text ) {
	return trim( $text ); }
function sanitize_key( $text ) {
	return $text; }
function wp_unslash( $text ) {
	return $text; }
function absint( $value ) {
	return abs( (int) $value ); }
function wp_json_encode( $value ) {
	return json_encode( $value ); }
function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) {
	return esc_html( $value ); }
function esc_url( $value ) {
	return esc_html( $value ); }
function esc_html_e( $value, $domain = '' ) {
	print esc_html( $value ); }
function esc_attr_e( $value, $domain = '' ) {
	print esc_attr( $value ); }
function admin_url( $value ) {
	return '/admin/' . $value; }
function get_woocommerce_currency_symbol() {
	return '$'; }
function wc_get_products( $args ) {
	return array(); }
function wp_nonce_field( $action, $name ) {
	print '<input id="' . esc_attr( $name ) . '" value="fixture-nonce">'; }
function wpsubs_render_adv_select( $args ) {
	print '<div data-dur-interval><input type="hidden" value="month"></div>'; }
class WP_Error {
	public $code;
	public function __construct( $code, $message, $data ) {
		$this->code = $code; }
}
function current_user_can( $cap ) {
	return $GLOBALS['allowed']; }
function get_current_user_id() {
	return 7; }
function check_ajax_referer( $action, $key ) {
	if ( empty( $GLOBALS['nonce'] ) ) {
		throw new RuntimeException( 'nonce' );
	} }
function wc_format_decimal( $value ) {
	return $value; }
function wc_get_price_decimals() {
	return 2; }
function wc_get_product( $id ) {
	return $GLOBALS['products'][ $id ] ?? false; }
function wp_send_json_success( $data = array() ) {
	throw new FixtureResponse(
		array(
			'success' => true,
			'data'    => $data, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON data captured by test termination, not an HTML exception message.
		)
	); }
function wp_send_json_error( $data, $status = 200 ) {
	throw new FixtureResponse(
		array(
			'success' => false,
			'data'    => $data, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON data captured by test termination, not an HTML exception message.
			'status'  => $status, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Numeric fixture HTTP status, not HTML.
		)
	); }
class FixtureResponse extends RuntimeException {
	public $response;
	public function __construct( $response ) {
		$this->response = $response; }
}
class WC_Product_Simple {
	public $status = '';
	public $name   = '';
	public $meta   = array();
	public $id     = 51;
	public function set_name( $name ) {
		$this->name = $name; }
	public function set_regular_price( $price ) {}
	public function set_status( $status ) {
		$this->status = $status; }
	public function get_status() {
		return $this->status; }
	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value; }
	public function save() {
		$GLOBALS['products'][ $this->id ] = clone $this;
		return $this->id; }
}
require dirname( __DIR__, 2 ) . '/plugin/includes/Admin/OnboardingAjax.php';
$GLOBALS['allowed'] = true;
$GLOBALS['nonce']   = true;
$ajax               = ( new ReflectionClass( '\SpringDevs\Subscription\Admin\OnboardingAjax' ) )->newInstanceWithoutConstructor();
$mode               = $argv[1] ?? 'create';
$_POST              = array(
	'product_name'      => 'Box',
	'product_id'        => 51,
	'publish_confirmed' => 'yes',
);
try {
	if ( 'template' === $mode ) {
		require dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/onboarding-wizard.php';
	} elseif ( in_array( $mode, array( 'commitment', 'draft-relation', 'persistence' ), true ) ) {
		require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Plans/PlanRepository.php';
		require dirname( __DIR__, 2 ) . '/plugin/includes/Api/PlanController.php';
		require dirname( __DIR__, 2 ) . '/plugin/includes/functions.php';
		require dirname( __DIR__, 2 ) . '/plugin/includes/Admin/PlanPresenter.php';
		require dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Plans/PlanPrice.php';
		require dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/PlanCheckout.php';
		$controller = new \SpringDevs\Subscription\Api\PlanController();
		if ( 'persistence' === $mode ) {
			$GLOBALS['wpdb'] = new FixtureDB();
			$group           = $controller->create_group(
				new WP_REST_Request(
					array(
						'title'        => 'Split',
						'type'         => 'installments',
						'product_type' => 1,
						'status'       => 'draft',
					)
				)
			);
			$seed            = $group['plans'][0];
			$request         = array(
				'id'                => $seed['id'],
				'plan_group_id'     => $group['id'],
				'type'              => 'installments',
				'title'             => 'Monthly',
				'billing_frequency' => 1,
				'billing_interval'  => 3,
				'billing_length'    => 0,
				'free_trial'        => '',
				'signup_fee'        => array( 'amount' => '' ),
				'status'            => 'draft',
				'data'              => array(
					'free_trial_interval' => 'day',
					'installment_count'   => 5,
				),
			);
			$controller->update_term( new WP_REST_Request( $request ) );
			$refused = $controller->update_term(
				new WP_REST_Request(
					array(
						'id'   => $seed['id'],
						'data' => array( 'free_trial_interval' => 'day' ),
					)
				)
			);
			$controller->update_term(
				new WP_REST_Request(
					array(
						'id'     => $seed['id'],
						'status' => 'active',
					)
				)
			);
			$saved    = $controller->get_term( new WP_REST_Request( array( 'id' => $seed['id'] ) ) );
			$checkout = ( new ReflectionClass( '\SpringDevs\Subscription\Frontend\PlanCheckout' ) )->newInstanceWithoutConstructor();
			$quote    = new ReflectionMethod( $checkout, 'term_terms' );
			$quote->setAccessible( true );
			$quoted = $quote->invoke(
				$checkout,
				array(
					'relation_data'     => array( 'regular_price' => '10.00' ),
					'plan_data'         => $saved['data'],
					'group_type'        => 3,
					'billing_frequency' => $saved['billing_frequency'],
					'billing_interval'  => $saved['billing_interval'],
					'billing_length'    => 0,
					'free_trial'        => $saved['free_trial'],
					'signup_fee'        => $saved['signup_fee'],
				)
			);
			print json_encode(
				array(
					'seed'    => $seed,
					'saved'   => $saved,
					'refused' => $refused instanceof WP_Error,
					'quote'   => $quoted,
				)
			);
			exit;
		}
		if ( 'draft-relation' === $mode ) {
			$GLOBALS['wpdb']         = new FixtureDB();
			$repo                    = '\SpringDevs\Subscription\Illuminate\Plans\PlanRepository';
			$gid                     = $repo::insert_group(
				array(
					'title'        => 'Split',
					'type'         => 3,
					'product_type' => 1,
					'status'       => 'draft',
				)
			);
			$tid                     = $repo::insert_plan(
				array(
					'plan_group_id' => $gid,
					'title'         => 'Monthly',
					'type'          => 3,
					'status'        => 'draft',
					'data'          => array( 'installment_count' => 3 ),
				)
			);
			$GLOBALS['metadata'][51] = array(
				'_subscrpt_enabled'          => '',
				'_subscrpt_one_time_enabled' => 'yes',
			);
			$before                  = $GLOBALS['metadata'][51];
			$record                  = $controller->create_relation(
				new WP_REST_Request(
					array(
						'plan_id' => $tid,
						'oid'     => 51,
						'vid'     => 0,
						'type'    => 1,
						'status'  => 'draft',
						'data'    => array( 'regular_price' => '10.00' ),
					)
				)
			);
			$draft                   = $GLOBALS['metadata'][51];
			$controller->update_group(
				new WP_REST_Request(
					array(
						'id'     => $gid,
						'status' => 'active',
					)
				)
			);
			$controller->update_relation(
				new WP_REST_Request(
					array(
						'id'     => $record['id'],
						'status' => 'active',
					)
				)
			);
			print json_encode(
				array(
					'before'   => $before,
					'draft'    => $draft,
					'active'   => $GLOBALS['metadata'][51],
					'relation' => $repo::get_relation( $record['id'] ),
				)
			);
			exit;
		}
		$validate = new ReflectionMethod( $controller, 'validate_installment_commitment' );
		$validate->setAccessible( true );
		$cases = array();
		foreach ( array( null, 0, 1, 2.5, '3', 3, 9007199254740992 ) as $count ) {
			$result  = $validate->invoke( $controller, array( 'data' => array( 'installment_count' => $count ) ), array( 'type' => 3 ) );
			$cases[] = ! ( $result instanceof WP_Error );
		}
		$prepare = new ReflectionMethod( '\SpringDevs\Subscription\Illuminate\Plans\PlanRepository', 'prepare_plan_columns' );
		$prepare->setAccessible( true );
		$stored   = $prepare->invoke(
			null,
			array(
				'type'           => 'installments',
				'status'         => 'draft',
				'billing_length' => 0,
				'data'           => array(
					'free_trial_interval' => 'day',
					'installment_count'   => 3,
				),
			)
		);
		$checkout = ( new ReflectionClass( '\SpringDevs\Subscription\Frontend\PlanCheckout' ) )->newInstanceWithoutConstructor();
		$quote    = new ReflectionMethod( $checkout, 'term_terms' );
		$quote->setAccessible( true );
		$quoted = $quote->invoke(
			$checkout,
			array(
				'relation_data'     => array( 'regular_price' => '10.00' ),
				'plan_data'         => json_decode( $stored['data'], true ),
				'group_type'        => 3,
				'billing_frequency' => 1,
				'billing_interval'  => 3,
				'billing_length'    => 0,
				'free_trial'        => '',
				'signup_fee'        => array( 'amount' => '' ),
			)
		);
		print json_encode(
			array(
				'valid'  => $cases,
				'stored' => $stored,
				'quote'  => $quoted,
			)
		);
	} elseif ( 'create' === $mode ) {
		try {
			$ajax->create_wizard_product(); } catch ( FixtureResponse $result ) {
			// Capture WordPress JSON termination and inspect its response below.
			if ( ! isset( $result->response ) ) {
				throw new RuntimeException( 'Fixture JSON response is missing.' );
			}
			}
			print json_encode(
				array(
					'response'      => $result->response,
					'stored_status' => wc_get_product( 51 )->get_status(),
				)
			);
	} elseif ( 'publish' === $mode || 'existing' === $mode || 'no-confirmation' === $mode ) {
		$product = new WC_Product_Simple();
		$product->set_status( 'draft' );
		if ( 'existing' !== $mode ) {
			$product->update_meta_data( '_subscrpt_wizard_created_by', 7 );
		}
		$product->save();
		if ( 'no-confirmation' === $mode ) {
			unset( $_POST['publish_confirmed'] );
		}
		try {
			$ajax->publish_wizard_product(); } catch ( FixtureResponse $result ) {
			// Capture WordPress JSON termination and inspect its response below.
			if ( ! isset( $result->response ) ) {
				throw new RuntimeException( 'Fixture JSON response is missing.' );
			}
			}
			print json_encode(
				array(
					'response'      => $result->response,
					'stored_status' => wc_get_product( 51 )->get_status(),
				)
			);
	} elseif ( 'permission' === $mode ) {
		$GLOBALS['allowed'] = false;
		try {
			$ajax->create_wizard_product(); } catch ( FixtureResponse $result ) {
			// Capture WordPress JSON termination and inspect its response below.
			if ( ! isset( $result->response ) ) {
				throw new RuntimeException( 'Fixture JSON response is missing.' );
			}
			}
			print json_encode( $result->response );
	} elseif ( 'nonce' === $mode ) {
		$GLOBALS['nonce'] = false;
		try {
			$ajax->create_wizard_product();
		} catch ( RuntimeException $result ) {
			print json_encode(
				array(
					'blocked'  => $result->getMessage(),
					'products' => count( $GLOBALS['products'] ?? array() ),
				)
			); }
	}
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Isolated CLI fixture diagnostic, not a WordPress filesystem operation.
	exit( 1 ); }

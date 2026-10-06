<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Isolated migration runtime double: no WordPress, network or real database.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,Generic.Files.OneObjectStructurePerFile.MultipleFound,Universal.Files.SeparateFunctionsFromOO.Mixed,Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden,Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden,Universal.Namespaces.OneDeclarationPerFile.MultipleFound,Generic.CodeAnalysis.UnusedFunctionParameter -- Isolated subprocess runtime fixtures deliberately group doubles.
namespace SpringDevs\Subscription\Illuminate\Plans {
	/** In-memory plan persistence double. */
	class PlanRepository {
		const REL_PRODUCT = 1;
		/**
		 * Lookup fixture groups.
		 *
		 * @return array
		 */
		public static function get_groups() {
			return array_values( $GLOBALS['catalogue']['groups'] ); }
		/**
		 * Filter fixture terms.
		 *
		 * @param int $id Group id.
		 * @return array
		 */
		public static function get_plans( $id ) {
			return array_values(
				array_filter(
					$GLOBALS['catalogue']['terms'],
					static function ( $row ) use ( $id ) {
						return $row['plan_group_id'] === $id;
					}
				)
			); }
		/**
		 * Get group double.
		 *
		 * @param int $id ID.
		 * @return mixed
		 */
		public static function get_group( $id ) {
			return $GLOBALS['catalogue']['groups'][ $id ] ?? null; }
		/**
		 * Get term double.
		 *
		 * @param int $id ID.
		 * @return mixed
		 */
		public static function get_plan( $id ) {
			return $GLOBALS['catalogue']['terms'][ $id ] ?? null; }
		/**
		 * Get relation double.
		 *
		 * @param int $id ID.
		 * @return mixed
		 */
		public static function get_relation( $id ) {
			return $GLOBALS['catalogue']['relations'][ $id ] ?? null; }
		/**
		 * Relations for a term.
		 *
		 * @param int $id Term id.
		 * @return array
		 */
		public static function get_relations( $id ) {
			return array_values(
				array_filter(
					$GLOBALS['catalogue']['relations'],
					static function ( $row ) use ( $id ) {
						return $row['plan_id'] === $id;
					}
				)
			); }
		/**
		 * Connections double.
		 *
		 * @param int $id Product id.
		 * @return array
		 */
		public static function get_product_connections( $id ) {
			$rows = array();
			foreach ( $GLOBALS['catalogue']['relations'] as $row ) {
				if ( $row['oid'] === $id ) {
					$row['relation_id']     = $row['id'];
					$row['relation_data']   = $row['data'];
					$row['relation_status'] = $row['status'];
					$rows[]                 = $row;
				}
			}
			return $rows;
		}
		/**
		 * Create a fixture row.
		 *
		 * @param string $table Table.
		 * @param array  $row Row.
		 * @return int|false
		 */
		private static function create( $table, $row ) {
			if ( ! empty( $GLOBALS['fail_relation'] ) && 'relations' === $table ) {
				return false; }
			$id = count( $GLOBALS['catalogue'][ $table ] ) + 1;
			while ( isset( $GLOBALS['catalogue'][ $table ][ $id ] ) ) {
				++$id; }
			$row['id']                             = $id;
			$GLOBALS['catalogue'][ $table ][ $id ] = $row;
			return $id;
		}
		/**
		 * Create group.
		 *
		 * @param array $row Row.
		 * @return int|false
		 */
		public static function insert_group( $row ) {
			return self::create( 'groups', $row ); }
		/**
		 * Create term.
		 *
		 * @param array $row Row.
		 * @return int|false
		 */
		public static function insert_plan( $row ) {
			return self::create( 'terms', $row ); }
		/**
		 * Create relation.
		 *
		 * @param array $row Row.
		 * @return int|false
		 */
		public static function insert_relation( $row ) {
			return self::create( 'relations', $row ); }
		/**
		 * Natural key lookup.
		 *
		 * @param int $plan Term.
		 * @param int $oid Product.
		 * @param int $vid Variation.
		 * @param int $type Relation type.
		 * @return int
		 */
		public static function find_relation( $plan, $oid, $vid, $type = 1 ) {
			foreach ( $GLOBALS['catalogue']['relations'] as $row ) {
				if ( $row['plan_id'] === $plan && $row['oid'] === $oid && $row['vid'] === $vid && $row['type'] === $type ) {
					return $row['id']; }
			}
			return 0;
		}
		/**
		 * Remove exactly one relation.
		 *
		 * @param int $id ID.
		 * @return bool
		 */
		public static function delete_relation( $id ) {
			unset( $GLOBALS['catalogue']['relations'][ $id ] );
			return true; }
		/**
		 * Remove empty term double.
		 *
		 * @param int $id ID.
		 * @return bool
		 */
		public static function delete_plan( $id ) {
			unset( $GLOBALS['catalogue']['terms'][ $id ] );
			return true; }
		/**
		 * Remove empty group double.
		 *
		 * @param int $id ID.
		 * @return bool
		 */
		public static function delete_group( $id ) {
			unset( $GLOBALS['catalogue']['groups'][ $id ] );
			return true; }
		/**
		 * Cache flush double.
		 *
		 * @param int $id ID.
		 * @return void
		 */
		public static function flush_cache( $id ) {
			$GLOBALS['flushes'][] = $id; }
		/** Fixture group table.
		 *
		 * @return string
		 */
		public static function group_table() {
			return 'groups'; }
		/** Fixture term table.
		 *
		 * @return string
		 */
		public static function plan_table() {
			return 'terms'; }
		/** Fixture relation table.
		 *
		 * @return string
		 */
		public static function relation_table() {
			return 'relations'; }
	}
}

namespace {
	use SpringDevs\Subscription\Illuminate\Migration\VariationPlanMigrator;
	/** Runtime fixture product; never mutates stock or prices. */
	class MigrationProductDouble {
		/** Entity id.
		 *
		 * @var int
		 */
		private $id;
		/**
		 * Initialize id.
		 *
		 * @param int $id ID.
		 */
		public function __construct( $id ) {
			$this->id = $id; }
		/**
		 * Meta lookup.
		 *
		 * @param string $key Key.
		 * @return mixed
		 */
		public function get_meta( $key ) {
			return get_post_meta( $this->id, $key, true ); }
		/**
		 * Type check.
		 *
		 * @param string $type Type.
		 * @return bool
		 */
		public function is_type( $type ) {
			return ( ! empty( $GLOBALS['simple_fixture'] ) ? 'simple' : ( 10 === $this->id ? 'variable' : 'variation' ) ) === $type; }
		/** Child fixture ids.
		 *
		 * @return array
		 */
		public function get_children() {
			return array( 11, 12 ); }
		/** Frequency attribute double.
		 *
		 * @return array
		 */
		public function get_variation_attributes() {
			return array( 'attribute_delivery-frequency' => 11 === $this->id ? 'Monthly' : 'One Time' ); }
		/**
		 * Set a meta double.
		 *
		 * @param string $key Key.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function update_meta_data( $key, $value ) {
			$GLOBALS['catalogue']['meta'][ $this->id ][ $key ] = $value; }
		/**
		 * Remove a meta double.
		 *
		 * @param string $key Key.
		 * @return void
		 */
		public function delete_meta_data( $key ) {
			unset( $GLOBALS['catalogue']['meta'][ $this->id ][ $key ] ); }
		/** Persist double (already in memory). */
		public function save_meta_data() {}

		/**
		 * Refresh the runtime cache double.
		 *
		 * @param bool $force Force refresh.
		 * @return void
		 */
		public function read_meta_data( $force = false ) {}
	}
	/** In-memory transactional storage double. */
	class MigrationDatabaseDouble {
		/** Fixture prefix.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';
		/** Meta table.
		 *
		 * @var string
		 */
		public $postmeta = 'postmeta';
		/** Options table.
		 *
		 * @var string
		 */
		public $options = 'options';
		/** Transaction state.
		 *
		 * @var array
		 */
		private $before;
		/**
		 * Prepared SQL double.
		 *
		 * @param string $sql SQL.
		 * @param mixed  ...$args Arguments.
		 * @return string
		 */
		public function prepare( $sql, ...$args ) {
			return $sql; }
		/**
		 * Safety query double.
		 *
		 * @param string $sql SQL.
		 * @return string
		 */
		public function get_var( $sql ) {
			return false !== strpos( $sql, 'information_schema' ) ? ( $GLOBALS['engine'] ?? 'InnoDB' ) : ( ! empty( $GLOBALS['busy'] ) ? '0' : '1' ); }
		/**
		 * Transaction double.
		 *
		 * @param string $sql SQL.
		 * @return int
		 */
		public function query( $sql ) {
			if ( 'START TRANSACTION' === $sql ) {
				$this->before = array( $GLOBALS['catalogue'], $GLOBALS['options'] ); }
			if ( 'ROLLBACK' === $sql ) {
				list( $GLOBALS['catalogue'], $GLOBALS['options'] ) = $this->before; }
			return 1;
		}
		/**
		 * Exact stopgap restoration double.
		 *
		 * @param string $table Table.
		 * @param array  $row Row.
		 * @return int
		 */
		public function insert( $table, $row ) {
			$row['data']                                     = json_decode( $row['data'], true );
			$GLOBALS['catalogue']['relations'][ $row['id'] ] = $row;
			return 1; }
	}
	/**
	 * Integer double.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value ); }
	/**
	 * Sanitizer double.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		return trim( $value ); }
	/**
	 * Slug sanitizer double.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_title( $value ) {
		return strtolower( trim( $value ) ); }
	/**
	 * JSON double.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	function wp_json_encode( $value ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Implements the WP JSON double.
		return json_encode( $value ); }
	/**
	 * Catalogue paging double.
	 *
	 * @param array $args Query.
	 * @return array
	 */
	function wc_get_products( $args ) {
		return 1 === $args['page'] ? array( 10 ) : array(); }
	/**
	 * Product CRUD double.
	 *
	 * @param int $id ID.
	 * @return MigrationProductDouble
	 */
	function wc_get_product( $id ) {
		return new MigrationProductDouble( $id ); }
	/**
	 * Meta lookup double.
	 *
	 * @param int    $id ID.
	 * @param string $key Key.
	 * @param bool   $single Single.
	 * @return mixed
	 */
	function get_post_meta( $id, $key, $single ) {
		return $GLOBALS['catalogue']['meta'][ $id ][ $key ] ?? ''; }
	/**
	 * Meta existence double.
	 *
	 * @param string $type Type.
	 * @param int    $id ID.
	 * @param string $key Key.
	 * @return bool
	 */
	function metadata_exists( $type, $id, $key ) {
		return array_key_exists( $key, $GLOBALS['catalogue']['meta'][ $id ] ?? array() ); }
	/**
	 * Option lookup double.
	 *
	 * @param string $key Key.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	function get_option( $key, $default_value = false ) {
		return $GLOBALS['options'][ $key ] ?? $default_value; }
	/**
	 * Non-autoload option persistence double.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 * @param bool   $autoload Autoload flag.
	 * @return bool
	 */
	function update_option( $key, $value, $autoload ) {
		$GLOBALS['options'][ $key ]  = $value;
		$GLOBALS['autoload'][ $key ] = $autoload;
		return true; }
	/**
	 * Option removal double.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	function delete_option( $key ) {
		unset( $GLOBALS['options'][ $key ] ); }
	/**
	 * Cache deletion double.
	 *
	 * @param mixed  $key Key.
	 * @param string $group Group.
	 * @return void
	 */
	function wp_cache_delete( $key, $group ) {}
	/**
	 * Product transient cleanup double.
	 *
	 * @param int $id ID.
	 * @return void
	 */
	function wc_delete_product_transients( $id ) {}
	/** Fixture site URL.
	 *
	 * @return string
	 */
	function site_url() {
		return 'https://example.test'; }
	/**
	 * Redacted log double.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	function subscrpt_write_log( $line ) {
		$GLOBALS['logs'][] = $line; }

	$root = dirname( __DIR__, 2 );
	require_once $root . '/plugin/includes/Illuminate/Migration/FrequencyParser.php';
	require_once $root . '/plugin/includes/Illuminate/Migration/VariationPlanPlanner.php';
	require_once $root . '/plugin/includes/Illuminate/Migration/VariationPlanMigrator.php';
	$scenario_mode = getenv( 'ASHBI_VARIATION_SCENARIO' ) ? getenv( 'ASHBI_VARIATION_SCENARIO' ) : 'apply';
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database double.
	$GLOBALS['wpdb']      = new MigrationDatabaseDouble();
	$GLOBALS['options']   = array();
	$GLOBALS['catalogue'] = array(
		'groups'    => array(),
		'terms'     => array(),
		'relations' => array(),
		'meta'      => array(
			10 => array(),
			11 => array(),
			12 => array(),
			99 => array(
				'_subscrpt_price'     => 'unchanged',
				'_subscrpt_next_date' => 'unchanged',
			),
		),
	);
	if ( 'stopgap' === $scenario_mode ) {
		$GLOBALS['catalogue']['groups'][7]    = array(
			'id'     => 7,
			'title'  => 'Subscribe & Save',
			'type'   => 1,
			'status' => 'active',
		);
		$GLOBALS['catalogue']['terms'][8]     = array(
			'id'                => 8,
			'plan_group_id'     => 7,
			'billing_frequency' => 1,
			'billing_interval'  => 3,
			'status'            => 'active',
		);
		$GLOBALS['catalogue']['relations'][9] = array(
			'id'      => 9,
			'plan_id' => 8,
			'oid'     => 10,
			'vid'     => 0,
			'type'    => 1,
			'exclude' => 0,
			'status'  => 'active',
			'data'    => array( 'regular_price' => '99' ),
		);
	}
	$GLOBALS['simple_fixture'] = in_array( $scenario_mode, array( 'simple_default', 'simple_include', 'ordinary_simple', 'simple_stale' ), true );
	if ( $GLOBALS['simple_fixture'] && 'ordinary_simple' !== $scenario_mode ) {
		$GLOBALS['catalogue']['meta'][10] = array(
			'_subscrpt_enabled'       => 'yes',
			'_subscrpt_timing_per'    => 1,
			'_subscrpt_timing_option' => 'months',
		);
	}
	$before        = $GLOBALS['catalogue'];
	$migrator      = new VariationPlanMigrator();
	$settings      = VariationPlanMigrator::settings(
		array(
			'remove_stopgap' => 'stopgap' === $scenario_mode,
			'include_simple' => 'simple_include' === $scenario_mode,
		)
	);
	$scan          = $migrator->scan( $settings );
	$error_message = null;
	$rollback      = null;
	$idempotent    = null;
	try {
		if ( 'dry' === $scenario_mode ) {
			$migrator->save_report( $scan, 'dry_run' );
		} else {
			$GLOBALS['fail_relation'] = 'failure' === $scenario_mode;
			$GLOBALS['busy']          = 'busy' === $scenario_mode;
			$GLOBALS['engine']        = 'engine' === $scenario_mode ? 'MyISAM' : 'InnoDB';
			if ( 'stale' === $scenario_mode ) {
				$GLOBALS['catalogue']['meta'][10]['_subscrpt_enabled'] = 'changed'; }
			if ( 'simple_stale' === $scenario_mode ) {
				$settings['include_simple'] = true;
			}
			$migrator->apply( $settings, $scan['fingerprint'] );
			$again               = $migrator->scan( $settings );
			$state_before_repeat = $GLOBALS['catalogue'];
			$migrator->apply( $settings, $again['fingerprint'] );
			$idempotent = $GLOBALS['catalogue'] === $state_before_repeat;
			if ( 'changed' === $scenario_mode ) {
				$GLOBALS['catalogue']['relations'][1]['data']['price_source'] = 'typed'; }
			$rollback               = $GLOBALS['simple_fixture'] && 'simple_include' !== $scenario_mode ? null : $migrator->rollback();
			$dry_rollback_unchanged = $GLOBALS['catalogue'] === $state_before_repeat;
			if ( 'rollback' === $scenario_mode || 'stopgap' === $scenario_mode || 'changed' === $scenario_mode ) {
				$rollback = $migrator->rollback( true ); }
		}
	} catch ( Throwable $error ) {
		$error_message = $error->getMessage(); }
	echo wp_json_encode(
		array(
			'error'                  => $error_message,
			'totals'                 => $scan['totals'],
			'actions'                => array_column( $scan['products'][0]['rows'], 'action' ),
			'catalogue'              => $GLOBALS['catalogue'],
			'same_as_before'         => $before === $GLOBALS['catalogue'],
			'idempotent'             => $idempotent,
			'dry_rollback_unchanged' => $dry_rollback_unchanged ?? null,
			'rollback_errors'        => $rollback['errors'] ?? array(),
			'autoload'               => $GLOBALS['autoload'] ?? array(),
			'mode'                   => get_option( VariationPlanMigrator::REPORT, array() )['mode'] ?? null,
		)
	);
}

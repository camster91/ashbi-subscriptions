<?php // phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden,Universal.Namespaces.OneDeclarationPerFile.MultipleFound,Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden,Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Verify safe decoding of legacy serialized metadata.
 *
 * @package AshbiSubscriptions
 */

namespace SpringDevs\Subscription {
	/**
	 * Return the isolated upgrade order fixture.
	 *
	 * @param int $order_id Order fixture ID.
	 * @return mixed Order fixture or false.
	 */
	function wc_get_order( $order_id ) {
		return $GLOBALS['ashbi_upgrade_orders'][ (int) $order_id ] ?? false;
	}

	/**
	 * Return isolated product subscription metadata.
	 *
	 * @param int    $post_id Product post ID.
	 * @param string $key Metadata key.
	 * @param bool   $single Whether to return one value.
	 * @return mixed Product metadata or false.
	 */
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['ashbi_upgrade_product_meta'][ (int) $post_id ][ $key ] ?? false;
	}

	/**
	 * Record legacy metadata deletion in the isolated fixture.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key Metadata key.
	 * @return bool Always true in the fixture.
	 */
	function delete_post_meta( $post_id, $key ) {
		$GLOBALS['ashbi_upgrade_deleted_meta'][] = array( (int) $post_id, $key );
		return true;
	}

	/**
	 * Persist an order-item metadata update in the isolated fixture.
	 *
	 * @param int    $item_id Order item ID.
	 * @param string $key Metadata key.
	 * @param mixed  $value Metadata value.
	 * @param string $prev_value Previous metadata value.
	 * @return bool|int Fixture write result.
	 */
	function wc_update_order_item_meta( $item_id, $key, $value, $prev_value = '' ) {
		$GLOBALS['ashbi_upgrade_item_meta_updates'][] = array( (int) $item_id, $key, $value );
		if ( ! empty( $GLOBALS['ashbi_upgrade_fail_item_meta'] ) ) {
			return false;
		}

		return 1;
	}
}

namespace {

	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__, 2 ) . '/plugin/includes/Upgrade.php';

	/** Provide the order item behavior required by the migration fixture. */
	final class UpgradeMigrationOrderItemFake {
		/** Order item ID.
		 *
		 * @var int
		 */
		private $id;
		/** Product ID.
		 *
		 * @var int
		 */
		private $product_id;

		/**
		 * Create an order item fixture.
		 *
		 * @param int $id Order item ID.
		 * @param int $product_id Product ID.
		 */
		public function __construct( $id, $product_id ) {
			$this->id         = $id;
			$this->product_id = $product_id;
		}

		/** Return the product ID. */
		public function get_product_id() {
			return $this->product_id;
		}

		/** Return the order item ID. */
		public function get_id() {
			return $this->id;
		}
	}

	/** Provide the order behavior required by the migration fixture. */
	final class UpgradeMigrationOrderFake {
		/** Order items.
		 *
		 * @var array<int,UpgradeMigrationOrderItemFake>
		 */
		private $items;

		/**
		 * Create an order fixture.
		 *
		 * @param array<int,UpgradeMigrationOrderItemFake> $items Order items.
		 */
		public function __construct( array $items ) {
			$this->items = $items;
		}

		/** Return order items. */
		public function get_items() {
			return $this->items;
		}
	}

	/** Provide the database behavior required by the migration fixture. */
	final class UpgradeMigrationWpdbFake {
		/** @var string */
		public $prefix = 'wp_';
		/** @var string */
		public $postmeta = 'wp_postmeta';
		/** @var array<int,object> */
		public $history_rows = array();
		/** @var array<int,array<string,mixed>> */
		public $relation_rows = array();
		/** @var bool */
		public $fail_relation_insert = false;
		/** @var int */
		public $insert_calls = 0;
		/** @var array<int,mixed> */
		private $prepared_values = array();

		/**
		 * Keep the prepared values for the fake query handlers.
		 *
		 * @param string       $query SQL query.
		 * @param array<mixed> ...$args Query arguments.
		 * @return string Unchanged query.
		 */
		public function prepare( $query, ...$args ) {
			$this->prepared_values = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
			return $query;
		}

		/**
		 * Return the requested legacy history rows.
		 *
		 * @param string $query SQL query.
		 * @return array<int,object> Legacy history rows.
		 */
		public function get_results( $query ) {
			return '_subscrpt_order_history' === ( $this->prepared_values[0] ?? null ) ? $this->history_rows : array();
		}

		/**
		 * Return an exact matching relation row ID, if one exists.
		 *
		 * @param string $query SQL query.
		 * @return int|string|null Relation ID or null.
		 */
		public function get_var( $query ) {
			if ( false === strpos( $query, 'order_item_id' ) || 4 !== count( $this->prepared_values ) ) {
				return null;
			}

			list( $subscription_id, $order_id, $order_item_id, $type ) = $this->prepared_values;
			foreach ( $this->relation_rows as $row ) {
				if (
				(int) $row['subscription_id'] === (int) $subscription_id
				&& (int) $row['order_id'] === (int) $order_id
				&& (int) $row['order_item_id'] === (int) $order_item_id
				&& $row['type'] === $type
				) {
					return $row['id'];
				}
			}

			return null;
		}

		/**
		 * Insert a relation row or simulate a durable write failure.
		 *
		 * @param string               $table Relation table name.
		 * @param array<string,mixed> $data Relation row.
		 * @return int|false Insert result.
		 */
		public function insert( $table, $data ) {
			++$this->insert_calls;
			if ( $this->fail_relation_insert ) {
				return false;
			}

			$data['id']            = count( $this->relation_rows ) + 1;
			$this->relation_rows[] = $data;
			return 1;
		}
	}

	/**
	 * Legacy upgrade security contract tests.
	 */
	final class UpgradeSecurityContractTest extends TestCase {
		/**
		 * Source for the legacy upgrade class.
		 *
		 * @var string
		 */
		private string $source;

		/**
		 * Load the upgrade source under test.
		 */
		protected function setUp(): void {
			// Local source fixture reads are intentional in this contract test.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Upgrade.php' );
		}

		/**
		 * Legacy decoding must reject objects and malformed shapes.
		 */
		public function test_legacy_upgrade_disallows_object_instantiation_and_skips_invalid_shapes(): void {
			$this->assertStringContainsString( "allowed_classes' => false", $this->source );
			$this->assertStringContainsString( 'decode_legacy_value', $this->source );
			$this->assertStringContainsString( 'is_array( $decoded )', $this->source );
			$this->assertStringNotContainsString( 'unserialize( $product_meta->meta_value )', $this->source );
			$this->assertStringNotContainsString( 'unserialize( $subscription_meta->meta_value )', $this->source );
			$this->assertStringNotContainsString( 'unserialize( $history->meta_value )', $this->source );
		}

		/** Opt-in uninstall must remove the effective PayPal mapping table. */
		public function test_uninstall_names_the_effective_paypal_mapping_table(): void {
			$root = dirname( __DIR__, 2 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixture.
			$uninstall = (string) file_get_contents( $root . '/plugin/uninstall.php' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixture.
			$paypal_db = (string) file_get_contents( $root . '/plugin/includes/Illuminate/Gateways/Paypal/PaypalDB.php' );

			$this->assertStringContainsString( "'subscrpt_paypal_map'", $uninstall );
			$this->assertStringContainsString( "'subscrpt_paypal_map'", $paypal_db );
			$this->assertStringNotContainsString( "'subscrpt_paypal',", $uninstall );
		}

		/** Legacy history must remain when one entry is malformed. */
		public function test_history_meta_is_retained_when_one_entry_is_malformed(): void {
			$wpdb = $this->setUpMigrationFixture(
				array(
					$this->validHistoryEntry( 101, 7, 900, 'Parent Order' ),
					array( 'order_id' => 102 ),
				)
			);

			$this->runHistoryMigration( $wpdb );

			$this->assertCount( 1, $wpdb->relation_rows );
			$this->assertSame( array(), $GLOBALS['ashbi_upgrade_deleted_meta'] );
		}

		/** Legacy history must remain when an order or order item cannot be resolved. */
		public function test_history_meta_is_retained_when_an_entry_cannot_be_resolved(): void {
			$wpdb = $this->setUpMigrationFixture(
				array( $this->validHistoryEntry( 999, 7, 900, 'Renewal' ) )
			);

			$this->runHistoryMigration( $wpdb );

			$this->assertSame( array(), $wpdb->relation_rows );
			$this->assertSame( array(), $GLOBALS['ashbi_upgrade_deleted_meta'] );

			$wpdb                                 = $this->setUpMigrationFixture(
				array( $this->validHistoryEntry( 101, 8, 900, 'Renewal' ) )
			);
			$GLOBALS['ashbi_upgrade_orders'][101] = new UpgradeMigrationOrderFake(
				array( new UpgradeMigrationOrderItemFake( 501, 7 ) )
			);

			$this->runHistoryMigration( $wpdb );

			$this->assertSame( array(), $wpdb->relation_rows );
			$this->assertSame( array(), $GLOBALS['ashbi_upgrade_deleted_meta'] );
		}

		/** Legacy history must remain when the relation write is not durable. */
		public function test_history_meta_is_retained_when_relation_insert_fails(): void {
			$wpdb                       = $this->setUpMigrationFixture(
				array( $this->validHistoryEntry( 101, 7, 900, 'Renewal' ) )
			);
			$wpdb->fail_relation_insert = true;

			$this->runHistoryMigration( $wpdb );

			$this->assertSame( array(), $wpdb->relation_rows );
			$this->assertSame( array(), $GLOBALS['ashbi_upgrade_deleted_meta'] );
		}

		/** An exact existing relation makes a retry idempotent and safe to clean up. */
		public function test_exact_existing_relation_is_not_inserted_again(): void {
			$wpdb                       = $this->setUpMigrationFixture(
				array( $this->validHistoryEntry( 101, 7, 900, 'Renewal' ) )
			);
			$wpdb->relation_rows[]      = array(
				'id'              => 12,
				'subscription_id' => 900,
				'order_id'        => 101,
				'order_item_id'   => 501,
				'type'            => 'renew',
			);
			$wpdb->fail_relation_insert = true;

			$this->runHistoryMigration( $wpdb );

			$this->assertSame( 0, $wpdb->insert_calls );
			$this->assertCount( 1, $wpdb->relation_rows );
			$this->assertSame( array( array( 900, '_subscrpt_order_history' ) ), $GLOBALS['ashbi_upgrade_deleted_meta'] );
		}

		/**
		 * Configure an isolated history migration fixture.
		 *
		 * @param array<int,array<string,mixed>> $entries Legacy history entries.
		 * @return UpgradeMigrationWpdbFake Database fixture.
		 */
		private function setUpMigrationFixture( array $entries ) {
			$wpdb                                       = new UpgradeMigrationWpdbFake();
			$wpdb->history_rows                         = array(
				(object) array(
					'post_id'    => 900,
					'meta_value' => serialize( $entries ),
				),
			);
			$GLOBALS['wpdb']                            = $wpdb;
			$GLOBALS['ashbi_upgrade_orders']            = array(
				101 => new UpgradeMigrationOrderFake(
					array( new UpgradeMigrationOrderItemFake( 501, 7 ) )
				),
			);
			$GLOBALS['ashbi_upgrade_product_meta']      = array(
				7 => array(
					'_subscrpt_meta' => array(
						'time' => '1',
						'type' => 'month',
					),
				),
				8 => array( '_subscrpt_meta' => array( 'time' => '1' ) ),
			);
			$GLOBALS['ashbi_upgrade_deleted_meta']      = array();
			$GLOBALS['ashbi_upgrade_item_meta_updates'] = array();
			$GLOBALS['ashbi_upgrade_fail_item_meta']    = false;

			return $wpdb;
		}

		/** Run the history-only migration under test. */
		private function runHistoryMigration( UpgradeMigrationWpdbFake $wpdb ): void {
			$upgrade = new \SpringDevs\Subscription\Upgrade();
			$upgrade->update_subscription_meta();
		}

		/**
		 * Build a valid legacy history entry.
		 *
		 * @param int    $order_id Order ID.
		 * @param int    $product_id Product ID.
		 * @param int    $subscription_id Subscription ID.
		 * @param string $stats Legacy history label.
		 * @return array<string,mixed> Legacy history entry.
		 */
		private function validHistoryEntry( $order_id, $product_id, $subscription_id, $stats ) {
			return array(
				'order_id'   => $order_id,
				'product_id' => $product_id,
				'post_id'    => $subscription_id,
				'trial'      => false,
				'start_date' => '2024-01-01',
				'next_date'  => '2024-02-01',
				'stats'      => $stats,
			);
		}
	}

}

<?php
/**
 * Verify paid installment counting.
 *
 * phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,Generic.Files.OneObjectStructurePerFile.MultipleFound,Universal.Files.SeparateFunctionsFromOO.Mixed -- PHPUnit fixture intentionally combines global WooCommerce stubs and test doubles.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		return $GLOBALS['ashbi_counting_orders'][ $order_id ] ?? false;
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/functions.php';

/** Minimal fabricated order used by the installment counter test. */
final class InstallmentCountingOrderFake {
	/** Whether the fabricated order is paid. */
	private bool $paid;

	/**
	 * Create a fabricated order.
	 *
	 * @param bool $paid Whether the order is paid.
	 */
	public function __construct( bool $paid ) {
		$this->paid = $paid;
	}

	/** Return whether the fabricated order is paid. */
	public function is_paid(): bool {
		return $this->paid;
	}
}

/** Verify paid relation counting ignores duplicates and unpaid orders. */
final class InstallmentCountingTest extends TestCase {
	/** Seed fabricated orders for the test. */
	protected function setUp(): void {
		$GLOBALS['ashbi_counting_orders'] = array(
			10 => new InstallmentCountingOrderFake( true ),
			11 => new InstallmentCountingOrderFake( false ),
			12 => new InstallmentCountingOrderFake( true ),
		);
	}

	/** Verify duplicate and unpaid relations do not inflate installments. */
	public function test_duplicate_relations_and_unpaid_orders_do_not_inflate_installments(): void {
		$relations = array(
			(object) array(
				'order_id' => 10,
				'type'     => 'new',
			),
			(object) array(
				'order_id' => 10,
				'type'     => 'renew',
			),
			(object) array(
				'order_id' => 11,
				'type'     => 'renew',
			),
			(object) array(
				'order_id' => 12,
				'type'     => 'renew',
			),
			(object) array(
				'order_id' => 13,
				'type'     => 'switch',
			),
		);

		$this->assertSame( 2, subscrpt_count_paid_relation_orders( $relations, array( 'new', 'renew', 'early-renew' ) ) );
	}
}

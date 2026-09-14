<?php

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

final class InstallmentCountingOrderFake {
	private bool $paid;

	public function __construct( bool $paid ) {
		$this->paid = $paid;
	}

	public function is_paid(): bool {
		return $this->paid;
	}
}

final class InstallmentCountingTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ashbi_counting_orders'] = array(
			10 => new InstallmentCountingOrderFake( true ),
			11 => new InstallmentCountingOrderFake( false ),
			12 => new InstallmentCountingOrderFake( true ),
		);
	}

	public function test_duplicate_relations_and_unpaid_orders_do_not_inflate_installments(): void {
		$relations = array(
			(object) array( 'order_id' => 10, 'type' => 'new' ),
			(object) array( 'order_id' => 10, 'type' => 'renew' ),
			(object) array( 'order_id' => 11, 'type' => 'renew' ),
			(object) array( 'order_id' => 12, 'type' => 'renew' ),
			(object) array( 'order_id' => 13, 'type' => 'switch' ),
		);

		$this->assertSame( 2, subscrpt_count_paid_relation_orders( $relations, array( 'new', 'renew', 'early-renew' ) ) );
	}
}

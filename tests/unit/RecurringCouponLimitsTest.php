<?php
/**
 * Verify recurring discounts use payments rather than attempted orders.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Exercise actual display/builder helpers in an isolated runtime. */
final class RecurringCouponLimitsTest extends TestCase {
	/** Run the actual helper fixture without sharing WordPress doubles. */
	private function results(): array {
		$path = dirname( __DIR__ ) . '/fixtures/recurring-coupon-limits.php';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote a local fixture path for the isolated PHP process.
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		return json_decode( $output['stdout'], true );
	}

	/** Failed, abandoned and duplicate links cannot exhaust a paid-payment limit. */
	public function test_unpaid_and_duplicate_orders_keep_the_promised_discount(): void {
		$result = $this->results();
		self::assertSame( 5, $result['unpaid_display']['amount'] );
		self::assertFalse( $result['unpaid_display']['exhausted'] );
		self::assertSame( array( 'fabricated-five' => 5 ), $result['unpaid_builder'] );
		self::assertSame( array(), $result['exhausted_builder'] );
		self::assertSame( 5, $result['unlimited']['amount'] );
		self::assertSame( 0, $result['initial_only']['amount'] );
		self::assertSame( 0, $result['initial_without_history']['amount'] );
		self::assertSame( array(), $result['initial_builder'] );
		self::assertSame( 0, $result['one_time']['amount'] );
	}

	/** Payment and price changes must be reflected within the same worker request. */
	public function test_discount_display_refreshes_after_payment_or_price_change(): void {
		$result = $this->results();
		self::assertSame( 5, $result['price_before']['amount'] );
		self::assertSame( 10, $result['price_after']['amount'] );
		self::assertSame( 5, $result['payment_before']['amount'] );
		self::assertSame( 0, $result['payment_after']['amount'] );
		self::assertTrue( $result['payment_after']['exhausted'] );
	}
}

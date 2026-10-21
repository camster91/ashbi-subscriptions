<?php
/**
 * Verify period reuse respects real historical order states.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Exercise the actual selection helper in an isolated runtime. */
final class EarlyRenewalDueTest extends TestCase {
	/** Open/paid orders reserve their exact period; voided orders do not. */
	public function test_matching_invoices_are_unique_and_exclude_voided_or_other_periods(): void {
		$path = dirname( __DIR__ ) . '/fixtures/early-renewal-due.php';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote a local fixture path for the isolated PHP process.
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		self::assertSame( array( 10, 11, 15, 16 ), json_decode( $output['stdout'], true ) );
	}
	/** A fresh cart checkout cannot claim an already-invoiced billing period. */
	public function test_cart_renewal_cannot_attach_another_invoice_for_early_period(): void {
		$path = dirname( __DIR__ ) . '/fixtures/cart-early-renewal-collision.php';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote a local fixture path for the isolated PHP process.
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$results = json_decode( $output['stdout'], true );
		foreach ( array( 'pending', 'failed', 'on-hold', 'completed' ) as $status ) {
			self::assertFalse( $results[ $status ]['accepted'], $status );
			self::assertNull( $results[ $status ]['claim'], $status );
			self::assertSame( 1, $results[ $status ]['relations'], $status );
		}
		foreach ( array( 'cancelled', 'refunded' ) as $status ) {
			self::assertTrue( $results[ $status ]['accepted'], $status );
			self::assertSame( 20, $results[ $status ]['claim']['order_id'], $status );
			self::assertSame( 2, $results[ $status ]['relations'], $status );
		}
	}
}

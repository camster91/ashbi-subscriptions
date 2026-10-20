<?php
/**
 * Customer cancellation screen regressions.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Exercise the real account controller and both templates in isolation. */
final class CustomerCancellationScreenTest extends TestCase {
	/** Load fabricated screen cases without polluting other tests. */
	private function screens(): array {
		$path   = dirname( __DIR__ ) . '/fixtures/customer-cancellation-screen.php';
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertIsArray( $data, $output['stdout'] );
		return $data;
	}

	/** Protected cancellation and unavailable storage must never offer reactivation. */
	public function test_reactivation_matches_the_durable_billing_guard(): void {
		$cases = $this->screens()['pe_cancelled'];
		self::assertTrue( $cases['legacy']['can_reactivate'] );
		self::assertStringContainsString( '>Reactivate</a>', $cases['legacy']['single'] );
		foreach ( array( 'barrier', 'storage_error' ) as $storage ) {
			self::assertFalse( $cases[ $storage ]['can_reactivate'], $storage );
			self::assertStringNotContainsString( '>Reactivate</a>', $cases[ $storage ]['single'], $storage );
		}
	}

	/** Old billing anchors must not look like future charges after cancellation. */
	public function test_cancelled_screens_do_not_advertise_another_payment(): void {
		$cases = $this->screens();
		foreach ( array( 'pe_cancelled', 'cancelled' ) as $status ) {
			self::assertStringNotContainsString( 'Next payment date', $cases[ $status ]['barrier']['single'] );
			self::assertStringNotContainsString( 'December 1, 2026', $cases[ $status ]['barrier']['list'] );
		}
		self::assertStringContainsString( 'Next payment date', $cases['active']['legacy']['single'] );
		self::assertStringContainsString( 'December 1, 2026', $cases['active']['legacy']['list'] );
		self::assertStringContainsString( 'Pending Cancellation', $cases['pe_cancelled']['barrier']['list'] );
	}
}

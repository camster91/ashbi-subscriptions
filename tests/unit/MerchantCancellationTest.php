<?php
/**
 * Merchant cancellation must use durable evidence before status or email.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Exercise the actual merchant service and core cancellation implementation. */
final class MerchantCancellationTest extends TestCase {
	/** Both access choices preserve billing intent, permissions and replay safety. */
	public function test_merchant_cancellation_is_durable_and_preserves_access_choices(): void {
		$path = dirname( __DIR__ ) . '/fixtures/run-cancellation-evidence.php';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote the isolated local fixture path.
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertIsArray( $data );
		foreach ( array( 'now', 'scheduled', 'mail_failure', 'busy', 'editor', 'completed', 'on_hold' ) as $name ) {
			$case = $data['merchant'][ $name ];
			self::assertNull( $case['error'], $name );
			self::assertSame( array(), $case['order_writes'], $name );
			self::assertCount( 1, $case['barriers'], $name );
			self::assertTrue( $case['outcome']['recorded'], $name );
			self::assertSame( 8, (int) $case['barriers'][99]['actor_id'], $name );
			if ( 'busy' !== $name ) {
				self::assertSame( 0, (int) $case['meta']['_subscrpt_auto_renew'], $name );
			}
		}
		self::assertSame( 'cancelled', $data['merchant']['now']['status'] );
		self::assertLessThanOrEqual( time(), (int) $data['merchant']['now']['barriers'][99]['access_end'] );
		self::assertSame( 'pe_cancelled', $data['merchant']['scheduled']['status'] );
		self::assertGreaterThan( time(), (int) $data['merchant']['scheduled']['barriers'][99]['access_end'] );
		foreach ( array( 'barrier_failure', 'denied' ) as $name ) {
			self::assertSame( 'active', $data['merchant'][ $name ]['status'], $name );
			self::assertCount( 0, $data['merchant'][ $name ]['barriers'], $name );
		}
		self::assertSame( 'pending', $data['merchant']['busy']['outcome']['state'] );
		self::assertSame( $data['merchant_replay']['before'], $data['merchant_replay']['after'] );
		self::assertSame( $data['merchant_end_existing']['original'], $data['merchant_end_existing']['final'] );
		self::assertSame( 'cancelled', $data['merchant_end_existing']['status'] );
	}
}

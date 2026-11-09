<?php
/**
 * Persistent, truthful customer cancellation receipt regressions.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Exercise the actual notice entry point and template against storage doubles. */
final class CancellationReceiptTest extends TestCase {
	/** Confirmation must survive reload without trusting cached metadata or status. */
	public function test_saved_confirmation_remains_visible_after_reload(): void {
		$path = dirname( __DIR__ ) . '/fixtures/cancellation-receipt.php';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote the local fixture path for subprocess execution.
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertIsArray( $data );
		self::assertStringContainsString( 'Cancellation confirmed.', $data['confirmed']['first'] );
		foreach ( $data as $case ) {
			self::assertSame( $case['first'], $case['reload'] );
		}
		foreach ( array( 'pending', 'status_write_pending' ) as $name ) {
			self::assertStringContainsString( 'Confirmation is pending', $data[ $name ]['first'] );
			self::assertStringNotContainsString( 'Cancellation confirmed.', $data[ $name ]['first'] );
		}
		self::assertSame( '', $data['no_record']['first'] );
		self::assertStringContainsString( 'temporarily unavailable', $data['unavailable']['first'] );
		self::assertStringNotContainsString( 'request is recorded', $data['unavailable']['first'] );
		self::assertStringContainsString( 'temporarily unavailable', $data['exception']['first'] );
		self::assertStringContainsString( 'scheduled to be cancelled', $data['legacy']['first'] );
		self::assertStringNotContainsString( 'Cancellation confirmed.', $data['legacy']['first'] );
		self::assertStringContainsString( '2026-11-01 01:30', $data['protected_access']['first'] );
		self::assertStringContainsString( 'role="status"', $data['confirmed']['first'] );
	}
}

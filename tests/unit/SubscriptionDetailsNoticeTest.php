<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit fixture filename.
/**
 * Subscription detail renewal notice rendering regression.
 *
 * @package AshbiSubscriptions\Tests
 */
use PHPUnit\Framework\TestCase;

/** Exercises the real admin template without a production WordPress runtime. */
final class SubscriptionDetailsNoticeTest extends TestCase {
	/** Past and inactive schedules must not be presented as upcoming renewals. */
	public function test_schedule_notice_respects_status_and_date(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated local render fixture.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/subscription-details-notice.php' ) . ' 2>&1', $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( array( 'PASS' ), $output );
	}
}

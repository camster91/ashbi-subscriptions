<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit fixture filename.
/**
 * Saved variation cart compatibility regression.
 *
 * @package AshbiSubscriptions\Tests
 */
use PHPUnit\Framework\TestCase;

/** Exercises the real cart restoration and validation listeners in isolation. */
final class SavedVariationCartTest extends TestCase {
	/** Migrated saved lines survive; conflicting terms block checkout without removal. */
	public function test_saved_cart_restoration(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated local cart fixture.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/saved-variation-cart.php' ) . ' 2>&1', $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( array( 'PASS' ), $output );
	}
}

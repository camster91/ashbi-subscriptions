<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit test filename.
/**
 * Classic variation cart and checkout regression.
 *
 * @package AshbiSubscriptions\Tests
 */
use PHPUnit\Framework\TestCase;
/** Exercises registered variation snapshot, cart preservation and checkout routes. */
final class ClassicVariationCartTest extends TestCase {
	/** Selected classic variations retain terms while one-time and mapped purchases stay separate. */
	public function test_classic_variation_cart_and_checkout(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated local fixture subprocess.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/classic-variation-cart.php' ) . ' 2>&1', $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( array( 'PASS' ), $output );
	}
}

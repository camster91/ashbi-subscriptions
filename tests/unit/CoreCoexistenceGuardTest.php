<?php
/**
 * Executable isolated-process coexistence regressions.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Local source and isolated PHP process regressions.
use PHPUnit\Framework\TestCase;

/** Exercises the actual main file without sharing PHPUnit globals. */
final class CoreCoexistenceGuardTest extends TestCase {
	/** No class may be early-bound in the guarded main file. */
	public function test_main_has_no_class_declarations(): void {
		$tokens = token_get_all( file_get_contents( dirname( __DIR__, 2 ) . '/plugin/subscription.php' ) );
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				self::assertNotSame( T_CLASS, $token[0] );
			}
		}
	}

	/**
	 * Execute actual plugin inclusion with minimal WordPress stubs.
	 *
	 * @dataProvider scenarios
	 * @param string $scenario Loaded owner scenario.
	 */
	public function test_guard_in_separate_process( string $scenario ): void {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/coexistence.php' ) . ' ' . escapeshellarg( $scenario );
		exec( $command . ' 2>&1', $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( array( 'PASS' ), $output );
	}

	/**
	 * Isolated owner scenarios.
	 *
	 * @return array
	 */
	public static function scenarios(): array {
		return array( array( 'clean' ), array( 'class' ), array( 'constant' ), array( 'subscrpt' ), array( 'own' ) );
	}
}

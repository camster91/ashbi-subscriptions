<?php
/**
 * Direct execution regression for the public runtime entry point.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated execution of the actual public PHP file.
use PHPUnit\Framework\TestCase;

/** Exercises bootstrap.php without WordPress or PHPUnit globals. */
final class BootstrapDirectAccessTest extends TestCase {
	/** Direct execution must terminate silently before loading the runtime. */
	public function test_direct_bootstrap_execution_exits_cleanly(): void {
		$bootstrap = dirname( __DIR__, 2 ) . '/plugin/bootstrap.php';
		$observer  = dirname( __DIR__ ) . '/fixtures/bootstrap-direct-access.php';
		$command   = escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d error_reporting=-1 -d ' . escapeshellarg( 'auto_prepend_file=' . $observer ) . ' ' . escapeshellarg( $bootstrap );
		exec( $command . ' 2>&1', $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( array( 'PASS' ), $output );
	}
}

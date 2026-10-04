<?php
/**
 * Administrator access regression tests.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Local source and isolated PHP process regressions.
use PHPUnit\Framework\TestCase;

/** Tests registration and authorization without a WordPress installation. */
final class MigrationAccessTest extends TestCase {
	/** Capture actual menu arguments and reach render after permission checks. */
	public function test_menu_and_render_permissions(): void {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/migration-access.php' ) . ' 2>&1', $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( array( 'PASS' ), $output );
	}
	/** Keep the WooCommerce runtime-class fallback alongside the legacy path. */
	public function test_woocommerce_detection_fallback(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Admin.php' );
		self::assertStringContainsString( "! class_exists( 'WooCommerce' ) && ! is_plugin_active( 'woocommerce/woocommerce.php' )", $source );
	}
}

<?php
/**
 * Contract for refusing dual subscription-plugin loads.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Asserts the bootstrap coexistence guard sits before Composer autoload.
 */
final class CoreCoexistenceGuardTest extends TestCase {
	/**
	 * Guard symbols and early return must precede the vendor autoloader require.
	 */
	public function testGuardAppearsBeforeAutoloaderAndChecksConflictSymbols(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/subscription.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract.
		self::assertIsString( $source );

		$autoload_pos = strpos( $source, "require_once __DIR__ . '/vendor/autoload.php'" );
		$guard_pos    = strpos( $source, "class_exists( 'Sdevs_Subscription', false )" );

		self::assertNotFalse( $autoload_pos, 'Autoloader require must remain in the bootstrap.' );
		self::assertNotFalse( $guard_pos, 'Coexistence guard must check Sdevs_Subscription.' );
		self::assertLessThan( $autoload_pos, $guard_pos, 'Guard must run before the Composer autoloader.' );

		self::assertStringContainsString( "defined( 'WP_SUBSCRIPTION_FILE' )", $source );
		self::assertStringContainsString( "defined( 'SUBSCRPT_FILE' )", $source );
		self::assertStringContainsString( 'ashbi_subscriptions_', $source );
		self::assertStringContainsString( "add_action( 'admin_notices'", $source );
		self::assertStringContainsString( "deactivate_plugins( plugin_basename( __FILE__ ) )", $source );
		self::assertStringContainsString( "'subscription'", $source );
	}
}

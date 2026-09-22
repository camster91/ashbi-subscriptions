<?php
/**
 * Admin component asset routing contract.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies the supported admin component asset-hook families.
 */
final class AdminAssetsContractTest extends TestCase {
	/**
	 * The standalone details page uses the current Ashbi menu hook, while
	 * established installations still use the two legacy hook families.
	 */
	public function testAdminComponentGuardCoversAllSupportedMenuHookFamilies(): void {
		$assets = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Assets.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads a local source contract.

		self::assertIsString( $assets );
		self::assertStringContainsString( "'wpsubscription_page'", $assets );
		self::assertStringContainsString( "'wp-subscription_page'", $assets );
		self::assertStringContainsString( "'ashbi-subscriptions_page_'", $assets );
		self::assertStringContainsString( "wp_enqueue_style( 'subscrpt_admin_components' )", $assets );
		self::assertStringContainsString( "wp_enqueue_script( 'subscrpt_admin_components' )", $assets );
	}
}

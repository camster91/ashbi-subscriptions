<?php
/**
 * Branding and in-place compatibility boundaries.
 *
 * @package Ashbi_Subscriptions
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies the bounded public-branding and compatibility contract.
 */
final class BrandingBoundaryTest extends TestCase {
	private const PROHIBITED_PATTERNS = array(
		'/\bWPSubscription\b/',
		'/\bConversWP\b/i',
		'/\bConvers Labs?\b/i',
		'/\b(?:docs\.|my\.)?wpsubscription\.co\b/i',
		'/\b(?:www\.)?converswp\.com\b/i',
		'/\b(?:www\.)?converslabs\.com\b/i',
		'/\bUpgrade to (?:Pro|premium)\b/i',
		'/\bSee (?:it|what) (?:in )?Pro\b/i',
		'/\bActivate licen[cs]e\b/i',
		'/\bSubscription for WooCommerce\b/i',
		'/\bWPS:\s/i',
		'/What does Subscriptions for WooCommerce do\?/i',
		'/wordpress\.org\/support\/plugin\/subscription/i',
	);

	/**
	 * Text-bearing source files shipped by the release packager.
	 *
	 * Compatibility identifiers remain lowercase and therefore do not conflict
	 * with the upstream product-name boundary. Attribution is permitted in the
	 * historical changelog, notices and bundled licence files.
	 *
	 * @return string[]
	 */
	private function distributedTextFiles(): array {
		$root                = dirname( __DIR__, 2 );
		$plugin              = $root . '/plugin';
		$files               = array();
		$allowed_attribution = array(
			'changelog.txt',
			'THIRD_PARTY_NOTICES.md',
		);
		$text_extensions     = array( 'css', 'js', 'json', 'md', 'php', 'pot', 'txt' );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path     = $file->getPathname();
			$relative = str_replace( '\\', '/', substr( $path, strlen( $plugin ) + 1 ) );
			if ( in_array( $relative, $allowed_attribution, true ) || 0 === strpos( $relative, 'licenses/' ) || 0 === strpos( $relative, 'vendor/' ) ) {
				continue;
			}

			if ( in_array( strtolower( $file->getExtension() ), $text_extensions, true ) ) {
				$files[] = $path;
			}
		}

		$files = array_values( array_unique( $files ) );
		sort( $files );

		return $files;
	}

	/**
	 * Distributed customer-facing sources contain no upstream product branding.
	 */
	public function testDistributedCustomerFacingSurfacesContainNoUpstreamBranding(): void {
		$failures = array();

		foreach ( $this->distributedTextFiles() as $file ) {
			self::assertFileExists( $file );
			$contents = file_get_contents( $file );
			self::assertNotFalse( $contents, 'Unable to read ' . $file );
			// Coexistence guard (and its catalog entry) must name the conflicting products.
			$contents = str_replace(
				array(
					'Ashbi Subscriptions is inactive because another subscription plugin (WP Subscription Core / WPSubscription) is active and must be deactivated first.',
					'WP Subscription Core / WPSubscription',
				),
				'',
				$contents
			);

			foreach ( self::PROHIBITED_PATTERNS as $pattern ) {
				if ( preg_match( $pattern, $contents ) ) {
					$failures[] = str_replace( dirname( __DIR__, 2 ) . '/', '', $file ) . ' matches ' . $pattern;
				}
			}
		}

		self::assertSame( array(), $failures, "Prohibited upstream branding remains:\n" . implode( "\n", $failures ) );
	}

	/**
	 * Retired upstream logo assets are neither referenced nor release-packaged.
	 */
	public function testRetiredLogoAssetsAreNotReferenced(): void {
		$failures = array();

		foreach ( $this->distributedTextFiles() as $file ) {
			$contents = file_get_contents( $file );
			self::assertNotFalse( $contents, 'Unable to read ' . $file );
			if ( preg_match( '#assets/images/(?:logo(?:-title)?\.(?:png|svg)|icons/subscription-20(?:-gray)?\.png)#', $contents ) ) {
				$failures[] = str_replace( dirname( __DIR__, 2 ) . '/', '', $file );
			}
		}

		self::assertSame( array(), $failures, "Retired upstream logo references remain:\n" . implode( "\n", $failures ) );
	}

	/**
	 * Provider artwork is optional extension input, never a bundled dependency.
	 */
	public function testProviderLogoAssetsAreNotBundledOrReferencedByDefault(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array(
			$root . '/plugin/includes/Illuminate/Gateways/Paypal/Paypal.php',
			$root . '/plugin/includes/Admin/Integrations.php',
		);

		foreach ( $files as $file ) {
			$contents = file_get_contents( $file );
			self::assertNotFalse( $contents, 'Unable to read ' . $file );
			self::assertStringNotContainsString( 'assets/images/integrations/', $contents );
			self::assertStringNotContainsString( 'assets/images/other_plugins/', $contents );
		}

		$paypal = file_get_contents( $root . '/plugin/includes/Illuminate/Gateways/Paypal/Paypal.php' );
		self::assertIsString( $paypal );
		self::assertStringContainsString( "apply_filters( 'wp_subscription_paypal_icon', '' )", $paypal );

		$build_script = file_get_contents( $root . '/scripts/build-release.sh' );
		self::assertIsString( $build_script );
		self::assertStringContainsString( "--exclude 'assets/images/integrations/'", $build_script );
		self::assertStringContainsString( "--exclude 'assets/images/other_plugins/'", $build_script );
	}

	/**
	 * The repository landing page uses only Ashbi product branding.
	 */
	public function testRepositoryLandingPageContainsNoUpstreamBranding(): void {
		$readme   = dirname( __DIR__, 2 ) . '/README.md';
		$contents = file_get_contents( $readme );
		self::assertNotFalse( $contents );

		foreach ( self::PROHIBITED_PATTERNS as $pattern ) {
			self::assertSame( 0, preg_match( $pattern, $contents ), 'README.md matches ' . $pattern );
		}
	}

	/**
	 * Rebranding must not alter identifiers used by existing installations.
	 */
	public function testLegacyCompatibilityIdentifiersRemainIntact(): void {
		$root        = dirname( __DIR__, 2 );
		$main        = file_get_contents( $root . '/plugin/subscription.php' );
		$menu        = file_get_contents( $root . '/plugin/includes/Admin/Menu.php' );
		$composer    = file_get_contents( $root . '/plugin/composer.json' );
		$identifiers = array(
			'Text Domain: subscription'           => $main,
			'final class Sdevs_Subscription'      => $main,
			"'wp-subscription'"                   => $menu,
			"'wp-subscription-list'"              => $menu,
			"'wp-subscription-details'"           => $menu,
			"'wp-subscription-stats'"             => $menu,
			"'wp-subscription-health'"            => $menu,
			"'wp-subscription-delivery'"          => $menu,
			"'wp-subscription-support'"           => $menu,
			"rest_url( 'wpsubscription/v1/plans'" => $menu,
			'SpringDevs\\\\Subscription\\\\'      => $composer,
		);

		foreach ( $identifiers as $identifier => $contents ) {
			self::assertIsString( $contents );
			self::assertStringContainsString( $identifier, $contents, 'Missing compatibility identifier: ' . $identifier );
		}
	}

	/**
	 * The distributed bundle must match availability and link behavior in source.
	 */
	public function testDashboardBundleMatchesCustomerFacingSourceBehavior(): void {
		$root   = dirname( __DIR__, 2 );
		$bundle = file_get_contents( $root . '/plugin/build/dashboard.js' );
		self::assertIsString( $bundle );

		self::assertStringNotContainsString( '.__)("Unavailable","subscription")', $bundle );
		self::assertStringNotContainsString( 'Not included in this build', $bundle );
		self::assertStringNotContainsString( '.__)("Pro","subscription")', $bundle );
		self::assertStringContainsString( 'target:s.external?"_blank":void 0', $bundle );
		self::assertStringContainsString( 's.external?(0,t.jsx)(c,{name:"external",size:11}):null', $bundle );
	}
}

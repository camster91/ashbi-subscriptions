<?php
/**
 * Header contract preventing replacement through the inherited plugin slug.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Offline local plugin-header contract.
use PHPUnit\Framework\TestCase;

/** Pins the WordPress-supported no-updater metadata without changing identity. */
final class UpdateUriContractTest extends TestCase {
	/**
	 * WordPress reads the supported UpdateURI header in the first 8 KiB.
	 *
	 * Source: WordPress 6.2 wp-admin/includes/plugin.php get_plugin_data()
	 * maps UpdateURI to Update URI, then wp-includes/functions.php
	 * get_file_data() uses this header regex on the first 8 KiB. The official
	 * WordPress 5.8 Update URI dev note explicitly supports the string false
	 * to opt out of directory updates without implementing a network updater.
	 *
	 * @see https://make.wordpress.org/core/2021/06/29/introducing-update-uri-plugin-header-in-wordpress-5-8/
	 * @see https://github.com/WordPress/WordPress/blob/6.2/wp-includes/functions.php
	 */
	public function test_main_header_opts_out_of_directory_updates(): void {
		$main   = dirname( __DIR__, 2 ) . '/plugin/subscription.php';
		$header = str_replace( "\r", "\n", substr( file_get_contents( $main ), 0, 8192 ) );
		self::assertSame( 1, preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Update URI:(.*)$/mi', $header, $matches ), 'The legacy basename must not accept upstream directory updates.' );
		self::assertSame( 'false', trim( $matches[1] ) );
	}
}

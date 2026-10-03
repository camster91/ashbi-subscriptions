<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit fixture filename.
/**
 * Full-page cache purge helper after variation mapping changes.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Illuminate\Plans\CachePurge;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Isolated WordPress stubs plus PHPUnit tests.

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Absolute integer helper for the isolated fixture.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Record actions (shared recorder used by other isolated fixtures).
	 *
	 * @param string $hook Action name.
	 * @param mixed  ...$args Action arguments.
	 */
	function do_action( $hook, ...$args ) {
		$GLOBALS['ashbi_actions'][] = array( $hook, $args );
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Plans/CachePurge.php';

/**
 * Verifies LiteSpeed/Rocket/etc. hooks fire only when available.
 */
final class CachePurgeTest extends TestCase {
	/** Reset recorded hooks. */
	protected function setUp(): void {
		$GLOBALS['ashbi_actions'] = array();
		$GLOBALS['ashbi_cache_purge_calls']   = array();
	}

	/** LiteSpeed action and mapping-changed hook fire for each unique id. */
	public function testFiresLiteSpeedAndMappingChangedHooks(): void {
		self::assertFalse( function_exists( 'rocket_clean_post' ) );
		self::assertFalse( function_exists( 'wp_cache_post_change' ) );
		self::assertFalse( function_exists( 'w3tc_flush_post' ) );

		$count = CachePurge::products( array( 10, '10', 0, 20 ) );

		self::assertSame( 2, $count );
		self::assertSame(
			array(
				array( 'litespeed_purge_post', array( 10 ) ),
				array( 'litespeed_purge_post', array( 20 ) ),
				array( 'subscrpt_variation_mapping_changed', array( array( 10, 20 ) ) ),
			),
			$GLOBALS['ashbi_actions']
		);
		self::assertSame( array(), $GLOBALS['ashbi_cache_purge_calls'] );
	}

	/**
	 * Optional cache APIs are invoked only when they exist.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testOptionalPurgeFunctionsAreCalledOnlyWhenPresent(): void {
		require_once dirname( __DIR__ ) . '/fixtures/cache-purge-optional-functions.php';

		$GLOBALS['ashbi_actions'] = array();
		$GLOBALS['ashbi_cache_purge_calls']   = array();

		CachePurge::products( array( 5 ) );

		self::assertSame(
			array(
				array( 'rocket_clean_post', 5 ),
				array( 'wp_cache_post_change', 5 ),
				array( 'w3tc_flush_post', 5 ),
			),
			$GLOBALS['ashbi_cache_purge_calls']
		);
		self::assertSame(
			array(
				array( 'litespeed_purge_post', array( 5 ) ),
				array( 'subscrpt_variation_mapping_changed', array( array( 5 ) ) ),
			),
			$GLOBALS['ashbi_actions']
		);
	}

	/** Empty input is a no-op. */
	public function testEmptyIdsDoNotFireHooks(): void {
		self::assertSame( 0, CachePurge::products( array() ) );
		self::assertSame( array(), $GLOBALS['ashbi_actions'] );
	}
}

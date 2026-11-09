<?php
/**
 * Scoped merchant cancellation review query regressions.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Verify query isolation and visible failures against actual source. */
final class CancellationReviewTest extends TestCase {
	/** Only the selected subscription query is affected; failures remove its filter. */
	public function test_query_is_scoped_uncached_and_cleans_up_after_failures(): void {
		$path = dirname( __DIR__ ) . '/fixtures/cancellation-review-query.php';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote a local fixture path for subprocess execution.
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertIsArray( $data );
		self::assertNull( $data['success']['error'] );
		self::assertSame( 'subscrpt_order', $data['success']['args']['post_type'] );
		self::assertSame( 20, $data['success']['args']['posts_per_page'] );
		self::assertSame( 2, $data['success']['args']['paged'] );
		self::assertSame( 99, $data['success']['args']['p'] );
		self::assertFalse( $data['success']['args']['cache_results'] );
		self::assertFalse( $data['success']['args']['suppress_filters'] );
		self::assertNotSame( ' WHERE wp_posts.ID > 0', $data['success']['where'] );
		foreach ( array( 'success', 'storage', 'exception' ) as $name ) {
			$case = $data[ $name ];
			self::assertSame( ' WHERE wp_posts.ID > 0', $case['nested'] );
			self::assertSame( 0, $case['filters'] );
		}
		self::assertNotNull( $data['storage']['error'] );
		self::assertStringNotContainsString( 'private SQL', $data['storage']['error'] );
		self::assertSame( 'fabricated_query_exception', $data['exception']['error'] );
		foreach ( $data['access'] as $case ) {
			self::assertTrue( $case['denied'] );
			self::assertSame( 0, $case['queries'] );
		}
	}
}

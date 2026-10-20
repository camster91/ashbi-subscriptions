<?php
/** Optional contact intake adapter acceptance tests. */
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;
final class ContactRequestReviewTest extends TestCase {
	public function test_intake_failures_permissions_and_verified_resolution(): void {
		$path = dirname( __DIR__ ) . '/fixtures/run-contact-request-review.php';
		$r    = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $r['stderr'] );
		$d = json_decode( $r['stdout'], true );
		self::assertIsArray( $d, $r['stdout'] );
		self::assertGreaterThanOrEqual( 30, $d['passed'] );
	}
}

<?php
/** Optional contact intake adapter acceptance tests. */
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;
final class ContactRequestReviewTest extends TestCase {
	/** A status and metadata cannot replace a missing core proof reader. */
	public function test_missing_core_reader_cannot_confirm_cancellation(): void {
		$path = dirname( __DIR__, 2 ) . '/tools/contact-request-review/ashbi-contact-request-review.php';
		$setup = <<<'PHP'
<?php
define( 'ABSPATH', '/' );
function add_action( ...$args ) {}
function get_post( $id ) { return (object) array( 'post_type' => 'subscrpt_order', 'post_status' => 'cancelled' ); }
PHP;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote the local adapter path for the isolated PHP process.
		$result = AbstractPhpProcess::factory()->runJob( $setup . "\nrequire " . var_export( $path, true ) . '; echo json_encode( Ashbi_Contact_Request_Review::confirmed( 123 ) );' );
		self::assertSame( '', $result['stderr'] );
		self::assertSame( 'false', trim( $result['stdout'] ) );
	}

	public function test_intake_failures_permissions_and_verified_resolution(): void {
		$path = dirname( __DIR__ ) . '/fixtures/run-contact-request-review.php';
		$r    = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $r['stderr'] );
		$d = json_decode( $r['stdout'], true );
		self::assertIsArray( $d, $r['stdout'] );
		self::assertGreaterThanOrEqual( 30, $d['passed'] );
	}
}

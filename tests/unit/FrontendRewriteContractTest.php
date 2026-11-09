<?php
/**
 * Verify that rewrite flushing is bounded to activation and admin paths.
 *
 * @package AshbiSubscriptions
 */

use PHPUnit\Framework\TestCase;

/**
 * Frontend rewrite contract tests.
 */
final class FrontendRewriteContractTest extends TestCase {
	/** Admin permalink generation must keep the configured customer routes. */
	public function test_account_endpoints_register_once_in_admin_frontend_and_ajax(): void {
		$fixture = dirname( __DIR__ ) . '/fixtures/account-admin-bootstrap.php';
		foreach ( array( 'admin', 'frontend', 'ajax' ) as $scenario ) {
			$output = \PHPUnit\Util\PHP\AbstractPhpProcess::factory()->runJob( '<?php $argv[1] = ' . var_export( $scenario, true ) . '; require ' . var_export( $fixture, true ) . ';' );
			self::assertSame( '', $output['stderr'], $scenario );
			self::assertSame( array( array( 'my-plans', 4160 ), array( 'my-plan', 4160 ) ), json_decode( $output['stdout'], true ), $scenario );
		}
	}

	/**
	 * Source for the customer account controller.
	 *
	 * @var string
	 */
	private string $my_account_source;
	/**
	 * Source for the plugin bootstrap.
	 *
	 * @var string
	 */
	private string $bootstrap_source;

	/**
	 * Load the source files under test.
	 */
	protected function setUp(): void {
		$root = dirname( __DIR__, 2 );
		// Local source fixture reads are intentional in this contract test.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->my_account_source = (string) file_get_contents( $root . '/plugin/includes/Frontend/MyAccount.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->bootstrap_source = (string) file_get_contents( $root . '/plugin/bootstrap.php' );
	}

	/**
	 * Public requests must not flush rewrite rules on every request.
	 */
	public function test_public_requests_register_endpoints_without_flushing_rewrite_rules(): void {
		$this->assertStringContainsString( 'register_rewrite_endpoints', $this->my_account_source );
		$this->assertStringContainsString( 'maybe_flush_rewrite_rules', $this->my_account_source );
		$this->assertStringContainsString( 'flush_rewrite_rules( false )', $this->my_account_source );
		$this->assertStringNotContainsString( "add_action( 'init', array( \$this, 'flush_rewrite_rules' ) )", $this->my_account_source );
	}

	/**
	 * Activation must schedule the bounded rewrite flush.
	 */
	public function test_activation_schedules_the_bounded_rewrite_flush(): void {
		$this->assertStringContainsString( "update_option( 'subscrpt_rewrite_flush_pending', 1 )", $this->bootstrap_source );
	}
}

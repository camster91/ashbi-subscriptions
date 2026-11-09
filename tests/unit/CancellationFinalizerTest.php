<?php
/**
 * Legacy cancellation finalizer preserves durable intent and feedback history.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;
/** Regressions against the actual isolated plugin runtime. */
final class CancellationFinalizerTest extends TestCase {
	/**
	 * Execute the isolated fixture and read its result.
	 */
	private function fixture(): array {
		$path   = dirname( __DIR__ ) . '/fixtures/run-cancellation-finalizer.php';
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertIsArray( $data, $output['stdout'] );
		return $data;}
	/**
	 * Verify due legacy cancellation does not depend on mailer availability.
	 */
	public function test_due_legacy_cancellation_does_not_depend_on_mailer_availability(): void {
		$case = $this->fixture()['mailer_absent'];
		self::assertNull( $case['error'] );
		self::assertSame( 'cancelled', $case['status'] );}
	/**
	 * Verify failed status write preserves original cancellation schedule.
	 */
	public function test_failed_status_write_preserves_original_cancellation_schedule(): void {
		$case = $this->fixture()['status_failure'];
		self::assertSame( 'pe_cancelled', $case['status'] );
		self::assertSame( $case['before'], $case['after'] );}
	/**
	 * Verify durable finalization uses repair and preserves original barrier.
	 */
	public function test_durable_finalization_uses_repair_and_preserves_original_barrier(): void {
		$case = $this->fixture()['durable'];
		self::assertSame( 'cancelled', $case['status'] );
		self::assertSame( $case['original'], $case['barrier'] );
		self::assertSame( 0, (int) $case['meta']['_subscrpt_auto_renew'] );
		self::assertSame( (int) $case['original']['access_end'], (int) $case['meta']['_subscrpt_cancel_at'] );
		self::assertSame( 1, (int) $case['meta']['_ashbi_cancellation_confirmed'] );}
	/**
	 * Verify feedback appends instead of updating or deleting prior history.
	 */
	public function test_feedback_appends_instead_of_updating_or_deleting_prior_history(): void {
		$case = $this->fixture()['feedback'];
		self::assertSame( array( 'json_success', 'json_success' ), $case['responses'] );
		self::assertCount( 2, $case['rows'] );
		self::assertSame( 'reason1', $case['rows'][0]['reason_key'] );
		self::assertSame( 'reason2', $case['rows'][1]['reason_key'] );
		self::assertSame( 'First reason', $case['rows'][0]['reason_label'] );
		self::assertSame( array(), $case['mutations'] );
		self::assertSame( 'active', $case['status'] );}
}

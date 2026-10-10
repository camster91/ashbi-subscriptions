<?php
/**
 * Cancellation barriers prevent new charges and permit only read-only reconciliation.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;
/** Regressions against the actual isolated plugin runtime. */
final class StripeCancellationRaceTest extends TestCase {
	/**
	 * Execute the isolated fixture and read its result.
	 */
	private function fixture(): array {
		$path   = dirname( __DIR__ ) . '/fixtures/run-stripe-cancellation-race.php';
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertIsArray( $data, $output['stdout'] );
		return $data;}
	/**
	 * Verify already cancelled dispatch stops before provider preparation.
	 */
	public function test_already_cancelled_dispatch_stops_before_provider_preparation(): void {
		$case = $this->fixture()['already_cancelled'];
		self::assertNull( $case['error'] );
		self::assertSame( 0, $case['prepare'] );
		self::assertSame( 0, $case['dispatches'] );
		self::assertSame( 'cancellation_barrier', $case['response_code'] );
		self::assertSame( $case['locks'], $case['unlocks'] );}
	/**
	 * Verify cancellation during preparation stops before provider charge and unlocks.
	 */
	public function test_cancellation_during_preparation_stops_before_provider_charge_and_unlocks(): void {
		$case = $this->fixture()['before_dispatch'];
		self::assertNull( $case['error'] );
		self::assertSame( 0, $case['dispatches'] );
		self::assertSame( 'cancellation_barrier', $case['response_code'] );
		self::assertSame( $case['locks'], $case['unlocks'] );
		self::assertSame( $case['order_locks'], $case['order_unlocks'] );
		self::assertNotEmpty( $case['events'] );}
	/**
	 * Verify cancelled retry only reads prior intent and records review.
	 */
	public function test_cancelled_retry_only_reads_prior_intent_and_records_review(): void {
		$d = $this->fixture();
		foreach ( array( 'reconcile', 'reconcile_error' ) as $name ) {
			$case = $d[ $name ];
			self::assertNull( $case['error'] );
			self::assertSame( 0, $case['dispatches'] );
			self::assertSame( array( 'payment_intents/pi_offline' ), $case['reads'] );
			self::assertSame( 0, $case['prepare'] );
			self::assertNotEmpty( $case['events'] );
			self::assertSame( 'dispatch_review', $case['events'][0]['event_type'] );}}
	/**
	 * Verify cancellation after dispatch records review without another charge.
	 */
	public function test_cancellation_after_dispatch_records_review_without_another_charge(): void {
		$case = $this->fixture()['during_dispatch'];
		self::assertNull( $case['error'] );
		self::assertSame( 1, $case['dispatches'] );
		self::assertSame( 'cancellation_review', $case['response_code'] );
		self::assertSame( $case['locks'], $case['unlocks'] );
		self::assertSame( $case['order_locks'], $case['order_unlocks'] );
		self::assertSame( 'dispatch_review', $case['events'][0]['event_type'] );}
}

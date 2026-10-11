<?php
/**
 * Staff evidence exports enforce capability, exact-ID nonce and private data boundaries.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;
/** Regressions against the actual isolated plugin runtime. */
final class EvidenceExportTest extends TestCase {
	/**
	 * Execute the isolated fixture and read its result.
	 *
	 * @param string $case Fixture case selector.
	 */
	private function fixture( $case ): array {
		$path   = dirname( __DIR__ ) . '/fixtures/run-evidence-export.php';
		$output = AbstractPhpProcess::factory()->runJob( '<?php $GLOBALS["export_case"]=' . var_export( $case, true ) . ';require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertIsArray( $data, $output['stdout'] );
		return $data;}
	/**
	 * Verify capability and exact subscription nonce are checked before database reads.
	 */
	public function test_capability_and_exact_subscription_nonce_are_checked_before_database_reads(): void {
		foreach ( array(
			'denied' => 'wp_die:403',
			'nonce'  => 'nonce_denied',
		) as $case => $expected ) {
			$d = $this->fixture( $case );
				self::assertSame( $expected, $d['error'] );
				self::assertSame( array(), $d['reads'] );
			if ( 'nonce' === $case ) {
				self::assertSame( 'subscrpt_evidence_export_99', $d['nonce_action'] );}
		}}
	/**
	 * Verify storage failure returns safe error without partial export.
	 */
	public function test_storage_failure_returns_safe_error_without_partial_export(): void {
		$d = $this->fixture( 'storage' );
		self::assertSame( 'wp_die:503', $d['error'] );
		self::assertArrayNotHasKey( 'report', $d );}
	/**
	 * Verify export is scoped to current site subscription and related orders.
	 */
	public function test_export_is_scoped_to_current_site_subscription_and_related_orders(): void {
		$d = $this->fixture( 'build' );
		self::assertSame( 99, $d['report']['subscription_id'] );
		self::assertSame( 501, $d['report']['orders'][0]['order_id'] );
		foreach ( $d['reads'] as $read ) {
			self::assertStringStartsWith( 'site_a_', $read[1][0] );
			self::assertContains( (int) $read[1][1], array( 99, 501 ) );
		}self::assertNotEmpty( $d['report']['limitations'] );
		self::assertFalse( $d['report']['orders'][0]['acceptance_verified_against_current_order'], 'Tampered snapshot must not be labelled verified.' );}
	/**
	 * Verify private fields are removed recursively from stored payloads and events.
	 */
	public function test_private_fields_are_removed_recursively_from_stored_payloads_and_events(): void {
		$d      = $this->fixture( 'build' );
		$report = json_encode( $d['report'] );
		foreach ( array( 'private-customer@example.test', 'private-plan-token', 'private-document-secret', 'private-event-token', '4242424242424242' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $report );}}
	/**
	 * Verify export preserves accepted installment obligations.
	 */
	public function test_export_preserves_accepted_installment_obligations(): void {
		$item = $this->fixture( 'build' )['report']['orders'][0]['acceptance']['snapshot']['items'][0];
		self::assertSame( 'split_payment', $item['payment_type'] ?? null );
		self::assertSame( 4, $item['billing_length'] ?? null );
		self::assertSame( '50.00', $item['plan_total'] ?? null );
	}
}

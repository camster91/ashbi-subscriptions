<?php
/**
 * Independent real-source cancellation evidence acceptance regressions.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Regressions against the actual isolated plugin runtime. */
final class CancellationEvidenceTest extends TestCase {
	/**
	 * Execute the isolated fixture and read its result.
	 */
	private function fixture(): array {
		$path   = dirname( __DIR__ ) . '/fixtures/run-cancellation-evidence.php';
		$result = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $result['stderr'] );
		$data = json_decode( $result['stdout'], true );
		self::assertIsArray( $data, $result['stdout'] );
		self::assertArrayNotHasKey( 'implementation_missing', $data, 'CancellationEvidence implementation is required.' );
		return $data;
	}
	/**
	 * Verify owner cancel persists immutable barrier and blocks resurrection.
	 */
	public function test_owner_cancel_persists_immutable_barrier_and_blocks_resurrection(): void {
		$d = $this->fixture();
		self::assertContains( $d['owner']['state'], array( 'pending', 'confirmed' ) );
		self::assertTrue( $d['owner_blocked'] );
		self::assertCount( 1, $d['owner_barriers'] );
		self::assertTrue( $d['barrier_immutable'] );
		self::assertTrue( $d['resurrection_blocked'] );
		self::assertSame( 'confirmed', $d['duplicate']['state'] );
		foreach ( array( 'hooks', 'status_writes', 'comments', 'status', 'meta' ) as $effect ) {
			self::assertSame( $d['duplicate_before'][ $effect ], $d['duplicate_after'][ $effect ], 'Duplicate must not repeat lifecycle effects: ' . $effect );}
		self::assertGreaterThan( $d['duplicate_before']['events'], $d['duplicate_after']['events'], 'Repeated received requests preserve append-only evidence.' );
	}
	/**
	 * Verify authorization is enforced independent of controller.
	 */
	public function test_authorization_is_enforced_independent_of_controller(): void {
		$d = $this->fixture();
		foreach ( array( 'foreign', 'guest', 'spoofed' ) as $name ) {
			self::assertSame( 'failed', $d[ $name ]['state'], $name );
			self::assertSame( 0, $d[ $name . '_barriers' ], $name );}
		foreach ( array( 'foreign', 'guest', 'spoofed' ) as $name ) {
			foreach ( $d[ $name . '_events' ] as $event ) {
				self::assertSame( 0, (int) $event['subscription_id'] );
				self::assertSame( 0, (int) $event['actor_id'] );
				self::assertSame( 'request_rejected', $event['event_type'] );}
		}
		self::assertContains( $d['admin']['state'], array( 'pending', 'confirmed' ) );
		self::assertSame( 1, $d['admin_barriers'] );
	}
	/**
	 * Verify outcome audit failure does not claim complete evidence.
	 */
	public function test_outcome_audit_failure_does_not_claim_complete_evidence(): void {
		$d = $this->fixture();
		self::assertTrue( $d['outcome_audit_write_blocked'] );
		self::assertFalse( $d['outcome_audit_write']['audit_complete'], 'A failed outcome insert must not be reported as complete evidence.' );
		self::assertNotSame( 'confirmed', $d['outcome_audit_write']['state'] );
	}
	/**
	 * Verify repeated request repairs failed writes using immutable original deadline.
	 */
	public function test_repeated_request_repairs_failed_writes_using_immutable_original_deadline(): void {
		$d = $this->fixture(); foreach ( array( 'repair_status', 'repair_meta' ) as $name ) {
			$case = $d[ $name ];
			self::assertTrue( $case['first']['barrier'] );
			self::assertSame( 'pending', $case['first']['state'] );
			self::assertSame( $case['original'], $case['final'], 'Repair must not replace original authorized intent.' );
			$end = (int) $case['original'][99]['access_end'];
			self::assertSame( 'confirmed', $case['second']['state'], $name );
			self::assertSame( $end, (int) $case['second']['access_end'] );
			self::assertSame( $end, (int) $case['meta']['_subscrpt_cancel_at'] );
			self::assertSame( 0, (int) $case['meta']['_subscrpt_auto_renew'] );
			self::assertSame( 'pe_cancelled', $case['status'], 'An unexpired original access period must remain pending cancellation.' );
			self::assertTrue( $case['blocked'] );
		}
	}
	/**
	 * Verify missing parent order cannot confirm unknown billing route.
	 */
	public function test_missing_parent_order_cannot_confirm_unknown_billing_route(): void {
		$d = $this->fixture();
		self::assertTrue( $d['missing_parent_blocked'] );
		self::assertNotSame( 'confirmed', $d['missing_parent']['state'] );
	}
	/**
	 * Verify unverified provider routes keep local barrier without claiming remote stop.
	 */
	public function test_unverified_provider_routes_keep_local_barrier_without_claiming_remote_stop(): void {
		foreach ( $this->fixture()['unverified_routes'] as $case ) {
			self::assertSame( 'pending', $case['result']['state'] );
			self::assertTrue( $case['blocked'] );
			self::assertSame( 0, (int) $case['meta']['_subscrpt_auto_renew'] );
			self::assertSame( 0, (int) $case['meta']['_ashbi_cancellation_confirmed'] );
			self::assertSame( $case['original'], $case['final'] );
			foreach ( $case['events'] as $event ) {
				self::assertNotSame( 'cancel_confirmed', $event['event_type'] );}
			$last = end( $case['events'] );
			self::assertSame( 'unverified', json_decode( $last['details'], true )['provider_state'] );
		}
	}
	/**
	 * Verify dispatch contention preserves authorized intent before lock and replay repairs.
	 */
	public function test_dispatch_contention_preserves_authorized_intent_before_lock_and_replay_repairs(): void {
		$d    = $this->fixture();
		$case = $d['dispatch_contention'];
		self::assertSame( 'pending', $case['first']['state'] );
		self::assertTrue( $case['first']['barrier'] );
		self::assertTrue( $case['first_blocked'] );
		self::assertCount( 1, $case['original'] );
		self::assertSame( $case['original'], $case['final'] );
		self::assertSame( 'confirmed', $case['second']['state'] );
		$end = (int) $case['original'][99]['access_end'];
		self::assertSame( $end, (int) $case['second']['access_end'] );
		self::assertSame( $end, (int) $case['meta']['_subscrpt_cancel_at'] );
		self::assertSame( 'pe_cancelled', $case['status'] );
	}
	/**
	 * Verify worker and lost queue sweep repair authorized intent without customer session.
	 */
	public function test_worker_and_lost_queue_sweep_repair_authorized_intent_without_customer_session(): void {
		$d = $this->fixture();
		foreach ( array( 'queued_repair', 'lost_queue_sweep' ) as $name ) {
			$case = $d[ $name ];
			self::assertNull( $case['error'], $name );
			self::assertSame( $case['original'], $case['final'] );
			self::assertSame( 'pe_cancelled', $case['status'] );
			self::assertSame( 0, (int) $case['meta']['_subscrpt_auto_renew'] );
			self::assertSame( 1, (int) $case['meta']['_ashbi_cancellation_confirmed'] );
			self::assertSame( (int) $case['original'][99]['access_end'], (int) $case['meta']['_subscrpt_cancel_at'] );
			self::assertNotEmpty( $case['queued_before'] );}
	}
	/**
	 * Verify overdue confirmed request is finalized by sweep without extending access.
	 */
	public function test_overdue_confirmed_request_is_finalized_by_sweep_without_extending_access(): void {
		$case = $this->fixture()['overdue_sweep'];
		self::assertNull( $case['error'] );
		self::assertSame( 'cancelled', $case['status'] );
		self::assertSame( $case['original'], $case['final'] );
		self::assertSame( (int) $case['original'][99]['access_end'], (int) $case['meta']['_subscrpt_cancel_at'] );
	}
	/**
	 * Verify busy worker requeues without removing barrier or fabricating outcome.
	 */
	public function test_busy_worker_requeues_without_removing_barrier_or_fabricating_outcome(): void {
		$case = $this->fixture()['busy_worker'];
		self::assertNull( $case['error'] );
		self::assertSame( $case['original'], $case['final'] );
		self::assertSame( 'active', $case['status'] );
		self::assertGreaterThan( count( $case['queued_before'] ), count( $case['queued_after'] ) );
		foreach ( $case['events'] as $event ) {
			self::assertNotSame( 'cancel_confirmed', $event['event_type'] );}
	}
	/**
	 * Verify worker does not create intent when no authorized barrier exists.
	 */
	public function test_worker_does_not_create_intent_when_no_authorized_barrier_exists(): void {
		$case = $this->fixture()['no_intent_repair'];
		self::assertSame( array(), $case['barriers'] );
		self::assertSame( array(), $case['events'] );
		self::assertSame( array(), $case['status_writes'] );
		self::assertSame( 'active', $case['status'] );
	}
	/**
	 * Verify storage and lock failures never confirm cancellation.
	 */
	public function test_storage_and_lock_failures_never_confirm_cancellation(): void {
		$d = $this->fixture();
		foreach ( array( 'barrier_write', 'database_read', 'lock', 'status_write', 'meta_write' ) as $name ) {
			self::assertArrayNotHasKey( 'threw', $d[ $name ] );
			self::assertContains( $d[ $name ]['state'], array( 'failed', 'pending' ), $name );}
		self::assertTrue( $d['database_read_blocked'] );
		self::assertTrue( $d['status_write_blocked'] );
		self::assertTrue( $d['meta_write_blocked'] );
	}
	/**
	 * Verify feedback comment mail and audit failure do not remove barrier.
	 */
	public function test_feedback_comment_mail_and_audit_failure_do_not_remove_barrier(): void {
		$d = $this->fixture();
		foreach ( array( 'comment_write', 'mailer', 'audit_write' ) as $name ) {
			self::assertArrayNotHasKey( 'threw', $d[ $name ] );
			self::assertTrue( $d[ $name . '_blocked' ] );}
		self::assertNotSame( 'confirmed', $d['audit_write']['state'], 'Do not claim complete durable evidence when ledger insertion failed.' );
	}
	/**
	 * Verify append only events allowlist details and surface write failure.
	 */
	public function test_append_only_events_allowlist_details_and_surface_write_failure(): void {
		$d = $this->fixture();
		self::assertTrue( $d['record_first'] );
		self::assertTrue( $d['record_second'] );
		self::assertFalse( $d['record_failure'] );
		self::assertCount( 2, $d['events'] );
		foreach ( $d['events'] as $event ) {
			self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $event['request_id'] );
			self::assertSame( 99, (int) $event['subscription_id'] );}
		$details = json_decode( $d['events'][0]['details'], true );
		self::assertIsArray( $details );
		self::assertEmpty( array_diff( array_keys( $details ), array( 'code', 'status', 'order_id', 'access_end', 'audit_complete', 'provider_state' ) ) );
		$serialized = json_encode( $d['events'] );
		foreach ( array( 'fixture-secret', 'private@example.test', '4242424242424242', 'fixture-token', 'nested-secret', 'unnecessary personal data' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $serialized );}
		foreach ( $d['mutations'] as $mutation ) {
			self::assertNotSame( 'forbidden', $mutation[0] );}
	}
	/**
	 * Verify customer notice never claims receipt on unrecorded storage failure.
	 */
	public function test_customer_notice_never_claims_receipt_on_unrecorded_storage_failure(): void {
		$d = $this->fixture();
		foreach ( array( 'read_failure', 'write_failure', 'throw_failure' ) as $case ) {
			$x = $d['notices'][ $case ];
			self::assertSame( array(), $x['barriers'] );
			self::assertSame( 'error', $x['notices'][0]['type'] );
			self::assertStringNotContainsString( 'request is recorded', $x['notices'][0]['text'] );}
		self::assertTrue( $d['notices']['read_failure']['blocked'], 'Storage errors must still fail closed for dispatch.' );
		foreach ( array( 'pending', 'confirmed' ) as $case ) {
			self::assertCount( 1, $d['notices'][ $case ]['barriers'] );
			self::assertSame( 'success', $d['notices'][ $case ]['notices'][0]['type'] );}
	}
	/**
	 * Verify admin cannot mutate status or side effects before barrier guard.
	 */
	public function test_admin_cannot_mutate_status_or_side_effects_before_barrier_guard(): void {
		$case = $this->fixture()['admin_reopen'];
		self::assertSame( $case['before'], $case['after'] );
	}
	/**
	 * Verify admin legitimate transition completes order only after guarded success.
	 */
	public function test_admin_legitimate_transition_completes_order_only_after_guarded_success(): void {
		$cases = $this->fixture()['admin_status'];
		self::assertSame( 'active', $cases['normal']['status'] );
		self::assertSame( array( 'completed' ), $cases['normal']['order_writes'] );
		self::assertContains( 'subscrpt_status_changed_admin_email_notification', $cases['normal']['hooks'] );
		foreach ( array( 'write_failure', 'storage_failure' ) as $case ) {
			self::assertSame( 'on_hold', $cases[ $case ]['status'] );
			self::assertSame( array(), $cases[ $case ]['order_writes'] );
			self::assertNotContains( 'subscrpt_status_changed_admin_email_notification', $cases[ $case ]['hooks'] );}
	}
}

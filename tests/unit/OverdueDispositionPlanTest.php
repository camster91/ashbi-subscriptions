<?php
/**
 * Tests for immutable overdue-disposition planning.
 *
 * @package AshbiSubscriptions
 */

use Ashbi\Subscriptions\Tools\OverdueDispositionPlan;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/tools/lib/OverdueDispositionPlan.php';

/**
 * Verify that operator worksheets fail closed and remain source-bound.
 */
final class OverdueDispositionPlanTest extends TestCase {
	/**
	 * Build a fabricated source record.
	 *
	 * @return array<string,mixed>
	 */
	private function record(): array {
		$record                      = array(
			'subscription_id'       => 42,
			'status'                => 'active',
			'next_date_utc'         => '2025-09-14T12:00:00+00:00',
			'overdue_days'          => 365,
			'auto_renew'            => 'enabled',
			'payment_failure_count' => 0,
			'latest_paid_order_id'  => 101,
			'latest_paid_date_utc'  => '2025-08-14T12:00:00+00:00',
			'latest_paid_gateway'   => 'stripe',
			'open_renewal_orders'   => array(),
			'recurrence'            => array(
				'interval' => 1,
				'period'   => 'month',
			),
			'max_payments_reached'  => false,
			'operator_disposition'  => null,
			'operator_note'         => null,
		);
		$record['evidence_checksum'] = OverdueDispositionPlan::evidence_checksum( $record );
		return $record;
	}

	/**
	 * Build a fabricated worksheet around a source record.
	 *
	 * @param array<string,mixed> $record Source record.
	 * @return array<string,mixed>
	 */
	private function worksheet( array $record ): array {
		return array(
			'schema_version'       => 2,
			'generated_at'         => '2026-09-14T12:00:00+00:00',
			'site_url'             => 'https://client.example',
			'record_count'         => 1,
			'allowed_dispositions' => OverdueDispositionPlan::dispositions(),
			'records'              => array( $record ),
		);
	}

	/** Operator edits must not change the immutable evidence checksum. */
	public function test_operator_fields_do_not_invalidate_immutable_evidence(): void {
		$record                         = $this->record();
		$checksum                       = $record['evidence_checksum'];
		$record['operator_disposition'] = 'advance_without_charge';
		$record['operator_note']        = 'Reviewed against customer request.';
		$record['overdue_days']         = 366;

		$this->assertSame( $checksum, OverdueDispositionPlan::evidence_checksum( $record ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', OverdueDispositionPlan::validate( $this->worksheet( $record ), 'https://client.example' ) );
	}

	/** Changed source evidence must reject a previously reviewed worksheet. */
	public function test_changed_source_evidence_is_rejected(): void {
		$record                          = $this->record();
		$current                         = $record;
		$current['latest_paid_order_id'] = 102;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Source evidence changed' );
		OverdueDispositionPlan::assert_current( $record, $current );
	}

	/** Advancing a record with an open renewal order must fail closed. */
	public function test_advance_rejects_open_renewal_order(): void {
		$record                         = $this->record();
		$record['open_renewal_orders']  = array(
			array(
				'order_id' => 55,
				'status'   => 'pending',
			),
		);
		$record['evidence_checksum']    = OverdueDispositionPlan::evidence_checksum( $record );
		$record['operator_disposition'] = 'advance_without_charge';

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'has an open renewal order' );
		OverdueDispositionPlan::validate( $this->worksheet( $record ), 'https://client.example' );
	}

	/** Validation must bind to the exact site URL. */
	public function test_exact_site_and_complete_disposition_are_required(): void {
		$record = $this->record();
		$this->expectException( InvalidArgumentException::class );
		OverdueDispositionPlan::validate( $this->worksheet( $record ), 'https://other.example' );
	}

	/** Future-anchor calculation must advance from the original schedule. */
	public function test_future_anchor_steps_from_original_schedule(): void {
		$next = OverdueDispositionPlan::future_anchor(
			100,
			350,
			static function ( int $anchor ): int {
				return $anchor + 100;
			}
		);
		$this->assertSame( 400, $next );
	}

	/** Non-advancing recurrence must fail closed. */
	public function test_non_advancing_recurrence_fails_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		OverdueDispositionPlan::future_anchor(
			100,
			200,
			static function ( int $anchor ): int {
				return $anchor;
			}
		);
	}
}

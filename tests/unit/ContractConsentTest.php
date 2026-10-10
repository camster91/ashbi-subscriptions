<?php
/**
 * Independent contract consent validation regression tests.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Real-source tests; WordPress doubles remain isolated from the canonical suite. */
final class ContractConsentTest extends TestCase {
	/** Fetch one fixture run without caching production decisions across cases. */
	private function fixture(): array {
		$path   = dirname( __DIR__ ) . '/fixtures/run-contract-consent.php';
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertIsArray( $data, $output['stdout'] );
		return $data;
	}

	/** A checkbox cannot make an unapproved, disabled, or malformed contract valid. */
	public function test_only_explicitly_approved_consistent_document_is_available(): void {
		$data = $this->fixture();
		foreach ( $data['config'] as $name => $valid ) {
			self::assertSame( 'approved' === $name, $valid, $name );
		}
		self::assertSame( 'fixture-v1', $data['document']['version'] );
		self::assertSame( hash( 'sha256', $data['document']['text'] ), $data['document']['hash'] );
		self::assertSame( 'fixture-approval-1', $data['document']['approval_ref'] );
	}

	/** Truthy values, missing values and unchecked inputs must never count as consent. */
	public function test_acceptance_requires_exact_checkbox_value_and_current_document_hash(): void {
		$data = $this->fixture();
		self::assertSame( array_merge( array( true ), array_fill( 0, 12, false ) ), $data['accepted_values'] );
		self::assertFalse( $data['forged_revision'] );
		self::assertFalse( $data['missing_revision'] );
		self::assertFalse( $data['superseded_revision'] );
		self::assertTrue( $data['classic_simple'] );
	}

	/** Plan changes invalidate acceptance of the previously displayed snapshot. */
	public function test_acceptance_is_bound_to_complete_current_purchased_snapshot(): void {
		$data = $this->fixture();
		self::assertFalse( $data['missing_snapshot_hash'] );
		self::assertFalse( $data['forged_snapshot_hash'] );
		self::assertFalse( $data['changed_quantity'] );
		self::assertFalse( $data['changed_price'] );
		foreach ( $data['snapshots'] as $name => $accepted ) {
			self::assertFalse( $accepted, $name );
		}
	}

	/** Canonical plugin cart terms must survive normalization into the consent schema. */
	public function test_cart_builder_normalizes_plan_and_legacy_simple_terms(): void {
		$data = $this->fixture();
		self::assertTrue( $data['canonical_cart_valid'], 'Actual plugin cart field shape must be accepted.' );
		$items = $data['canonical_cart']['items'];
		self::assertCount( 2, $items );
		self::assertSame( 12.50, (float) $items[0]['plan']['price'] );
		self::assertSame( 2, $items[0]['plan']['time'] );
		self::assertSame( 'months', $items[0]['plan']['type'] );
		self::assertSame( 3.25, (float) $items[0]['signup_fee'] );
		self::assertSame( 4, $items[0]['payment_count'] );
		self::assertSame( 2, $items[0]['quantity'] );
		self::assertSame( 9.00, (float) $items[1]['plan']['price'] );
		self::assertSame( 1, $items[1]['plan']['time'] );
		self::assertSame( 0, $items[1]['plan_id'] );
		self::assertSame( 0, $items[1]['variation_id'] );
		self::assertSame( 5, $items[1]['payment_count'] );
	}

	/** Renewal permission and historic subscribers cannot manufacture contract evidence. */
	public function test_validation_neither_infers_nor_backfills_historical_acceptance(): void {
		$data = $this->fixture();
		self::assertFalse( $data['historical_not_inferred'] );
		self::assertSame( 0, $data['writes'] );
		self::assertSame( 0, $data['historical_reads'] );
	}

	/** New store wording cannot retroactively replace acceptance on an unchanged order. */
	public function test_frozen_approved_revision_remains_valid_for_unchanged_order_retry(): void {
		$data = $this->fixture();
		self::assertTrue( $data['frozen_without_current_config'] );
		self::assertTrue( $data['frozen_after_revision_change'] );
	}

	/** Compare with actual final purchase details and independently validate the frozen document. */
	public function test_frozen_acceptance_rejects_changed_order_and_invalid_document(): void {
		$data = $this->fixture();
		foreach ( $data['frozen_order_mutations'] as $name => $accepted ) {
			self::assertFalse( $accepted, 'Changed actual order: ' . $name );
		}
		foreach ( $data['frozen_invalid_documents'] as $name => $accepted ) {
			self::assertFalse( $accepted, 'Invalid frozen evidence: ' . $name );
		}
	}
}

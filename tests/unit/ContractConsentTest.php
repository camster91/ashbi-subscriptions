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

	/** Final payable order fields must match the explicitly accepted cart. */
	public function test_order_snapshot_and_payability_bind_actual_canonical_terms_and_amounts(): void {
		$data = $this->fixture();
		self::assertNull( $data['order_gate_error'] );
		self::assertSame( $data['matching_cart_snapshot'], $data['order_snapshot'] );
		foreach ( $data['order_gate_mutations'] as $name => $payable ) {
			self::assertFalse( $payable, 'Changed final order still payable: ' . $name );
		}
		self::assertTrue( $data['altered_evidence_rejected'] );
		self::assertTrue( $data['missing_required_evidence_rejected'] );
	}

	/** Retrying an unchanged order preserves the original immutable acceptance. */
	public function test_order_retry_preserves_ledger_and_prior_approved_revision(): void {
		$data = $this->fixture();
		self::assertNull( $data['order_gate_error'] );
		self::assertTrue( $data['retry_can_pay'] );
		self::assertFalse( $data['already_nonpayable_stays_nonpayable'] );
		self::assertTrue( $data['retry_ledger_unchanged'] );
		self::assertTrue( $data['retry_meta_unchanged'] );
		self::assertSame( 1, $data['retry_insert_count'] );
		self::assertSame( 1, $data['final_ledger_insert_count'] );
	}

	/** Legacy renewal payment remains available without invented acceptance. */
	public function test_legacy_order_pay_has_no_backfill_and_required_failure_is_usable(): void {
		$data = $this->fixture();
		self::assertTrue( $data['legacy_can_pay'] );
		self::assertTrue( $data['legacy_meta_unchanged'] );
		self::assertTrue( $data['legacy_ledger_unchanged'] );
		self::assertNull( $data['guard_exception'] );
		self::assertGreaterThan( 0, $data['guard_notices'] );
	}

	/** Turning off new-purchase acceptance never bypasses a required order's evidence. */
	public function test_required_order_evidence_gate_survives_disabled_current_configuration(): void {
		$data = $this->fixture();
		self::assertTrue( $data['disabled_config_valid_order_can_pay'] );
		self::assertTrue( $data['disabled_config_missing_evidence_denied'] );
		self::assertTrue( $data['disabled_config_forged_evidence_denied'] );
		self::assertTrue( $data['disabled_config_legacy_can_pay'] );
		self::assertFalse( $data['eligibility_wrote_ledger'] );
	}

	/** Payment is denied until immutable acceptance can be durably stored and read back. */
	public function test_ledger_write_and_readback_failures_remain_nonpayable_without_erasing_evidence(): void {
		$data = $this->fixture();
		self::assertTrue( $data['write_failure_threw'] );
		self::assertTrue( $data['write_failure_nonpayable'] );
		self::assertTrue( $data['write_failure_no_row'] );
		self::assertTrue( $data['read_failure_threw'] );
		self::assertTrue( $data['read_failure_nonpayable'] );
		self::assertTrue( $data['corrupt_read_nonpayable'] );
		self::assertFalse( $data['failed_eligibility_wrote_ledger'] );
		self::assertTrue( $data['read_failure_evidence_retained'] );
		self::assertTrue( $data['read_recovery_can_pay'] );
	}

	/** A normal DB scalar reload must not invalidate the same accepted recurring price. */
	public function test_float_cart_price_matches_reloaded_wordpress_string_metadata(): void {
		$data = $this->fixture();
		self::assertSame( $data['float_cart_snapshot'], $data['reloaded_price_snapshot'] );
		self::assertTrue( $data['float_price_binding_valid'] );
	}

	/** Classic creation cannot silently substitute current product terms after consent. */
	public function test_classic_canonical_cadence_trial_and_amounts_are_stamped_and_bound(): void {
		$data = $this->fixture();
		self::assertTrue( $data['classic_order_binding_valid'] );
		$meta = $data['classic_canonical_meta'];
		self::assertSame( 1, $meta['_subscrpt_meta']['time'] );
		self::assertSame( 'months', $meta['_subscrpt_meta']['type'] );
		self::assertNull( $meta['_subscrpt_meta']['trial'] );
		self::assertSame( 9.0, (float) $meta['_subscrpt_plan_price'] );
		self::assertSame( 0.0, (float) $meta['_subscrpt_signup_fee'] );
		self::assertSame( 5, $meta['_subscrpt_max_no_payment'] );
		foreach ( $data['classic_canonical_mutations'] as $name => $accepted ) {
			self::assertFalse( $accepted, 'Classic canonical drift accepted: ' . $name );
		}
	}

	/** Finite installment obligations survive DB reload with exact lifecycle semantics. */
	public function test_payment_type_billing_length_and_installment_total_are_preserved(): void {
		$data = $this->fixture();
		self::assertSame( $data['installment_cart_snapshot'], $data['installment_order_snapshot'] );
		$item = $data['installment_order_snapshot']['items'][0];
		self::assertSame( 'split_payment', $item['payment_type'] );
		self::assertSame( 4, $item['billing_length'] );
		self::assertSame( '50.00', $item['plan_total'] );
	}

	/** Actual recovery companion preserves ledger gates and cancellation protection. */
	public function test_reviewed_policy_link_is_escaped_and_bound_to_document_and_ledger(): void {
		$data     = $this->fixture();
		$document = $data['policy_document'];
		self::assertSame( 'https://example.test/shipping-refunds?section=terms&lang=en', $document['policy_url'] );
		self::assertSame(
			hash(
				'sha256',
				json_encode(
					array(
						'text'       => $document['text'],
						'policy_url' => $document['policy_url'],
					)
				)
			),
			$document['hash']
		);
		self::assertStringContainsString( 'href="https://example.test/shipping-refunds?section=terms&amp;lang=en"', $data['policy_render'] );
		self::assertStringContainsString( '<a ', $data['policy_render'] );
		self::assertStringNotContainsString( 'checked', $data['policy_render'] );
		foreach ( $data['policy_invalid_urls'] as $rejected ) {
			self::assertTrue( $rejected ); }
		foreach ( $data['policy_invalid_frozen_urls'] as $rejected ) {
			self::assertTrue( $rejected ); }
		self::assertTrue( $data['policy_stale_hash_rejected'] );
		self::assertTrue( $data['policy_frozen_stale_hash_rejected'] );
		self::assertTrue( $data['policy_order_payable'] );
		self::assertTrue( $data['policy_rehashed_tamper_denied'] );
		self::assertTrue( $data['policy_ledger_unchanged'] );
		self::assertSame( hash( 'sha256', $data['document']['text'] ), $data['policy_legacy_hash'] );
		self::assertTrue( $data['policy_legacy_document_valid'] );
		self::assertTrue( $data['policy_legacy_frozen_valid'] );
	}

	/** Actual recovery companion preserves ledger gates and cancellation protection. */
	public function test_recovery_companion_disables_only_new_consent_without_erasing_protections(): void {
		$data = $this->fixture();
		self::assertTrue( $data['companion_new_document_disabled'] );
		self::assertTrue( $data['companion_existing_order_payable'] );
		self::assertTrue( $data['companion_missing_evidence_denied'] );
		self::assertTrue( $data['barrier_before_companion'] );
		self::assertTrue( $data['barrier_after_companion'] );
		self::assertTrue( $data['companion_config_unchanged'] );
		self::assertTrue( $data['companion_ledger_unchanged'] );
		self::assertTrue( $data['companion_barriers_unchanged'] );
		self::assertTrue( $data['companion_no_ledger_writes'] );
	}
}

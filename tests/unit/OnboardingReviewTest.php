<?php
/**
 * Wizard publication regressions using isolated WC/WordPress doubles.
 *
 * @package AshbiSubscriptions\Tests
 */
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit path.
use PHPUnit\Framework\TestCase;

/** Exercise real handlers in an isolated PHP process. */
final class OnboardingReviewTest extends TestCase {
	/** Exercise actual REST callbacks and repository writes/readback with a DB double. */
	public function test_seed_replacement_and_status_update_preserve_explicit_commitment(): void {
		$result = $this->run_fixture( 'persistence' );
		self::assertSame( 3, $result['seed']['data']['installment_count'] );
		self::assertSame( 5, $result['saved']['data']['installment_count'] );
		self::assertSame( 'active', $result['saved']['status'] );
		self::assertTrue( $result['refused'] );
		self::assertSame( 5, $result['quote']['max_payments'] );
		self::assertSame( 2, $result['quote']['price'] );
	}
	/** Draft linking cannot activate a classic fallback or change one-time settings. */
	public function test_draft_relation_does_not_enable_storefront_until_explicit_activation(): void {
		$result = $this->run_fixture( 'draft-relation' );
		self::assertSame( $result['before'], $result['draft'] );
		self::assertSame( 'yes', $result['active']['_subscrpt_enabled'] );
		self::assertSame( 'yes', $result['active']['_subscrpt_one_time_enabled'] );
		self::assertSame( 'active', $result['relation']['status'] );
	}
	/** Real validation, serialization and checkout quote agree on the count. */
	public function test_installment_commitment_is_validated_serialized_and_quoted(): void {
		$result = $this->run_fixture( 'commitment' );
		self::assertSame( array( false, false, false, false, false, true, false ), $result['valid'] );
		self::assertSame( 3, json_decode( $result['stored']['data'], true )['installment_count'] );
		self::assertSame( 'draft', $result['stored']['status'] );
		self::assertSame( 3, $result['quote']['max_payments'] );
		self::assertSame( 10, $result['quote']['total_price'] );
		self::assertSame( 3.33, $result['quote']['price'] );
	}
	/**
	 * Run the fixture (not a live WordPress/database test).
	 *
	 * @param string $mode Scenario.
	 * @return array
	 */
	private function run_fixture( $mode ) {
		$output = array();
		$code   = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/run-onboarding.php' ) . ' ' . escapeshellarg( $mode ), $output, $code );
		self::assertSame( 0, $code, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		self::assertIsArray( $result );
		return $result;
	}

	/** Draft is mandatory even for old clients omitting publication choices. */
	public function test_new_product_is_stored_as_draft_by_default(): void {
		$result = $this->run_fixture( 'create' );
		self::assertSame( 'draft', $result['stored_status'] );
		self::assertSame( 'draft', $result['response']['data']['product_status'] );
	}

	/** Only an explicitly confirmed wizard-owned new product can be published. */
	public function test_confirmed_new_product_can_be_published(): void {
		$result = $this->run_fixture( 'publish' );
		self::assertTrue( $result['response']['success'] );
		self::assertSame( 'publish', $result['stored_status'] );
	}

	/** The publication action must not change an ordinary existing product. */
	public function test_existing_product_status_cannot_be_changed_by_wizard(): void {
		$result = $this->run_fixture( 'existing' );
		self::assertFalse( $result['response']['success'] );
		self::assertSame( 'draft', $result['stored_status'] );
	}

	/** Explicit confirmation cannot be omitted. */
	public function test_publication_without_confirmation_is_refused(): void {
		$result = $this->run_fixture( 'no-confirmation' );
		self::assertFalse( $result['response']['success'] );
		self::assertSame( 'draft', $result['stored_status'] );
	}

	/** Existing capability boundary remains in force. */
	public function test_capability_refusal_makes_no_product(): void {
		$result = $this->run_fixture( 'permission' );
		self::assertFalse( $result['success'] );
		self::assertSame( 403, $result['status'] );
	}

	/** Existing nonce boundary remains in force. */
	public function test_nonce_refusal_makes_no_product(): void {
		$result = $this->run_fixture( 'nonce' );
		self::assertSame( 'nonce', $result['blocked'] );
		self::assertSame( 0, $result['products'] );
	}
}

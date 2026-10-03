<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Pure variation plan and migration regression fixtures.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PHPUnit class path.
use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Illuminate\Migration\FrequencyParser;
use SpringDevs\Subscription\Illuminate\Migration\VariationPlanPlanner;
use SpringDevs\Subscription\Illuminate\Plans\PlanRepository;
use SpringDevs\Subscription\Frontend\PlanCheckout;

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Migration/FrequencyParser.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Migration/VariationPlanPlanner.php';

/** Verify conservative parsing, mapping and compatibility. */
final class VariationPlansTest extends TestCase {
	/**
	 * Parser cases.
	 *
	 * @return array
	 */
	public function frequencies(): array {
		return array(
			array( '1-Month Subscription', 1, 3 ),
			array( '2 Month', 2, 3 ),
			array( 'Every 2 months', 2, 3 ),
			array( 'Monthly', 1, 3 ),
			array( 'Bi-monthly', 2, 3 ),
			array( 'Quarterly', 3, 3 ),
			array( 'Every 6 weeks', 6, 2 ),
			array( 'Weekly', 1, 2 ),
			array( 'Biweekly', 2, 2 ),
			array( 'Every other week', 2, 2 ),
			array( 'Annual', 1, 4 ),
			array( 'Yearly', 1, 4 ),
			array( '30 days', 30, 1 ),
			array( 'Every year', 1, 4 ),
			array( ' 3-month subscription ', 3, 3 ),
		);
	}

	/**
	 * Complete values parse to explicit cadence.
	 *
	 * @dataProvider frequencies
	 * @param string $value Input.
	 * @param int    $frequency Expected frequency.
	 * @param int    $interval Expected interval.
	 */
	public function test_parser( $value, $frequency, $interval ): void {
		$parsed = FrequencyParser::parse( $value );
		self::assertSame( 'detected', $parsed['action'] );
		self::assertSame( $frequency, $parsed['frequency'] );
		self::assertSame( $interval, $parsed['interval'] );
	}

	/** One-time and unknown values never guess a term. */
	public function test_parser_skips_one_time_and_unknown(): void {
		foreach ( array( 'One Time', 'One-time purchase', 'Single purchase' ) as $value ) {
			self::assertSame( 'one_time', FrequencyParser::parse( $value )['action'] );
		}
		foreach ( array( 'Large', '', '0 months', 'Monthly special offer', 'twice monthly', '999999 months' ) as $value ) {
			self::assertSame( 'unrecognised', FrequencyParser::parse( $value )['action'] );
		}
		self::assertNotEmpty( FrequencyParser::parse( 'Bi-monthly' )['note'] );
	}

	/**
	 * Fabricated variable product.
	 *
	 * @return array
	 */
	private function product(): array {
		return array(
			'id'        => 10,
			'variable'  => true,
			'mode'      => '',
			'entities'  => array(
				array(
					'id'         => 11,
					'attributes' => array( 'attribute_delivery-frequency' => 'Monthly' ),
				),
				array(
					'id'         => 12,
					'attributes' => array( 'attribute_delivery-frequency' => 'One Time' ),
				),
			),
			'relations' => array(),
		);
	}

	/**
	 * Fabricated safe monthly term.
	 *
	 * @return array
	 */
	private function terms(): array {
		return array(
			array(
				'id'                => 8,
				'billing_frequency' => 1,
				'billing_interval'  => 3,
				'status'            => 'active',
			),
		);
	}

	/** Reuse a term, keep stopgap rows and never overwrite relations. */
	public function test_reuse_idempotency_and_stopgap(): void {
		$product                = $this->product();
		$product['relations'][] = array(
			'vid'           => 0,
			'plan_id'       => 8,
			'relation_data' => array( 'regular_price' => '99' ),
		);
		$plan                   = VariationPlanPlanner::plan( $product, $this->terms() );
		self::assertSame( 'would_link', $plan['rows'][0]['action'] );
		self::assertSame( 8, $plan['rows'][0]['plan_id'] );
		self::assertCount( 1, $plan['stopgap'] );
		self::assertFalse( $plan['remove_stopgap'] );
		$product['relations'][] = array(
			'vid'           => 11,
			'plan_id'       => 8,
			'relation_data' => array( 'price_source' => 'variation' ),
		);
		self::assertSame( 'already_linked', VariationPlanPlanner::plan( $product, $this->terms() )['rows'][0]['action'] );
		self::assertTrue( VariationPlanPlanner::plan( $product, $this->terms(), '', true )['remove_stopgap'] );
		$product['relations'][1]['plan_id'] = 9;
		self::assertTrue( VariationPlanPlanner::plan( $product, $this->terms() )['blocked'] );
	}

	/** Same-term variations block all parent writes. */
	public function test_ambiguous_variations_and_missing_term(): void {
		$product = $this->product();
		self::assertSame( 'would_create_term', VariationPlanPlanner::plan( $product, array() )['rows'][0]['action'] );
		$product['entities'][1]['attributes']['attribute_delivery-frequency'] = 'Every month';
		$plan = VariationPlanPlanner::plan( $product, $this->terms() );
		self::assertTrue( $plan['blocked'] );
		self::assertFalse( $plan['mapped'] );
		self::assertSame( array( 'conflict', 'conflict' ), array_column( $plan['rows'], 'action' ) );
	}

	/** Legacy financial details are never silently lost in a shared term. */
	public function test_legacy_meta_and_manual_review(): void {
		$entity = array(
			'meta' => array(
				'_subscrpt_enabled'       => 'yes',
				'_subscrpt_timing_per'    => 2,
				'_subscrpt_timing_option' => 'months',
			),
		);
		self::assertSame( 2, VariationPlanPlanner::detect( $entity )['frequency'] );
		$entity['meta']['_subscrpt_signup_fee'] = '10';
		self::assertSame( 10.0, VariationPlanPlanner::detect( $entity )['signup_fee'] );
		$entity['meta']['_subscrpt_limit'] = 3;
		self::assertSame( 'manual_review', VariationPlanPlanner::detect( $entity )['action'] );
		self::assertSame(
			'unrecognised',
			VariationPlanPlanner::detect(
				array(
					'attributes' => array(
						'color' => 'blue',
						'size'  => 'large',
					),
				)
			)['action']
		);
		self::assertSame( 'unrecognised', VariationPlanPlanner::detect( array( 'attributes' => array( 'delivery-frequency' => 'Monthly' ) ), 'other' )['action'] );
	}

	/** Classic simple subscriptions require explicit opt-in; ordinary products are omitted. */
	public function test_classic_simple_is_report_only(): void {
		$product             = $this->product();
		$product['variable'] = false;
		$product['entities'] = array(
			array(
				'id'   => 10,
				'meta' => array(
					'_subscrpt_enabled'       => 'yes',
					'_subscrpt_timing_per'    => 1,
					'_subscrpt_timing_option' => 'months',
				),
			),
		);
		$plan                = VariationPlanPlanner::plan( $product, $this->terms() );
		self::assertSame( 'classic_simple', $plan['rows'][0]['action'] );
		self::assertSame( 'Classic simple subscription product (possible DWL target); report only. Re-run with --include-simple to link.', $plan['rows'][0]['note'] );
		self::assertFalse( $plan['mapped'] );
		self::assertSame( 'would_link', VariationPlanPlanner::plan( $product, $this->terms(), '', false, true )['rows'][0]['action'] );
		$product['entities'][0]['meta'] = array();
		self::assertSame( array(), VariationPlanPlanner::plan( $product, $this->terms(), '', false, true )['rows'] );
	}

	/** Default save timing cannot silently override a detected variation cadence. */
	public function test_legacy_timing_must_agree_with_attribute(): void {
		$product                        = $this->product();
		$product['entities'][0]['meta'] = array(
			'_subscrpt_enabled'       => 'yes',
			'_subscrpt_timing_per'    => 1,
			'_subscrpt_timing_option' => 'months',
		);
		self::assertSame( 'legacy_meta', VariationPlanPlanner::detect( $product['entities'][0] )['source'] );
		foreach ( array( '2-Month Subscription', 'Weekly' ) as $label ) {
			$product['entities'][0]['attributes']['attribute_delivery-frequency'] = $label;
			$detected = VariationPlanPlanner::detect( $product['entities'][0] );
			self::assertSame( 'manual_review', $detected['action'] );
			self::assertStringContainsString( 'Legacy timing ' . FrequencyParser::title( 1, 3 ), $detected['note'] );
			$parsed = FrequencyParser::parse( $label );
			self::assertStringContainsString( FrequencyParser::title( $parsed['frequency'], $parsed['interval'] ), $detected['note'] );
			self::assertTrue( VariationPlanPlanner::plan( $product, $this->terms() )['blocked'] );
		}
	}

	/** Exact mapping suppresses parent seeds; legacy inheritance remains unchanged. */
	public function test_context_filter_and_single_plan_selection(): void {
		$rows = array(
			array(
				'vid'     => 0,
				'plan_id' => 8,
			),
			array(
				'vid'     => 11,
				'plan_id' => 9,
			),
			array(
				'vid'     => 13,
				'plan_id' => 10,
			),
		);
		self::assertSame( array( $rows[1] ), PlanRepository::filter_context( $rows, 11, true ) );
		self::assertSame( array(), PlanRepository::filter_context( $rows, 12, true ) );
		self::assertSame( array(), PlanRepository::filter_context( $rows, 0, true ) );
		self::assertSame( array( $rows[0], $rows[1] ), PlanRepository::filter_context( $rows, 11 ) );
		self::assertSame( array( $rows[0] ), PlanRepository::filter_context( $rows, 12 ) );
		self::assertSame( 9, PlanCheckout::mapped_plan_id( array( $rows[1] ) ) );
		self::assertSame( 0, PlanCheckout::mapped_plan_id( array() ) );
	}
	/** Multiple mappings reject checkout rather than silently becoming a one-time purchase. */
	public function test_multiple_mappings_fail_closed(): void {
		$this->expectException( UnexpectedValueException::class );
		PlanCheckout::mapped_plan_id( array( array( 'plan_id' => 1 ), array( 'plan_id' => 2 ) ) );
	}
}

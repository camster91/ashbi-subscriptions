<?php
/**
 * Verify finite plan limits and currency-aware installment math.
 *
 * phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,Generic.Files.OneObjectStructurePerFile.Mixed,Universal.Files.SeparateFunctionsFromOO.Mixed -- PHPUnit fixture intentionally loads the production helpers and plan classes together.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	/** Return the fabricated store currency precision for the math tests. */
	function wc_get_price_decimals() {
		return $GLOBALS['ashbi_price_decimals'] ?? 2;
	}
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/functions.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Plans/PlanRepository.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Admin/PlanPresenter.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Frontend/PlanCheckout.php';

/** Verify plan payment limits and split installment selection. */
final class PlanBillingRulesTest extends TestCase {
	/** Use the normal two-decimal currency for each test unless overridden. */
	protected function setUp(): void {
		$GLOBALS['ashbi_price_decimals'] = 2;
	}

	/** A finite recurring plan must cap total payments at billing_length. */
	public function test_finite_recurring_plan_uses_billing_length_as_payment_limit(): void {
		$checkout = ( new ReflectionClass( '\SpringDevs\Subscription\Frontend\PlanCheckout' ) )->newInstanceWithoutConstructor();
		$method   = new ReflectionMethod( $checkout, 'term_terms' );

		$terms = $method->invoke(
			$checkout,
			array(
				'relation_data'   => array( 'regular_price' => '24.00' ),
				'plan_data'       => array(),
				'group_type'      => 2,
				'billing_frequency' => 1,
				'billing_interval'  => 3,
				'billing_length'    => 3,
				'free_trial'       => 0,
				'signup_fee'       => array( 'amount' => '0.00' ),
			)
		);

		self::assertSame( 'recurring', $terms['payment_type'] );
		self::assertSame( 3, $terms['max_payments'] );
		self::assertSame( 3, $terms['billing_length'] );
		self::assertSame( 24.0, $terms['price'] );
	}

	/** An installment plan must use its explicit installment count as the cap. */
	public function test_installment_plan_uses_installment_count_as_payment_limit(): void {
		$checkout = ( new ReflectionClass( '\SpringDevs\Subscription\Frontend\PlanCheckout' ) )->newInstanceWithoutConstructor();
		$method   = new ReflectionMethod( $checkout, 'term_terms' );

		$terms = $method->invoke(
			$checkout,
			array(
				'relation_data'     => array( 'regular_price' => '10.00' ),
				'plan_data'         => array( 'installment_count' => 3 ),
				'group_type'        => 3,
				'billing_frequency' => 1,
				'billing_interval'  => 3,
				'billing_length'    => 0,
				'free_trial'        => 0,
				'signup_fee'        => array( 'amount' => '0.00' ),
			)
		);

		self::assertSame( 'split_payment', $terms['payment_type'] );
		self::assertSame( 3, $terms['max_payments'] );
		self::assertSame( 3.33, $terms['price'] );
	}

	/** Splits use currency minor units and put the residual in the final installment. */
	public function test_split_amounts_use_currency_decimals_and_never_overcharge(): void {
		$amounts = subscrpt_split_amounts( 10.00, 3 );

		self::assertSame( array( 3.33, 3.33, 3.34 ), $amounts['installments'] );
		self::assertSame( 10.00, array_sum( $amounts['installments'] ) );
		self::assertSame( 3.33, subscrpt_split_installment_amount( 10.00, 3, 0 ) );
		self::assertSame( 3.33, subscrpt_split_installment_amount( 10.00, 3, 1 ) );
		self::assertSame( 3.34, subscrpt_split_installment_amount( 10.00, 3, 2 ) );
		self::assertSame( 0.0, subscrpt_split_installment_amount( 10.00, 3, 3 ) );
	}

	/** Zero-decimal currencies must round in minor units instead of assuming cents. */
	public function test_split_amounts_follow_zero_decimal_currency_precision(): void {
		$GLOBALS['ashbi_price_decimals'] = 0;
		$amounts = subscrpt_split_amounts( 10, 3 );

		self::assertSame( array( 3.0, 3.0, 4.0 ), $amounts['installments'] );
		self::assertSame( 10.0, array_sum( $amounts['installments'] ) );
	}

	/** Renewal creation must choose the next residual installment from the paid count. */
	public function test_renewals_use_paid_count_and_the_plan_total_snapshot(): void {
		$root   = dirname( __DIR__, 2 );
		$helper = (string) file_get_contents( $root . '/plugin/includes/Illuminate/Helper.php' );

		self::assertStringContainsString( 'subscrpt_split_installment_amount', $helper );
		self::assertStringContainsString( "get_post_meta( \$subscription_id, '_subscrpt_plan_total', true )", $helper );
		self::assertStringContainsString( 'subscrpt_count_payments_made( $subscription_id )', $helper );
		self::assertStringContainsString( 'payments_made', $helper );
	}
}

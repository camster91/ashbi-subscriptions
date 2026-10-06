<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Mapped prices and cadence survive the legacy updated-price renewal setting.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Illuminate\AutoRenewal;

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/AutoRenewal.php';

/** Regression test against the runtime metadata doubles in the existing suite. */
final class VariationRenewalTest extends TestCase {
	/** Mapped renewal price is frozen even if updated-product pricing is enabled. */
	public function test_updated_setting_does_not_override_mapped_snapshot(): void {
		$GLOBALS['ashbi_options']['subscrpt_renewal_price'] = 'updated';
		$GLOBALS['renewal_claim_meta'][987]                 = array(
			'_subscrpt_plan_data' => array( 'ashbi_price_source' => 'variation' ),
			'_subscrpt_plan_id'   => 8,
		);
		$renewal = ( new ReflectionClass( AutoRenewal::class ) )->newInstanceWithoutConstructor();
		$args    = array(
			'subtotal' => 17.5,
			'total'    => 17.5,
		);
		$meta    = array(
			'time'  => 2,
			'type'  => 'months',
			'trial' => null,
		);
		self::assertSame( $args, $renewal->filter_renewal_product_args( $args, null, null, 987 ) );
		self::assertSame( $meta, $renewal->filter_renewal_item_meta( $meta, null, null, 987 ) );
		unset( $GLOBALS['ashbi_options']['subscrpt_renewal_price'], $GLOBALS['renewal_claim_meta'][987] );
	}
}

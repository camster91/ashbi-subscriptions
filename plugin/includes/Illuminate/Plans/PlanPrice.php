<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Shared purchase-time price selection.
 *
 * @package SpringDevs\Subscription
 */

namespace SpringDevs\Subscription\Illuminate\Plans;

use SpringDevs\Subscription\Admin\PlanPresenter;

/** Resolve live prices only at purchase; renewal consumes the existing snapshot. */
class PlanPrice {
	/**
	 * Read the WC sale-aware price or the legacy typed offer.
	 *
	 * @param array $row Resolved row.
	 * @return float
	 * @throws \UnexpectedValueException When a live price cannot be resolved safely.
	 */
	public static function purchase_price( array $row ) {
		$data   = $row['relation_data'] ?? array();
		$source = $data['price_source'] ?? '';
		if ( in_array( $source, array( 'variation', 'product' ), true ) ) {
			$id      = 'variation' === $source ? (int) ( $row['vid'] ?? 0 ) : (int) ( $row['oid'] ?? 0 );
			$product = $id ? wc_get_product( $id ) : false;
			if ( ! $product || ! is_numeric( $product->get_price() ) || (float) $product->get_price() < 0 ) {
				throw new \UnexpectedValueException( 'Subscription purchase price is unavailable.' );
			}
			return (float) $product->get_price();
		}
		return (float) PlanPresenter::offer_price( (string) ( $data['regular_price'] ?? '' ), (string) ( $data['sale_price'] ?? '' ), (string) ( $data['discount_type'] ?? 'percentage' ), (string) ( $data['discount_value'] ?? '0' ) );
	}
}

<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Pure product-to-plan migration planning.
 *
 * @package SpringDevs\Subscription
 */

namespace SpringDevs\Subscription\Illuminate\Migration;

/** Classify one product at a time from immutable input arrays. */
class VariationPlanPlanner {
	/**
	 * Plan a product; a conflict blocks the whole parent to avoid partial mode changes.
	 *
	 * @param array  $product Product snapshot: id, variable, mode, entities, relations.
	 * @param array  $terms Target group's existing terms.
	 * @param string $attribute Optional attribute slug filter.
	 * @param bool   $remove_stopgap Explicit removal choice.
	 * @param bool   $include_simple Explicit classic simple linking choice.
	 * @return array
	 */
	public static function plan( array $product, array $terms, $attribute = '', $remove_stopgap = false, $include_simple = false ) {
		$rows    = array();
		$seen    = array();
		$blocked = false;
		foreach ( $product['entities'] as $entity ) {
			if ( ! $product['variable'] && ! in_array( strtolower( (string) ( $entity['meta']['_subscrpt_enabled'] ?? '' ) ), array( 'yes', '1', 'true', 'on' ), true ) ) {
				continue;
			}
			$detected = self::detect( $entity, $attribute );
			$row      = array_merge(
				array(
					'product_id'     => $product['id'],
					'variation_id'   => $product['variable'] ? $entity['id'] : 0,
					'entity_id'      => $entity['id'],
					'source'         => 'attribute',
					'note'           => '',
					'plan_id'        => 0,
					'trial'          => 0,
					'trial_interval' => 'days',
					'signup_fee'     => 0.0,
				),
				$detected
			);
			if ( ! $product['variable'] && ! $include_simple ) {
				$row['action'] = 'classic_simple';
				$row['note']   = 'Classic simple subscription product (possible DWL target); report only. Re-run with --include-simple to link.';
			}
			if ( 'detected' !== $row['action'] ) {
				$blocked = $blocked || 'manual_review' === $row['action'];
				$rows[]  = $row;
				continue;
			}
			$key = $row['frequency'] . ':' . $row['interval'];
			if ( isset( $seen[ $key ] ) && $product['variable'] ) {
				$row['action'] = 'conflict';
				$row['note']  .= ' Two variations have the same term; product skipped.';
				$blocked       = true;
			}
			$seen[ $key ] = true;
			foreach ( $terms as $term ) {
				if ( self::matching_term( $row, $term ) ) {
					$row['plan_id'] = (int) $term['id'];
					break;
				}
			}
			if ( ! $row['plan_id'] ) {
				foreach ( $terms as $term ) {
					if ( 'active' === ( $term['status'] ?? 'active' ) && (int) $term['billing_frequency'] === $row['frequency'] && (int) $term['billing_interval'] === $row['interval'] ) {
						$row['action'] = 'conflict';
						$row['note']  .= ' Existing cadence has different trial, signup fee or lifecycle terms; manual review required.';
						$blocked       = true;
					}
				}
			}
			$own = array_values(
				array_filter(
					$product['relations'],
					static function ( $relation ) use ( $row ) {
						return (int) $relation['vid'] === (int) $row['variation_id'];
					}
				)
			);
			if ( $own ) {
				$relation = $own[0];
				$source   = $product['variable'] ? 'variation' : 'product';
				if ( 1 !== count( $own ) || ! $row['plan_id'] || (int) $relation['plan_id'] !== $row['plan_id'] || ( $relation['relation_data']['price_source'] ?? '' ) !== $source || ! empty( $relation['exclude'] ) || 'active' !== ( $relation['relation_status'] ?? 'active' ) ) {
					$row['action'] = 'conflict';
					$row['note']  .= ' Existing mapping differs; no overwrite.';
					$blocked       = true;
				} elseif ( 'conflict' !== $row['action'] ) {
					$row['action'] = 'already_linked';
				}
			} elseif ( 'conflict' !== $row['action'] ) {
				$row['action'] = $row['plan_id'] ? 'would_link' : 'would_create_term';
			}
			$rows[] = $row;
		}
		// One-time/unrecognised rows with an existing relation must not silently subscribe.
		foreach ( $rows as &$row ) {
			if ( in_array( $row['action'], array( 'one_time', 'unrecognised', 'manual_review' ), true ) && $product['variable'] ) {
				foreach ( $product['relations'] as $relation ) {
					if ( (int) $relation['vid'] === (int) $row['variation_id'] ) {
						$row['action'] = 'conflict';
						$row['note']  .= ' Existing relation on skipped variation; product skipped.';
						$blocked       = true;
					}
				}
			}
		}
		unset( $row );
		if ( $blocked ) {
			foreach ( $rows as &$row ) {
				if ( in_array( $row['action'], array( 'would_link', 'would_create_term', 'already_linked' ), true ) ) {
					$row['action'] = 'conflict';
					$row['note']  .= ' Parent has an ambiguous or unsupported variation; product skipped.';
				}
			}
			unset( $row );
		}
		$stopgap = $product['variable'] ? array_values(
			array_filter(
				$product['relations'],
				static function ( $relation ) {
					return 0 === (int) $relation['vid'];
				}
			)
		) : array();
		$mapped  = array_filter(
			$rows,
			static function ( $row ) {
				return in_array( $row['action'], array( 'would_link', 'would_create_term', 'already_linked' ), true );
			}
		);
		return array(
			'product'        => $product,
			'rows'           => $rows,
			'blocked'        => $blocked,
			'mapped'         => ! empty( $mapped ),
			'stopgap'        => $stopgap,
			'remove_stopgap' => $remove_stopgap && ! $blocked && ! empty( $mapped ),
			'stopgap_note'   => $stopgap ? 'Product-level relations will be suppressed by variation term mode; retained unless explicitly removed.' : '',
		);
	}

	/**
	 * Match only terms with compatible financial and lifecycle semantics.
	 *
	 * @param array $row Detected cadence.
	 * @param array $term Existing term.
	 * @return bool
	 */
	public static function matching_term( array $row, array $term ) {
		return 'active' === ( $term['status'] ?? 'active' ) && (int) $term['billing_frequency'] === $row['frequency'] && (int) $term['billing_interval'] === $row['interval'] && (int) ( $term['free_trial'] ?? 0 ) === (int) ( $row['trial'] ?? 0 ) && (float) ( $term['signup_fee']['amount'] ?? 0 ) === (float) ( $row['signup_fee'] ?? 0 ) && ( empty( $row['trial'] ) || ( $term['data']['free_trial_interval'] ?? 'days' ) === $row['trial_interval'] ) && empty( $term['billing_length'] ) && empty( $term['prepaid'] ) && empty( $term['offer'] );
	}

	/**
	 * Detect legacy metadata and check agreement with frequency attributes.
	 *
	 * Payment limits require review; trial and signup fee use supported term columns:
	 * reusing a shared cadence term must not silently lose financial semantics.
	 *
	 * @param array  $entity Entity snapshot.
	 * @param string $attribute Attribute filter.
	 * @return array
	 */
	public static function detect( array $entity, $attribute = '' ) {
		$meta = $entity['meta'] ?? array();
		if ( in_array( strtolower( (string) ( $meta['_subscrpt_enabled'] ?? '' ) ), array( 'yes', '1', 'true', 'on' ), true ) && ! empty( $meta['_subscrpt_timing_per'] ) ) {
			$units     = array(
				'days'   => 1,
				'weeks'  => 2,
				'months' => 3,
				'years'  => 4,
			);
			$unit      = $meta['_subscrpt_timing_option'] ?? '';
			$frequency = (int) $meta['_subscrpt_timing_per'];
			if ( ! isset( $units[ $unit ] ) || ! preg_match( '/^[1-9][0-9]*$/', (string) $meta['_subscrpt_timing_per'] ) || $frequency > 365 ) {
				return array(
					'source' => 'legacy_meta',
					'action' => 'manual_review',
					'note'   => 'Invalid classic timing; not migrated.',
				);
			}
			$label = self::detect( array( 'attributes' => $entity['attributes'] ?? array() ), $attribute );
			if ( 'detected' === $label['action'] && ( $frequency !== $label['frequency'] || $units[ $unit ] !== $label['interval'] ) ) {
				return array(
					'source' => 'legacy_meta',
					'action' => 'manual_review',
					'note'   => 'Legacy timing ' . FrequencyParser::title( $frequency, $units[ $unit ] ) . ' disagrees with frequency attribute ' . FrequencyParser::title( $label['frequency'], $label['interval'] ) . '; not migrated.',
				);
			}
			if ( 'manual_review' === $label['action'] ) {
				return $label;
			}
			foreach ( array( '_subscrpt_limit', '_subscrpt_max_no_payment' ) as $key ) {
				if ( ! empty( $meta[ $key ] ) && 'unlimited' !== $meta[ $key ] ) {
					return array(
						'source' => 'legacy_meta',
						'action' => 'manual_review',
						'note'   => 'Payment limit needs manual review; not migrated.',
					);
				}
			}
			$trial          = (int) ( $meta['_subscrpt_trial_timing_per'] ?? 0 );
			$trial_interval = $meta['_subscrpt_trial_timing_option'] ?? 'days';
			$fee            = $meta['_subscrpt_signup_fee'] ?? '';
			if ( $trial < 0 || ( $trial && ! isset( $units[ $trial_interval ] ) ) || ( '' !== $fee && ( ! is_numeric( $fee ) || (float) $fee < 0 ) ) ) {
				return array(
					'source' => 'legacy_meta',
					'action' => 'manual_review',
					'note'   => 'Invalid trial or signup fee; not migrated.',
				);
			}
			return array(
				'source'         => 'legacy_meta',
				'action'         => 'detected',
				'frequency'      => $frequency,
				'interval'       => $units[ $unit ],
				'trial'          => $trial,
				'trial_interval' => $trial_interval,
				'signup_fee'     => (float) $fee,
			);
		}
		$detected = array();
		foreach ( $entity['attributes'] ?? array() as $name => $value ) {
			$name = str_replace( 'attribute_', '', strtolower( $name ) );
			if ( $attribute && str_replace( 'attribute_', '', strtolower( $attribute ) ) !== $name ) {
				continue;
			}
			$parsed = FrequencyParser::parse( $value );
			if ( ! $attribute && 'unrecognised' === $parsed['action'] && ! preg_match( '/frequency|delivery|subscription|billing|cadence/', $name ) ) {
				continue;
			}
			$detected[] = $parsed;
		}
		if ( count( $detected ) > 1 && count( array_unique( array_map( 'serialize', $detected ) ) ) > 1 ) {
			return array(
				'action' => 'manual_review',
				'note'   => 'Multiple frequency attributes disagree; not migrated.',
			);
		}
		return $detected ? $detected[0] : array(
			'action' => 'unrecognised',
			'note'   => 'Unrecognised — skipped.',
		);
	}
}

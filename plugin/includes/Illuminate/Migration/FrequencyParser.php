<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Conservative parsing of delivery frequency attribute values.
 *
 * @package SpringDevs\Subscription
 */

namespace SpringDevs\Subscription\Illuminate\Migration;

/** Pure parser; unrecognised input never guesses a billing period. */
class FrequencyParser {
	/**
	 * Parse a complete attribute value.
	 *
	 * @param string $value Attribute value.
	 * @return array
	 */
	public static function parse( $value ) {
		$value = strtolower( trim( str_replace( array( '-', '_', '–', '—' ), ' ', $value ) ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		if ( preg_match( '/^(one time(?: purchase)?|single purchase)$/', $value ) ) {
			return array( 'action' => 'one_time' );
		}
		$value   = trim( preg_replace( '/\s+subscription$/', '', $value ) );
		$aliases = array(
			'monthly'          => array( 1, 3 ),
			'bi monthly'       => array( 2, 3 ),
			'bimonthly'        => array( 2, 3 ),
			'quarterly'        => array( 3, 3 ),
			'weekly'           => array( 1, 2 ),
			'biweekly'         => array( 2, 2 ),
			'bi weekly'        => array( 2, 2 ),
			'every other week' => array( 2, 2 ),
			'annual'           => array( 1, 4 ),
			'annually'         => array( 1, 4 ),
			'yearly'           => array( 1, 4 ),
			'daily'            => array( 1, 1 ),
		);
		$note    = '';
		if ( isset( $aliases[ $value ] ) ) {
			list( $frequency, $interval ) = $aliases[ $value ];
			if ( in_array( $value, array( 'bi monthly', 'bimonthly' ), true ) ) {
				$note = 'Bi-monthly interpreted as every 2 months; confirm this ambiguous label.';
			}
		} elseif ( preg_match( '/^(?:every )?(?:(\d+) )?(day|week|month|year)s?$/', $value, $matches ) ) {
			$frequency = ! isset( $matches[1] ) || '' === $matches[1] ? 1 : (int) $matches[1];
			$units     = array(
				'day'   => 1,
				'week'  => 2,
				'month' => 3,
				'year'  => 4,
			);
			$interval  = $units[ $matches[2] ];
		} else {
			return array( 'action' => 'unrecognised' );
		}
		if ( $frequency < 1 || $frequency > 365 ) {
			return array( 'action' => 'unrecognised' );
		}
		return array(
			'action'    => 'detected',
			'frequency' => $frequency,
			'interval'  => $interval,
			'note'      => $note,
		);
	}

	/**
	 * Consistent term title.
	 *
	 * @param int $frequency Multiplier.
	 * @param int $interval Unit.
	 * @return string
	 */
	public static function title( $frequency, $interval ) {
		$units = array(
			1 => 'Day',
			2 => 'Week',
			3 => 'Month',
			4 => 'Year',
		);
		return 1 === (int) $frequency ? 'Every ' . $units[ $interval ] : 'Every ' . $frequency . ' ' . $units[ $interval ] . 's';
	}
}

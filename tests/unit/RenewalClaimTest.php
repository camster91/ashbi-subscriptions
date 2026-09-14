<?php

namespace SpringDevs\Subscription\Illuminate;

use PHPUnit\Framework\TestCase;

function get_post_meta( $subscription_id, $key, $single ) {
	return $GLOBALS['renewal_claim_meta'][ $subscription_id ][ $key ] ?? '';
}

function wp_generate_uuid4() {
	$GLOBALS['renewal_claim_uuid'] = ( $GLOBALS['renewal_claim_uuid'] ?? 0 ) + 1;
	return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['renewal_claim_uuid'] );
}

function current_time( $type, $gmt ) {
	return '2026-09-14 19:00:00';
}

function subscrpt_write_log( $message ) {
	$GLOBALS['renewal_claim_log'][] = $message;
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

final class RenewalClaimWpdbFake {
	public string $prefix = 'wp_';
	public array $rows = array();
	private array $args = array();

	public function prepare( $sql, $args ) {
		$this->args = $args;
		return $sql;
	}

	public function query( $sql ) {
		if ( false !== strpos( $sql, 'INSERT INTO' ) ) {
			list( $table, $subscription_id, $period_key, $period_anchor, $order_id, $state, $token, $lease, $created ) = $this->args;
			$key = $subscription_id . '|' . $period_key;
			if ( ! isset( $this->rows[ $key ] ) ) {
				$this->rows[ $key ] = (object) array(
					'period_anchor'   => $period_anchor,
					'order_id'        => $order_id,
					'state'           => $state,
					'claim_token'     => $token,
					'lease_expires_at' => $lease,
				);
				return 1;
			}

			$row = $this->rows[ $key ];
			if ( 0 === (int) $row->order_id && $row->lease_expires_at < '2026-09-14 19:00:00' ) {
				$row->state            = $state;
				$row->claim_token      = $token;
				$row->lease_expires_at = $lease;
				$row->order_id         = $order_id;
				return 2;
			}

			return 0;
		}

		if ( false !== strpos( $sql, 'UPDATE' ) ) {
			if ( false !== strpos( $sql, "payment_state = 'pending'" ) ) {
				list( $table, $next_retry, $error, $subscription_id, $order_id ) = $this->args;
				foreach ( $this->rows as $row ) {
					if ( $subscription_id === (int) $row->subscription_id && $order_id === (int) $row->order_id && 'ready' === $row->state ) {
						$row->payment_state        = 'pending';
						$row->payment_attempts    += 1;
						$row->payment_next_attempt = $next_retry;
						$row->payment_last_error   = $error;
						return 1;
					}
				}
				return 0;
			}

			if ( false !== strpos( $sql, "payment_state = 'complete'" ) ) {
				list( $table, $subscription_id, $order_id ) = $this->args;
				foreach ( $this->rows as $row ) {
					if ( $subscription_id === (int) $row->subscription_id && $order_id === (int) $row->order_id && 'ready' === $row->state ) {
						$row->payment_state        = 'complete';
						$row->payment_next_attempt = null;
						$row->payment_last_error   = '';
						return 1;
					}
				}
				return 0;
			}

			if ( false !== strpos( $sql, "payment_state = 'failed'" ) ) {
				list( $table, $error, $subscription_id, $order_id ) = $this->args;
				foreach ( $this->rows as $row ) {
					if ( $subscription_id === (int) $row->subscription_id && $order_id === (int) $row->order_id && 'ready' === $row->state ) {
						$row->payment_state        = 'failed';
						$row->payment_next_attempt = null;
						$row->payment_last_error   = $error;
						return 1;
					}
				}
				return 0;
			}

			if ( false !== strpos( $sql, "schedule_state = 'pending'" ) ) {
				list( $table, $next_retry, $error, $subscription_id, $order_id ) = $this->args;
				foreach ( $this->rows as $row ) {
					if ( $subscription_id === (int) $row->subscription_id && $order_id === (int) $row->order_id && 'ready' === $row->state ) {
						$row->schedule_state        = 'pending';
						$row->schedule_attempts    += 1;
						$row->schedule_next_attempt = $next_retry;
						$row->schedule_last_error   = $error;
						return 1;
					}
				}
				return 0;
			}

			if ( false !== strpos( $sql, "schedule_state = 'scheduled'" ) ) {
				list( $table, $subscription_id, $order_id ) = $this->args;
				foreach ( $this->rows as $row ) {
					if ( $subscription_id === (int) $row->subscription_id && $order_id === (int) $row->order_id && 'ready' === $row->state ) {
						$row->schedule_state      = 'scheduled';
						$row->schedule_last_error = '';
						return 1;
					}
				}
				return 0;
			}

			if ( false !== strpos( $sql, "schedule_state = 'complete'" ) ) {
				list( $table, $subscription_id, $order_id ) = $this->args;
				foreach ( $this->rows as $row ) {
					if ( $subscription_id === (int) $row->subscription_id && $order_id === (int) $row->order_id && 'ready' === $row->state ) {
						$row->schedule_state        = 'complete';
						$row->schedule_next_attempt = null;
						$row->schedule_last_error   = '';
						return 1;
					}
				}
				return 0;
			}

			list( $table, $order_id, $subscription_id, $period_key, $token ) = $this->args;
			$key = $subscription_id . '|' . $period_key;
			if ( isset( $this->rows[ $key ] ) && 0 === (int) $this->rows[ $key ]->order_id && $token === $this->rows[ $key ]->claim_token ) {
				$this->rows[ $key ]->order_id = $order_id;
				$this->rows[ $key ]->state    = 'ready';
				return 1;
			}

			return 0;
		}

		if ( false !== strpos( $sql, 'DELETE FROM' ) ) {
			list( $table, $subscription_id, $period_key, $token ) = $this->args;
			$key = $subscription_id . '|' . $period_key;
			if ( isset( $this->rows[ $key ] ) && 0 === (int) $this->rows[ $key ]->order_id && $token === $this->rows[ $key ]->claim_token ) {
				unset( $this->rows[ $key ] );
				return 1;
			}
		}

		return 0;
	}

	public function get_row( $sql ) {
		list( $table, $subscription_id, $period_key ) = $this->args;
		return $this->rows[ $subscription_id . '|' . $period_key ] ?? null;
	}

	public function get_var( $sql ) {
		list( $table, $subscription_id, $order_id ) = $this->args;
		foreach ( $this->rows as $key => $row ) {
			if ( 0 === strpos( $key, $subscription_id . '|' ) && $order_id === (int) $row->order_id && 'ready' === $row->state ) {
				if ( false !== strpos( $sql, 'SELECT schedule_attempts' ) ) {
					return (string) $row->schedule_attempts;
				}
				if ( false !== strpos( $sql, 'SELECT payment_attempts' ) ) {
					return (string) $row->payment_attempts;
				}
				if ( false !== strpos( $sql, 'SELECT payment_state' ) ) {
					return (string) $row->payment_state;
				}
				if ( false !== strpos( $sql, 'SELECT schedule_state' ) ) {
					return (string) $row->schedule_state;
				}
				return false !== strpos( $sql, 'SELECT period_anchor' ) ? (string) $row->period_anchor : '1';
			}
		}

		return '0';
	}
}

final class RenewalClaimTest extends TestCase {
	private RenewalClaimWpdbFake $database;

	protected function setUp(): void {
		$this->database = new RenewalClaimWpdbFake();
		$GLOBALS['wpdb'] = $this->database;
		$GLOBALS['renewal_claim_uuid'] = 0;
		$GLOBALS['renewal_claim_meta'] = array(
			42 => array(
				'_subscrpt_next_date' => 1789412400,
			),
		);

		require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/RenewalClaim.php';
	}

	public function test_only_one_worker_acquires_and_finalizes_a_period(): void {
		$first  = RenewalClaim::acquire( 42 );
		$second = RenewalClaim::acquire( 42 );

		$this->assertTrue( $first['acquired'] );
		$this->assertFalse( $second['acquired'] );
		$this->assertTrue( RenewalClaim::finalize( 42, $first['period_key'], $first['token'], 501 ) );
		$this->assertTrue( RenewalClaim::is_claimed_order( 42, 501 ) );
		$this->assertFalse( RenewalClaim::is_claimed_order( 42, 502 ) );

		$retry = RenewalClaim::acquire( 42 );
		$this->assertFalse( $retry['acquired'] );
		$this->assertSame( 501, $retry['order_id'] );
	}

	public function test_expired_unfinalized_lease_can_be_recovered_by_checkout(): void {
		$claim = RenewalClaim::acquire( 42 );
		$key   = '42|' . $claim['period_key'];
		$this->database->rows[ $key ]->lease_expires_at = '2026-09-14 18:00:00';

		$this->assertTrue( RenewalClaim::claim_order( 42, 601 ) );
		$this->assertSame( 601, $this->database->rows[ $key ]->order_id );
		$this->assertSame( 'ready', $this->database->rows[ $key ]->state );
		$this->assertTrue( RenewalClaim::is_claimed_order( 42, 601 ) );
		$this->assertFalse( RenewalClaim::claim_order( 42, 602 ) );
	}

	public function test_schedule_repair_state_is_durable_on_the_canonical_claim(): void {
		$claim = RenewalClaim::acquire( 42 );
		$this->assertTrue( RenewalClaim::finalize( 42, $claim['period_key'], $claim['token'], 701 ) );

		$key = '42|' . $claim['period_key'];
		$this->database->rows[ $key ]->subscription_id       = 42;
		$this->database->rows[ $key ]->schedule_state        = 'waiting';
		$this->database->rows[ $key ]->schedule_attempts     = 0;
		$this->database->rows[ $key ]->schedule_next_attempt = null;
		$this->database->rows[ $key ]->schedule_last_error   = '';

		$this->assertTrue( RenewalClaim::mark_schedule_pending( 42, 701, 'marker write failed' ) );
		$this->assertSame( 'pending', $this->database->rows[ $key ]->schedule_state );
		$this->assertSame( 1, $this->database->rows[ $key ]->schedule_attempts );
		$this->assertTrue( RenewalClaim::mark_schedule_complete( 42, 701 ) );
		$this->assertSame( 'scheduled', $this->database->rows[ $key ]->schedule_state );
		$this->assertTrue( RenewalClaim::mark_renewal_complete( 42, 701 ) );
		$this->assertSame( 'complete', $this->database->rows[ $key ]->schedule_state );
	}

	public function test_payment_dispatch_state_survives_a_stale_gateway_lock(): void {
		$claim = RenewalClaim::acquire( 42 );
		$this->assertTrue( RenewalClaim::finalize( 42, $claim['period_key'], $claim['token'], 702 ) );

		$key = '42|' . $claim['period_key'];
		$this->database->rows[ $key ]->subscription_id      = 42;
		$this->database->rows[ $key ]->payment_state        = 'waiting';
		$this->database->rows[ $key ]->payment_attempts     = 0;
		$this->database->rows[ $key ]->payment_next_attempt = null;
		$this->database->rows[ $key ]->payment_last_error   = '';

		$this->assertTrue( RenewalClaim::mark_payment_pending( 42, 702, 'gateway lock active' ) );
		$this->assertSame( 'pending', $this->database->rows[ $key ]->payment_state );
		$this->assertSame( 1, $this->database->rows[ $key ]->payment_attempts );
		$this->assertTrue( RenewalClaim::mark_payment_complete( 42, 702 ) );
		$this->assertSame( 'complete', $this->database->rows[ $key ]->payment_state );
	}

	public function test_deterministic_payment_failure_stops_automatic_retry(): void {
		$claim = RenewalClaim::acquire( 42 );
		$this->assertTrue( RenewalClaim::finalize( 42, $claim['period_key'], $claim['token'], 703 ) );

		$key = '42|' . $claim['period_key'];
		$this->database->rows[ $key ]->subscription_id      = 42;
		$this->database->rows[ $key ]->payment_state        = 'pending';
		$this->database->rows[ $key ]->payment_attempts     = 1;
		$this->database->rows[ $key ]->payment_next_attempt = '2026-09-14 20:00:00';
		$this->database->rows[ $key ]->payment_last_error   = '';

		$this->assertTrue( RenewalClaim::mark_payment_failed( 42, 703, 'authentication required' ) );
		$this->assertSame( 'failed', $this->database->rows[ $key ]->payment_state );
		$this->assertNull( $this->database->rows[ $key ]->payment_next_attempt );
	}

	public function test_captured_period_anchor_does_not_change_when_subscription_date_advances(): void {
		$anchor = 1789412400;
		$first  = RenewalClaim::acquire( 42, 0, $anchor );
		$GLOBALS['renewal_claim_meta'][42]['_subscrpt_next_date'] = 1792004400;
		$second = RenewalClaim::acquire( 42, 0, $anchor );

		$this->assertSame( $first['period_key'], $second['period_key'] );
		$this->assertFalse( $second['acquired'] );
	}
}

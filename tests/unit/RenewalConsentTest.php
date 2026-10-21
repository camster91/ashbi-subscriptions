<?php
/**
 * Customer consent remains authoritative through refresh and Stripe preparation.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Real-source fixture with local metadata and provider-dispatch doubles. */
final class RenewalConsentTest extends TestCase {
	/** An already-prepared saved token cannot override a later customer opt-out. */
	public function test_deferred_dispatch_rechecks_consent_after_preparation(): void {
		$path   = dirname( __DIR__ ) . '/fixtures/renewal-consent-deferred.php';
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		self::assertSame(
			array(
				'prepared'            => true,
				'dispatches'          => 0,
				'provider_boundaries' => 0,
				'denied'              => true,
				'raw'                 => 0,
			),
			json_decode( $output['stdout'], true ),
			$output['stdout']
		);
	}


	/** The real explicit enable/disable actions retain their nonce and ownership gates. */
	public function test_account_actions_require_nonce_and_owner_or_admin(): void {
		$path  = dirname( __DIR__ ) . '/fixtures/renewal-consent-actions.php';
		$cases = array(
			array(
				'action'   => 'renew-on',
				'initial'  => 0,
				'user'     => 7,
				'admin'    => false,
				'nonce'    => 'fixture-valid',
				'expected' => true,
				'writes'   => 1,
			),
			array(
				'action'   => 'renew-off',
				'initial'  => 1,
				'user'     => 7,
				'admin'    => false,
				'nonce'    => 'fixture-valid',
				'expected' => false,
				'writes'   => 1,
			),
			array(
				'action'   => 'renew-on',
				'initial'  => 0,
				'user'     => 7,
				'admin'    => false,
				'nonce'    => 'invalid',
				'expected' => false,
				'writes'   => 0,
			),
			array(
				'action'   => 'renew-on',
				'initial'  => 0,
				'user'     => 8,
				'admin'    => false,
				'nonce'    => 'fixture-valid',
				'expected' => false,
				'writes'   => 0,
			),
			array(
				'action'   => 'renew-on',
				'initial'  => 0,
				'user'     => 0,
				'admin'    => false,
				'nonce'    => 'fixture-valid',
				'expected' => false,
				'writes'   => 0,
			),
			array(
				'action'   => 'renew-on',
				'initial'  => 0,
				'user'     => 8,
				'admin'    => true,
				'nonce'    => 'fixture-valid',
				'expected' => true,
				'writes'   => 1,
			),
		);
		foreach ( $cases as $case ) {
			$output = AbstractPhpProcess::factory()->runJob( '<?php $GLOBALS["action_case"] = ' . var_export( $case, true ) . '; require ' . var_export( $path, true ) . ';' );
			self::assertSame( '', $output['stderr'] );
			$data = json_decode( $output['stdout'], true );
			self::assertIsArray( $data, $output['stdout'] );
			self::assertSame( $case['expected'], $data['consent'] );
			self::assertSame( $case['writes'], $data['writes'] );
		}
	}

	/** Explicit choices are not empty defaults; only missing metadata inherits. */
	public function test_consent_survives_refresh_metadata_cloning_and_dispatch(): void {
		$path   = dirname( __DIR__ ) . '/fixtures/renewal-consent.php';
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertIsArray( $data, $output['stdout'] );
		foreach ( $data as $mode => $stripe_cases ) {
			foreach ( $stripe_cases as $stripe_mode => $cases ) {
				foreach ( $cases as $name => $actual ) {
					$label   = $mode . '/' . $stripe_mode . '/' . $name;
					$consent = 'missing' === $name ? 'auto' === $mode : in_array( $name, array( 'one', 'string_one', 'true', 'string_true', 'yes' ), true );
					$charge  = $consent && 'auto' === $mode && 1 === (int) $stripe_mode;
					self::assertSame( $consent, $actual['display'], $label );
					self::assertSame( $consent, $actual['refreshed'], $label );
					self::assertTrue( $actual['preserved'], $label );
					self::assertSame( 0, $actual['writes'], $label );
					self::assertSame( $charge, $actual['cloned'], $label );
					self::assertSame( $charge ? 1 : 0, $actual['dispatches'], $label );
					self::assertSame( $charge ? 2 : 0, $actual['provider_boundaries'], $label );
					self::assertSame( $charge ? 1 : 0, $actual['provider_lookups'], $label );
					self::assertSame( $charge, $actual['bancontact_meta'], $label );
					self::assertTrue( $actual['resumed'], $label );
					self::assertSame( $charge ? 1 : 0, $actual['resume_dispatches'], $label );
					self::assertTrue( $actual['consent_preserved_after_resume'], $label );
				}
			}
		}
	}
}

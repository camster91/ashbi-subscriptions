<?php
/**
 * Tests for fail-closed staging rehearsal preflight logic.
 *
 * @package AshbiSubscriptions
 */

use Ashbi\Subscriptions\Tools\StagingPreflight;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/tools/lib/StagingPreflight.php';

/**
 * Verify staging isolation controls and gateway classification.
 */
final class StagingPreflightTest extends TestCase {
	/**
	 * Build a fully isolated staging state fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function safe_state(): array {
		return array(
			'expected_site_url'                => 'https://staging.example.com',
			'site_url'                         => 'https://staging.example.com/',
			'home_url'                         => 'https://staging.example.com/shop',
			'environment_type'                 => 'staging',
			'wp_cron_disabled'                 => true,
			'action_scheduler_runner_disabled' => true,
			'active_webhook_count'             => 0,
			'gateway_discovery_complete'       => true,
			'expected_gateway_ids'             => array( 'stripe', 'wp_subscription_paypal' ),
			'gateways'                         => array(
				'stripe'                 => array(
					'enabled' => true,
					'mode'    => 'sandbox',
				),
				'wp_subscription_paypal' => array(
					'enabled' => true,
					'mode'    => 'sandbox',
				),
			),
			'attestations'                     => array(
				'host_cron_disabled'            => true,
				'outbound_email_disabled'       => true,
				'production_callbacks_disabled' => true,
			),
		);
	}

	/** Verify a fully isolated staging state passes. */
	public function test_isolated_staging_state_passes(): void {
		$report = StagingPreflight::evaluate( $this->safe_state() );
		$this->assertTrue( $report['passed'] );
		$this->assertNotContains( false, array_column( $report['checks'], 'passed' ), true );
	}

	/** Verify production URLs and active workers fail closed. */
	public function test_production_urls_environment_and_workers_fail_closed(): void {
		$state                                     = $this->safe_state();
		$state['site_url']                         = 'https://production.example.com';
		$state['home_url']                         = 'https://production.example.com';
		$state['environment_type']                 = 'production';
		$state['wp_cron_disabled']                 = false;
		$state['action_scheduler_runner_disabled'] = false;

		$report = StagingPreflight::evaluate( $state );
		$this->assertFalse( $report['passed'] );
		$this->assertSame( 5, count( array_filter( $report['checks'], static fn( array $check ): bool => ! $check['passed'] ) ) );
	}

	/** Verify live gateways, active webhooks, and missing attestations fail. */
	public function test_live_gateway_active_webhook_and_missing_attestations_fail(): void {
		$state                                       = $this->safe_state();
		$state['active_webhook_count']               = 2;
		$state['gateways']['stripe']['mode']         = 'live';
		$state['attestations']['host_cron_disabled'] = false;
		$state['attestations']['outbound_email_disabled']       = false;
		$state['attestations']['production_callbacks_disabled'] = false;

		$report = StagingPreflight::evaluate( $state );
		$this->assertFalse( $report['passed'] );
		$this->assertSame( 6, count( array_filter( $report['checks'], static fn( array $check ): bool => ! $check['passed'] ) ) );
	}

	/** Verify unknown enabled gateways fail while offline gateways pass. */
	public function test_unknown_enabled_gateway_mode_fails_but_offline_passes(): void {
		$state                       = $this->safe_state();
		$state['gateways']['manual'] = array(
			'enabled' => true,
			'mode'    => 'unknown',
		);
		$this->assertFalse( StagingPreflight::evaluate( $state )['passed'] );

		$state['gateways']['manual']['mode'] = 'offline';
		$this->assertTrue( StagingPreflight::evaluate( $state )['passed'] );
	}

	/** Verify gateway inventory omits settings and retains safe classifications. */
	public function test_gateway_inventory_reduces_registered_gateway_settings(): void {
		$inventory = StagingPreflight::gateway_inventory(
			array(
				'stripe'      => array(
					'enabled'    => 'yes',
					'testmode'   => 'yes',
					'secret_key' => 'must-not-escape',
				),
				'cod'         => array( 'enabled' => 'yes' ),
				'third_party' => array(
					'enabled'     => 'yes',
					'environment' => 'production',
				),
				'disabled'    => array(
					'enabled' => 'no',
					'mode'    => 'live',
				),
			)
		);

		$this->assertSame( array( 'cod', 'disabled', 'stripe', 'third_party' ), array_keys( $inventory ) );
		$this->assertSame(
			array(
				'enabled' => true,
				'mode'    => 'sandbox',
			),
			$inventory['stripe']
		);
		$this->assertSame(
			array(
				'enabled' => true,
				'mode'    => 'offline',
			),
			$inventory['cod']
		);
		$this->assertSame(
			array(
				'enabled' => true,
				'mode'    => 'live',
			),
			$inventory['third_party']
		);
		$this->assertSame(
			array(
				'enabled' => false,
				'mode'    => 'disabled',
			),
			$inventory['disabled']
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- PHPUnit runs without WordPress bootstrap; this is a local assertion-only serialization.
		$this->assertStringNotContainsString( 'must-not-escape', json_encode( $inventory ) );
	}

	/** Verify enabled gateways without a recognized mode fail closed. */
	public function test_enabled_gateway_without_recognized_mode_fails_closed(): void {
		$this->assertSame(
			array(
				'enabled' => true,
				'mode'    => 'unknown',
			),
			StagingPreflight::classify_gateway( 'custom_gateway', array( 'enabled' => 'yes' ) )
		);
		$this->assertSame(
			array(
				'enabled' => false,
				'mode'    => 'disabled',
			),
			StagingPreflight::classify_gateway(
				'custom_gateway',
				array(
					'enabled' => 'no',
					'mode'    => 'live',
				)
			)
		);
	}

	/** Verify an unknown webhook state fails closed. */
	public function test_unknown_webhook_state_fails_closed(): void {
		$state                         = $this->safe_state();
		$state['active_webhook_count'] = null;
		$this->assertFalse( StagingPreflight::evaluate( $state )['passed'] );
	}

	/** Verify an expected sandbox gateway cannot be silently omitted or disabled. */
	public function test_expected_gateway_matrix_fails_closed_when_a_gateway_is_missing_or_disabled(): void {
		$state = $this->safe_state();
		unset( $state['gateways']['wp_subscription_paypal'] );
		$this->assertFalse( StagingPreflight::evaluate( $state )['passed'] );

		$state                                  = $this->safe_state();
		$state['gateways']['stripe']['enabled'] = false;
		$this->assertFalse( StagingPreflight::evaluate( $state )['passed'] );
	}

	/** Verify incomplete or malformed gateway discovery fails closed. */
	public function test_unknown_or_malformed_gateway_discovery_fails_closed(): void {
		$state                               = $this->safe_state();
		$state['gateway_discovery_complete'] = false;
		$state['gateways']                   = array();
		$this->assertFalse( StagingPreflight::evaluate( $state )['passed'] );

		$state['gateway_discovery_complete'] = true;
		$state['gateways']                   = null;
		$this->assertFalse( StagingPreflight::evaluate( $state )['passed'] );
	}

	/** Verify unknown gateway enablement and mode values fail closed. */
	public function test_unknown_gateway_enablement_and_mode_values_fail_closed(): void {
		$this->assertSame(
			array(
				'enabled' => null,
				'mode'    => 'unknown',
			),
			StagingPreflight::classify_gateway( 'custom_gateway', array() )
		);
		$this->assertSame(
			array(
				'enabled' => true,
				'mode'    => 'unknown',
			),
			StagingPreflight::classify_gateway(
				'custom_gateway',
				array(
					'enabled'  => 'yes',
					'testmode' => 'unexpected',
				)
			)
		);

		$state                               = $this->safe_state();
		$state['gateways']['custom_gateway'] = array(
			'enabled' => null,
			'mode'    => 'unknown',
		);
		$this->assertFalse( StagingPreflight::evaluate( $state )['passed'] );
	}

	/** Verify missing required state is rejected. */
	public function test_missing_required_state_is_rejected(): void {
		$state = $this->safe_state();
		unset( $state['attestations'] );
		$this->expectException( InvalidArgumentException::class );
		StagingPreflight::evaluate( $state );
	}
}

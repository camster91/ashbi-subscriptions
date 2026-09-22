<?php
// phpcs:ignoreFile WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit tests read local source fixtures; no remote URLs are accessed.
/**
 * Smoke tests for repository tooling and disposable safety checks.
 *
 * @package AshbiSubscriptions
 */

use PHPUnit\Framework\TestCase;

/** Verify the repository's supporting tooling remains available. */
final class ToolingSmokeTest extends TestCase {
	/** Verify the unit-test environment is bootstrapped. */
	public function test_test_environment_is_bootstrapped(): void {
		$this->assertTrue( defined( 'PHP_VERSION' ) );
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/plugin/subscription.php' );
	}

	/** Verify the no-charge disposition tool cannot dispatch payment work. */
	public function test_no_charge_apply_tool_has_no_gateway_or_order_dispatch_calls(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/apply-overdue-dispositions.php' );
		$this->assertIsString( $tool );
		$this->assertStringNotContainsString( 'create_renewal_order(', $tool );
		$this->assertStringNotContainsString( 'process_payment(', $tool );
		$this->assertStringNotContainsString( 'wc_create_order(', $tool );
		$this->assertStringNotContainsString( 'wp_update_post(', $tool );
		$this->assertStringContainsString( "'apply-no-charge'", $tool );
		$this->assertStringContainsString( 'ASHBI_DISPOSITION_CONFIRM', $tool );
		$this->assertStringContainsString( 'array_diff( $active, $claim, $overdue )', $tool );
		$this->assertStringContainsString( 'array_diff( $advanced_ids, $preexisting_operator, $unowned )', $tool );
	}

	/** Verify fingerprint reports do not emit protected source rows or keys. */
	public function test_fingerprint_report_never_emits_source_rows_or_key(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/site-state-fingerprint.php' );
		$this->assertIsString( $tool );
		$this->assertStringContainsString( 'SiteFingerprint::digest_rows', $tool );
		$this->assertStringContainsString( 'SiteFingerprint::seal_report', $tool );
		$this->assertStringContainsString( "unset( \$stable_order['data']['date_modified'], \$stable_order['data']['version'], \$stable_order['data']['meta_data'] );", $tool );
		$this->assertStringContainsString( "'_subscrpt_renewal_quarantined'", $tool );
		$this->assertStringNotContainsString( "'rows' =>", $tool );
		$this->assertStringNotContainsString( "'key' =>", $tool );
	}

	/** Verify the staging preflight is read-only and secret-minimal. */
	public function test_staging_preflight_is_read_only_and_omits_secret_settings(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/staging-preflight.php' );
		$this->assertIsString( $tool );
		$this->assertStringContainsString( 'StagingPreflight::evaluate', $tool );
		$this->assertStringContainsString( 'StagingPreflight::gateway_inventory', $tool );
		$this->assertStringContainsString( 'ASHBI_EXPECTED_GATEWAYS', $tool );
		$this->assertStringContainsString( 'payment_gateways()->payment_gateways()', $tool );
		$this->assertStringContainsString( 'wc_webhooks', $tool );
		$this->assertStringContainsString( '$wpdb->get_var', $tool );
		$this->assertStringNotContainsString( 'update_option(', $tool );
		$this->assertStringNotContainsString( 'maybe_unserialize(', $tool );
		$this->assertStringNotContainsString( 'get_results(', $tool );
		$this->assertStringNotContainsString( 'client_secret', $tool );
		$this->assertStringNotContainsString( 'webhook_url', $tool );
	}

	/** Verify release tooling records reproducible archive evidence. */
	public function test_release_build_has_reproducible_evidence_and_manifest_checks(): void {
		$build    = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/build-release.sh' );
		$evidence = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/build-release-evidence.sh' );
		$verify   = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/verify-release-evidence.sh' );
		$package  = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/test-release-package.sh' );

		$this->assertIsString( $build );
		$this->assertIsString( $evidence );
		$this->assertIsString( $verify );
		$this->assertIsString( $package );
		$this->assertStringContainsString( 'SOURCE_DATE_EPOCH', $build );
		$this->assertStringContainsString( 'sort | zip -q -X', $build );
		$this->assertStringContainsString( 'archive_sha256', $evidence );
		$this->assertStringContainsString( 'source_commit', $evidence );
		$this->assertStringContainsString( 'dirty_tree', $evidence );
		$this->assertStringContainsString( 'dirty_patch_sha256', $evidence );
		$this->assertStringContainsString( 'dirty_status_sha256', $evidence );
		$this->assertStringContainsString( 'source_date_epoch', $evidence );
		$this->assertStringContainsString( 'build_script_sha256', $evidence );
		$this->assertStringContainsString( 'members_sha256', $evidence );
		$this->assertStringContainsString( 'members_sha256', $verify );
		$this->assertStringContainsString( 'verify-release-evidence.sh', $package );
		$this->assertStringContainsString( 'build-release-evidence.sh', $package );
	}

	/** Verify the disposable integration covers diagnostics and lifecycle safety. */
	public function test_disposable_integration_exercises_the_diagnostics_route(): void {
		$integration = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/tests/integration/security-boundaries.php' );

		$this->assertIsString( $integration );
		$this->assertStringContainsString( "'/wpsubscription/v1/diagnostics'", $integration );
		$this->assertStringContainsString( 'rest_do_request', $integration );
		$this->assertStringContainsString( 'ASHBI_ALLOW_STAGING_INTEGRATION', $integration );
		$this->assertStringContainsString( 'status_counts', $integration );
		$this->assertStringContainsString( 'webhook_secret', $integration );
		$this->assertStringContainsString( "get_option( 'subscrpt_db_version'", $integration );
		$this->assertStringContainsString( 'Helper::pause_subscription', $integration );
		$this->assertStringContainsString( 'Helper::resume_subscription', $integration );
		$this->assertStringContainsString( "Action::status( 'pe_cancelled'", $integration );
		$this->assertStringContainsString( "'_subscrpt_cancel_at'", $integration );
		$this->assertStringContainsString( 'Helper::create_early_renewal_order', $integration );
		$this->assertStringContainsString( 'Duplicate early renewal created a second pending order.', $integration );
		$this->assertStringContainsString( 'resolve_checkout_renewal_subscription', $integration );
		$this->assertStringContainsString( 'Manual renewal retry created a duplicate renewal relation.', $integration );
		$this->assertStringContainsString( 'filter_renewal_product_args', $integration );
		$this->assertStringContainsString( 'Grace-period end was not scheduled', $integration );
		$this->assertStringContainsString( 'Paid switch did not update the subscription product snapshot.', $integration );
		$this->assertStringContainsString( 'Repeated switch callbacks created duplicate relation rows.', $integration );
		$this->assertStringContainsString( 'Recurring coupon classification omitted the synthetic renewal discount.', $integration );
		$this->assertStringContainsString( '$wpdb->prefix . \'woocommerce_payment_tokens\'', $integration );
		$this->assertStringContainsString( "'type'       => 'CC'", $integration );
		$this->assertStringNotContainsString( "'type'       => 'eCheck'", $integration );
		$this->assertStringContainsString( "remove_filter( 'woocommerce_get_customer_payment_tokens'", $integration );
		$this->assertStringContainsString( "add_filter( 'woocommerce_get_customer_payment_tokens'", $integration );
		$this->assertStringContainsString( '$administrator_id = get_current_user_id();', $integration );
		$this->assertStringNotContainsString( 'wp_set_current_user( 1 );', $integration );
		$this->assertStringContainsString( '->deactivate()', $integration );
		$this->assertStringContainsString( "require_once SUBSCRPT_PATH . '/uninstall.php'", $integration );
		$this->assertStringContainsString( 'Default uninstall removed the subscription record.', $integration );
	}

	/** Verify the disposable runner waits for WooCommerce dependency activation. */
	public function test_disposable_runner_waits_for_woocommerce_dependency_activation(): void {
		$runner = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/run-integration.sh' );

		$this->assertIsString( $runner );
		$this->assertStringContainsString( 'dependencies_ready=false', $runner );
		$this->assertStringContainsString( '"plugin":"woocommerce', $runner );
		$this->assertStringContainsString( '"plugin":"woocommerce-gateway-stripe', $runner );
		$this->assertStringContainsString( 'Integration prerequisites did not become active before timeout.', $runner );
	}

	/** Verify the release runtime can mount the package separately from test fixtures. */
	public function test_release_runtime_uses_packaged_plugin_and_external_fixture_mounts(): void {
		$runner = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/test-release-runtime.sh' );
		$matrix = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/test-release-runtime-matrix.sh' );
		$start  = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/start-playground.sh' );
		$launch = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/launch-playground.mjs' );
		$loader = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/playground-mu-plugins/ashbi-integration-loader.php' );
		$stop   = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/stop-playground.sh' );

		$this->assertIsString( $runner );
		$this->assertIsString( $matrix );
		$this->assertIsString( $start );
		$this->assertIsString( $launch );
		$this->assertIsString( $loader );
		$this->assertIsString( $stop );
		$this->assertStringContainsString( 'ASHBI_PLAYGROUND_PLUGIN_DIR', $runner );
		$this->assertStringContainsString( 'ASHBI_PLAYGROUND_HPOS_MODE', $runner );
		$this->assertStringContainsString( 'ASHBI_PLAYGROUND_HPOS_MODE', $matrix );
		$this->assertStringContainsString( 'for hpos_mode in off on', $matrix );
		$this->assertStringContainsString( 'test-release-package.sh', $runner );
		$this->assertStringContainsString( 'ASHBI_PLAYGROUND_PLUGIN_DIR', $start );
		$this->assertStringContainsString( 'pluginDir', $launch );
		$this->assertStringContainsString( '/ashbi-integration/security-boundaries.php', $loader );
		$this->assertStringContainsString( 'plugin/tests/integration/security-boundaries.php', $loader );
		$this->assertStringContainsString( 'command -v wp-env', $stop );
		$this->assertStringContainsString( 'no stop action was taken', $stop );
	}
}

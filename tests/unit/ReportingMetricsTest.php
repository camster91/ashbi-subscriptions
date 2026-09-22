<?php
/**
 * Reporting metric contracts.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use SpringDevs\Subscription\Admin\Menu;
use SpringDevs\Subscription\Illuminate\Stats;

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Stats.php';
require_once dirname( __DIR__, 2 ) . '/plugin/includes/Admin/Menu.php';


/**
 * Reporting metrics and view contracts.
 *
 * @package AshbiSubscriptions\Tests
 */
final class ReportingMetricsTest extends TestCase {
	/** Test bounded percentage calculations. */
	public function test_percentage_metrics_are_safe_and_rounded(): void {
		$this->assertSame( 12.5, Stats::calculate_churn_rate( 1, 8 ) );
		$this->assertSame( 66.67, Stats::calculate_recovery_rate( 2, 3 ) );
		$this->assertSame( 75.0, Stats::calculate_retention_rate( 3, 4 ) );
		$this->assertSame( 0.0, Stats::calculate_churn_rate( 1, 0 ) );
		$this->assertSame( 0.0, Stats::calculate_recovery_rate( 0, 0 ) );
	}

	/** Test invalid counts do not create impossible rates. */
	public function test_percentage_metrics_clamp_invalid_counts_without_inventing_rate(): void {
		$this->assertSame( 0.0, Stats::calculate_churn_rate( -1, 10 ) );
		$this->assertSame( 100.0, Stats::calculate_recovery_rate( 5, 3 ) );
		$this->assertSame( 0.0, Stats::calculate_retention_rate( 5, 0 ) );
	}

	/** Test live reporting methods and view wiring remain present. */
	public function test_reporting_exposes_live_churn_recovery_risk_and_cohort_queries(): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repository source contract.
		$stats = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Stats.php' );
		$view  = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/reports.php' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertIsString( $stats );
		$this->assertIsString( $view );
		$this->assertStringContainsString( 'count_churned_since', $stats );
		$this->assertStringContainsString( 'calculate_revenue_at_risk', $stats );
		$this->assertStringContainsString( 'count_recovered_renewals_since', $stats );
		$this->assertStringContainsString( 'get_cancellation_reason_counts', $stats );
		$this->assertStringContainsString( 'get_cohort_summary', $stats );
		$this->assertStringContainsString( 'get_recovery_campaign_counts', $stats );
		$this->assertStringContainsString( 'get_cancellation_reason_counts', $view );
		$this->assertStringContainsString( 'get_cohort_summary', $view );
		$this->assertStringContainsString( 'get_recovery_campaign_counts', $view );
		$this->assertStringContainsString( 'subscrpt_export_reports', $view );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixture.
		$this->assertStringContainsString( 'export_reports', file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/Menu.php' ) );
		$this->assertStringNotContainsString( 'sample data', strtolower( $view ) );
	}

	/** Test the durable retention campaign contract. */
	public function test_recovery_events_are_additive_idempotent_and_attributed_to_orders(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixtures.
		$installer = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Installer.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixture.
		$cancellation = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Cancellation.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixture.
		$flow = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Admin/CancellationFlow.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source contract fixture.
		$order = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/includes/Illuminate/Order.php' );

		$this->assertIsString( $installer );
		$this->assertIsString( $cancellation );
		$this->assertIsString( $flow );
		$this->assertIsString( $order );
		$this->assertStringContainsString( 'create_recovery_events_table', $installer );
		$this->assertStringContainsString( 'INSERT IGNORE', $cancellation );
		$this->assertStringContainsString( 'cancellation-retention', $cancellation );
		$this->assertStringContainsString( 'purge_recovery_events', $cancellation );
		$this->assertStringContainsString( 'subscrpt_recovery_event_retention_days', $flow );
		$this->assertStringContainsString( 'win_back', $order );
	}

	/** Test report exports neutralize spreadsheet formula-like strings. */
	public function test_report_csv_cells_neutralize_formula_like_strings(): void {
		$method = new ReflectionMethod( Menu::class, 'csv_cell' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame( "'=SUM(A1:A2)", $method->invoke( null, '=SUM(A1:A2)' ) );
		$this->assertSame( "'+IMPORTXML(A1,\"//a\")", $method->invoke( null, '+IMPORTXML(A1,"//a")' ) );
		$this->assertSame( "'@malicious", $method->invoke( null, '@malicious' ) );
		$this->assertSame( 'ordinary label', $method->invoke( null, 'ordinary label' ) );
		$this->assertSame( -1, $method->invoke( null, -1 ) );
	}
}

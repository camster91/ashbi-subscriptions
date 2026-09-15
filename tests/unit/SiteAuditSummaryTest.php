<?php

use PHPUnit\Framework\TestCase;

final class SiteAuditSummaryTest extends TestCase {
	/**
	 * @param array $payload Audit payload.
	 * @return array{exit:int,stdout:string,stderr:string}
	 */
	private function runSummary( array $payload ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/tools/summarize-site-audit.php' );
		$pipes   = array();
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		fwrite( $pipes[0], json_encode( $payload ) );
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'exit'   => proc_close( $process ),
			'stdout' => (string) $stdout,
			'stderr' => (string) $stderr,
		);
	}

	public function test_reducer_drops_unexpected_nested_fields_and_aggregates_unknown_categories(): void {
		$result = $this->runSummary(
			array(
				'schema_version' => 2,
				'site' => array( 'url' => 'https://client.example' ),
				'subscriptions' => array(
					'overdue_active' => 3,
					'overdue_reconciliation' => array(
						'total' => 3,
						'due_age_buckets' => array( '8_30_days' => '2', 'customer_123' => 99 ),
						'auto_renew' => array( 'enabled' => 2, 'email@example.test' => 1 ),
						'payment_failure_history' => array( 'recorded' => 1 ),
						'latest_paid_gateway' => array( 'stripe' => 2, 'secret_custom_gateway' => 1 ),
						'last_paid_age_buckets' => array( '31_90_days' => 3 ),
						'open_renewal_order_statuses' => array( 'pending' => 1, 'order_987' => 2 ),
						'raw_records' => array( array( 'customer_email' => 'email@example.test' ) ),
					),
				),
			)
		);

		self::assertSame( 0, $result['exit'], $result['stderr'] );
		self::assertStringNotContainsString( 'customer_123', $result['stdout'] );
		self::assertStringNotContainsString( 'email@example.test', $result['stdout'] );
		self::assertStringNotContainsString( 'secret_custom_gateway', $result['stdout'] );
		self::assertStringNotContainsString( 'order_987', $result['stdout'] );
		self::assertStringNotContainsString( 'raw_records', $result['stdout'] );
		$decoded = json_decode( $result['stdout'], true );
		self::assertSame( 1, $decoded['overdue_reconciliation']['latest_paid_gateway']['other'] );
		self::assertSame( 2, $decoded['overdue_reconciliation']['open_renewal_order_statuses']['other'] );
	}

	public function test_reducer_rejects_unknown_schema_versions(): void {
		$result = $this->runSummary( array( 'schema_version' => 3 ) );

		self::assertSame( 1, $result['exit'] );
		self::assertStringContainsString( 'expected version 2', $result['stderr'] );
		self::assertSame( '', $result['stdout'] );
	}
}

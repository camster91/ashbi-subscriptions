<?php

use PHPUnit\Framework\TestCase;

final class ToolingSmokeTest extends TestCase {
	public function test_test_environment_is_bootstrapped(): void {
		$this->assertTrue( defined( 'PHP_VERSION' ) );
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/plugin/subscription.php' );
	}
}

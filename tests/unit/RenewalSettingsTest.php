<?php
/**
 * Actual settings-registration renewal-mode regression.
 *
 * @package AshbiSubscriptions\Tests
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

/** Isolate WordPress doubles from the other canonical tests. */
final class RenewalSettingsTest extends TestCase {
	/** Saving either displayed mode must retain the exact mode, not a role. */
	public function test_registered_renewal_mode_callback_is_strict_and_roles_remain_safe(): void {
		$path   = dirname( __DIR__ ) . '/fixtures/renewal-settings.php';
		$output = AbstractPhpProcess::factory()->runJob( '<?php require ' . var_export( $path, true ) . ';' );
		self::assertSame( '', $output['stderr'] );
		$data = json_decode( $output['stdout'], true );
		self::assertSame(
			array(
				'auto'   => 'auto',
				'manual' => 'manual',
			),
			$data['saved']
		);
		self::assertSame( array( 'customer', 'subscriber', 'safe_fixture' ), $data['allowed_roles'] );
		self::assertSame( 'customer', $data['active_role'] );
		self::assertSame( array_fill( 0, 8, 'manual' ), $data['invalid'] );
		self::assertNotEmpty( $data['errors'] );
		self::assertSame(
			array(
				'missing'        => 'auto',
				'legacy_manual'  => 'manual',
				'legacy_auto'    => 'auto',
				'current_manual' => 'manual',
				'corrupt'        => 'manual',
				'empty'          => 'manual',
				'false'          => 'manual',
			),
			$data['resolved']
		);
		self::assertNotContains( false, $data['unchanged'] );
	}
}

<?php

use PHPUnit\Framework\TestCase;

final class SecurityBoundaryContractTest extends TestCase {
	private string $post;
	private string $ajax;
	private string $required;
	private string $installer_js;
	private string $guest_checkout;
	private string $action_controller;

	protected function setUp(): void {
		$root                    = dirname( __DIR__, 2 );
		$this->post              = (string) file_get_contents( $root . '/plugin/includes/Illuminate/Post.php' );
		$this->ajax              = (string) file_get_contents( $root . '/plugin/includes/Ajax.php' );
		$this->required          = (string) file_get_contents( $root . '/plugin/includes/Admin/Required.php' );
		$this->installer_js      = (string) file_get_contents( $root . '/plugin/assets/js/installer.js' );
		$this->guest_checkout    = (string) file_get_contents( $root . '/plugin/includes/Illuminate/GuestCheckout.php' );
		$this->action_controller = (string) file_get_contents( $root . '/plugin/includes/Frontend/ActionController.php' );
	}

	public function test_subscription_records_are_not_exposed_to_core_rest(): void {
		$this->assertSame( 2, substr_count( $this->post, "'show_in_rest'          => false" ) );
		$this->assertStringNotContainsString( 'WP_REST_Posts_Controller', $this->post );
	}

	public function test_dependency_installer_requires_capabilities_and_action_nonces(): void {
		$this->assertStringContainsString( "can_manage_dependency( 'install_plugins', 'subscrpt_install_woocommerce_plugin'", $this->ajax );
		$this->assertStringContainsString( "can_manage_dependency( 'activate_plugins', 'subscrpt_activate_woocommerce_plugin'", $this->ajax );
		$this->assertStringContainsString( 'current_user_can( $capability )', $this->ajax );
		$this->assertStringContainsString( 'wp_verify_nonce( $nonce, $nonce_action )', $this->ajax );
		$this->assertStringContainsString( "wp_create_nonce( 'subscrpt_install_woocommerce_plugin' )", $this->required );
		$this->assertStringContainsString( "wp_create_nonce( 'subscrpt_activate_woocommerce_plugin' )", $this->required );
		$this->assertStringContainsString( 'nonce: sdevs_installer_helper_obj.install_nonce', $this->installer_js );
		$this->assertStringContainsString( 'nonce: sdevs_installer_helper_obj.activate_nonce', $this->installer_js );
	}

	public function test_guest_email_alone_never_binds_an_existing_account(): void {
		$this->assertStringContainsString( '$user_id && ( ! is_user_logged_in()', $this->guest_checkout );
		$this->assertStringContainsString( 'get_current_user_id() !== (int) $user_id', $this->guest_checkout );
		$this->assertStringContainsString( 'return null;', $this->guest_checkout );
	}

	public function test_customer_reactivation_is_limited_to_unexpired_pending_cancellation(): void {
		$this->assertStringContainsString( "'pe_cancelled' !== get_post_status( \$subscription_id )", $this->action_controller );
		$this->assertStringContainsString( 'subscrpt_is_max_payments_reached( $subscription_id )', $this->action_controller );
		$this->assertStringContainsString( "get_post_meta( \$subscription_id, '_subscrpt_cancel_at', true )", $this->action_controller );
		$this->assertStringContainsString( "Action::status( 'active', \$subscrpt_id )", $this->action_controller );
	}
}

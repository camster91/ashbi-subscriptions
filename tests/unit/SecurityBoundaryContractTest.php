<?php
/**
 * Security boundary source-contract tests.
 *
 * @package AshbiSubscriptions
 */

use PHPUnit\Framework\TestCase;


/**
 * Verify that sensitive routes retain their authorization and privacy guards.
 */
final class SecurityBoundaryContractTest extends TestCase {
	/**
	 * Subscription post-type source.
	 *
	 * @var string
	 */
	private string $post;
	/**
	 * Dependency installer source.
	 *
	 * @var string
	 */
	private string $ajax;
	/**
	 * Admin required-fields source.
	 *
	 * @var string
	 */
	private string $required;
	/**
	 * Installer JavaScript source.
	 *
	 * @var string
	 */
	private string $installer_js;
	/**
	 * Guest checkout source.
	 *
	 * @var string
	 */
	private string $guest_checkout;
	/**
	 * Customer lifecycle controller source.
	 *
	 * @var string
	 */
	private string $action_controller;
	/**
	 * Customer account view source.
	 *
	 * @var string
	 */
	private string $my_account;
	/**
	 * Subscription role-management source.
	 *
	 * @var string
	 */
	private string $role_management;
	/**
	 * Subscription settings source.
	 *
	 * @var string
	 */
	private string $settings;
	/**
	 * Admin subscription action source.
	 *
	 * @var string
	 */
	private string $admin_menu;
	/**
	 * REST bootstrap source.
	 *
	 * @var string
	 */
	private string $api;
	/**
	 * Advanced settings source.
	 *
	 * @var string
	 */
	private string $pro_settings;
	/**
	 * Shared logging helpers source.
	 *
	 * @var string
	 */
	private string $functions;
	/**
	 * Subscription switching source.
	 *
	 * @var string
	 */
	private string $switching;

	/** Load the source files under test. */
	protected function setUp(): void {
		$root                    = dirname( __DIR__, 2 );
		$this->post              = (string) file_get_contents( $root . '/plugin/includes/Illuminate/Post.php' );
		$this->ajax              = (string) file_get_contents( $root . '/plugin/includes/Ajax.php' );
		$this->required          = (string) file_get_contents( $root . '/plugin/includes/Admin/Required.php' );
		$this->installer_js      = (string) file_get_contents( $root . '/plugin/assets/js/installer.js' );
		$this->guest_checkout    = (string) file_get_contents( $root . '/plugin/includes/Illuminate/GuestCheckout.php' );
		$this->action_controller = (string) file_get_contents( $root . '/plugin/includes/Frontend/ActionController.php' );
		$this->my_account        = (string) file_get_contents( $root . '/plugin/includes/Frontend/MyAccount.php' );
		$this->role_management   = (string) file_get_contents( $root . '/plugin/includes/Illuminate/RoleManagement.php' );
		$this->settings          = (string) file_get_contents( $root . '/plugin/includes/Admin/Settings.php' );
		$this->admin_menu        = (string) file_get_contents( $root . '/plugin/includes/Admin/Menu.php' );
		$this->api               = (string) file_get_contents( $root . '/plugin/includes/API.php' );
		$this->pro_settings      = (string) file_get_contents( $root . '/plugin/includes/Admin/ProSettingsFields.php' );
		$this->functions         = (string) file_get_contents( $root . '/plugin/includes/functions.php' );
		$this->switching         = (string) file_get_contents( $root . '/plugin/includes/Illuminate/Switching.php' );
	}

	/** Subscription records must remain hidden from core REST. */
	public function test_subscription_records_are_not_exposed_to_core_rest(): void {
		$this->assertSame( 2, preg_match_all( "/'show_in_rest'\\s*=>\\s*false/", $this->post ) );
		$this->assertStringNotContainsString( 'WP_REST_Posts_Controller', $this->post );
	}

	/** Dependency installation must require capabilities and action nonces. */
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

	/** A guest email alone must never bind an existing account. */
	public function test_guest_email_alone_never_binds_an_existing_account(): void {
		$this->assertStringContainsString( '$user_id && ( ! is_user_logged_in()', $this->guest_checkout );
		$this->assertStringContainsString( 'get_current_user_id() !== (int) $user_id', $this->guest_checkout );
		$this->assertStringContainsString( 'return null;', $this->guest_checkout );
	}

	/** Customer reactivation must require an unexpired pending cancellation. */
	public function test_customer_reactivation_is_limited_to_unexpired_pending_cancellation(): void {
		$this->assertStringContainsString( "'pe_cancelled' !== get_post_status( \$subscription_id )", $this->action_controller );
		$this->assertStringContainsString( 'subscrpt_is_max_payments_reached( $subscription_id )', $this->action_controller );
		$this->assertStringContainsString( "get_post_meta( \$subscription_id, '_subscrpt_cancel_at', true )", $this->action_controller );
		$this->assertStringContainsString( "Action::status( 'active', \$subscrpt_id )", $this->action_controller );
	}

	/** Logged-out visitors must never inherit ownership of guest-owned records. */
	public function test_customer_subscription_routes_require_an_authenticated_owner(): void {
		$this->assertStringContainsString( 'is_user_logged_in()', $this->action_controller );
		$this->assertStringContainsString( 'is_user_logged_in()', $this->my_account );
		$this->assertStringContainsString( '0 === (int) $author_id', $this->action_controller );
		$this->assertStringContainsString( '0 === (int) $author_id', $this->my_account );
	}

	/** Customer routes must never treat an arbitrary post ID as a subscription. */
	public function test_customer_subscription_routes_require_the_subscription_post_type(): void {
		$this->assertStringContainsString( "'subscrpt_order' !== \$subs_post->post_type", $this->action_controller );
		$this->assertStringContainsString( "'subscrpt_order' !== \$subs_post->post_type", $this->my_account );
	}

	/** Switching must not expose guest-owned records to anonymous users. */
	public function test_switching_requires_an_authenticated_nonzero_owner_or_capable_admin(): void {
		$this->assertStringContainsString( 'public static function can_switch_subscription( int $subscription_id ): bool', $this->switching );
		$this->assertStringContainsString( "'subscrpt_order' !== \$post->post_type", $this->switching );
		$this->assertStringContainsString( '! is_user_logged_in()', $this->switching );
		$this->assertStringContainsString( '$owner_id <= 0', $this->switching );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $this->switching );
	}

	/** Paid reconciliation must fail closed before mutating an invalid owner/order pair. */
	public function test_paid_switch_reconciliation_requires_subscription_type_positive_ids_and_exact_owner_match(): void {
		$this->assertStringContainsString( "'subscrpt_order' !== \$post->post_type", $this->switching );
		$this->assertStringContainsString( '$owner_id <= 0', $this->switching );
		$this->assertStringContainsString( '$order_customer_id <= 0', $this->switching );
		$this->assertStringContainsString( '$owner_id !== $order_customer_id', $this->switching );
	}

	/** Subscription lifecycle roles must exclude privileged WordPress roles. */
	public function test_subscription_roles_are_allowlisted_and_revalidated_before_assignment(): void {
		$this->assertStringContainsString( 'get_allowed_customer_roles', $this->role_management );
		$this->assertStringContainsString( 'sanitize_customer_role', $this->role_management );
		$this->assertStringContainsString( 'PROTECTED_ROLE_CAPABILITIES', $this->role_management );
		$this->assertStringContainsString( 'RoleManagement::get_allowed_customer_roles()', $this->settings );
		$this->assertStringContainsString( "array( RoleManagement::class, 'sanitize_customer_role' )", $this->settings );
		$this->assertStringContainsString( 'self::sanitize_customer_role( self::get_default_active_role() )', $this->role_management );
		$this->assertStringContainsString( 'self::sanitize_customer_role( self::get_default_inactive_role() )', $this->role_management );
	}

	/** Admin subscription mutations must be scoped to the subscription post type and object capability. */
	public function test_admin_subscription_mutations_are_object_scoped(): void {
		$this->assertStringContainsString( "'subscrpt_order' !== " . '$post->post_type', $this->admin_menu );
		$this->assertStringContainsString( "current_user_can( 'delete_post', " . '$subscription_id )', $this->admin_menu );
		$this->assertStringContainsString( 'can_mutate_subscription( $sub_id )', $this->admin_menu );
		$this->assertStringContainsString( 'can_mutate_subscription( $trash_id )', $this->admin_menu );
	}

	/** The API toggle must actually disable route registration, and API keys must not be rendered in full. */
	public function test_api_setting_controls_routes_and_masks_api_keys(): void {
		$this->assertStringContainsString( "get_option( 'wpsubscription_api_enabled', 'on' )", $this->api );
		$this->assertStringContainsString( 'return;', $this->api );
		$this->assertStringContainsString( 'get_masked_api_key', $this->pro_settings );
		$this->assertStringNotContainsString( "'value'      => esc_attr( get_option( 'wpsubscription_api_key', '' ) )", $this->pro_settings );
	}

	/** Shared logs must redact gateway credentials, identifiers, and contact data before storage. */
	public function test_shared_logging_redacts_sensitive_values(): void {
		$this->assertStringContainsString( 'function subscrpt_redact_log_message', $this->functions );
		$this->assertStringContainsString( 'subscrpt_redact_log_message( $message )', $this->functions );
		$this->assertStringContainsString( 'subscrpt_redact_log_message( $log )', $this->functions );
		$this->assertStringContainsString( '[redacted-gateway-key]', $this->functions );
		$this->assertStringContainsString( '[redacted-paypal-id]', $this->functions );
	}
}

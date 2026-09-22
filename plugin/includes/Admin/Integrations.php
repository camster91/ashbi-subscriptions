<?php
/**
 * Optional gateway and ecosystem integration catalog.
 *
 * @package SpringDevs\Subscription\Admin
 */

// The filename is part of the imported public class path.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrations class
 *
 * @package SpringDevs\Subscription\Admin
 */
class Integrations {
	/**
	 * Integrations list.
	 *
	 * @var array
	 */
	protected $integrations = array();

	/**
	 * Initialize the class
	 */
	public function __construct() {
		// Initialize.
		add_action( 'init', array( $this, 'init' ), 10 );

		// Admin menu (sidebar).
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 20 );

		// Ashbi Subscriptions navbar.
		add_filter( 'subscrpt_admin_header_menu_items', array( $this, 'add_integrations_menu_item' ), 10, 2 );

		// Enqueue integrations scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_integrations_scripts' ) );

	}

	/**
	 * Initialize the integrations.
	 */
	public function init() {
		// Set integrations.
		$this->integrations = $this->get_integrations();
	}

	/**
	 * Register submenu under `subscriptions` menu.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		$parent_slug = 'wp-subscription';
		add_submenu_page(
			$parent_slug,
			__( 'Integrations', 'subscription' ),
			__( 'Integrations', 'subscription' ),
			'manage_options',
			'wp-subscription-integrations',
			array( $this, 'render_integrations_page' ),
		);
	}

	/**
	 * Add Integrations link to the Ashbi Subscriptions admin header menu.
	 *
	 * @param array  $menu_items Array of menu items.
	 * @param string $current Current active menu item slug.
	 */
	public function add_integrations_menu_item( $menu_items, $current ) {
		$menu_items[] = array(
			'slug'  => 'wp-subscription-integrations',
			'label' => __( 'Integrations', 'subscription' ),
			'url'   => admin_url( 'admin.php?page=wp-subscription-integrations' ),
		);
		return $menu_items;
	}

	/**
	 * Enqueue scripts for integrations page.
	 */
	public function enqueue_integrations_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'wp-subscription-integrations' ) ) {
			return;
		}

		wp_enqueue_script( 'subscrpt-integrations', SUBSCRPT_ASSETS . '/js/integration_settings.js', array(), SUBSCRPT_VERSION, true );

		wp_localize_script(
			'subscrpt-integrations',
			'subscrptIntegrations',
			array(
				'nonce'   => wp_create_nonce( 'subscrpt_integration_install_nonce' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);

		// Browser-side filter rail. Enqueued on this hook, not in the render.
		// callback, so it always loads.
		wp_enqueue_script( 'subscrpt-integrations-filter', SUBSCRPT_ASSETS . '/js/admin/integrations-filter.js', array(), SUBSCRPT_VERSION, true );
	}

	/**
	 * Handle AJAX request for integrations.
	 */
	public function integrations_handler_callback() {
		check_ajax_referer( 'wp_subs_integrations_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage integrations.', 'subscription' ) ), 403 );
		}

		$action_callback = ! empty( $_POST['action_callback'] ) ? sanitize_text_field( wp_unslash( $_POST['action_callback'] ) ) : '';

		wp_send_json_success( array( 'action_callback' => $action_callback ) );
	}

	/**
	 * Check if a payment gateway is installed and active.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param bool   $partial_match Whether to allow partial match of gateway ID.
	 * @return bool
	 */
	public static function is_gateway_installed( $gateway_id, $partial_match = false ) {
		$installed_gateways = WC()->payment_gateways()->payment_gateways();

		// Partial match check. For gateways with dynamic IDs.
		if ( $partial_match ) {
			foreach ( $installed_gateways as $key => $gateway ) {
				if ( strpos( $key, $gateway_id ) !== false ) {
					return true;
				}
			}
			return false;
		}

		return isset( $installed_gateways[ $gateway_id ] );
	}

	/**
	 * Check if a payment gateway is enabled.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param bool   $partial_match Whether to allow partial match of gateway ID.
	 * @return bool
	 */
	public static function is_gateway_enabled( $gateway_id, $partial_match = false ) {
		$installed_gateways = WC()->payment_gateways()->payment_gateways();

		// Partial match check. For gateways with dynamic IDs.
		if ( $partial_match ) {
			foreach ( $installed_gateways as $key => $gateway ) {
				if ( strpos( $key, $gateway_id ) !== false ) {
					return $gateway->is_available();
				}
			}
			return false;
		}

		if ( ! isset( $installed_gateways[ $gateway_id ] ) ) {
			return false;
		}
		return $installed_gateways[ $gateway_id ]->is_available();
	}



	/**
	 * Check if a gateway is installed by plugin file.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return bool
	 */
	protected function is_plugin_installed( $plugin_file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		return isset( $plugins[ $plugin_file ] );
	}

	/**
	 * Check if a gateway plugin is active.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return bool
	 */
	protected function is_plugin_active( $plugin_file ) {
		return is_plugin_active( $plugin_file );
	}

	/**
	 * Check if a payment gateway is enabled.
	 *
	 * @param string $gateway_id Gateway ID.
	 */
	public function is_payment_gateway_enabled( $gateway_id ) {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		return isset( $gateways[ $gateway_id ] );
	}

	/**
	 * Get the list of integrations.
	 *
	 * @return array
	 */
	protected function get_integrations(): array {
		$integrations = array(
			'paypal'   => array(
				'title'              => 'PayPal',
				'description'        => 'Accept recurring subscription payments directly through PayPal.',
				'type'               => 'payment_gateway',
				'is_installed'       => 'on' === get_option( 'wp_subs_paypal_integration_enabled', 'off' ),
				'is_active'          => self::is_gateway_enabled( 'wp_subscription_paypal' ),
				'supports_recurring' => true,
				'actions'            => array(
					// [.
					// 'action'   => 'install',.
					// 'label'    => 'Install Now',.
					// 'type'     => 'function',.
					// 'function' => 'wpSubsInstallPaypalIntegration()',.
					// ],.
					array(
						'action' => 'settings',
						'label'  => 'Settings',
						'type'   => 'link',
						'url'    => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wp_subscription_paypal' ),
					),
					// [.
					// 'action'   => 'uninstall',.
					// 'label'    => 'Uninstall',.
					// 'type'     => 'function',.
					// 'function' => 'wpSubsUninstallPaypalIntegration()',.
					// 'class'    => 'button button-primary wp-subs-button-danger',.
					// ],.
				),
			),
			'stripe'   => array(
				'title'              => 'Stripe',
				'description'        => 'Process subscription payments securely with Stripe.',
				'type'               => 'payment_gateway',
				'is_installed'       => class_exists( 'WC_Stripe' ),
				'is_active'          => self::is_gateway_enabled( 'stripe' ),
				'supports_recurring' => true,
				'actions'            => array(
					array(
						'action'   => 'install',
						'label'    => 'Install Now',
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'woocommerce-gateway-stripe')",
					),
					array(
						'action' => 'settings',
						'label'  => 'Settings',
						'type'   => 'link',
						'url'    => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=stripe&panel=settings' ),
					),
				),
			),
			'paddle'   => array(
				'title'              => 'Paddle',
				'description'        => 'Process subscription payments securely with Paddle.',
				'type'               => 'payment_gateway',
				'is_installed'       => class_exists( 'SmartPayWoo\Gateways\Paddle\SmartPay_Paddle' ),
				'is_active'          => self::is_gateway_enabled( 'smartpay_paddle' ),
				'supports_recurring' => true,
				'actions'            => array(
					array(
						'action' => 'install',
						'label'  => 'Get Paddle',
						'type'   => 'external_link',
						'url'    => 'https://wpsmartpay.com/paddle-for-woocommerce/',
					),
					array(
						'action'     => 'enable',
						'label'      => 'Enable Gateway',
						'type'       => 'toggle_option',
						'option_key' => 'woocommerce_enable_paddle_gateway',
						'value'      => true,
					),
					array(
						'action' => 'settings',
						'label'  => 'Settings',
						'type'   => 'link',
						'url'    => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=smartpay_paddle&from=WCADMIN_PAYMENT_SETTINGS' ),
					),
					array(
						'label' => 'More Details',
						'type'  => 'external_link',
						'url'   => 'https://wpsmartpay.com/paddle-for-woocommerce/',
					),
				),
			),
			'mollie'   => array(
				'title'              => 'Mollie',
				'description'        => 'Pay for subscriptions with Mollie Payments for WooCommerce.',
				'type'               => 'payment_gateway',
				'is_optional'        => true,
				'is_installed'       => class_exists( 'Mollie\WooCommerce\Activation\ActivationModule' ),
				'is_active'          => self::is_gateway_enabled( 'mollie_wc_gateway', true ),
				'supports_recurring' => true,
				'actions'            => array(
					array(
						'action'   => 'install',
						'label'    => 'Install Now',
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'mollie-payments-for-woocommerce')",
					),
					array(
						'action' => 'settings',
						'label'  => 'Settings',
						'type'   => 'link',
						'url'    => admin_url( 'admin.php?page=wc-settings&tab=mollie_settings' ),
					),
					array(
						'label' => 'More Details',
						'type'  => 'link',
						'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),
					),
				),
			),
			'razorpay' => array(
				'title'              => 'Razorpay',
				'description'        => 'Pay for subscriptions securely with Razorpay for WooCommerce.',
				'type'               => 'payment_gateway',
				'is_optional'        => true,
				'is_beta'            => true,
				'is_installed'       => class_exists( 'WC_Razorpay' ),
				'is_active'          => self::is_gateway_enabled( 'razorpay' ),
				'supports_recurring' => true,
				'actions'            => array(
					array(
						'action'   => 'install',
						'label'    => 'Install Now',
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'woo-razorpay')",
					),
					array(
						'action' => 'settings',
						'label'  => 'Settings',
						'type'   => 'link',
						'url'    => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=razorpay' ),
					),
					array(
						'label' => 'More Details',
						'type'  => 'link',
						'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),
					),
				),
			),
			'xendit'   => array(
				'title'              => 'Xendit',
				'description'        => 'Pay for subscriptions securely with Xendit for WooCommerce.',
				'type'               => 'payment_gateway',
				'is_optional'        => true,
				'is_beta'            => true,
				'is_installed'       => class_exists( 'WC_Xendit_CC' ),
				'is_active'          => self::is_gateway_enabled( 'xendit' ),
				'supports_recurring' => true,
				'actions'            => array(
					array(
						'action'   => 'install',
						'label'    => 'Install Now',
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'woo-xendit-virtual-accounts')",
					),
					array(
						'action' => 'settings',
						'label'  => 'Settings',
						'type'   => 'link',
						'url'    => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=xendit_gateway' ),
					),
					array(
						'label' => 'More Details',
						'type'  => 'link',
						'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),
					),
				),
			),
		);

		// Third-party integrations are optional adapters supplied by other plugins.
		$third_party = array(
			// LMS.
			'tutor_lms'   => array(
				'title'        => 'Tutor LMS',
				'description'  => 'Restrict course access based on subscription status. Enroll and unenroll students automatically.',
				'type'         => 'third_party',
				'is_optional'  => true,
				'category'     => 'lms',
				'is_installed' => is_plugin_active( 'tutor/tutor.php' ) && class_exists( 'TUTOR\Tutor' ),
				'is_active'    => is_plugin_active( 'tutor/tutor.php' ) && class_exists( 'TUTOR\Tutor' ),
				'actions'      => array(
					array(
						'action'   => 'install',
						'label'    => __( 'Install Now', 'subscription' ),
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'tutor')",
					),
					array(
						'label' => __( 'Learn More', 'subscription' ),
						'type'  => 'link',
						'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),
					),
				),
			),
			'learnpress'  => array(
				'title'        => 'LearnPress',
				'description'  => 'Connect subscriptions with LearnPress courses. Enroll users automatically when subscriptions are active.',
				'type'         => 'third_party',
				'is_optional'  => true,
				'category'     => 'lms',
				'is_installed' => is_plugin_active( 'learnpress/learnpress.php' ) && class_exists( 'LearnPress' ),
				'is_active'    => is_plugin_active( 'learnpress/learnpress.php' ) && class_exists( 'LearnPress' ),
				'actions'      => array(
					array(
						'action'   => 'install',
						'label'    => __( 'Install Now', 'subscription' ),
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'learnpress')",
					),
					array(
						'label' => __( 'Learn More', 'subscription' ),
						'type'  => 'link',
						'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),
					),
				),
			),
			'learndash'   => array(
				'title'        => 'LearnDash',
				'description'  => 'Sync subscription status with LearnDash group enrollment and course access.',
				'type'         => 'third_party',
				'is_optional'  => true,
				'category'     => 'lms',
				'is_installed' => is_plugin_active( 'sfwd-lms/sfwd_lms.php' ) && class_exists( 'LearnDash\Core\App' ),
				'is_active'    => is_plugin_active( 'sfwd-lms/sfwd_lms.php' ) && class_exists( 'LearnDash\Core\App' ),
				'actions'      => array(
					array(
						'action' => 'install',
						'label'  => __( 'Get LearnDash', 'subscription' ),
						'type'   => 'external_link',
						'url'    => 'https://www.learndash.com/',
					),
					array(
						'label' => __( 'Learn More', 'subscription' ),
						'type'  => 'link',
						'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),
					),
				),
			),
			// CRM.
			'fluentcrm'   => array(
				'title'        => 'FluentCRM',
				'description'  => 'Trigger email sequences and manage contacts based on subscription events and status changes.',
				'type'         => 'third_party',
				'is_optional'  => true,
				'category'     => 'crm',
				'is_installed' => class_exists( 'FluentCrm\App\Services\Funnel\BaseTrigger' ),
				'is_active'    => class_exists( 'FluentCrm\App\Services\Funnel\BaseTrigger' ),
				'actions'      => array(
					array(
						'action'   => 'install',
						'label'    => __( 'Install Now', 'subscription' ),
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'fluent-crm')",
					),
					array(
						'label' => __( 'Learn More', 'subscription' ),
						'type'  => 'link',
						'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),
					),
				),
			),
			// Automation.
			'automatorwp' => array(
				'title'        => 'AutomatorWP',
				'description'  => 'Build powerful automations triggered by subscription events without writing any code.',
				'type'         => 'third_party',
				'is_optional'  => true,
				'category'     => 'automation',
				'is_installed' => is_plugin_active( 'automatorwp/automatorwp.php' ) && class_exists( 'AutomatorWP' ),
				'is_active'    => is_plugin_active( 'automatorwp/automatorwp.php' ) && class_exists( 'AutomatorWP' ),
				'actions'      => array(
					array(
						'action'   => 'install',
						'label'    => __( 'Install Now', 'subscription' ),
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'automatorwp')",
					),
					// [.
					// 'label' => __( 'Learn More', 'subscription' ),.
					// 'type'  => 'external_link',.
					// 'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),.
					// ],.
				),
			),
			'wpfusion'    => array(
				'title'        => 'WP Fusion',
				'description'  => 'Sync subscription data with your CRM and marketing platforms through WP Fusion.',
				'type'         => 'third_party',
				'is_optional'  => true,
				'category'     => 'automation',
				'is_installed' => class_exists( 'WPF_Integrations_Base' ),
				'is_active'    => class_exists( 'WPF_Integrations_Base' ),
				'actions'      => array(
					array(
						'action' => 'install',
						'label'  => __( 'Get WP Fusion', 'subscription' ),
						'type'   => 'external_link',
						'url'    => 'https://wpfusion.com/',
					),
					// [.
					// 'label' => __( 'Learn More', 'subscription' ),.
					// 'type'  => 'external_link',.
					// 'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),.
					// ],.
				),
			),
			// Email Marketing.
			'mailpoet'    => array(
				'title'        => 'MailPoet',
				'description'  => 'Add subscribers to MailPoet lists and trigger email automations based on subscription lifecycle events.',
				'type'         => 'third_party',
				'is_optional'  => true,
				'category'     => 'email',
				'is_installed' => is_plugin_active( 'mailpoet/mailpoet.php' ) || is_plugin_active( 'mailpoet-premium/mailpoet-premium.php' ),
				'is_active'    => is_plugin_active( 'mailpoet/mailpoet.php' ) || is_plugin_active( 'mailpoet-premium/mailpoet-premium.php' ),
				'actions'      => array(
					array(
						'action'   => 'install',
						'label'    => __( 'Install Now', 'subscription' ),
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'mailpoet')",
					),
					array(
						'label' => __( 'Learn More', 'subscription' ),
						'type'  => 'link',
						'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),
					),
				),
			),
			// License Management.
			'wp_soft_lic' => array(
				'title'        => 'WP Software License',
				'description'  => 'Issue and validate software licenses for subscription-based digital products.',
				'type'         => 'third_party',
				'is_optional'  => true,
				'category'     => 'license',
				'is_installed' => is_plugin_active( 'software-license/software-license.php' ) && class_exists( 'WOO_SL' ),
				'is_active'    => is_plugin_active( 'software-license/software-license.php' ) && class_exists( 'WOO_SL' ),
				'actions'      => array(
					array(
						'action' => 'install',
						'label'  => __( 'Get Plugin', 'subscription' ),
						'type'   => 'external_link',
						'url'    => 'https://wpsoftwarelicense.com/',
					),
					// [.
					// 'label' => __( 'Learn More', 'subscription' ),.
					// 'type'  => 'external_link',.
					// 'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),.
					// ],.
				),
			),
			'license_mgr' => array(
				'title'        => 'License Manager for WooCommerce',
				'description'  => 'Generate and manage software license keys that are automatically tied to active subscriptions.',
				'type'         => 'third_party',
				'is_optional'  => true,
				'category'     => 'license',
				'is_installed' => is_plugin_active( 'license-manager-for-woocommerce/license-manager-for-woocommerce.php' ) && class_exists( 'LicenseManagerForWooCommerce\Models\Resources\License' ),
				'is_active'    => is_plugin_active( 'license-manager-for-woocommerce/license-manager-for-woocommerce.php' ) && class_exists( 'LicenseManagerForWooCommerce\Models\Resources\License' ),
				'actions'      => array(
					array(
						'action'   => 'install',
						'label'    => __( 'Install Now', 'subscription' ),
						'type'     => 'function',
						'function' => "subscrptInstallPlugin(this, 'license-manager-for-woocommerce')",
					),
					// [.
					// 'label' => __( 'Learn More', 'subscription' ),.
					// 'type'  => 'external_link',.
					// 'url'   => admin_url( 'admin.php?page=wp-subscription-support' ),.
					// ],.
				),
			),
		);
		$integrations = array_merge( $integrations, $third_party );

		// Add more integrations as needed.
		$integrations = apply_filters( 'wpsubs_integrations', $integrations );

		return $integrations;
	}

	/**
	 * Filter actions.
	 *
	 * @param array $integrations Integrations array.
	 */
	protected function filter_integration_actions( array $integrations ): array {
		$cleaned_integrations = array();

		foreach ( $integrations as $integration ) {
			$is_installed = $integration['is_installed'] ?? false;
			$is_active    = $integration['is_active'] ?? false;

			$cleaned_actions   = array();
			$shown_install_url = null;

			foreach ( $integration['actions'] as $integration_action ) {
				$action_tag = $integration_action['action'] ?? null;

				if ( 'install' === $action_tag ) {
					if ( ! $is_installed ) {
						$cleaned_actions[] = $integration_action;
						$shown_install_url = $integration_action['url'] ?? null;
					}
					continue;
				}
				if ( 'uninstall' === $action_tag ) {
					if ( $is_installed ) {
						$cleaned_actions[] = $integration_action;
					}
					continue;
				}
				if ( 'enable' === $action_tag ) {
					if ( $is_installed && ! $is_active ) {
						$cleaned_actions[] = $integration_action;
					}
					continue;
				}
				if ( 'settings' === $action_tag ) {
					if ( $is_installed ) {
						$cleaned_actions[] = $integration_action;
					}
					continue;
				}

				// Default. Skip a "More Details"-style link that just repeats the.
				// install/"Get X" link already shown (e.g. Paddle), so the card.
				// does not carry the same URL twice.
				if ( null !== $shown_install_url && ( $integration_action['url'] ?? null ) === $shown_install_url ) {
					continue;
				}
				$cleaned_actions[] = $integration_action;
			}

			// Overwrite.
			$integration['actions'] = $cleaned_actions;
			$cleaned_integrations[] = $integration;
		}

		return $cleaned_integrations;
	}

	/**
	 * Render the Integrations admin page.
	 */
	public function render_integrations_page() {
		$integrations = $this->integrations;
		$integrations = $this->filter_integration_actions( $integrations );

		$menu = new \SpringDevs\Subscription\Admin\Menu();
		$menu->render_admin_header( __( 'Integrations', 'subscription' ) );
		include 'views/integrations.php';
		$menu->render_admin_footer();
	}
}

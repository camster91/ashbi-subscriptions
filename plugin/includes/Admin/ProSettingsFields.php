<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- This filename is part of the imported public compatibility surface.
/**
 * Ashbi Settings Fields — the single source of the advanced settings UI.
 *
 * Defines the advanced settings fields and owns their registration. The class
 * name remains `ProSettingsFields` for autoload and upgrade compatibility with
 * the public source, but the Ashbi build does not require or load a paid add-on
 * to render or save these options.
 *
 * @package SpringDevs\Subscription\Admin
 */

namespace SpringDevs\Subscription\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class ProSettingsFields
 *
 * @package SpringDevs\Subscription\Admin
 */
class ProSettingsFields {

	/**
	 * Initialize the class.
	 */
	public function __construct() {
		add_filter( 'subscrpt_settings_fields', array( $this, 'add_pro_preview_fields' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Return a non-secret API-key display value for the settings screen.
	 *
	 * @return string
	 */
	private function get_masked_api_key(): string {
		$api_key = (string) get_option( 'wpsubscription_api_key', '' );

		if ( '' === $api_key ) {
			return '';
		}

		return '********' . substr( $api_key, -4 );
	}

	/**
	 * Merge the Ashbi advanced settings fields into the settings fields list.
	 *
	 * @param array $settings_fields Existing settings fields.
	 * @return array
	 */
	public function add_pro_preview_fields( $settings_fields ) {
		$pro_fields = array_merge(
			$this->pro_core_fields(),
			$this->grace_period_fields(),
			$this->payment_failure_fields(),
			$this->api_fields(),
			$this->health_queue_fields(),
			$this->order_fields(),
			$this->switch_fields(),
			$this->role_management_fields(),
			$this->live_qr_fields(),
			$this->payment_gateway_fields()
		);

		return array_merge( $settings_fields, $pro_fields );
	}

	/**
	 * Register every option rendered by this class.
	 *
	 * The settings page posts to `wp_subscription_settings`. Registering these
	 * options here makes the visible controls real settings in the standalone
	 * build rather than a paid-plugin preview. Checkbox sanitizers intentionally
	 * turn an omitted field into `0`, matching the form's unchecked state.
	 *
	 * @return void
	 */
	public function register_settings() {
		$checkboxes = array(
			'subscrpt_early_renew',
			'subscrpt_enable_grace_period_notifications',
			'subscrpt_enable_payment_failure_emails',
			'wp_subscription_auto_complete_order',
			'subscrpt_require_payment_on_trial',
			'subscrpt_switch_enabled',
			'subscrpt_downgrade_allowed',
			'subscrpt_role_based_access',
			'subscrpt_live_qr_active',
			'subscrpt_live_qr_show_product',
			'subscrpt_live_qr_show_billing',
			'subscrpt_live_qr_show_timeline',
		);

		foreach ( $checkboxes as $option ) {
			register_setting(
				'wp_subscription_settings',
				$option,
				array(
					'type'              => 'string',
					'default'           => '0',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
		}

		register_setting(
			'wp_subscription_settings',
			'subscrpt_renewal_price',
			array(
				'type'              => 'string',
				'default'           => 'subscribed',
				'sanitize_callback' => array( __CLASS__, 'sanitize_choice' ),
				'args'              => array( 'subscribed', 'updated' ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'subscrpt_default_payment_grace_period',
			array(
				'type'              => 'integer',
				'default'           => 7,
				'sanitize_callback' => array( __CLASS__, 'sanitize_range' ),
				'args'              => array( 0, 30 ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'subscrpt_grace_period_warning_days',
			array(
				'type'              => 'integer',
				'default'           => 2,
				'sanitize_callback' => array( __CLASS__, 'sanitize_range' ),
				'args'              => array( 1, 7 ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'subscrpt_default_max_payment_retries',
			array(
				'type'              => 'integer',
				'default'           => 3,
				'sanitize_callback' => array( __CLASS__, 'sanitize_range' ),
				'args'              => array( 0, 10 ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'subscrpt_payment_failure_email_delay',
			array(
				'type'              => 'integer',
				'default'           => 24,
				'sanitize_callback' => array( __CLASS__, 'sanitize_range' ),
				'args'              => array( 0, 168 ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'wpsubscription_api_enabled',
			array(
				'type'              => 'string',
				'default'           => 'on',
				'sanitize_callback' => array( __CLASS__, 'sanitize_api_toggle' ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'subscrpt_health_queue_email_frequency',
			array(
				'type'              => 'string',
				'default'           => 'subscrpt_weekly',
				'sanitize_callback' => array( __CLASS__, 'sanitize_choice' ),
				'args'              => array( 'daily', 'subscrpt_weekly', 'subscrpt_monthly', 'never' ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'subscrpt_switch_fee_amount',
			array(
				'type'              => 'number',
				'default'           => 0,
				'sanitize_callback' => array( __CLASS__, 'sanitize_decimal' ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'subscrpt_switch_fee_type',
			array(
				'type'              => 'string',
				'default'           => 'flat',
				'sanitize_callback' => array( __CLASS__, 'sanitize_choice' ),
				'args'              => array( 'flat', 'percent' ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'subscrpt_switch_fee_apply_to',
			array(
				'type'              => 'string',
				'default'           => 'both',
				'sanitize_callback' => array( __CLASS__, 'sanitize_choice' ),
				'args'              => array( 'both', 'upgrade', 'downgrade' ),
			)
		);
		register_setting(
			'wp_subscription_settings',
			'hidden_payment_gateways',
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_gateway_list' ),
			)
		);
	}

	/**
	 * Normalize a checkbox value from the settings form.
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	public static function sanitize_checkbox( $value ) {
		return in_array( $value, array( 1, '1', true, 'on', 'yes' ), true ) ? '1' : '0';
	}

	/**
	 * Normalize the API toggle value.
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	public static function sanitize_api_toggle( $value ) {
		return in_array( $value, array( 1, '1', true, 'on', 'yes' ), true ) ? 'on' : 'off';
	}

	/**
	 * Keep a scalar value within a callback-supplied range.
	 *
	 * @param mixed $value Posted value.
	 * @param array $args Minimum and maximum values.
	 * @return int
	 */
	public static function sanitize_range( $value, $args = array( 0, 0 ) ) {
		if ( ! is_array( $args ) ) {
			$option = $args;
			$args   = array(
				'subscrpt_default_payment_grace_period' => array( 0, 30 ),
				'subscrpt_grace_period_warning_days'    => array( 1, 7 ),
				'subscrpt_default_max_payment_retries'  => array( 0, 10 ),
				'subscrpt_payment_failure_email_delay'  => array( 0, 168 ),
			);
			$args   = $args[ $option ] ?? array( 0, 0 );
		}
		$min = isset( $args[0] ) ? (int) $args[0] : 0;
		$max = isset( $args[1] ) ? (int) $args[1] : $min;
		return max( $min, min( $max, absint( $value ) ) );
	}

	/**
	 * Keep a decimal amount non-negative and WooCommerce-compatible.
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	public static function sanitize_decimal( $value ) {
		return number_format( max( 0, (float) $value ), 2, '.', '' );
	}

	/**
	 * Restrict a setting to one of its declared choices.
	 *
	 * @param mixed $value Posted value.
	 * @param array $args Allowed values.
	 * @return string
	 */
	public static function sanitize_choice( $value, $args = array() ) {
		if ( ! is_array( $args ) ) {
			$allowed = array(
				'subscrpt_renewal_price'                => array( 'subscribed', 'updated' ),
				'subscrpt_health_queue_email_frequency' => array( 'daily', 'subscrpt_weekly', 'subscrpt_monthly', 'never' ),
				'subscrpt_switch_fee_type'              => array( 'flat', 'percent' ),
				'subscrpt_switch_fee_apply_to'          => array( 'both', 'upgrade', 'downgrade' ),
			);
			$args    = $allowed[ $args ] ?? array();
		}
		$value = sanitize_key( $value );
		return in_array( $value, $args, true ) ? $value : (string) ( $args[0] ?? '' );
	}

	/**
	 * Sanitize the gateway IDs selected for subscription checkout.
	 *
	 * @param mixed $value Posted gateway IDs.
	 * @return array<int,string>
	 */
	public static function sanitize_gateway_list( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $value ) ) ) );
	}

	/**
	 * Module: Pro Core (Admin/Settings.php) — general settings additions.
	 *
	 * @return array
	 */
	private function pro_core_fields() {
		return array(
			array(
				'type'       => 'select',
				'group'      => 'renewals',
				'priority'   => 7,
				'field_data' => array(
					'id'          => 'subscrpt_renewal_price',
					'title'       => __( 'Renewal Price', 'subscription' ),
					'description' => __( 'Choose a price that will be used for subscription renewal.', 'subscription' ),
					'options'     => array(
						'subscribed' => __( 'Subscribed Price', 'subscription' ),
						'updated'    => __( 'New/Updated Price', 'subscription' ),
					),
					'selected'    => esc_attr( get_option( 'subscrpt_renewal_price', 'subscribed' ) ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'renewals',
				'priority'   => 8,
				'field_data' => array(
					'id'          => 'subscrpt_early_renew',
					'title'       => __( 'Early Renewal', 'subscription' ),
					'label'       => __( 'Accept Early Renewal Payments', 'subscription' ),
					'description' => __( 'With early renewals enabled, customers can renew their subscriptions before the next payment date.', 'subscription' ),
					'value'       => '1',
					'checked'     => '1' === get_option( 'subscrpt_early_renew', '1' ),
				),
			),
		);
	}

	/**
	 * Module: Grace Period (Admin/Settings.php).
	 *
	 * @return array
	 */
	private function grace_period_fields() {
		return array(
			array(
				'type'       => 'heading',
				'group'      => 'grace_period',
				'priority'   => 5,
				'field_data' => array(
					'title' => __( 'Grace Period Settings', 'subscription' ),
				),
			),
			array(
				'type'       => 'input',
				'group'      => 'grace_period',
				'priority'   => 1,
				'field_data' => array(
					'id'          => 'subscrpt_default_payment_grace_period',
					'title'       => __( 'Grace Period (Days)', 'subscription' ),
					'description' => __( 'Days to maintain access after subscriptions expires. (0 = No grace period. Max 30 days)', 'subscription' ),
					'value'       => esc_attr( get_option( 'subscrpt_default_payment_grace_period', '7' ) ),
					'type'        => 'number',
					'attributes'  => array(
						'min' => 0,
						'max' => 30,
					),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'grace_period',
				'priority'   => 2,
				'field_data' => array(
					'id'          => 'subscrpt_enable_grace_period_notifications',
					'title'       => __( 'Grace Period Notifications', 'subscription' ),
					'label'       => __( 'Send notifications during grace period', 'subscription' ),
					'description' => __( 'Customers will receive warnings before their access is suspended.', 'subscription' ),
					'value'       => '1',
					'checked'     => '1' === get_option( 'subscrpt_enable_grace_period_notifications', '1' ),
				),
			),
			array(
				'type'       => 'input',
				'group'      => 'grace_period',
				'priority'   => 3,
				'field_data' => array(
					'id'          => 'subscrpt_grace_period_warning_days',
					'title'       => __( 'Grace Period Warning (Days Before)', 'subscription' ),
					'description' => __( 'Send warning emails this many days before grace period expires. (1-7 days)', 'subscription' ),
					'value'       => esc_attr( get_option( 'subscrpt_grace_period_warning_days', '2' ) ),
					'type'        => 'number',
					'attributes'  => array(
						'min' => 1,
						'max' => 7,
					),
				),
			),
		);
	}

	/**
	 * Module: Payment Failure Handling (Admin/Settings.php).
	 *
	 * @return array
	 */
	private function payment_failure_fields() {
		return array(
			array(
				'type'       => 'heading',
				'group'      => 'payment_failure',
				'priority'   => 6,
				'field_data' => array(
					'title' => __( 'Payment Failure Handling', 'subscription' ),
				),
			),
			array(
				'type'       => 'input',
				'group'      => 'payment_failure',
				'priority'   => 1,
				'field_data' => array(
					'id'          => 'subscrpt_default_max_payment_retries',
					'title'       => __( 'Default Max Payment Retries', 'subscription' ),
					'description' => __( 'Default number of automatic retry attempts for failed payments. (0 = No retries. Max 10 retries)', 'subscription' ),
					'value'       => esc_attr( get_option( 'subscrpt_default_max_payment_retries', '3' ) ),
					'type'        => 'number',
					'attributes'  => array(
						'min' => 0,
						'max' => 10,
					),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'payment_failure',
				'priority'   => 2,
				'field_data' => array(
					'id'          => 'subscrpt_enable_payment_failure_emails',
					'title'       => __( 'Enable Payment Failure Emails', 'subscription' ),
					'label'       => __( 'Send email notifications when payments fail', 'subscription' ),
					'description' => __( 'Customers will receive emails about failed payments and retry attempts.', 'subscription' ),
					'value'       => '1',
					'checked'     => '1' === get_option( 'subscrpt_enable_payment_failure_emails', '1' ),
				),
			),
			array(
				'type'       => 'input',
				'group'      => 'payment_failure',
				'priority'   => 3,
				'field_data' => array(
					'id'          => 'subscrpt_payment_failure_email_delay',
					'title'       => __( 'Payment Failure Email Delay (Hours)', 'subscription' ),
					'description' => __( 'Delay before sending payment failure emails to avoid spam during temporary issues. (0-168 hours)', 'subscription' ),
					'value'       => esc_attr( get_option( 'subscrpt_payment_failure_email_delay', '24' ) ),
					'type'        => 'number',
					'attributes'  => array(
						'min' => 0,
						'max' => 168,
					),
				),
			),
		);
	}

	/**
	 * Module: API Settings (Admin/Settings.php).
	 *
	 * @return array
	 */
	private function api_fields() {
		return array(
			array(
				'type'       => 'heading',
				'group'      => 'api_settings',
				'priority'   => 99,
				'field_data' => array(
					'title' => __( 'API Settings', 'subscription' ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'api_settings',
				'priority'   => 1,
				'field_data' => array(
					'id'          => 'wpsubscription_api_enabled',
					'title'       => __( 'Enable API', 'subscription' ),
					'description' => __( 'Enable REST API endpoints for subscription actions', 'subscription' ),
					'value'       => 'on',
					'checked'     => 'on' === get_option( 'wpsubscription_api_enabled', 'on' ),
				),
			),
			array(
				'type'       => 'join',
				'group'      => 'api_settings',
				'priority'   => 2,
				'field_data' => array(
					'title'       => __( 'API Key', 'subscription' ),
					'description' => __( 'A configured API key is shown only by its final four characters. Keep it secure.', 'subscription' ),
					'elements'    => array(
						SettingsHelper::inp_element(
							array(
								'id'         => 'wpsubscription_api_key',
								'value'      => esc_attr( $this->get_masked_api_key() ),
								'type'       => 'text',
								'style'      => 'min-width:366px;',
								'attributes' => array(
									'readonly' => true,
								),
							),
							true
						),
						'<span class="wpsubs-tooltip" data-tip="' . esc_attr__( 'Regenerate API Key', 'subscription' ) . '">'
						. '<button type="button" class="wpsubs-btn wpsubs-btn--outline wpsubs-btn--icon" id="regenerate_api_key" aria-label="' . esc_attr__( 'Regenerate API Key', 'subscription' ) . '">'
						. '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>'
						. '</button>'
						. '</span>',
					),
				),
			),
			array(
				'type'       => 'input',
				'group'      => 'api_settings',
				'priority'   => 3,
				'field_data' => array(
					'id'          => 'subscrpt_api_endpoint_url',
					'title'       => __( 'REST API Endpoint URL', 'subscription' ),
					'description' => __( 'Copy and use this endpoint for all API actions.', 'subscription' ),
					'value'       => esc_url( home_url( '/wp-json/wpsubscription/v1/action' ) ),
					'type'        => 'text',
					'attributes'  => array(
						'readonly' => true,
					),
				),
			),
		);
	}

	/**
	 * Module: Subscription Health (Admin/Settings.php).
	 *
	 * @return array
	 */
	private function health_queue_fields() {
		return array(
			array(
				'type'       => 'heading',
				'group'      => 'health_queue',
				'priority'   => 9,
				'field_data' => array(
					'title' => __( 'Subscription Health', 'subscription' ),
				),
			),
			array(
				'type'       => 'select',
				'group'      => 'health_queue',
				'priority'   => 2,
				'field_data' => array(
					'id'          => 'subscrpt_health_queue_email_frequency',
					'title'       => __( 'Health Queue Email Frequency', 'subscription' ),
					'description' => __( 'How often to send the admin health queue reminder email. The email is only sent when subscriptions need attention.', 'subscription' ),
					'options'     => array(
						'daily'            => __( 'Daily', 'subscription' ),
						'subscrpt_weekly'  => __( 'Weekly', 'subscription' ),
						'subscrpt_monthly' => __( 'Monthly', 'subscription' ),
						'never'            => __( 'Never', 'subscription' ),
					),
					'selected'    => esc_attr( get_option( 'subscrpt_health_queue_email_frequency', 'subscrpt_weekly' ) ),
				),
			),
		);
	}

	/**
	 * Module: Order (Illuminate/Order.php) — auto complete orders.
	 *
	 * @return array
	 */
	private function order_fields() {
		return array(
			array(
				'type'       => 'toggle',
				'group'      => 'payment_gateways',
				'priority'   => 1,
				'field_data' => array(
					'id'          => 'wp_subscription_auto_complete_order',
					'title'       => __( 'Auto Complete Orders', 'subscription' ),
					'description' => __( 'Automatically change status of processing orders to completed.', 'subscription' ),
					'value'       => '1',
					'checked'     => '1' === get_option( 'wp_subscription_auto_complete_order', '1' ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'payment_gateways',
				'priority'   => 2,
				'field_data' => array(
					'id'          => 'subscrpt_require_payment_on_trial',
					'title'       => __( 'Require Payment for Free Trials', 'subscription' ),
					'label'       => __( 'Collect payment details at checkout for trial subscriptions', 'subscription' ),
					'description' => __( 'Collect payment details up front on free trials so renewals charge automatically.', 'subscription' ),
					'value'       => '1',
					'checked'     => '1' === get_option( 'subscrpt_require_payment_on_trial', '1' ),
				),
			),
		);
	}

	/**
	 * Module: Switch Subscription (Illuminate/SubscriptionSwitch.php).
	 *
	 * @return array
	 */
	private function switch_fields() {
		return array(
			array(
				'type'       => 'heading',
				'group'      => 'switching',
				'priority'   => 3,
				'field_data' => array(
					'title' => __( 'Switching & Upgrades', 'subscription' ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'switching',
				'priority'   => 9,
				'field_data' => array(
					'id'          => 'subscrpt_switch_enabled',
					'title'       => __( 'Switch Subscription', 'subscription' ),
					'label'       => __( 'Allow users to upgrade/downgrade their subscriptions.', 'subscription' ),
					'description' => '',
					'value'       => '1',
					'checked'     => '1' === get_option( 'subscrpt_switch_enabled', '0' ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'switching',
				'priority'   => 10,
				'field_data' => array(
					'id'          => 'subscrpt_downgrade_allowed',
					'title'       => __( 'Downgrade Subscription', 'subscription' ),
					'label'       => __( 'Allow users to downgrade their subscriptions.', 'subscription' ),
					'description' => '',
					'value'       => '1',
					'checked'     => '1' === get_option( 'subscrpt_downgrade_allowed', '1' ),
				),
			),
			array(
				'type'       => 'join',
				'group'      => 'switching',
				'priority'   => 11,
				'field_data' => array(
					'title'       => __( 'Switch Fee', 'subscription' ),
					'description' => __( 'Optional fee charged when a customer switches plans. Use a flat amount or a percentage of the new plan price. (0 = no fee)', 'subscription' ),
					'elements'    => array(
						SettingsHelper::inp_element(
							array(
								'id'         => 'subscrpt_switch_fee_amount',
								'value'      => esc_attr( get_option( 'subscrpt_switch_fee_amount', '0' ) ),
								'type'       => 'number',
								'style'      => 'min-width:170px;width:170px;',
								'attributes' => array(
									'min'  => 0,
									'step' => '0.01',
								),
							),
							true
						),
						SettingsHelper::select_element(
							array(
								'id'       => 'subscrpt_switch_fee_type',
								'class'    => 'subscrpt-switch-fee-type',
								'options'  => array(
									'flat'    => sprintf(
										/* translators: %s: store currency symbol. */
										__( 'Flat (%s)', 'subscription' ),
										get_woocommerce_currency_symbol()
									),
									'percent' => __( 'Percentage (%)', 'subscription' ),
								),
								'selected' => esc_attr( get_option( 'subscrpt_switch_fee_type', 'flat' ) ),
							),
							true
						),
					),
				),
			),
			array(
				'type'       => 'select',
				'group'      => 'switching',
				'priority'   => 12,
				'field_data' => array(
					'id'          => 'subscrpt_switch_fee_apply_to',
					'title'       => __( 'Apply Fee To', 'subscription' ),
					'description' => __( 'Choose which switch directions the fee applies to.', 'subscription' ),
					'options'     => array(
						'both'      => __( 'Both upgrade and downgrade', 'subscription' ),
						'upgrade'   => __( 'Upgrade only', 'subscription' ),
						'downgrade' => __( 'Downgrade only', 'subscription' ),
					),
					'selected'    => esc_attr( get_option( 'subscrpt_switch_fee_apply_to', 'both' ) ),
				),
			),
		);
	}

	/**
	 * Module: Role Management (Illuminate/RoleManagement.php).
	 *
	 * @return array
	 */
	private function role_management_fields() {
		return array(
			array(
				'type'       => 'heading',
				'group'      => 'role_based_settings',
				'priority'   => 7,
				'field_data' => array(
					'title' => __( 'Role-Based Settings', 'subscription' ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'role_based_settings',
				'priority'   => 1,
				'field_data' => array(
					'id'          => 'subscrpt_role_based_access',
					'title'       => __( 'Enable Role Based Access', 'subscription' ),
					'description' => __( 'Show/Hide products based on user roles.', 'subscription' ),
					'value'       => '1',
					'checked'     => '1' === get_option( 'subscrpt_role_based_access', '1' ),
				),
			),
		);
	}

	/**
	 * Module: Live QR (Illuminate/LiveQR/LiveQR.php) — quick details QR.
	 *
	 * @return array
	 */
	private function live_qr_fields() {
		return array(
			array(
				'type'       => 'heading',
				'group'      => 'live_qr_settings',
				'priority'   => 8,
				'field_data' => array(
					'title' => __( 'Quick Details QR Settings', 'subscription' ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'live_qr_settings',
				'priority'   => 1,
				'field_data' => array(
					'id'      => 'subscrpt_live_qr_active',
					'title'   => __( 'Enable Quick Details QR', 'subscription' ),
					'label'   => '',
					'value'   => '1',
					'checked' => '1' === get_option( 'subscrpt_live_qr_active', '1' ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'live_qr_settings',
				'priority'   => 2,
				'field_data' => array(
					'id'      => 'subscrpt_live_qr_show_product',
					'title'   => __( 'Product Details', 'subscription' ),
					'label'   => __( 'Show product details in the subscription quick view', 'subscription' ),
					'value'   => '1',
					'checked' => '1' === get_option( 'subscrpt_live_qr_show_product', '1' ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'live_qr_settings',
				'priority'   => 3,
				'field_data' => array(
					'id'      => 'subscrpt_live_qr_show_billing',
					'title'   => __( 'Billing Details', 'subscription' ),
					'label'   => __( 'Show billing details in the subscription quick view', 'subscription' ),
					'value'   => '1',
					'checked' => '1' === get_option( 'subscrpt_live_qr_show_billing', '0' ),
				),
			),
			array(
				'type'       => 'toggle',
				'group'      => 'live_qr_settings',
				'priority'   => 4,
				'field_data' => array(
					'id'      => 'subscrpt_live_qr_show_timeline',
					'title'   => __( 'Timeline', 'subscription' ),
					'label'   => __( 'Show subscription timeline in the subscription quick view', 'subscription' ),
					'value'   => '1',
					'checked' => '1' === get_option( 'subscrpt_live_qr_show_timeline', '0' ),
				),
			),
		);
	}

	/**
	 * Module: Payment Gateways (Illuminate/Gateways/PaymentGateways.php).
	 *
	 * @return array
	 */
	private function payment_gateway_fields() {
		$gateway_options = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways ) {
			foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway_id => $gateway ) {
				$gateway_options[ $gateway_id ] = $gateway->get_title();
			}
		}

		return array(
			array(
				'type'       => 'heading',
				'group'      => 'payment_gateways',
				'priority'   => 1,
				'field_data' => array(
					'title' => __( 'Payment Gateway Settings', 'subscription' ),
				),
			),
			array(
				'type'       => 'multi_select',
				'group'      => 'payment_gateways',
				'priority'   => 2,
				'field_data' => array(
					'id'          => 'hidden_payment_gateways',
					'title'       => __( 'Hide Payment Gateways', 'subscription' ),
					'description' => __( 'Select payment gateways to hide for subscription products. (keep empty for not hiding any)', 'subscription' ),
					'options'     => $gateway_options,
					'selected'    => get_option( 'hidden_payment_gateways', array() ),
				),
			),
		);
	}
}

<?php
/**
 * Role Management File
 *
 * @package SpringDevs\Subscription\Illuminate
 */

// PSR-4 class filename is retained for the public role compatibility path.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Illuminate;

/**
 * RoleManagement [ helper class ]
 *
 * @package SpringDevs\Subscription\Illuminate
 */
class RoleManagement {
	/**
	 * Capabilities that make a role unsuitable for automatic customer assignment.
	 *
	 * @var array<string,bool>
	 */
	private const PROTECTED_ROLE_CAPABILITIES = array(
		'manage_options'         => true,
		'manage_woocommerce'     => true,
		'view_woocommerce_reports' => true,
		'promote_users'          => true,
		'create_users'           => true,
		'edit_users'             => true,
		'delete_users'           => true,
		'list_users'             => true,
		'remove_users'           => true,
		'edit_plugins'           => true,
		'activate_plugins'       => true,
		'install_plugins'        => true,
		'update_plugins'         => true,
		'delete_plugins'         => true,
		'edit_themes'            => true,
		'switch_themes'          => true,
		'edit_theme_options'     => true,
		'update_themes'          => true,
		'delete_themes'          => true,
		'unfiltered_html'        => true,
		'unfiltered_upload'      => true,
	);

	/**
	 * Initialize the class
	 */
	public function __construct() {
		add_action( 'subscrpt_subscription_activated', array( $this, 'maybe_change_user_role_on_subscription_activation' ) );

		add_action( 'subscrpt_subscription_expired', array( $this, 'maybe_change_user_role_on_subscription_deactivation' ) );
		add_action( 'subscrpt_subscription_cancelled', array( $this, 'maybe_change_user_role_on_subscription_deactivation' ) );

		// A failed renewal suspends the subscription rather than ending it, so the.
		// role has to come down here too. Nothing listened to this before, which.
		// left a customer who stopped paying holding their subscriber role for the.
		// whole retry ladder. Reversible: a recovered payment fires.
		// `subscrpt_subscription_activated`, which puts the role back.
		add_action( 'subscrpt_subscription_on_hold', array( $this, 'maybe_change_user_role_on_subscription_deactivation' ) );
	}

	/**
	 * Return installed roles that are safe for subscription customer assignment.
	 *
	 * @return array<string,string> Role slug => translated display name.
	 */
	public static function get_allowed_customer_roles(): array {
		global $wp_roles;

		$roles = is_object( $wp_roles ) && isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ? $wp_roles->roles : array();
		$allowed = array();

		foreach ( $roles as $role_key => $role ) {
			$capabilities = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$protected    = false;

			foreach ( array_keys( self::PROTECTED_ROLE_CAPABILITIES ) as $capability ) {
				if ( ! empty( $capabilities[ $capability ] ) ) {
					$protected = true;
					break;
				}
			}

			if ( ! $protected && isset( $role['name'] ) ) {
				$allowed[ sanitize_key( $role_key ) ] = translate_user_role( $role['name'] );
			}
		}

		return $allowed;
	}

	/**
	 * Normalize a configured customer role to the safe installed-role allowlist.
	 *
	 * @param mixed $role Requested role slug.
	 * @return string
	 */
	public static function sanitize_customer_role( $role ): string {
		$role    = sanitize_key( (string) $role );
		$allowed = self::get_allowed_customer_roles();

		if ( isset( $allowed[ $role ] ) ) {
			return $role;
		}

		foreach ( array( 'customer', 'subscriber' ) as $fallback ) {
			if ( isset( $allowed[ $fallback ] ) ) {
				return $fallback;
			}
		}

		foreach ( $allowed as $fallback => $label ) {
			return (string) $fallback;
		}

		return 'subscriber';
	}

	/**
	 * Get default active role
	 */
	public static function get_default_active_role(): string {
		$default_active_role = get_option( 'wp_subscription_active_role' );

		// Migrate legacy option if needed.
		if ( empty( $default_active_role ) ) {
			$default_active_role = get_option( 'subscrpt_active_role', 'subscriber' );
			update_option( 'wp_subscription_active_role', $default_active_role );
		}

		return self::sanitize_customer_role( $default_active_role );
	}

	/**
	 * Get default inactive role
	 */
	public static function get_default_inactive_role(): string {
		$default_inactive_role = get_option( 'wp_subscription_unactive_role' ); // ? Yeap! it is a typo from the ancient times. Too lazy to change!

		// Migrate legacy option if needed.
		if ( empty( $default_inactive_role ) ) {
			$default_inactive_role = get_option( 'subscrpt_unactive_role', 'customer' );
			update_option( 'wp_subscription_unactive_role', $default_inactive_role );
		}

		return self::sanitize_customer_role( $default_inactive_role );
	}

	/**
	 * Maybe change user role on subscription activation
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function maybe_change_user_role_on_subscription_activation( $subscription_id ) {
		// Get the subscription owner's user ID.
		$user_id = get_post_field( 'post_author', (int) $subscription_id );

		if ( ! $user_id ) {
			return;
		}

		$user = new \WP_User( $user_id );

		// Don't change roles for administrators.
		if ( in_array( 'administrator', $user->roles ?? array(), true ) ) {
			return;
		}

		$default_active_role = self::sanitize_customer_role( self::get_default_active_role() );

		$user->set_role( $default_active_role );
	}

	/**
	 * Maybe change user role on subscription deactivation
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function maybe_change_user_role_on_subscription_deactivation( $subscription_id ) {
		// Get the subscription owner's user ID.
		$user_id = get_post_field( 'post_author', (int) $subscription_id );

		if ( ! $user_id ) {
			return;
		}

		$user = new \WP_User( $user_id );

		// Don't change roles for administrators.
		if ( in_array( 'administrator', $user->roles ?? array(), true ) ) {
			return;
		}

		$args              = array(
			'author' => $user_id,
			'status' => 'active',
		);
		$all_subscriptions = Helper::get_subscriptions( $args );

		// Don't change role if user has other active subscriptions.
		if ( count( $all_subscriptions ) > 0 ) {
			return;
		}

		$default_inactive_role = self::sanitize_customer_role( self::get_default_inactive_role() );

		$user->set_role( $default_inactive_role );
	}
}

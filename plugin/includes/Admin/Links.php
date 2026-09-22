<?php
/**
 * Plugin action links.
 *
 * @package SpringDevs\Subscription\Admin
 */

// This filename is part of the imported public compatibility surface.
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName

namespace SpringDevs\Subscription\Admin;

/**
 * Plugin action links
 *
 * Class Links
 *
 * @package SpringDevs\Subscription\Admin
 */
class Links {

	/**
	 * Initialize the class
	 */
	public function __construct() {
		add_filter( 'plugin_action_links_' . plugin_basename( SUBSCRPT_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add plugin action links
	 *
	 * @param array $links Plugin Links.
	 */
	public function plugin_action_links( $links ) {
		$getting_started_url = admin_url( 'admin.php?page=wp-subscription-onboarding' );
		array_unshift( $links, '<a href="' . esc_url( $getting_started_url ) . '">' . __( 'Getting Started', 'subscription' ) . '</a>' );
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=wp-subscription-support' ) ) . '">' . __( 'Help', 'subscription' ) . '</a>';
		return $links;
	}
}

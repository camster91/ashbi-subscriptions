<?php
/**
 * Local help page.
 *
 * @package SpringDevs\Subscription\Admin
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wp-subscription-admin-content list-page">
	<?php
	wpsubs_render_page_header(
		array(
			'title'       => __( 'Help & Resources', 'subscription' ),
			'description' => __( 'Local guidance for operating Ashbi Subscriptions.', 'subscription' ),
		)
	);
	?>

	<div class="wpsubs-table-card" style="padding:24px;margin-bottom:20px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Before changing a live store', 'subscription' ); ?></h2>
		<p><?php esc_html_e( 'Back up the database and plugin files, rehearse the change on staging, and test checkout plus a sandbox renewal with every enabled payment gateway.', 'subscription' ); ?></p>
		<p><?php esc_html_e( 'Do not rename the plugin directory or delete subscription records during an in-place update.', 'subscription' ); ?></p>
	</div>

	<div class="wpsubs-table-card" style="padding:24px;margin-bottom:20px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'When requesting help', 'subscription' ); ?></h2>
		<p><?php esc_html_e( 'Contact the administrator or support contact responsible for this managed site. Include the site URL, WordPress and WooCommerce versions, enabled gateway, affected order and subscription IDs, and the exact time of the issue.', 'subscription' ); ?></p>
		<p><?php esc_html_e( 'Never send passwords, private keys, complete payment tokens, or live gateway secrets in a support message.', 'subscription' ); ?></p>
	</div>

	<div class="wpsubs-table-card" style="padding:24px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Feature availability', 'subscription' ); ?></h2>
		<p><?php esc_html_e( 'This build does not require a commercial license or vendor account. Screens marked unavailable describe optional functionality that is not included in the current distribution.', 'subscription' ); ?></p>
	</div>
</div>

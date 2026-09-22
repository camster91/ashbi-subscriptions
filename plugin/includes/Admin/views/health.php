<?php
/**
 * Subscription health using live store data.
 *
 * @package SpringDevs\Subscription\Admin
 */

defined( 'ABSPATH' ) || exit;

use SpringDevs\Subscription\Illuminate\Stats;

$issue_posts = get_posts(
	array(
		'post_type'      => 'subscrpt_order',
		'post_status'    => array( 'on_hold', 'expired' ),
		'posts_per_page' => 100,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	)
);

$risk_total = 0.0;
foreach ( $issue_posts as $issue_post ) {
	$risk_total += (float) get_post_meta( $issue_post->ID, '_subscrpt_price', true );
}

$counts = Stats::get_status_counts();
$failed = Stats::count_failed_renewals_since( 24 );

if ( function_exists( 'wc_price' ) ) {
	$risk_display = wc_price( $risk_total );
} else {
	$risk_display = esc_html( number_format_i18n( $risk_total, 2 ) );
}
?>

<div class="wp-subscription-admin-content list-page">
	<?php
	wpsubs_render_page_header(
		array(
			'title'       => __( 'Subscription Health', 'subscription' ),
			'description' => __( 'Review subscriptions that are paused, expired, or need payment reconciliation.', 'subscription' ),
			'actions'     => '<a class="wpsubs-btn wpsubs-btn--outline" href="' . esc_url( admin_url( 'admin.php?page=wp-subscription-health' ) ) . '">' . esc_html__( 'Refresh', 'subscription' ) . '</a>',
		)
	);
	?>

	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:20px 0;">
		<div class="subscrpt-card"><div class="subscrpt-card__body"><div class="subscrpt-muted"><?php esc_html_e( 'Needs attention', 'subscription' ); ?></div><div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo esc_html( number_format_i18n( count( $issue_posts ) ) ); ?></div></div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body"><div class="subscrpt-muted"><?php esc_html_e( 'Revenue at risk', 'subscription' ); ?></div><div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo wp_kses_post( $risk_display ); ?></div></div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body"><div class="subscrpt-muted"><?php esc_html_e( 'Failed renewals in 24 hours', 'subscription' ); ?></div><div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo esc_html( number_format_i18n( $failed ) ); ?></div></div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body"><div class="subscrpt-muted"><?php esc_html_e( 'Active subscriptions', 'subscription' ); ?></div><div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo esc_html( number_format_i18n( (int) ( $counts['active'] ?? 0 ) ) ); ?></div></div></div>
	</div>

	<div class="subscrpt-card">
		<div class="subscrpt-card__head"><?php esc_html_e( 'Subscriptions needing attention', 'subscription' ); ?></div>
		<div class="subscrpt-card__body" style="padding:0;overflow:auto;">
			<table class="wpsubs-table">
				<thead><tr><th><?php esc_html_e( 'Subscription', 'subscription' ); ?></th><th><?php esc_html_e( 'Customer', 'subscription' ); ?></th><th><?php esc_html_e( 'Amount', 'subscription' ); ?></th><th><?php esc_html_e( 'Next payment', 'subscription' ); ?></th><th><?php esc_html_e( 'Issue', 'subscription' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php if ( empty( $issue_posts ) ) : ?>
						<tr><td colspan="6" class="subscrpt-muted"><?php esc_html_e( 'No subscriptions currently need attention.', 'subscription' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $issue_posts as $issue_post ) : ?>
							<?php
							$subscription_id = (int) $issue_post->ID;
							$billing_order   = wc_get_order( (int) get_post_meta( $subscription_id, '_subscrpt_order_id', true ) );
							$product_id      = (int) get_post_meta( $subscription_id, '_subscrpt_variation_id', true );
							if ( ! $product_id ) {
								$product_id = (int) get_post_meta( $subscription_id, '_subscrpt_product_id', true );
							}
							$product      = $product_id ? wc_get_product( $product_id ) : null;
							$issue_status = get_post_status( $subscription_id );
							$manual_pause = 'on_hold' === $issue_status && 'manual' === get_post_meta( $subscription_id, '_subscrpt_hold_reason', true );
							$issue_label  = 'expired' === $issue_status ? __( 'Expired', 'subscription' ) : ( $manual_pause ? __( 'Manual pause', 'subscription' ) : __( 'Payment hold', 'subscription' ) );
							$issue_mod    = 'expired' === $issue_status ? 'expired' : ( $manual_pause ? 'pending' : 'cancelled' );
							$next_date    = (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
							$amount       = (float) get_post_meta( $subscription_id, '_subscrpt_price', true );
							?>
								<tr>
									<?php /* translators: %d: subscription ID. */ ?>
									<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-subscription-details&id=' . $subscription_id ) ); ?>"><strong><?php echo esc_html( $product ? $product->get_name() : sprintf( __( 'Subscription #%d', 'subscription' ), $subscription_id ) ); ?></strong></a><span class="wpsubs-cell-id">#<?php echo esc_html( $subscription_id ); ?></span></td>
								<td><?php echo esc_html( $billing_order ? $billing_order->get_formatted_billing_full_name() : __( 'Unknown customer', 'subscription' ) ); ?></td>
								<td><?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $amount ) ) : esc_html( number_format_i18n( $amount, 2 ) ); ?></td>
								<td><?php echo $next_date > 0 ? esc_html( wp_date( get_option( 'date_format' ), $next_date ) ) : '&mdash;'; ?></td>
								<td><span class="wpsubs-badge wpsubs-badge--<?php echo esc_attr( $issue_mod ); ?>"><?php echo esc_html( $issue_label ); ?></span></td>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-subscription-details&id=' . $subscription_id ) ); ?>"><?php esc_html_e( 'Review', 'subscription' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php
/**
 * Delivery schedule view. A subscription follows its billing date unless a
 * delivery cadence was explicitly stored on the plan.
 *
 * @package SpringDevs\Subscription\Admin
 */

defined( 'ABSPATH' ) || exit;

$subscriptions = get_posts(
	array(
		'post_type'      => 'subscrpt_order',
		'post_status'    => array( 'active', 'pending', 'on_hold' ),
		'posts_per_page' => 100,
		'orderby'        => 'meta_value_num',
		'meta_key'       => '_subscrpt_next_date',
		'order'          => 'ASC',
	)
);
?>

<div class="wp-subscription-admin-content list-page">
	<?php
	wpsubs_render_page_header(
		array(
			'title'       => __( 'Delivery Schedules', 'subscription' ),
			'description' => __( 'Upcoming subscription deliveries based on each subscription billing schedule.', 'subscription' ),
			'actions'     => '<a class="wpsubs-btn wpsubs-btn--outline" href="' . esc_url( admin_url( 'admin.php?page=wp-subscription-delivery' ) ) . '">' . esc_html__( 'Refresh', 'subscription' ) . '</a>',
		)
	);
	?>
	<div class="subscrpt-card">
		<div class="subscrpt-card__head"><?php esc_html_e( 'Upcoming schedules', 'subscription' ); ?></div>
		<div class="subscrpt-card__body" style="padding:0;overflow:auto;">
			<table class="wpsubs-table">
				<thead><tr><th><?php esc_html_e( 'Subscription', 'subscription' ); ?></th><th><?php esc_html_e( 'Customer', 'subscription' ); ?></th><th><?php esc_html_e( 'Scheduled date', 'subscription' ); ?></th><th><?php esc_html_e( 'Cadence', 'subscription' ); ?></th><th><?php esc_html_e( 'Status', 'subscription' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $subscriptions ) ) : ?>
						<tr><td colspan="5" class="subscrpt-muted"><?php esc_html_e( 'No active subscription schedules found.', 'subscription' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $subscriptions as $subscription ) : ?>
							<?php
							$subscription_id = (int) $subscription->ID;
							$billing_order   = wc_get_order( (int) get_post_meta( $subscription_id, '_subscrpt_order_id', true ) );
							$product_id      = (int) get_post_meta( $subscription_id, '_subscrpt_variation_id', true );
							if ( ! $product_id ) {
								$product_id = (int) get_post_meta( $subscription_id, '_subscrpt_product_id', true );
							}
							$product   = $product_id ? wc_get_product( $product_id ) : null;
							$next_date = (int) get_post_meta( $subscription_id, '_subscrpt_next_date', true );
							$frequency = (int) get_post_meta( $subscription_id, '_subscrpt_delivery_frequency', true );
							$interval  = (string) get_post_meta( $subscription_id, '_subscrpt_delivery_interval', true );
							/* translators: 1: delivery frequency, 2: delivery interval. */
							$cadence = $frequency > 0 && $interval ? sprintf( __( 'Every %1$d %2$s', 'subscription' ), $frequency, $interval ) : __( 'Follows billing date', 'subscription' );
							?>
								<tr>
									<?php /* translators: %d: subscription ID. */ ?>
									<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-subscription-details&id=' . $subscription_id ) ); ?>"><strong><?php echo esc_html( $product ? $product->get_name() : sprintf( __( 'Subscription #%d', 'subscription' ), $subscription_id ) ); ?></strong></a><span class="wpsubs-cell-id">#<?php echo esc_html( $subscription_id ); ?></span></td>
								<td><?php echo esc_html( $billing_order ? $billing_order->get_formatted_billing_full_name() : __( 'Unknown customer', 'subscription' ) ); ?></td>
								<td><?php echo $next_date > 0 ? esc_html( wp_date( get_option( 'date_format' ), $next_date ) ) : '&mdash;'; ?></td>
								<td><?php echo esc_html( $cadence ); ?></td>
								<td><span class="wpsubs-badge wpsubs-badge--<?php echo esc_attr( 'active' === $subscription->post_status ? 'active' : 'pending' ); ?>"><?php echo esc_html( get_post_status_object( $subscription->post_status )->label ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

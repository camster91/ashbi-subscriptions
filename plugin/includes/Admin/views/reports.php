<?php
/**
 * Subscription reports using live store data.
 *
 * @package SpringDevs\Subscription\Admin
 */

defined( 'ABSPATH' ) || exit;

use SpringDevs\Subscription\Illuminate\Stats;

$counts        = Stats::get_status_counts();
$mrr           = Stats::calculate_active_mrr();
$revenue       = Stats::get_monthly_revenue( 6 );
$new_last_30   = Stats::count_new_since( 30 );
$renewals_due  = Stats::count_renewals_due_within( 7 );
$failed        = Stats::count_failed_renewals_since( 24 );
$recovered     = Stats::count_recovered_renewals_since( 24 );
$recovery_rate = Stats::calculate_recovery_rate( $recovered, $failed );
$churned       = Stats::count_churned_since( 30 );
$churn_base    = (int) ( $counts['active'] ?? 0 ) + $churned;
$churn_rate    = Stats::calculate_churn_rate( $churned, $churn_base );
$risk          = Stats::calculate_revenue_at_risk();
$reasons       = Stats::get_cancellation_reason_counts( 90 );
$cohorts       = Stats::get_cohort_summary( 6 );
$campaign_data = Stats::get_recovery_campaign_counts( 90 );
$max_revenue   = 0.0;

foreach ( $revenue as $month ) {
	$max_revenue = max( $max_revenue, (float) $month['total'] );
}

$status_labels = array(
	'active'       => __( 'Active', 'subscription' ),
	'pending'      => __( 'Pending', 'subscription' ),
	'on_hold'      => __( 'On hold', 'subscription' ),
	'cancelled'    => __( 'Cancelled', 'subscription' ),
	'expired'      => __( 'Expired', 'subscription' ),
	'completed'    => __( 'Completed', 'subscription' ),
	'pe_cancelled' => __( 'Pending cancellation', 'subscription' ),
);

if ( function_exists( 'wc_price' ) ) {
	$mrr_display = wc_price( $mrr );
} else {
	$mrr_display = esc_html( number_format_i18n( $mrr, 2 ) );
}
?>

<div class="wp-subscription-admin-content list-page">
	<?php
	wpsubs_render_page_header(
		array(
			'title'       => __( 'Subscription Reports', 'subscription' ),
			'description' => __( 'Revenue and subscription counts calculated from this store.', 'subscription' ),
			'actions'     => '<a class="wpsubs-btn wpsubs-btn--outline" href="' . esc_url( admin_url( 'admin.php?page=wp-subscription-stats' ) ) . '">' . esc_html__( 'Refresh', 'subscription' ) . '</a> <a class="wpsubs-btn wpsubs-btn--outline" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=subscrpt_export_reports' ), 'subscrpt_export_reports' ) ) . '">' . esc_html__( 'Export aggregate CSV', 'subscription' ) . '</a>',
		)
	);
	?>

	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:14px;margin:20px 0;">
		<div class="subscrpt-card"><div class="subscrpt-card__body">
			<div class="subscrpt-muted"><?php esc_html_e( 'Active MRR', 'subscription' ); ?></div>
			<div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo wp_kses_post( $mrr_display ); ?></div>
		</div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body">
			<div class="subscrpt-muted"><?php esc_html_e( 'Active subscriptions', 'subscription' ); ?></div>
			<div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo esc_html( number_format_i18n( (int) ( $counts['active'] ?? 0 ) ) ); ?></div>
		</div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body">
			<div class="subscrpt-muted"><?php esc_html_e( 'Renewals due in 7 days', 'subscription' ); ?></div>
			<div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo esc_html( number_format_i18n( $renewals_due ) ); ?></div>
		</div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body">
			<div class="subscrpt-muted"><?php esc_html_e( 'New in 30 days', 'subscription' ); ?></div>
			<div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo esc_html( number_format_i18n( $new_last_30 ) ); ?></div>
		</div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body">
			<div class="subscrpt-muted"><?php esc_html_e( 'Failed renewals in 24 hours', 'subscription' ); ?></div>
			<div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo esc_html( number_format_i18n( $failed ) ); ?></div>
		</div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body">
			<div class="subscrpt-muted"><?php esc_html_e( 'Revenue at risk', 'subscription' ); ?></div>
			<div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $risk ) ) : esc_html( number_format_i18n( $risk, 2 ) ); ?></div>
			<div class="subscrpt-muted" style="margin-top:4px;"><?php esc_html_e( 'On-hold and pending cancellation MRR', 'subscription' ); ?></div>
		</div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body">
			<div class="subscrpt-muted"><?php esc_html_e( '30-day churn rate', 'subscription' ); ?></div>
			<div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo esc_html( number_format_i18n( $churn_rate, 2 ) ); ?>%</div>
			<div class="subscrpt-muted" style="margin-top:4px;"><?php esc_html_e( 'Terminal churned states / current base', 'subscription' ); ?></div>
		</div></div>
		<div class="subscrpt-card"><div class="subscrpt-card__body">
			<div class="subscrpt-muted"><?php esc_html_e( '24-hour recovery rate', 'subscription' ); ?></div>
			<div style="font-size:24px;font-weight:700;margin-top:6px;"><?php echo esc_html( number_format_i18n( $recovery_rate, 2 ) ); ?>%</div>
			<div class="subscrpt-muted" style="margin-top:4px;"><?php esc_html_e( 'Paid renewals recovered after failure', 'subscription' ); ?></div>
		</div></div>
	</div>

	<div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:16px;align-items:start;">
		<div class="subscrpt-card">
			<div class="subscrpt-card__head"><?php esc_html_e( 'Subscription revenue by month', 'subscription' ); ?></div>
			<div class="subscrpt-card__body">
				<table class="wpsubs-table">
					<thead><tr><th><?php esc_html_e( 'Month', 'subscription' ); ?></th><th><?php esc_html_e( 'Revenue', 'subscription' ); ?></th><th style="width:50%;"><?php esc_html_e( 'Relative volume', 'subscription' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $revenue as $month ) : ?>
							<?php
							$amount = (float) $month['total'];
							$width  = $max_revenue > 0 ? (int) round( ( $amount / $max_revenue ) * 100 ) : 0;
							?>
							<tr>
								<td><?php echo esc_html( $month['label'] ); ?></td>
								<td><?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $amount ) ) : esc_html( number_format_i18n( $amount, 2 ) ); ?></td>
								<td><div style="height:8px;background:var(--wpsubs-border);border-radius:999px;overflow:hidden;"><span style="display:block;width:<?php echo esc_attr( $width ); ?>%;height:100%;background:var(--wpsubs-brand);border-radius:999px;"></span></div></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="subscrpt-card">
			<div class="subscrpt-card__head"><?php esc_html_e( 'Subscription status', 'subscription' ); ?></div>
			<div class="subscrpt-card__body">
				<table class="wpsubs-table">
					<tbody>
		<?php foreach ( $status_labels as $status_key => $label ) : ?>
			<tr><td><?php echo esc_html( $label ); ?></td><td style="text-align:right;font-weight:600;"><?php echo esc_html( number_format_i18n( (int) ( $counts[ $status_key ] ?? 0 ) ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;margin-top:16px;align-items:start;">
		<div class="subscrpt-card">
			<div class="subscrpt-card__head"><?php esc_html_e( 'Cancellation reasons', 'subscription' ); ?></div>
			<div class="subscrpt-card__body">
				<p class="subscrpt-muted" style="margin-top:0;"><?php esc_html_e( 'Last 90 days; aggregated labels only.', 'subscription' ); ?></p>
				<?php if ( empty( $reasons['reasons'] ) ) : ?>
					<p><?php esc_html_e( 'No cancellation feedback has been recorded.', 'subscription' ); ?></p>
				<?php else : ?>
					<table class="wpsubs-table">
						<thead><tr><th><?php esc_html_e( 'Reason', 'subscription' ); ?></th><th><?php esc_html_e( 'Count', 'subscription' ); ?></th><th><?php esc_html_e( 'Share', 'subscription' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $reasons['reasons'] as $reason ) : ?>
								<tr>
									<td><?php echo esc_html( $reason['reason'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $reason['count'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (float) $reason['percent'], 2 ) ); ?>%</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="subscrpt-card">
			<div class="subscrpt-card__head"><?php esc_html_e( 'Signup cohorts', 'subscription' ); ?></div>
			<div class="subscrpt-card__body">
				<p class="subscrpt-muted" style="margin-top:0;"><?php esc_html_e( 'Current state of subscriptions created in each calendar month.', 'subscription' ); ?></p>
				<table class="wpsubs-table">
					<thead><tr><th><?php esc_html_e( 'Cohort', 'subscription' ); ?></th><th><?php esc_html_e( 'Started', 'subscription' ); ?></th><th><?php esc_html_e( 'Retained', 'subscription' ); ?></th><th><?php esc_html_e( 'Retention', 'subscription' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $cohorts as $cohort ) : ?>
							<tr>
								<td><?php echo esc_html( $cohort['label'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $cohort['cohort'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $cohort['retained'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $cohort['retention_rate'], 2 ) ); ?>%</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="subscrpt-card" style="margin-top:16px;">
		<div class="subscrpt-card__head"><?php esc_html_e( 'Recovery campaigns', 'subscription' ); ?></div>
		<div class="subscrpt-card__body">
			<p class="subscrpt-muted" style="margin-top:0;"><?php esc_html_e( 'Last 90 days; event counts are deduplicated locally and contain no customer details.', 'subscription' ); ?></p>
			<?php if ( empty( $campaign_data['campaigns'] ) ) : ?>
				<p><?php esc_html_e( 'No recovery campaign activity has been recorded.', 'subscription' ); ?></p>
			<?php else : ?>
				<table class="wpsubs-table">
					<thead><tr><th><?php esc_html_e( 'Campaign', 'subscription' ); ?></th><th><?php esc_html_e( 'Offers issued', 'subscription' ); ?></th><th><?php esc_html_e( 'Accepted', 'subscription' ); ?></th><th><?php esc_html_e( 'Win-back payments', 'subscription' ); ?></th><th><?php esc_html_e( 'Win-back share', 'subscription' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $campaign_data['campaigns'] as $campaign ) : ?>
							<tr>
								<td><?php echo esc_html( $campaign['campaign'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $campaign['offer_issued'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $campaign['offer_accepted'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $campaign['win_back'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $campaign['win_back_rate'], 2 ) ); ?>%</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>

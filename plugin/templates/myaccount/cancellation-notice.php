<?php
/**
 * Persistent cancellation receipt within an authorized subscription account view.
 *
 * @package SpringDevs\Subscription
 * @var string $receipt_state Read-only recorded confirmation state.
 * @var int $cancel_at Original recorded access end, or legacy scheduled date.
 * @var string $subscription_status Canonical subscription status.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="subscrpt-pending-cancel-notice" role="<?php echo 'unavailable' === $receipt_state ? 'alert' : 'status'; ?>">
	<div class="subscrpt-pending-cancel-notice__body">
		<?php if ( 'confirmed' === $receipt_state ) : ?>
			<p class="subscrpt-pending-cancel-notice__text"><?php esc_html_e( 'Cancellation confirmed. Future automatic billing is blocked. Any payment already sent for processing requires separate review.', 'subscription' ); ?></p>
		<?php elseif ( 'pending' === $receipt_state ) : ?>
			<p class="subscrpt-pending-cancel-notice__text"><?php esc_html_e( 'Your cancellation request is recorded and local renewal billing is blocked. Confirmation is pending; any provider-managed billing or payment already in progress requires review. Please contact the store for an update.', 'subscription' ); ?></p>
		<?php elseif ( 'unavailable' === $receipt_state ) : ?>
			<p class="subscrpt-pending-cancel-notice__text"><?php esc_html_e( 'Cancellation details are temporarily unavailable. Refresh this page or contact the store if you need confirmation.', 'subscription' ); ?></p>
		<?php endif; ?>
		<?php if ( 'pe_cancelled' === $subscription_status && 'unavailable' !== $receipt_state ) : ?>
			<p class="subscrpt-pending-cancel-notice__text">
				<?php
				if ( $cancel_at ) {
					$effective = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cancel_at );
					printf(
						/* translators: %s: cancellation date and time in the store timezone. */
						$cancel_at > time() ? esc_html__( 'This subscription is scheduled to be cancelled on %s. You can continue accessing it until then.', 'subscription' ) : esc_html__( 'Access was scheduled to end on %s. Please contact the store if you need help with access.', 'subscription' ),
						esc_html( $effective )
					);
				} else {
					esc_html_e( 'This subscription is scheduled to be cancelled at the end of the current billing period. You can continue accessing it until then.', 'subscription' );
				}
				?>
			</p>
		<?php endif; ?>
	</div>
</div>

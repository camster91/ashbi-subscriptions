<?php
/**
 * Reusable unavailable-feature modal.
 *
 * Contained (`--contained`): the dim covers this plugin's screen only, not the
 * admin bar or the admin menu. A paywall that greys out Plugins and Users reads
 * like the site broke rather than like a feature is locked.
 *
 * Including file may set $modal_title and $modal_desc before the include.
 *
 * @package SpringDevs\Subscription\Admin
 */

defined( 'ABSPATH' ) || exit;

$modal_title = isset( $modal_title ) ? $modal_title : __( 'Feature unavailable', 'subscription' );
$modal_desc  = isset( $modal_desc ) ? $modal_desc : __( 'This optional feature is not included in the current build.', 'subscription' );
?>

<div class="wpsubs-modal wpsubs-modal--contained" id="subscrpt-pro-modal" role="dialog" aria-modal="true" aria-labelledby="subscrpt-pro-modal-title" data-wpsubs-modal-autoopen hidden>
	<div class="wpsubs-modal__backdrop" data-wpsubs-modal-close style="backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);"></div>
	<div class="wpsubs-modal__dialog" role="document" style="width:min(440px,calc(100vw - 40px));">
		<button type="button" class="wpsubs-modal__close" data-wpsubs-modal-close aria-label="<?php esc_attr_e( 'Close', 'subscription' ); ?>" style="position:absolute;top:12px;right:14px;">&times;</button>
		<div class="wpsubs-modal__body" style="display:flex;flex-direction:column;align-items:center;text-align:center;gap:12px;padding:28px 28px 24px;">
			<div style="position:relative;display:flex;align-items:center;justify-content:center;">
				<div style="position:absolute;width:100px;height:100px;background:radial-gradient(circle,rgba(255,106,52,0.22) 0%,transparent 70%);border-radius:50%;"></div>
				<div style="position:relative;width:56px;height:56px;border:1.5px solid #ff6a34;border-radius:14px;display:flex;align-items:center;justify-content:center;background:#fff;">
					<svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="#ff6a34" stroke-width="2" aria-hidden="true">
						<rect x="5" y="11" width="14" height="10" rx="2"/>
						<path stroke-linecap="round" d="M8 11V7a4 4 0 018 0v4"/>
					</svg>
				</div>
			</div>
			<h2 class="wpsubs-modal__title" id="subscrpt-pro-modal-title" style="font-size:1.25rem;line-height:1.25;margin:0;"><?php echo esc_html( $modal_title ); ?></h2>
			<p style="margin:0;font-size:13px;line-height:1.55;color:var(--wpsubs-text-muted);"><?php echo esc_html( $modal_desc ); ?></p>
			<button type="button" class="wpsubs-btn wpsubs-btn--primary" data-wpsubs-modal-close style="box-sizing:border-box;width:100%;margin-top:4px;height:42px;font-size:14px;">
				<?php esc_html_e( 'Close', 'subscription' ); ?>
			</button>
		</div>
	</div>
</div>

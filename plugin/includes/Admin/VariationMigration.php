<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Administrator dry-run review and digest-locked migration controls.
 *
 * @package SpringDevs\Subscription
 */

namespace SpringDevs\Subscription\Admin;

use SpringDevs\Subscription\Illuminate\Migration\VariationPlanMigrator as Migrator;

/** Uses the existing admin components without a new framework. */
class VariationMigration {
	/**
	 * Existing page shell.
	 *
	 * @var Menu
	 */
	private $shell;

	/**
	 * Register the submenu.
	 *
	 * @param Menu $shell Existing menu renderer.
	 */
	public function __construct( Menu $shell ) {
		$this->shell = $shell;
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
	}

	/** Add migration below the existing Ashbi menu. */
	public function menu() {
		add_submenu_page( 'wp-subscription', __( 'Migration', 'subscription' ), __( 'Migration', 'subscription' ), 'manage_woocommerce', 'wp-subscription-migration', array( $this, 'render' ) );
	}

	/**
	 * Session-bound review key, independent of other administrators and logins.
	 *
	 * @return string
	 */
	private function review_key() {
		return 'ashbi_vpm_review_' . get_current_user_id() . '_' . substr( hash( 'sha256', wp_get_session_token() ), 0, 24 );
	}

	/**
	 * Process protected forms and render escaped catalogue-only results.
	 *
	 * @return void
	 * @throws \RuntimeException Internally caught and rendered as form errors.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You cannot manage subscription migrations.', 'subscription' ), 403 );
		}
		$migrator      = new Migrator();
		$review_key    = $this->review_key();
		$review        = get_transient( $review_key );
		$report        = array();
		$error_message = '';
		$settings      = Migrator::settings( $review['settings'] ?? array() );
		if ( isset( $_POST['ashbi_migration_action'] ) ) {
			check_admin_referer( 'ashbi_variation_migration' );
			$settings = Migrator::settings(
				array(
					'group_title'    => isset( $_POST['group_title'] ) ? sanitize_text_field( wp_unslash( $_POST['group_title'] ) ) : 'Subscribe & Save',
					'attribute'      => isset( $_POST['attribute'] ) ? sanitize_text_field( wp_unslash( $_POST['attribute'] ) ) : '',
					'include_simple' => isset( $_POST['include_simple'] ),
					'remove_stopgap' => isset( $_POST['remove_stopgap'] ),
				)
			);
			$action   = sanitize_key( wp_unslash( $_POST['ashbi_migration_action'] ) );
			try {
				if ( 'apply' === $action ) {
					$digest = isset( $_POST['fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['fingerprint'] ) ) : '';
					if ( empty( $_POST['confirm_apply'] ) || ! $review || ! hash_equals( $review['fingerprint'], $digest ) || $review['settings'] !== $settings ) {
						throw new \RuntimeException( 'Scan in this login session and confirm the reviewed changes before applying.' );
					}
					$report = $migrator->apply( $settings, $digest );
					delete_transient( $review_key );
					$review = false;
				} elseif ( 'scan' === $action ) {
					$report = $migrator->save_report( $migrator->scan( $settings ), 'dry_run' );
					$review = array(
						'settings'    => $settings,
						'fingerprint' => $report['fingerprint'],
					);
					set_transient( $review_key, $review, 30 * MINUTE_IN_SECONDS );
				} elseif ( 'rollback_scan' === $action || 'rollback_apply' === $action ) {
					if ( 'rollback_apply' === $action && empty( $_POST['confirm_apply'] ) ) {
						throw new \RuntimeException( 'Confirm rollback before applying it.' );
					}
					$report = $migrator->rollback( 'rollback_apply' === $action );
					delete_transient( $review_key );
					$review = false;
				}
			} catch ( \Throwable $error ) {
				$error_message = $error->getMessage();
				delete_transient( $review_key );
				$review = false;
			}
		}
		if ( ! $report ) {
			$report = get_option( Migrator::REPORT, array() );
		}
		wp_enqueue_script( 'ashbi-variation-migration', SUBSCRPT_ASSETS . '/js/admin/variation-migration.js', array(), SUBSCRPT_VERSION, true );
		$menu = $this->shell;
		$menu->render_admin_header( __( 'Migration', 'subscription' ), __( 'Review delivery frequencies before mapping subscription terms. Scan makes no product changes.', 'subscription' ) );
		?>
		<div class="wpsubs-card" style="padding:24px;max-width:1100px;">
			<?php
			if ( $error_message ) :
				?>
				<p role="alert"><?php echo esc_html( $error_message ); ?></p><?php endif; ?>
			<form method="post" data-ashbi-migration>
				<?php wp_nonce_field( 'ashbi_variation_migration' ); ?>
				<p><label><?php esc_html_e( 'Plan group title', 'subscription' ); ?><br><input class="wpsubs-input" name="group_title" value="<?php echo esc_attr( $settings['group_title'] ); ?>" required></label></p>
				<p><label><?php esc_html_e( 'Attribute slug (blank: auto-detect)', 'subscription' ); ?><br><input class="wpsubs-input" name="attribute" value="<?php echo esc_attr( $settings['attribute'] ); ?>"></label></p>
				<p><label><input type="checkbox" name="include_simple" <?php checked( $settings['include_simple'] ); ?>> <?php esc_html_e( 'Also link classic simple subscription products', 'subscription' ); ?></label></p>
				<p><label><input type="checkbox" name="remove_stopgap" <?php checked( $settings['remove_stopgap'] ); ?>> <?php esc_html_e( 'Also remove product-level stopgap relations on mapped products', 'subscription' ); ?></label></p>
				<button class="wpsubs-btn wpsubs-btn--primary" name="ashbi_migration_action" value="scan"><?php esc_html_e( 'Scan (dry run)', 'subscription' ); ?></button>
				<p><label><input type="checkbox" name="confirm_apply" value="yes"> <?php esc_html_e( 'I reviewed the scan and confirm these changes (or the selected rollback).', 'subscription' ); ?></label></p>
				<input type="hidden" name="fingerprint" value="<?php echo esc_attr( $review['fingerprint'] ?? '' ); ?>">
				<button class="wpsubs-btn wpsubs-btn--outline" name="ashbi_migration_action" value="apply" data-reviewed="<?php echo $review ? 'yes' : 'no'; ?>" disabled><?php esc_html_e( 'Apply reviewed scan', 'subscription' ); ?></button>
				<button class="wpsubs-btn wpsubs-btn--outline" name="ashbi_migration_action" value="rollback_scan"><?php esc_html_e( 'Preview rollback', 'subscription' ); ?></button>
				<button class="wpsubs-btn wpsubs-btn--outline" name="ashbi_migration_action" value="rollback_apply"><?php esc_html_e( 'Rollback last apply', 'subscription' ); ?></button>
			</form>
			<?php if ( $report ) : ?>
				<p><?php echo esc_html( ( $report['mode'] ?? '' ) . ' — ' . ( $report['timestamp'] ?? '' ) ); ?></p>
				<p><?php echo esc_html( wp_json_encode( $report['totals'] ?? array() ) ); ?></p>
				<?php if ( isset( $report['cache_purged'] ) ) : ?>
					<p><?php esc_html_e( 'Product page caches are purged for touched products; if a page still lacks the delivery note, purge the page cache manually.', 'subscription' ); ?></p>
				<?php endif; ?>
				<?php
				foreach ( $report['errors'] ?? array() as $error ) :
					?>
					<p role="alert"><?php echo esc_html( $error ); ?></p><?php endforeach; ?>
				<div style="overflow-x:auto;"><table class="widefat striped"><thead><tr>
					<?php
					foreach ( array( 'Product', 'Variation', 'Source', 'Frequency', 'Action', 'Review notes' ) as $heading ) :
						?>
						<th scope="col"><?php echo esc_html( $heading ); ?></th><?php endforeach; ?>
				</tr></thead><tbody>
				<?php foreach ( $report['products'] ?? array() as $plan ) : ?>
					<?php foreach ( $plan['rows'] as $row ) : ?>
						<tr><td><?php echo esc_html( $row['product_id'] ); ?></td><td><?php echo esc_html( $row['variation_id'] ); ?></td><td><?php echo esc_html( $row['source'] ); ?></td><td><?php echo esc_html( isset( $row['frequency'] ) ? $row['frequency'] . ' / ' . \SpringDevs\Subscription\Illuminate\Plans\PlanRepository::interval_to_option( $row['interval'] ) : '—' ); ?></td><td><?php echo esc_html( $row['action'] ); ?></td><td><?php echo esc_html( $row['note'] . ' ' . $plan['stopgap_note'] ); ?></td></tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody></table></div>
				<?php
			else :
				?>
				<p><?php esc_html_e( 'No scan yet. Start with a dry run.', 'subscription' ); ?></p><?php endif; ?>
		</div>
		<?php
		$menu->render_admin_footer();
	}
}

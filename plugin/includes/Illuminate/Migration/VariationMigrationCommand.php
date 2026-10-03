<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 or PHPUnit path.
/**
 * Local WP-CLI migration command; default dry run, explicit confirmation for apply.
 *
 * @package SpringDevs\Subscription
 */

namespace SpringDevs\Subscription\Illuminate\Migration;

/** Entry point for wp ashbi-subscriptions migrate-variations. */
class VariationMigrationCommand {
	/**
	 * Scan, apply, or rollback exact variation mappings.
	 *
	 * ## OPTIONS
	 *
	 * [<operation>]
	 * : Use rollback to preview undoing the last apply.
	 *
	 * [--apply]
	 * : Apply changes. Default is dry run.
	 *
	 * [--yes]
	 * : Confirm apply non-interactively.
	 *
	 * [--group-title=<title>]
	 * : Target group title. Default Subscribe & Save.
	 *
	 * [--attribute=<slug>]
	 * : Frequency attribute slug. Default auto-detect (or site option).
	 *
	 * [--product=<ids>]
	 * : Comma-separated product IDs for a bounded batch.
	 *
	 * [--include-simple]
	 * : Also link classic simple subscription products. Default report-only (classic_simple).
	 *   Disagreeing variation metadata/attributes require manual_review.
	 *
	 * [--remove-product-level-relations]
	 * : Explicitly remove suppressed stopgap rows on mapped products.
	 *
	 * [--format=<format>]
	 * : table, json or yaml. Default table.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named options.
	 * @return void
	 * @throws \RuntimeException Internally caught and reported through WP-CLI.
	 */
	public function __invoke( $args, $assoc_args ) {
		try {
			$format = $assoc_args['format'] ?? 'table';
			if ( ! in_array( $format, array( 'table', 'json', 'yaml' ), true ) || ( $args && array( 'rollback' ) !== $args ) ) {
				throw new \RuntimeException( 'Use migrate-variations [rollback] --format=table|json|yaml.' );
			}
			$apply    = isset( $assoc_args['apply'] );
			$migrator = new VariationPlanMigrator();
			if ( $apply ) {
				\WP_CLI::confirm( 'Apply catalogue plan changes on this site? Existing orders and subscriptions will not be changed.', $assoc_args );
			}
			if ( $args ) {
				$report = $migrator->rollback( $apply );
			} else {
				if ( isset( $assoc_args['product'] ) && ! preg_match( '/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/', $assoc_args['product'] ) ) {
					throw new \RuntimeException( '--product must contain positive IDs separated by commas.' );
				}
				$settings = VariationPlanMigrator::settings(
					array(
						'group_title'    => $assoc_args['group-title'] ?? 'Subscribe & Save',
						'attribute'      => $assoc_args['attribute'] ?? get_option( 'subscrpt_variation_migration_attribute', '' ),
						'products'       => isset( $assoc_args['product'] ) ? explode( ',', $assoc_args['product'] ) : array(),
						'include_simple' => isset( $assoc_args['include-simple'] ),
						'remove_stopgap' => isset( $assoc_args['remove-product-level-relations'] ),
					)
				);
				$report   = $migrator->scan( $settings );
				$report   = $apply ? $migrator->apply( $settings, $report['fingerprint'] ) : $migrator->save_report( $report, 'dry_run' );
			}
			if ( 'table' === $format ) {
				$rows = array();
				foreach ( $report['products'] ?? array() as $plan ) {
					foreach ( $plan['rows'] as $row ) {
						$row['note'] .= ' ' . $plan['stopgap_note'];
						$rows[]       = array_merge(
							array(
								'frequency' => '',
								'interval'  => '',
							),
							$row
						);
					}
				}
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'product_id', 'variation_id', 'source', 'frequency', 'interval', 'action', 'note' ) );
				\WP_CLI::line( wp_json_encode( $report['totals'] ) );
			} else {
				\WP_CLI\Utils\format_items( $format, array( $report ), array_keys( $report ) );
			}
			if ( ! empty( $report['errors'] ) || ! empty( $report['totals']['conflict'] ) || ! empty( $report['totals']['manual_review'] ) ) {
				\WP_CLI::error( 'Review the reported conflicts or errors. Conflicted products were skipped.' );
			}
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
	}
}

<?php
/**
 * Read-only cancellation confirmation review in the existing subscription list.
 *
 * @package SpringDevs\Subscription\Admin
 */

// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- Public namespace autoloading uses the existing class-file convention.

namespace SpringDevs\Subscription\Admin;

/** Query existing intent and confirmation records without another status system. */
final class CancellationReview {
	/**
	 * Find recorded requests whose result is not yet confirmed.
	 *
	 * Permissions belong to the existing merchant screen. Never cache this view:
	 * repairs update confirmation independently of subscription post status.
	 *
	 * @param array $args Existing list filters and pagination.
	 * @return \WP_Query
	 * @throws \RuntimeException When storage cannot supply a trustworthy backlog.
	 */
	public static function query( array $args ): \WP_Query {
		global $wpdb;
		$query                     = new \WP_Query();
		$args['post_type']         = 'subscrpt_order';
		$args['cache_results']     = false;
		$args['suppress_filters']  = false;
		$where_filter = static function ( $where, $candidate ) use ( $query, $wpdb ) {
			if ( $candidate !== $query ) {
				return $where;
			}
			return $where . $wpdb->prepare(
				' AND EXISTS (SELECT 1 FROM %i cancellation_request WHERE cancellation_request.subscription_id = %i.ID) AND COALESCE((SELECT MIN(CASE WHEN confirmation.meta_value = %s THEN 1 ELSE 0 END) FROM %i confirmation WHERE confirmation.post_id = %i.ID AND confirmation.meta_key = %s), 0) = 0',
				$wpdb->prefix . 'subscrpt_cancellation_barrier',
				$wpdb->posts,
				'1',
				$wpdb->postmeta,
				$wpdb->posts,
				'_ashbi_cancellation_confirmed'
			);
		};
		add_filter( 'posts_where', $where_filter, 10, 2 );
		try {
			$query->query( $args );
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( __( 'Cancellation requests could not be loaded. Please try again or contact support.', 'subscription' ) );
			}
			return $query;
		} finally {
			remove_filter( 'posts_where', $where_filter, 10 );
		}
	}
}

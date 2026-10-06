<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 path.
/**
 * Ask known full-page caches to drop product pages after mapping changes.
 *
 * @package SpringDevs\Subscription
 */

namespace SpringDevs\Subscription\Illuminate\Plans;

/** Fire well-known purge APIs; missing plugins are a harmless no-op. */
class CachePurge {
	/**
	 * Purge product page caches for the given parent product ids.
	 *
	 * @param array $ids Parent product ids.
	 * @return int Number of unique product ids requested for purge.
	 */
	public static function products( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		foreach ( $ids as $id ) {
			do_action( 'litespeed_purge_post', $id );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			if ( function_exists( 'wp_cache_post_change' ) ) {
				wp_cache_post_change( $id );
			}
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $id );
			}
		}
		if ( $ids ) {
			do_action( 'subscrpt_variation_mapping_changed', $ids );
		}
		return count( $ids );
	}
}

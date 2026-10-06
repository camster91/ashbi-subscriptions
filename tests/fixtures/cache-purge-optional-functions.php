<?php
/**
 * Optional page-cache purge function doubles for CachePurgeTest.
 *
 * @package AshbiSubscriptions\Tests
 */

/**
 * WP Rocket purge double.
 *
 * @param int $id Post id.
 */
function rocket_clean_post( $id ) {
	$GLOBALS['ashbi_cache_purge_calls'][] = array( 'rocket_clean_post', $id );
}

/**
 * WP Super Cache purge double.
 *
 * @param int $id Post id.
 */
function wp_cache_post_change( $id ) {
	$GLOBALS['ashbi_cache_purge_calls'][] = array( 'wp_cache_post_change', $id );
}

/**
 * W3 Total Cache purge double.
 *
 * @param int $id Post id.
 */
function w3tc_flush_post( $id ) {
	$GLOBALS['ashbi_cache_purge_calls'][] = array( 'w3tc_flush_post', $id );
}

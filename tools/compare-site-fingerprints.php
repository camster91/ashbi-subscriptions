<?php
/**
 * Compare two keyed site-state fingerprints without WordPress.
 *
 * @package AshbiSubscriptions
 */

use Ashbi\Subscriptions\Tools\SiteFingerprint;

require_once __DIR__ . '/lib/SiteFingerprint.php';

$arguments      = isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ? $_SERVER['argv'] : array();
$argument_count = count( $arguments );
if ( $argument_count < 3 || $argument_count > 4 ) {
	fwrite( STDERR, "Usage: php tools/compare-site-fingerprints.php BEFORE.json AFTER.json [activation|exact]\n" );
	exit( 2 );
}

$comparison_mode = $arguments[3] ?? 'activation';
$key  = (string) getenv( 'ASHBI_FINGERPRINT_KEY' );
if ( strlen( $key ) < 32 ) {
	fwrite( STDERR, "ASHBI_FINGERPRINT_KEY must contain at least 32 bytes.\n" );
	exit( 2 );
}

/**
 * Read and decode a keyed fingerprint report.
 *
 * @param string $path Fingerprint path.
 * @return array<string,mixed> Decoded report.
 * @throws RuntimeException When the report cannot be decoded.
 */
function ashbi_read_fingerprint( string $path ): array {
	$raw  = is_readable( $path ) ? file_get_contents( $path ) : false;
	$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( ! is_array( $data ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception text is written to stderr, not HTML.
		throw new RuntimeException( "Invalid fingerprint: {$path}." );
	}
	return $data;
}

try {
	$comparison_errors = SiteFingerprint::compare( ashbi_read_fingerprint( $arguments[1] ), ashbi_read_fingerprint( $arguments[2] ), $comparison_mode, $key );
	if ( $comparison_errors ) {
		foreach ( $comparison_errors as $comparison_error ) {
			fwrite( STDERR, $comparison_error . PHP_EOL );
		}
		exit( 1 );
	}
	fwrite( STDOUT, "Fingerprint comparison passed ({$comparison_mode}).\n" );
} catch ( Throwable $comparison_exception ) {
	fwrite( STDERR, $comparison_exception->getMessage() . PHP_EOL );
	exit( 2 );
}

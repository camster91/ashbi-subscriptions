<?php
/**
 * Observe direct bootstrap execution without defining WordPress constants.
 *
 * @package AshbiSubscriptions\Tests
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed diagnostic strings in an isolated CLI regression.
register_shutdown_function(
	static function () {
		foreach ( get_included_files() as $file ) {
			if ( false !== strpos( str_replace( '\\', '/', $file ), '/plugin/vendor/' ) ) {
				echo "VENDOR_LOADED\n";
				exit( 1 );
			}
		}
		echo "PASS\n";
	}
);

<?php
/** Render the actual admin view against isolated, fabricated schedule states. */
// phpcs:ignoreFile -- Isolated rendering fixture contains WordPress stubs and a dependency double.
namespace SpringDevs\Subscription\Illuminate {
	class Helper {
		public static function get_verbose_status( $status ) { return ucfirst( $status ); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	date_default_timezone_set( 'America/Los_Angeles' );
	function __( $text, $domain ) { return $text; }
	function esc_html__( $text, $domain ) { return $text; }
	function esc_html_e( $text, $domain ) { echo esc_html( $text ); }
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $text ) { return esc_html( $text ); }
	function esc_url( $text ) { return esc_html( $text ); }
	function wp_kses_post( $text ) { return $text; }
	function get_option( $name ) { return 'date_format' === $name ? 'Y-m-d' : 'H:i'; }
	function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
	function get_post_meta( ...$args ) { return ''; }
	function get_comments( ...$args ) { return array(); }
	function do_action( ...$args ) {}
	function wp_nonce_field( ...$args ) {}
	function wpsubs_render_pager( ...$args ) {}
	function wpsubs_render_adv_select( ...$args ) {}
	function wpsubs_render_per_page_select( ...$args ) {}
	function _n( $single, $plural, $number, $domain ) { return 1 === $number ? $single : $plural; }

	function render_details( $status, $date, $grace = false ) {
		$subscription_id = 123;
		$subscription_data = array( 'status' => $status, 'next_date' => $date );
		if ( $grace ) {
			$subscription_data['grace_period'] = array( 'remaining_days' => 2 );
		}
		$rows = $actions = $actions_data = $order_histories = array();
		$order = $order_item = null;
		$list_url = $form_action = '';
		ob_start();
		include dirname( __DIR__, 2 ) . '/plugin/includes/Admin/views/subscription-details.php';
		return ob_get_clean();
	}

	$future = gmdate( DATE_RFC2822, time() + 86400 );
	$past = 'Sat, 10 Jan 2026 21:50:00 +0000';
	$cases = array(
		array( 'active', $future, false, true ),
		array( 'cancelled', $past, false, false ),
		array( 'cancelled', $future, false, false ),
		array( 'expired', $future, false, false ),
		array( 'completed', $future, false, false ),
		array( 'pending', $future, false, false ),
		array( 'on_hold', $future, false, false ),
		array( 'pe_cancelled', $future, false, false ),
		array( 'active', gmdate( DATE_RFC2822, time() - 86400 ), false, false ),
		array( 'active', '', false, false ),
		array( 'active', 'not-a-date', false, false ),
		array( 'active', $future, true, false ),
	);
	foreach ( $cases as $case ) {
		list( $status, $date, $grace, $expected ) = $case;
		$html = render_details( $status, $date, $grace );
		foreach ( array( 'Upcoming renewal', 'Next payment is scheduled for', 'Next payment on ' ) as $label ) {
			if ( $expected !== ( false !== strpos( $html, $label ) ) ) {
				throw new \RuntimeException( $status . ' incorrectly renders ' . $label );
			}
		}
		if ( $past === $date && false === strpos( $html, '2026-01-10' ) ) {
			throw new \RuntimeException( 'Historical date disappeared from the summary.' );
		}
	}
	echo "PASS\n";
}

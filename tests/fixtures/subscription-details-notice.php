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
	function get_comments( $args ) {
		$types = $args['type__in'] ?? array( $args['type'] ?? '' );
		return array_values( array_filter( $GLOBALS['activity_fixture'] ?? array(), function ( $note ) use ( $args, $types ) {
			return $note->comment_post_ID === $args['post_id'] && in_array( $note->comment_type, $types, true ) && '1' === $note->comment_approved && 'approve' === $args['status'];
		} ) );
	}
	function get_comment_meta( $id, $key, $single ) { return $GLOBALS['activity_labels'][ $id ][ $key ] ?? ''; }
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
	$GLOBALS['activity_fixture'] = array();
	foreach ( array( array( 1, 123, 'subscription_note', '1', 'Legacy renewal <script>bad</script>' ), array( 2, 123, 'order_note', '1', 'Current renewal' ), array( 3, 456, 'subscription_note', '1', 'Foreign subscription' ), array( 4, 123, 'comment', '1', 'Unrelated comment' ), array( 5, 123, 'subscription_note', '0', 'Unapproved note' ) ) as $note ) {
		$GLOBALS['activity_fixture'][] = (object) array( 'comment_ID' => $note[0], 'comment_post_ID' => $note[1], 'comment_type' => $note[2], 'comment_approved' => $note[3], 'comment_content' => $note[4], 'comment_date_gmt' => '2026-01-04 12:00:00' );
	}
	$GLOBALS['activity_labels'] = array( 1 => array( 'subscrpt_activity' => 'Legacy label' ), 2 => array( '_subscrpt_activity' => 'Current label', 'subscrpt_activity' => 'Obsolete label' ) );
	$before = serialize( array( $GLOBALS['activity_fixture'], $GLOBALS['activity_labels'] ) );
	$html = render_details( 'cancelled', $past );
	foreach ( array( 'Legacy renewal &lt;script&gt;bad&lt;/script&gt;', 'Current renewal', 'Legacy label', 'Current label' ) as $text ) {
		if ( false === strpos( $html, $text ) ) { throw new \RuntimeException( 'Missing activity: ' . $text ); }
	}
	foreach ( array( 'Foreign subscription', 'Unrelated comment', 'Unapproved note', 'Obsolete label', '<script>bad</script>', 'No activity recorded yet.' ) as $text ) {
		if ( false !== strpos( $html, $text ) ) { throw new \RuntimeException( 'Unexpected activity output: ' . $text ); }
	}
	if ( $before !== serialize( array( $GLOBALS['activity_fixture'], $GLOBALS['activity_labels'] ) ) ) { throw new \RuntimeException( 'Reading history mutated notes.' ); }
	echo "PASS\n";
}

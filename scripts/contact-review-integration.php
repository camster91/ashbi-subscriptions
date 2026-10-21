<?php
/** Execute only with wp eval-file on the disposable CI WordPress database. */
if ( 'local' !== wp_get_environment_type() || 'http://localhost:8099' !== get_option( 'siteurl' ) || 'ashbi_contact_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Disposable contact-review CI identity required.' );
}
add_filter( 'pre_wp_mail', '__return_false' );
add_filter(
	'pre_http_request',
	static function () {
		return new WP_Error( 'ci_network_disabled' );
	}
);
require_once dirname( __DIR__ ) . '/plugin/includes/Illuminate/CancellationEvidence.php';
require dirname( __DIR__ ) . '/tools/contact-request-review/ashbi-contact-request-review.php';
Ashbi_Contact_Request_Review::register();
register_post_type( 'subscrpt_order', array( 'public' => false ) );
$owner = get_user_by( 'login', 'admin' );
wp_set_current_user( $owner->ID );
if ( ! user_can( $owner->ID, 'manage_options' ) ) {
	throw new RuntimeException( 'Registering private storage must preserve administrator capabilities.' );
}
add_filter(
	'ashbi_contact_review_config',
	static function () use ( $owner ) {
		return array(
			'enabled'       => true,
			'form_name'     => 'Synthetic CI support',
			'owner_user_id' => $owner->ID,
		);
	}
);
function contact_ci_assert( $truth, $label ) {
	if ( ! $truth ) {
		throw new RuntimeException( esc_html( $label ) );
	} echo esc_html( $label ) . "\n"; }
$a           = 'Ashbi_Contact_Request_Review';
$before_subs = (int) wp_count_posts( 'subscrpt_order' )->publish;
$id          = $a::capture( 'synthetic@example.invalid', 'Please stop my next delivery.', 'Synthetic fixture' );
if ( is_wp_error( $id ) ) {
	throw new RuntimeException( 'Synthetic intake failed: ' . esc_html( $id->get_error_code() ) );
}
contact_ci_assert( is_int( $id ), 'actual MySQL intake succeeds' );
contact_ci_assert( $id === $a::capture( 'synthetic@example.invalid', 'Please stop my next delivery.' ), 'actual database deduplicates open request' );
$source     = get_post( $id )->post_content;
$subscriber = wp_create_user( 'synthetic-reviewer', 'fixture-only-not-a-real-credential', 'synthetic-reader@example.invalid' );
wp_set_current_user( $subscriber );
contact_ci_assert( is_wp_error( $a::resolve( $id, 'other_request', 0, 'Synthetic reply reference TEST-001.', true ) ), 'real subscriber capability denied' );
wp_set_current_user( $owner->ID );
contact_ci_assert( is_wp_error( $a::resolve( $id, 'cancellation_confirmed', 999999, 'Synthetic reply reference TEST-001.', true ) ), 'unconfirmed cancellation cannot close' );
contact_ci_assert( is_wp_error( $a::resolve( $id, 'other_request', 0, 'Synthetic reply reference TEST-001.', false ) ), 'reply attestation required' );
contact_ci_assert( true === $a::resolve( $id, 'other_request', 0, 'Not a cancellation; answered question, TEST-001.', true ), 'actual closure persists' );
contact_ci_assert( 'draft' === get_post_status( $id ) && $source === get_post( $id )->post_content, 'source preserved on closure' );
contact_ci_assert( is_wp_error( $a::resolve( $id, 'other_request', 0, 'Attempted overwrite, TEST-002.', true ) ), 'closed outcome immutable on replay' );
$second = $a::capture( 'synthetic@example.invalid', 'Please stop my next delivery.' );
contact_ci_assert( is_int( $second ) && $second !== $id, 'repeat after closure makes a new request' );
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'ID' => $second ) );
ob_start();
$a::notice();
$notice = ob_get_clean();
contact_ci_assert( strpos( $notice, '24 hours' ) !== false, 'actual overdue notice rendered' );
// Exercise real WordPress private-post access as an unrelated subscriber.
wp_set_current_user( $subscriber );
contact_ci_assert( ! current_user_can( 'read_post', $second ), 'private request not readable by subscriber' );
$editor = wp_create_user( 'synthetic-editor', 'fixture-only-not-a-real-credential', 'synthetic-editor@example.invalid' );
( new WP_User( $editor ) )->set_role( 'editor' );
wp_set_current_user( $editor );
contact_ci_assert( ! current_user_can( 'read_post', $second ), 'private request not readable by editor' );
contact_ci_assert( ! get_post_type_object( $a::TYPE )->show_in_rest && ! get_post_type_object( $a::TYPE )->publicly_queryable, 'no public REST or query exposure' );
wp_set_current_user( $owner->ID );
contact_ci_assert( $before_subs === (int) wp_count_posts( 'subscrpt_order' )->publish, 'subscription count unchanged' );
// A synthetic confirmation proof exercises the read-only contract, not a gateway.
register_post_status( 'cancelled', array( 'public' => false ) );
$sub = wp_insert_post( array( 'post_type' => 'subscrpt_order', 'post_status' => 'cancelled', 'post_title' => 'Synthetic cancellation proof' ) );
update_post_meta( $sub, '_ashbi_cancellation_confirmed', 1 );
$table = $wpdb->prefix . 'subscrpt_cancellation_barrier';
$wpdb->query( $wpdb->prepare( 'CREATE TEMPORARY TABLE %i (subscription_id bigint unsigned NOT NULL PRIMARY KEY, access_end bigint NOT NULL DEFAULT 0)', $table ) );
contact_ci_assert( ! $a::confirmed( $sub ), 'confirmed marker without barrier is insufficient in MySQL' );
$wpdb->insert( $table, array( 'subscription_id' => $sub ), array( '%d' ) );
update_post_meta( $sub, '_ashbi_cancellation_confirmed', 0 );
$cached = get_post_meta( $sub );
$cached['_ashbi_cancellation_confirmed'] = array( '1' );
wp_cache_set( $sub, $cached, 'post_meta' );
contact_ci_assert( ! $a::confirmed( $sub ), 'stale confirmation cache cannot close a cancellation request' );
wp_cache_delete( $sub, 'post_meta' );
update_post_meta( $sub, '_ashbi_cancellation_confirmed', 1 );
add_post_meta( $sub, '_ashbi_cancellation_confirmed', 0 );
contact_ci_assert( ! $a::confirmed( $sub ), 'conflicting confirmation flags cannot close a cancellation request' );
delete_post_meta( $sub, '_ashbi_cancellation_confirmed', 0 );
$request = $a::capture( 'synthetic@example.invalid', 'Synthetic cancellation proof request.' );
$subscription_before = get_post( $sub );
$meta_before = get_post_meta( $sub );
contact_ci_assert( true === $a::resolve( $request, 'cancellation_confirmed', $sub, 'Verified synthetic proof and replied, TEST-003.', true ), 'verified cancellation outcome persists in MySQL' );
contact_ci_assert( $subscription_before == get_post( $sub ) && $meta_before === get_post_meta( $sub ), 'confirmation review does not mutate subscription' );
echo 'WordPress ' . esc_html( get_bloginfo( 'version' ) ) . '; PHP ' . esc_html( PHP_VERSION ) . '; database ' . esc_html( $wpdb->db_version() ) . "\n";
echo "CONTACT_REVIEW_INTEGRATION_PASS\n";

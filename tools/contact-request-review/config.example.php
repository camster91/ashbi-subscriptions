<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- Documented configuration example filename.
/**
 * Reviewed site-local configuration, installed separately as an MU plugin.
 *
 * @package AshbiSubscriptions
 */

defined( 'ABSPATH' ) || exit;
add_filter(
	'ashbi_contact_review_config',
	static function () {
		return array(
			'enabled'       => false, // Enable only after isolated tests and site approval.
			'form_name'     => 'Support review', // Must be unique across this site's forms.
			'owner_user_id' => 0, // Named daily reviewer with manage_options capability.
			'name_field'    => 'name',
			'email_field'   => 'email',
			'message_field' => 'message',
		);
	}
);

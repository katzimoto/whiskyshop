<?php
/**
 * Access model: store admins configure the whole shop; registered users read
 * and buy. Registration is enabled (my-account and checkout) and non-editor
 * roles can never edit or publish content.
 */

defined( 'ABSPATH' ) || exit;

function omef_enable_user_registration(): void {
	update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
	update_option( 'woocommerce_enable_checkout_login_reminder', 'yes' );
	update_option( 'woocommerce_registration_generate_password', 'no' );
	update_option( 'woocommerce_registration_generate_username', 'yes' );
}
add_action( 'init', 'omef_enable_user_registration', 0 );

function omef_is_content_editor( int $user_id ): bool {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}

	return (bool) array_intersect( array( 'administrator', 'editor' ), (array) $user->roles );
}

function omef_limit_non_editors_cap( array $caps, string $cap, int $user_id ): array {
	if ( omef_is_content_editor( $user_id ) ) {
		return $caps;
	}

	$editing_caps = array(
		'delete_others_pages', 'delete_others_posts', 'delete_pages', 'delete_posts',
		'delete_published_pages', 'delete_published_posts', 'edit_others_pages',
		'edit_others_posts', 'edit_pages', 'edit_posts', 'edit_published_pages',
		'edit_published_posts', 'publish_pages', 'publish_posts', 'upload_files',
	);

	return in_array( $cap, $editing_caps, true ) ? array( 'do_not_allow' ) : $caps;
}
add_filter( 'map_meta_cap', 'omef_limit_non_editors_cap', 20, 3 );

function omef_redirect_customers_away_from_admin(): void {
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	// admin-post.php also serves front-end form submissions (contact form,
	// abandoned-cart links, etc.) for logged-out visitors — only guard actual
	// wp-admin screens, not that shared action-processing endpoint.
	if ( in_array( $GLOBALS['pagenow'] ?? '', array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}
add_action( 'admin_init', 'omef_redirect_customers_away_from_admin' );

function omef_login_notice(): void {
	if ( is_user_logged_in() ) {
		return;
	}
	echo '<p class="omef-login-note" role="note">הרשמה כחבר לקוח עוזרת לנו לשלוח לך עדכונים על חביות והנחות, ולשמור על סל רכישות בין ביקורים.</p>';
}
add_action( 'woocommerce_login_form_start', 'omef_login_notice' );
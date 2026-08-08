<?php
/**
 * SEO redirect manager: store owners move old bottle URLs to new paths and
 * keep a clean 301 trail instead of stale 404s.
 */

defined( 'ABSPATH' ) || exit;

function omef_redirect_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'omef_redirects';
}

function omef_redirect_create_table(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = omef_redirect_table();
	dbDelta(
		"CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(191) NOT NULL,
			target VARCHAR(191) NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY source (source)
		) {$wpdb->get_charset_collate()};"
	);
}

function omef_normalize_redirect_source( string $source ): string {
	$path = wp_parse_url( trim( $source ), PHP_URL_PATH );
	if ( $path === null || $path === '' ) {
		return '';
	}
	return strtolower( rtrim( ltrim( $path, '/' ), '/' ) );
}

function omef_current_request_path(): string {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $path, PHP_URL_PATH );
	return strtolower( rtrim( ltrim( (string) $path, '/' ), '/' ) );
}

function omef_apply_redirect(): void {
	global $wpdb;

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	$source = omef_current_request_path();
	if ( $source === '' ) {
		return;
	}

	$target = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT target FROM ' . omef_redirect_table() . ' WHERE active = 1 AND source = %s',
			$source
		)
	);

	if ( $target ) {
		wp_safe_redirect( home_url( '/' . ltrim( $target, '/' ) ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'omef_apply_redirect', 0 );

function omef_redirects_list_ajax(): void {
	omef_dashboard_guard( 'manage_options' );

	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . omef_redirect_table() . ' ORDER BY id DESC' );

	$redirects = array_map(
		static fn( $row ): array => array(
			'id'     => (int) $row->id,
			'source' => $row->source,
			'target' => $row->target,
			'active' => (bool) $row->active,
		),
		(array) $rows
	);

	wp_send_json_success( array( 'redirects' => $redirects ) );
}
add_action( 'wp_ajax_omef_redirects_list', 'omef_redirects_list_ajax' );

function omef_redirects_add_ajax(): void {
	omef_dashboard_guard( 'manage_options' );

	$source = omef_normalize_redirect_source( sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) ) );
	$target = omef_normalize_redirect_source( sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) ) );

	if ( ! $source || ! $target ) {
		wp_send_json_error( array( 'message' => 'יש להזין נתיב מקור ויעד תקינים.' ) );
	}

	global $wpdb;
	$wpdb->replace(
		omef_redirect_table(),
		array( 'source' => $source, 'target' => $target, 'active' => 1 )
	);

	wp_send_json_success();
}
add_action( 'wp_ajax_omef_redirects_add', 'omef_redirects_add_ajax' );

function omef_redirects_delete_ajax(): void {
	omef_dashboard_guard( 'manage_options' );

	global $wpdb;
	$wpdb->delete(
		omef_redirect_table(),
		array( 'id' => absint( $_POST['id'] ?? 0 ) )
	);

	wp_send_json_success();
}
add_action( 'wp_ajax_omef_redirects_delete', 'omef_redirects_delete_ajax' );
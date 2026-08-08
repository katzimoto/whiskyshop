<?php
/**
 * Plugin Name: Omef Core
 * Description: Structured content and editorial controls for The Omef.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Text Domain: omef-core
 */

defined( 'ABSPATH' ) || exit;

$omef_includes = array(
	'helpers.php',
	'content-types.php',
	'product-meta.php',
	'frontend.php',
	'discounts.php',
	'checkout.php',
	'redirects.php',
	'user-access.php',
	'wholesale.php',
	'backup.php',
	'podcast.php',
	'shop-manager.php',
	'age-gate.php',
	'publish-guardrails.php',
	'abandoned-cart.php',
	'mail.php',
);
foreach ( $omef_includes as $omef_include ) {
	require_once __DIR__ . '/includes/' . $omef_include;
}

function omef_activate(): void {
	omef_register_content_types();
	omef_abandoned_create_table();
	omef_redirect_create_table();
	omef_wholesale_ensure_role();
	if ( ! wp_next_scheduled( 'omef_abandoned_reminder_cron' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'omef_abandoned_reminder_cron' );
	}
	if ( ! wp_next_scheduled( 'omef_daily_backup_cron' ) ) {
		wp_schedule_event( time() + 6 * HOUR_IN_SECONDS, 'daily', 'omef_daily_backup_cron' );
	}
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'omef_activate' );

function omef_deactivate(): void {
	wp_clear_scheduled_hook( 'omef_abandoned_reminder_cron' );
	wp_clear_scheduled_hook( 'omef_daily_backup_cron' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'omef_deactivate' );

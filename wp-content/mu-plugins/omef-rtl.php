<?php
/**
 * Plugin Name: Omef RTL
 * Description: Makes the site natively right-to-left at the WordPress core level, independent of the configured site language. The Omef is a Hebrew-only storefront, so its text direction should never depend on whether WPLANG/site language happens to be set to a Hebrew locale.
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP_Locale::init() determines is_rtl() from the translated 'ltr'/'rtl' string
 * in the 'default' text domain's "text direction" context. Forcing that string
 * here — before the locale object is built — makes is_rtl() true everywhere:
 * <html dir>, core/WooCommerce RTL stylesheet swapping, admin bar, editor, etc.
 * Must live in mu-plugins so it loads before WP_Locale is instantiated.
 */
function omef_force_rtl_text_direction( $translation, $text, $context, $domain ) {
	if ( 'default' === $domain && 'text direction' === $context && 'ltr' === $text ) {
		return 'rtl';
	}
	return $translation;
}
add_filter( 'gettext_with_context', 'omef_force_rtl_text_direction', 10, 4 );

/**
 * lang="" should match the actual content language (Hebrew) rather than
 * whatever the site's WPLANG option is set to.
 */
function omef_force_hebrew_language_attributes( $output ) {
	return preg_replace( '/lang="[^"]*"/', 'lang="he-IL"', $output, 1 );
}
add_filter( 'language_attributes', 'omef_force_hebrew_language_attributes' );

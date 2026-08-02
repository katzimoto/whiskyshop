<?php
/**
 * Theme bootstrap.
 *
 * @package Omef
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'after_setup_theme',
	function (): void {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption' ) );
		add_theme_support( 'align-wide' );
		load_theme_textdomain( 'omef', get_template_directory() . '/languages' );
	}
);

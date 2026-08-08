<?php
defined( 'ABSPATH' ) || exit;

function omef_theme_setup(): void {
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'woocommerce' );
	add_editor_style( 'style.css' );
}
add_action( 'after_setup_theme', 'omef_theme_setup' );

function omef_enqueue_assets(): void {
	wp_enqueue_style(
		'omef-fonts',
		'https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700;800&display=swap',
		array(),
		null
	);

	wp_enqueue_style( 'omef-style', get_stylesheet_uri(), array( 'omef-fonts' ), wp_get_theme()->get( 'Version' ) );
}
add_action( 'wp_enqueue_scripts', 'omef_enqueue_assets' );

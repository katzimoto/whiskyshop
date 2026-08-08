<?php
/**
 * Custom post types and registered post meta.
 */

defined( 'ABSPATH' ) || exit;

function omef_register_content_types(): void {
	$types = array(
		'omef_episode' => array(
			'singular' => 'פרק',
			'plural'   => 'פרקים',
			'slug'     => 'podcast',
		),
		'omef_workshop' => array(
			'singular' => 'סדנה',
			'plural'   => 'סדנאות',
			'slug'     => 'workshops',
		),
		'omef_tasting' => array(
			'singular' => 'טעימה פתוחה',
			'plural'   => 'טעימות פתוחות',
			'slug'     => 'tastings',
		),
	);

	foreach ( $types as $type => $details ) {
		register_post_type(
			$type,
			array(
				'labels' => array(
					'name'               => $details['plural'],
					'singular_name'      => $details['singular'],
					'add_new'            => 'הוספת ' . $details['singular'],
					'add_new_item'       => 'הוספת ' . $details['singular'] . ' חדשה',
					'edit_item'          => 'עריכת ' . $details['singular'],
					'new_item'           => $details['singular'] . ' חדשה',
					'view_item'          => 'הצגת ' . $details['singular'],
					'search_items'       => 'חיפוש ' . $details['plural'],
					'not_found'          => 'לא נמצאו ' . $details['plural'],
					'all_items'          => 'כל ' . $details['plural'],
					'menu_name'          => $details['plural'],
				),
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => $details['slug'] ),
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
				'menu_icon'    => 'dashicons-format-audio',
			)
		);
	}
}
add_action( 'init', 'omef_register_content_types' );

function omef_fields(): array {
	return array(
		'product' => array(
			'_omef_distillery'    => array( 'label' => 'מזקקה', 'type' => 'text' ),
			'_omef_region'        => array( 'label' => 'אזור', 'type' => 'text' ),
			'_omef_age'           => array( 'label' => 'גיל (שנים)', 'type' => 'integer' ),
			'_omef_abv'           => array( 'label' => 'אחוז אלכוהול', 'type' => 'decimal' ),
			'_omef_cask_type'     => array( 'label' => 'סוג חבית', 'type' => 'text' ),
			'_omef_peated'        => array( 'label' => 'מעושן', 'type' => 'boolean' ),
			'_omef_tasting_notes' => array( 'label' => 'הערות טעימה', 'type' => 'textarea' ),
			'_omef_sample_price'  => array( 'label' => 'מחיר דגימה של 30 מ"ל (₪, אופציונלי)', 'type' => 'decimal' ),
			'_omef_sale_price'    => array( 'label' => 'מחיר מבצע (₪, אופציונלי)', 'type' => 'decimal' ),
			'_omef_sale_note'     => array( 'label' => 'סיבת המבצע (למשל: משחרור לקראת סגירת חבית)', 'type' => 'text' ),
		),
		'omef_episode' => array(
			'_omef_episode_number'  => array( 'label' => 'מספר פרק', 'type' => 'integer' ),
			'_omef_spotify_id'      => array( 'label' => 'Spotify ID', 'type' => 'text' ),
			'_omef_episode_summary' => array( 'label' => 'תקציר קצר', 'type' => 'textarea' ),
		),
		'omef_workshop' => array(
			'_omef_duration'    => array( 'label' => 'משך הסדנה', 'type' => 'text' ),
			'_omef_group_size'  => array( 'label' => 'מספר משתתפים', 'type' => 'text' ),
			'_omef_price_range' => array( 'label' => 'טווח מחירים', 'type' => 'text' ),
			'_omef_inclusions'  => array( 'label' => 'מה כלול', 'type' => 'textarea' ),
		),
		'omef_tasting' => array(
			'_omef_tasting_date'   => array( 'label' => 'תאריך ושעה', 'type' => 'datetime' ),
			'_omef_tasting_venue'  => array( 'label' => 'מיקום', 'type' => 'text' ),
			'_omef_tasting_price'  => array( 'label' => 'מחיר (₪)', 'type' => 'decimal' ),
			'_omef_tasting_seats'  => array( 'label' => 'מספר מקומות', 'type' => 'integer' ),
		),
	);
}

function omef_register_meta(): void {
	foreach ( omef_fields() as $post_type => $fields ) {
		foreach ( $fields as $key => $field ) {
			register_post_meta(
				$post_type,
				$key,
				array(
					'single'            => true,
					'type'              => $field['type'] === 'boolean' ? 'boolean' : 'string',
					'show_in_rest'      => true,
					'sanitize_callback' => static function ( $value ) use ( $field ) {
						return omef_sanitize_value( $value, $field['type'] );
					},
				)
			);
		}
	}

	register_post_meta(
		'omef_episode',
		'_omef_episode_products',
		array(
			'single'            => true,
			'type'              => 'array',
			'show_in_rest'      => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
			'sanitize_callback' => 'omef_sanitize_product_ids',
		)
	);

	register_post_meta(
		'omef_tasting',
		'_omef_tasting_product_id',
		array(
			'single'            => true,
			'type'              => 'integer',
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
		)
	);
}
add_action( 'init', 'omef_register_meta', 20 );


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
			'_omef_distillery'   => array( 'label' => 'מזקקה', 'type' => 'text' ),
			'_omef_region'       => array( 'label' => 'אזור', 'type' => 'text' ),
			'_omef_age'          => array( 'label' => 'גיל (שנים)', 'type' => 'integer' ),
			'_omef_abv'          => array( 'label' => 'אחוז אלכוהול', 'type' => 'decimal' ),
			'_omef_cask_type'    => array( 'label' => 'סוג חבית', 'type' => 'text' ),
			'_omef_peated'       => array( 'label' => 'מעושן', 'type' => 'boolean' ),
			'_omef_tasting_notes' => array( 'label' => 'הערות טעימה', 'type' => 'textarea' ),
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

function omef_sanitize_value( $value, string $type ) {
	if ( $type === 'integer' ) {
		return (string) absint( $value );
	}

	if ( $type === 'decimal' ) {
		$value = str_replace( ',', '.', sanitize_text_field( (string) $value ) );
		return preg_match( '/^\d+(?:\.\d{1,2})?$/', $value ) ? $value : '';
	}

	if ( $type === 'boolean' ) {
		return (bool) $value;
	}

	if ( $type === 'textarea' ) {
		return sanitize_textarea_field( (string) $value );
	}

	if ( $type === 'datetime' ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
	}

	return sanitize_text_field( (string) $value );
}

function omef_sanitize_product_ids( $product_ids ): array {
	return omef_sanitize_post_ids( $product_ids, 'product' );
}

function omef_sanitize_episode_ids( $episode_ids ): array {
	return omef_sanitize_post_ids( $episode_ids, 'omef_episode' );
}

function omef_sanitize_post_ids( $post_ids, string $post_type ): array {
	$post_ids = is_array( $post_ids ) ? $post_ids : array();
	$post_ids = array_map( 'absint', $post_ids );

	return array_values(
		array_filter(
			array_unique( $post_ids ),
			static fn( int $post_id ): bool => $post_id > 0 && get_post_type( $post_id ) === $post_type
		)
	);
}

function omef_add_meta_boxes(): void {
	add_meta_box( 'omef_product_details', 'פרטי וויסקי', 'omef_render_fields_box', 'product', 'normal', 'high' );
	add_meta_box( 'omef_episode_details', 'פרטי הפרק', 'omef_render_fields_box', 'omef_episode', 'normal', 'high' );
	add_meta_box( 'omef_workshop_details', 'פרטי הסדנה', 'omef_render_fields_box', 'omef_workshop', 'normal', 'high' );
	add_meta_box( 'omef_tasting_details', 'פרטי הטעימה', 'omef_render_fields_box', 'omef_tasting', 'normal', 'high' );

	add_meta_box( 'omef_episode_products', 'בקבוקים מהפרק', 'omef_render_episode_products_box', 'omef_episode', 'side', 'default' );
	add_meta_box( 'omef_tasting_product', 'מוצר WooCommerce מקושר', 'omef_render_tasting_product_box', 'omef_tasting', 'side', 'default' );
	add_meta_box( 'omef_product_episodes', 'פרקים קשורים', 'omef_render_product_episodes_box', 'product', 'side', 'default' );
}
add_action( 'add_meta_boxes', 'omef_add_meta_boxes' );

function omef_render_fields_box( WP_Post $post ): void {
	wp_nonce_field( 'omef_save_meta', 'omef_meta_nonce' );
	$fields = omef_fields()[ $post->post_type ] ?? array();

	foreach ( $fields as $key => $field ) {
		$value = get_post_meta( $post->ID, $key, true );
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $field['label'] ) . '</strong></label><br>';

		if ( $field['type'] === 'textarea' ) {
			echo '<textarea class="widefat" rows="4" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
		} elseif ( $field['type'] === 'boolean' ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( (bool) $value, true, false ) . '> כן</label>';
		} else {
			$type = $field['type'] === 'integer' || $field['type'] === 'decimal' ? 'number' : ( $field['type'] === 'datetime' ? 'datetime-local' : 'text' );
			$step = $field['type'] === 'decimal' ? ' step="0.01" min="0"' : '';
			echo '<input class="widefat" type="' . esc_attr( $type ) . '"' . $step . ' id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}

		echo '</p>';
	}
}

function omef_render_episode_products_box( WP_Post $post ): void {
	wp_nonce_field( 'omef_save_meta', 'omef_meta_nonce' );
	$selected = omef_sanitize_product_ids( get_post_meta( $post->ID, '_omef_episode_products', true ) );
	$products = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );

	echo '<p>בחרו את הבקבוקים שעלו בפרק.</p><select class="widefat" name="_omef_episode_products[]" multiple size="8">';
	foreach ( $products as $product ) {
		echo '<option value="' . esc_attr( $product->ID ) . '" ' . selected( in_array( $product->ID, $selected, true ), true, false ) . '>' . esc_html( $product->post_title ) . '</option>';
	}
	echo '</select>';
}

function omef_render_tasting_product_box( WP_Post $post ): void {
	wp_nonce_field( 'omef_save_meta', 'omef_meta_nonce' );
	$current = absint( get_post_meta( $post->ID, '_omef_tasting_product_id', true ) );
	$products = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );

	echo '<p>עם מחיר ומספר מקומות, מוצר הזמנות נוצר אוטומטית. מחיר, מלאי, מושבים והזמנות מנוהלים במוצר WooCommerce.</p><select class="widefat" name="_omef_tasting_product_id"><option value="">בחירת מוצר</option>';
	foreach ( $products as $product ) {
		echo '<option value="' . esc_attr( $product->ID ) . '" ' . selected( $product->ID, $current, false ) . '>' . esc_html( $product->post_title ) . '</option>';
	}
	echo '</select>';

	$whatsapp = omef_tasting_whatsapp_text( $post->ID );
	if ( $whatsapp ) {
		echo '<p style="margin-top:14px"><strong>הודעת ווטסאפ:</strong></p><textarea class="widefat" rows="6" readonly>' . esc_textarea( $whatsapp ) . '</textarea><p class="description">העתיקו והדביקו בכל קבוצה.</p>';
	}
}

function omef_tasting_whatsapp_text( int $post_id ): string {
	$date = get_post_meta( $post_id, '_omef_tasting_date', true );
	$venue = get_post_meta( $post_id, '_omef_tasting_venue', true );
	$price = get_post_meta( $post_id, '_omef_tasting_price', true );
	$seats = get_post_meta( $post_id, '_omef_tasting_seats', true );
	$url = get_permalink( $post_id );

	$lines = array( 'חברים, טעימה פתוחה חדשה:' );
	$lines[] = '• ' . get_the_title( $post_id );
	if ( $date ) {
		$lines[] = '• תאריך: ' . $date;
	}
	if ( $venue ) {
		$lines[] = '• מיקום: ' . $venue;
	}
	if ( $price ) {
		$lines[] = '• מחיר: ' . number_format_i18n( (float) $price ) . ' ₪';
	}
	if ( $seats ) {
		$lines[] = '• מקומות: ' . (int) $seats;
	}
	if ( $url ) {
		$lines[] = 'הרשמה: ' . $url;
	}

	return implode( "\n", $lines );
}

function omef_render_product_episodes_box( WP_Post $post ): void {
	$episode_ids = omef_sanitize_episode_ids( get_post_meta( $post->ID, '_omef_episode_ids', true ) );

	if ( ! $episode_ids ) {
		echo '<p>המוצר עדיין לא קושר לפרק.</p>';
		return;
	}

	echo '<ul>';
	foreach ( $episode_ids as $episode_id ) {
		echo '<li><a href="' . esc_url( get_edit_post_link( $episode_id ) ) . '">' . esc_html( get_the_title( $episode_id ) ) . '</a></li>';
	}
	echo '</ul>';
}

function omef_save_meta( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['omef_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['omef_meta_nonce'] ) ), 'omef_save_meta' ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fields = omef_fields()[ $post->post_type ] ?? array();
	foreach ( $fields as $key => $field ) {
		$value = $_POST[ $key ] ?? ( $field['type'] === 'boolean' ? false : '' );
		update_post_meta( $post_id, $key, omef_sanitize_value( wp_unslash( $value ), $field['type'] ) );
	}

	if ( $post->post_type === 'omef_episode' ) {
		$previous_ids = omef_sanitize_product_ids( get_post_meta( $post_id, '_omef_episode_products', true ) );
		$current_ids = omef_sanitize_product_ids( wp_unslash( $_POST['_omef_episode_products'] ?? array() ) );
		update_post_meta( $post_id, '_omef_episode_products', $current_ids );
		omef_sync_episode_products( $post_id, $previous_ids, $current_ids );
	}

	if ( $post->post_type === 'omef_tasting' ) {
		$product_id = absint( $_POST['_omef_tasting_product_id'] ?? 0 );
		update_post_meta( $post_id, '_omef_tasting_product_id', get_post_type( $product_id ) === 'product' ? $product_id : 0 );

		$price = (float) get_post_meta( $post_id, '_omef_tasting_price', true );
		$seats = (int) get_post_meta( $post_id, '_omef_tasting_seats', true );
		omef_ensure_tasting_product( $post_id, $post, $price, $seats );
	}
}
add_action( 'save_post', 'omef_save_meta', 10, 2 );

function omef_ensure_tasting_product( int $post_id, WP_Post $post, float $price, int $seats ): int {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return 0;
	}

	$product_id = absint( get_post_meta( $post_id, '_omef_tasting_product_id', true ) );
	if ( $product_id && get_post_type( $product_id ) === 'product' ) {
		return $product_id;
	}

	if ( $price <= 0 || $seats <= 0 ) {
		return 0;
	}

	$product = new WC_Product_Simple();
	$product->set_name( $post->post_title );
	$product->set_status( 'publish' );
	$product->set_slug( get_post_field( 'post_name', $post_id ) . '-tasting' );
	$product->set_regular_price( (string) $price );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $seats );
	$product->set_stock_status( 'instock' );
	$product->add_meta_data( '_omef_tasting_id', $post_id );

	$product_id = $product->save();
	if ( $product_id ) {
		update_post_meta( $post_id, '_omef_tasting_product_id', $product_id );
	}

	return $product_id;
}

function omef_sync_episode_products( int $episode_id, array $previous_ids, array $current_ids ): void {
	foreach ( array_diff( $previous_ids, $current_ids ) as $product_id ) {
		$episode_ids = array_diff( omef_sanitize_episode_ids( get_post_meta( $product_id, '_omef_episode_ids', true ) ), array( $episode_id ) );
		update_post_meta( $product_id, '_omef_episode_ids', array_values( $episode_ids ) );
	}

	foreach ( array_diff( $current_ids, $previous_ids ) as $product_id ) {
		$episode_ids = omef_sanitize_episode_ids( get_post_meta( $product_id, '_omef_episode_ids', true ) );
		$episode_ids[] = $episode_id;
		update_post_meta( $product_id, '_omef_episode_ids', array_values( array_unique( $episode_ids ) ) );
	}
}

function omef_product_facts( int $product_id ): array {
	$fields = array(
		'_omef_distillery' => 'מזקקה',
		'_omef_region'     => 'אזור',
		'_omef_age'        => 'גיל',
		'_omef_abv'        => 'אחוז אלכוהול',
		'_omef_cask_type'  => 'סוג חבית',
	);
	$facts = array();

	foreach ( $fields as $key => $label ) {
		$value = get_post_meta( $product_id, $key, true );
		if ( $value === '' ) {
			continue;
		}

		if ( $key === '_omef_age' ) {
			$value .= ' שנים';
		}

		if ( $key === '_omef_abv' ) {
			$value .= '%';
		}

		$facts[] = array( 'label' => $label, 'value' => $value );
	}

	if ( get_post_meta( $product_id, '_omef_peated', true ) ) {
		$facts[] = array( 'label' => 'אופי', 'value' => 'מעושן' );
	}

	return $facts;
}

function omef_render_product_facts(): void {
	global $product;
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$facts = omef_product_facts( $product->get_id() );
	$notes = get_post_meta( $product->get_id(), '_omef_tasting_notes', true );
	if ( ! $facts && ! $notes ) {
		return;
	}

	echo '<section class="omef-product-facts" aria-labelledby="omef-product-facts-heading">';
	echo '<h2 id="omef-product-facts-heading">פרטי הבקבוק</h2>';
	if ( $facts ) {
		echo '<dl>';
		foreach ( $facts as $fact ) {
			echo '<dt>' . esc_html( $fact['label'] ) . '</dt><dd>' . esc_html( $fact['value'] ) . '</dd>';
		}
		echo '</dl>';
	}

	if ( $notes ) {
		echo '<p class="omef-product-notes">' . nl2br( esc_html( $notes ) ) . '</p>';
	}
	echo '</section>';
}
add_action( 'woocommerce_single_product_summary', 'omef_render_product_facts', 25 );

function omef_render_product_card_facts(): void {
	global $product;
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$facts = omef_product_facts( $product->get_id() );
	if ( ! $facts ) {
		return;
	}

	$values = array_map( static fn( array $fact ): string => $fact['value'], array_slice( $facts, 0, 3 ) );
	echo '<p class="omef-product-card-facts">' . esc_html( implode( ' · ', $values ) ) . '</p>';
}
add_action( 'woocommerce_after_shop_loop_item_title', 'omef_render_product_card_facts', 7 );

function omef_render_product_episodes(): void {
	global $product;
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$episode_ids = omef_sanitize_episode_ids( get_post_meta( $product->get_id(), '_omef_episode_ids', true ) );
	if ( ! $episode_ids ) {
		return;
	}

	echo '<section class="omef-product-episodes"><h2>שמעתם עליו בפרק?</h2><ul>';
	foreach ( $episode_ids as $episode_id ) {
		echo '<li><a href="' . esc_url( get_permalink( $episode_id ) ) . '">' . esc_html( get_the_title( $episode_id ) ) . '</a></li>';
	}
	echo '</ul></section>';
}
add_action( 'woocommerce_after_single_product_summary', 'omef_render_product_episodes', 5 );

function omef_append_episode_products( string $content ): string {
	if ( ! is_singular( 'omef_episode' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$product_ids = omef_sanitize_product_ids( get_post_meta( get_the_ID(), '_omef_episode_products', true ) );
	if ( ! $product_ids ) {
		return $content;
	}

	$products = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'post__in'       => $product_ids,
			'orderby'        => 'post__in',
			'posts_per_page' => count( $product_ids ),
		)
	);
	if ( ! $products ) {
		return $content;
	}

	$list = '<section class="omef-episode-products"><h2>הבקבוקים מהפרק</h2><ul>';
	foreach ( $products as $product ) {
		$list .= '<li><a href="' . esc_url( get_permalink( $product ) ) . '">' . esc_html( get_the_title( $product ) ) . '</a></li>';
	}
	$list .= '</ul></section>';

	return $content . $list;
}
add_filter( 'the_content', 'omef_append_episode_products' );

function omef_prepend_tasting_details( string $content ): string {
	if ( ! is_singular( 'omef_tasting' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$post_id = get_the_ID();
	$date = get_post_meta( $post_id, '_omef_tasting_date', true );
	$venue = get_post_meta( $post_id, '_omef_tasting_venue', true );
	$product_id = absint( get_post_meta( $post_id, '_omef_tasting_product_id', true ) );

	$details = array();
	if ( $date ) {
		$details[] = '<span><strong>תאריך ושעה:</strong> ' . esc_html( $date ) . '</span>';
	}
	if ( $venue ) {
		$details[] = '<span><strong>מיקום:</strong> ' . esc_html( $venue ) . '</span>';
	}
	if ( $product_id && function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$details[] = '<span><strong>מחיר:</strong> ' . wp_kses_post( $product->get_price_html() ) . '</span>';
			$stock = $product->get_stock_quantity();
			if ( $stock !== null ) {
				$details[] = '<span><strong>מקומות:</strong> ' . esc_html( (string) $stock ) . '</span>';
			}
		}
	}

	$html = '<section class="omef-tasting-details"><dl>';
	foreach ( $details as $detail ) {
		$html .= '<div>' . $detail . '</div>';
	}
	$html .= '</dl>';
	if ( $product_id ) {
		$html .= '<p><a class="wp-block-button__link wp-element-button wp-element-button" href="' . esc_url( get_permalink( $product_id ) ) . '">הרשמה לטעימה</a></p>';
	}
	$html .= '</section>';

	return $html . $content;
}
add_filter( 'the_content', 'omef_prepend_tasting_details', 9 );

function omef_output_episode_schema(): void {
	if ( ! is_singular( 'omef_episode' ) ) {
		return;
	}

	$episode = get_queried_object();
	if ( ! $episode instanceof WP_Post ) {
		return;
	}

	$description = get_the_excerpt( $episode );
	if ( ! $description ) {
		$description = wp_trim_words( wp_strip_all_tags( $episode->post_content ), 40 );
	}

	$schema = array_filter(
		array(
			'@context'      => 'https://schema.org',
			'@type'         => 'PodcastEpisode',
			'name'          => get_the_title( $episode ),
			'description'   => $description,
			'url'           => get_permalink( $episode ),
			'datePublished' => get_post_time( DATE_W3C, true, $episode ),
			'episodeNumber' => absint( get_post_meta( $episode->ID, '_omef_episode_number', true ) ) ?: null,
			'identifier'    => get_post_meta( $episode->ID, '_omef_spotify_id', true ) ?: null,
		)
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
}
add_action( 'wp_head', 'omef_output_episode_schema' );

function omef_register_podcast_settings(): void {
	register_setting(
		'omef_podcast',
		'omef_podcast_feed_url',
		array(
			'sanitize_callback' => 'omef_sanitize_feed_url',
		)
	);
}
add_action( 'admin_init', 'omef_register_podcast_settings' );

function omef_sanitize_feed_url( string $url ): string {
	$url = esc_url_raw( trim( $url ) );
	return str_starts_with( $url, 'https://' ) ? $url : '';
}

function omef_add_podcast_import_page(): void {
	add_submenu_page(
		'edit.php?post_type=omef_episode',
		'ייבוא פרקים',
		'ייבוא פרקים',
		'edit_posts',
		'omef-podcast-import',
		'omef_render_podcast_import_page'
	);
}
add_action( 'admin_menu', 'omef_add_podcast_import_page' );

function omef_render_podcast_import_page(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$last_import = get_option( 'omef_podcast_last_import', array() );
	$notice = get_transient( 'omef_podcast_import_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'omef_podcast_import_notice_' . get_current_user_id() );
	}
	?>
	<div class="wrap">
		<h1>ייבוא פרקים מהפיד</h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<p>הייבוא יוצר פרקים כטיוטות בלבד. טקסט שכתבתם בפרק ובקבוקים שבחרתם אינם משתנים בייבוא חוזר.</p>
		<form action="options.php" method="post">
			<?php settings_fields( 'omef_podcast' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="omef_podcast_feed_url">כתובת RSS של Spotify</label></th>
					<td><input class="regular-text code" id="omef_podcast_feed_url" name="omef_podcast_feed_url" type="url" value="<?php echo esc_attr( get_option( 'omef_podcast_feed_url', '' ) ); ?>" placeholder="https://..."></td>
				</tr>
			</table>
			<?php submit_button( 'שמירת כתובת הפיד' ); ?>
		</form>
		<hr>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input name="action" type="hidden" value="omef_import_podcast">
			<?php wp_nonce_field( 'omef_import_podcast' ); ?>
			<?php submit_button( 'ייבוא עכשיו', 'primary', 'submit', false, array( 'disabled' => ! get_option( 'omef_podcast_feed_url' ) ) ); ?>
		</form>
		<?php if ( $last_import ) : ?>
			<p><strong>ייבוא אחרון:</strong> <?php echo esc_html( $last_import['message'] ?? '' ); ?> <?php echo esc_html( $last_import['time'] ?? '' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

function omef_import_podcast_admin_action(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( 'אין הרשאה לייבא פרקים.' );
	}

	check_admin_referer( 'omef_import_podcast' );
	$result = omef_import_podcast_feed();
	$notice = is_wp_error( $result ) ? $result->get_error_message() : sprintf( 'הייבוא הסתיים: %d פרקים חדשים, %d פרקים עודכנו.', $result['created'], $result['updated'] );
	set_transient( 'omef_podcast_import_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'edit.php?post_type=omef_episode&page=omef-podcast-import' ) );
	exit;
}
add_action( 'admin_post_omef_import_podcast', 'omef_import_podcast_admin_action' );

function omef_import_podcast_feed() {
	$feed_url = get_option( 'omef_podcast_feed_url', '' );
	if ( ! $feed_url ) {
		return new WP_Error( 'omef_missing_feed', 'יש לשמור כתובת RSS לפני הייבוא.' );
	}

	require_once ABSPATH . WPINC . '/feed.php';
	$feed = fetch_feed( $feed_url );
	if ( is_wp_error( $feed ) ) {
		omef_record_podcast_import( $feed->get_error_message() );
		return $feed;
	}

	$result = array( 'created' => 0, 'updated' => 0 );
	foreach ( $feed->get_items( 0, 100 ) as $item ) {
		$guid = (string) $item->get_id();
		if ( ! $guid ) {
			continue;
		}

		$existing = get_posts(
			array(
				'post_type'      => 'omef_episode',
				'post_status'    => 'any',
				'meta_key'       => '_omef_feed_guid',
				'meta_value'     => $guid,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$title = wp_strip_all_tags( (string) $item->get_title() );
		$excerpt = wp_trim_words( wp_strip_all_tags( (string) $item->get_description() ), 55 );
		$post_data = array(
			'post_type'    => 'omef_episode',
			'post_title'   => $title ?: 'פרק ללא כותרת',
			'post_excerpt' => $excerpt,
		);

		if ( $existing ) {
			$post_id = (int) $existing[0];
			if ( get_post_status( $post_id ) === 'draft' ) {
				$post_data['ID'] = $post_id;
				wp_update_post( $post_data );
				++$result['updated'];
			}
		} else {
			$post_data['post_status'] = 'draft';
			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			update_post_meta( $post_id, '_omef_feed_guid', $guid );
			++$result['created'];
		}

		if ( ! empty( $post_id ) ) {
			$link = (string) $item->get_permalink();
			if ( preg_match( '~episode/([A-Za-z0-9]+)~', $link, $matches ) ) {
				update_post_meta( $post_id, '_omef_spotify_id', $matches[1] );
			}
		}
	}

	omef_record_podcast_import( sprintf( '%d פרקים חדשים, %d פרקים עודכנו.', $result['created'], $result['updated'] ) );
	return $result;
}

function omef_record_podcast_import( string $message ): void {
	update_option(
		'omef_podcast_last_import',
		array(
			'message' => $message,
			'time'    => current_time( 'mysql' ),
		),
		false
	);
}

function omef_schedule_podcast_import(): void {
	if ( ! get_option( 'omef_podcast_feed_url' ) || ! function_exists( 'as_schedule_recurring_action' ) || as_next_scheduled_action( 'omef_import_podcast_feed', array(), 'omef' ) ) {
		return;
	}

	as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'omef_import_podcast_feed', array(), 'omef' );
}
add_action( 'init', 'omef_schedule_podcast_import', 30 );
add_action( 'omef_import_podcast_feed', 'omef_import_podcast_feed' );

function omef_is_shop_manager( ?int $user_id = null ): bool {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	return $user instanceof WP_User && in_array( 'shop_manager', (array) $user->roles, true );
}

function omef_add_shop_manager_dashboard(): void {
	if ( ! omef_is_shop_manager() ) {
		return;
	}

	add_menu_page( 'חוזק חבית', 'חוזק חבית', 'read', 'omef-dashboard', 'omef_render_shop_manager_dashboard', 'dashicons-admin-home', 2 );
}
add_action( 'admin_menu', 'omef_add_shop_manager_dashboard' );

function omef_render_shop_manager_dashboard(): void {
	if ( ! omef_is_shop_manager() ) {
		wp_die( 'אין הרשאה לצפות בעמוד זה.' );
	}

	$order_count = 0;
	$low_stock = array();
	if ( function_exists( 'wc_get_orders' ) ) {
		$today_orders = wc_get_orders(
			array(
				'limit'        => 50,
				'date_created' => '>=' . current_time( 'Y-m-d' ),
				'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
			)
		);
		$order_count = count( $today_orders );

		foreach ( wc_get_products( array( 'limit' => 50, 'manage_stock' => true, 'stock_status' => 'instock' ) ) as $product ) {
			$quantity = $product->get_stock_quantity();
			if ( $quantity !== null && $quantity <= $product->get_low_stock_amount() ) {
				$low_stock[] = $product;
			}
		}
	}

	$next_tasting = get_posts(
		array(
			'post_type'      => 'omef_tasting',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_key'       => '_omef_tasting_date',
			'meta_value'     => current_time( 'Y-m-d\TH:i' ),
			'meta_compare'   => '>=',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
		)
	);
	?>
	<div class="wrap omef-admin-dashboard">
		<h1>בוקר טוב, <?php echo esc_html( wp_get_current_user()->display_name ); ?></h1>
		<div class="omef-admin-grid">
			<section class="omef-admin-card">
				<h2>הזמנות היום</h2>
				<p class="omef-admin-number"><?php echo esc_html( (string) $order_count ); ?></p>
				<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>">לכל ההזמנות</a></p>
			</section>
			<section class="omef-admin-card">
				<h2>מלאי נמוך</h2>
				<?php if ( $low_stock ) : ?>
					<ul><?php foreach ( $low_stock as $product ) : ?><li><a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a> (<?php echo esc_html( (string) $product->get_stock_quantity() ); ?>)</li><?php endforeach; ?></ul>
				<?php else : ?>
					<p>אין מוצרים במלאי נמוך.</p>
				<?php endif; ?>
			</section>
			<section class="omef-admin-card">
				<h2>הטעימה הבאה</h2>
				<?php if ( $next_tasting ) : ?>
					<p><a href="<?php echo esc_url( get_edit_post_link( $next_tasting[0]->ID ) ); ?>"><?php echo esc_html( $next_tasting[0]->post_title ); ?></a></p>
					<p><?php echo esc_html( get_post_meta( $next_tasting[0]->ID, '_omef_tasting_date', true ) ); ?></p>
				<?php else : ?>
					<p>אין טעימה פתוחה מתוכננת.</p>
				<?php endif; ?>
			</section>
			<section class="omef-admin-card">
				<h2>פעולה מהירה</h2>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=omef_episode' ) ); ?>">הוספת פרק</a></p>
			</section>
		</div>
	</div>
	<?php
}

function omef_strip_shop_manager_navigation(): void {
	if ( ! omef_is_shop_manager() ) {
		return;
	}

	foreach ( array( 'index.php', 'edit.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'users.php', 'tools.php', 'options-general.php' ) as $menu_slug ) {
		remove_menu_page( $menu_slug );
	}

	foreach ( array( 'wc-admin&path=/analytics/overview', 'wc-admin&path=/marketing', 'wc-admin&path=/extensions' ) as $submenu_slug ) {
		remove_submenu_page( 'woocommerce', $submenu_slug );
	}
}
add_action( 'admin_menu', 'omef_strip_shop_manager_navigation', 999 );

function omef_restrict_shop_manager_capabilities( array $caps, string $cap, int $user_id ): array {
	if ( ! omef_is_shop_manager( $user_id ) ) {
		return $caps;
	}

	$restricted_caps = array(
		'activate_plugins', 'create_users', 'delete_plugins', 'delete_themes', 'delete_users', 'edit_plugins', 'edit_theme_options', 'edit_themes', 'edit_users', 'export', 'import', 'install_plugins', 'list_users', 'manage_options', 'promote_users', 'switch_themes', 'update_core', 'update_plugins', 'update_themes',
	);

	return in_array( $cap, $restricted_caps, true ) ? array( 'do_not_allow' ) : $caps;
}
add_filter( 'map_meta_cap', 'omef_restrict_shop_manager_capabilities', 10, 3 );

function omef_shop_manager_admin_styles(): void {
	if ( ! omef_is_shop_manager() ) {
		return;
	}

	echo '<style>.omef-admin-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));max-width:980px}.omef-admin-card{background:#fff;border:1px solid #dcdcde;padding:20px}.omef-admin-card h2{margin-top:0}.omef-admin-number{font-size:3rem;font-weight:700;margin:0}</style>';
}
add_action( 'admin_head', 'omef_shop_manager_admin_styles' );

function omef_age_gate_required(): bool {
	return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! defined( 'REST_REQUEST' ) && empty( $_COOKIE['omef_age_verified'] );
}

function omef_handle_age_gate(): void {
	if ( ! omef_age_gate_required() ) {
		return;
	}

	$age_gate_error = false;
	if ( isset( $_POST['omef_age_gate'] ) ) {
		$nonce = isset( $_POST['omef_age_gate_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['omef_age_gate_nonce'] ) ) : '';
		if ( wp_verify_nonce( $nonce, 'omef_age_gate' ) && isset( $_POST['omef_confirmed_18'] ) ) {
			setcookie(
				'omef_age_verified',
				'yes',
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ?: '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE['omef_age_verified'] = 'yes';
			$redirect = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : home_url( '/' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$age_gate_error = true;
	}

	nocache_headers();
	$template = get_theme_file_path( 'age-gate.php' );
	if ( file_exists( $template ) ) {
		require $template;
	} else {
		wp_die( 'הכניסה לאתר היא מגיל 18 ומעלה.' );
	}
	exit;
}
add_action( 'template_redirect', 'omef_handle_age_gate', 0 );

function omef_render_alcohol_notice(): void {
	echo '<p class="omef-alcohol-notice" role="note">מכירת אלכוהול מגיל 18 בלבד. יש לצרוך באחריות.</p>';
}
add_action( 'woocommerce_before_single_product', 'omef_render_alcohol_notice', 5 );
add_action( 'woocommerce_before_checkout_form', 'omef_render_alcohol_notice', 5 );

function omef_activate(): void {
	omef_register_content_types();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'omef_activate' );

function omef_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'omef_deactivate' );

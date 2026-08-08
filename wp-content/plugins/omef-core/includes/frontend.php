<?php
/**
 * Storefront rendering: product facts, episode/tasting links, schema and notice.
 */

defined( 'ABSPATH' ) || exit;

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

function omef_render_alcohol_notice(): void {
	echo '<p class="omef-alcohol-notice" role="note">מכירת אלכוהול מגיל 18 בלבד. יש לצרוך באחריות.</p>';
}
add_action( 'woocommerce_before_single_product', 'omef_render_alcohol_notice', 5 );
add_action( 'woocommerce_before_checkout_form', 'omef_render_alcohol_notice', 5 );


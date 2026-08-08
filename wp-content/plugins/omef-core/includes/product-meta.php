<?php
/**
 * Admin metaboxes, saving and product/linked-item sync.
 */

defined( 'ABSPATH' ) || exit;

function omef_add_meta_boxes(): void {
	add_meta_box( 'omef_product_details', 'פרטי וויסקי', 'omef_render_fields_box', 'product', 'normal', 'high' );
	add_meta_box( 'omef_episode_details', 'פרטי הפרק', 'omef_render_fields_box', 'omef_episode', 'normal', 'high' );
	add_meta_box( 'omef_workshop_details', 'פרטי הסדנה', 'omef_render_fields_box', 'omef_workshop', 'normal', 'high' );
	add_meta_box( 'omef_tasting_details', 'פרטי הטעימה', 'omef_render_fields_box', 'omef_tasting', 'normal', 'high' );

	add_meta_box( 'omef_episode_products', 'בקבוקים מהפרק', 'omef_render_episode_products_box', 'omef_episode', 'side', 'default' );
	add_meta_box( 'omef_tasting_product', 'מוצר WooCommerce מקושר', 'omef_render_tasting_product_box', 'omef_tasting', 'side', 'default' );
	add_meta_box( 'omef_workshop_product', 'כרטיסים ומוצר מקושר', 'omef_render_workshop_product_box', 'omef_workshop', 'side', 'default' );
	add_meta_box( 'omef_product_episodes', 'פרקים קשורים', 'omef_render_product_episodes_box', 'product', 'side', 'default' );
}
add_action( 'add_meta_boxes', 'omef_add_meta_boxes' );

function omef_render_fields_box( WP_Post $post ): void {
	wp_nonce_field( 'omef_save_meta', 'omef_meta_nonce' );
	$fields = omef_fields()[ $post->post_type ] ?? array();

	foreach ( $fields as $key => $field ) {
		$value       = get_post_meta( $post->ID, $key, true );
		$placeholder = $field['placeholder'] ?? '';
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $field['label'] ) . '</strong></label><br>';

		if ( $field['type'] === 'textarea' ) {
			echo '<textarea class="widefat" rows="4" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $value ) . '</textarea>';
		} elseif ( $field['type'] === 'boolean' ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( (bool) $value, true, false ) . '> כן</label>';
		} else {
			$type = $field['type'] === 'integer' || $field['type'] === 'decimal' ? 'number' : ( $field['type'] === 'datetime' ? 'datetime-local' : 'text' );
			$step = $field['type'] === 'decimal' ? ' step="0.01" min="0"' : '';
			echo '<input class="widefat" type="' . esc_attr( $type ) . '"' . $step . ' id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
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

function omef_render_workshop_product_box( WP_Post $post ): void {
	wp_nonce_field( 'omef_save_meta', 'omef_meta_nonce' );
	$current  = absint( get_post_meta( $post->ID, '_omef_workshop_product_id', true ) );
	$products = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );

	echo '<p>עם מחיר לכרטיס ומספר מקומות, מוצר הזמנות נוצר אוטומטית. מחיר, מלאי והזמנות מנוהלים במוצר WooCommerce.</p><select class="widefat" name="_omef_workshop_product_id"><option value="">בחירת מוצר</option>';
	foreach ( $products as $product ) {
		echo '<option value="' . esc_attr( $product->ID ) . '" ' . selected( $product->ID, $current, false ) . '>' . esc_html( $product->post_title ) . '</option>';
	}
	echo '</select>';

	if ( $current ) {
		echo '<p style="margin-top:10px"><a href="' . esc_url( get_edit_post_link( $current ) ) . '">עריכת המוצר ↗</a></p>';
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

	if ( $post->post_type === 'product' && function_exists( 'wc_get_product' ) ) {
		omef_ensure_sample_variations( $post_id, (float) get_post_meta( $post_id, '_omef_sample_price', true ) );
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

	if ( $post->post_type === 'omef_workshop' ) {
		$product_id = absint( $_POST['_omef_workshop_product_id'] ?? 0 );
		update_post_meta( $post_id, '_omef_workshop_product_id', get_post_type( $product_id ) === 'product' ? $product_id : 0 );

		$price = (float) get_post_meta( $post_id, '_omef_workshop_price', true );
		$seats = (int) get_post_meta( $post_id, '_omef_workshop_seats', true );
		omef_ensure_workshop_product( $post_id, $post, $price, $seats );
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

function omef_ensure_workshop_product( int $post_id, WP_Post $post, float $price, int $seats ): int {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return 0;
	}

	$product_id = absint( get_post_meta( $post_id, '_omef_workshop_product_id', true ) );
	if ( $product_id && get_post_type( $product_id ) === 'product' ) {
		return $product_id;
	}

	if ( $price <= 0 || $seats <= 0 ) {
		return 0;
	}

	$product = new WC_Product_Simple();
	$product->set_name( $post->post_title );
	$product->set_status( 'publish' );
	$product->set_slug( get_post_field( 'post_name', $post_id ) . '-workshop' );
	$product->set_regular_price( (string) $price );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $seats );
	$product->set_stock_status( 'instock' );
	$product->add_meta_data( '_omef_workshop_id', $post_id );

	$product_id = $product->save();
	if ( $product_id ) {
		update_post_meta( $post_id, '_omef_workshop_product_id', $product_id );
	}

	return $product_id;
}

function omef_sample_attribute_labels(): array {
	return array(
		'attribute' => 'גודל דגימה',
		'full'      => 'בקבוק מלא (700 מ"ל)',
		'sample'    => 'דגימה (30 מ"ל)',
	);
}

function omef_ensure_sample_variations( int $product_id, float $sample_price ): void {
	static $in_progress = array();
	if ( $sample_price <= 0 || ! function_exists( 'wc_get_product' ) || isset( $in_progress[ $product_id ] ) ) {
		return;
	}

	$product = wc_get_product( $product_id );
	if ( ! $product || $product->is_type( 'variation' ) ) {
		return;
	}

	$in_progress[ $product_id ] = true;

	$labels          = omef_sample_attribute_labels();
	$attribute_name  = $labels['attribute'];
	$full_label      = $labels['full'];
	$sample_label    = $labels['sample'];

	$base_price = (float) $product->get_regular_price( 'edit' );
	$base_stock = $product->get_stock_quantity( 'edit' );

	if ( $product->is_type( 'simple' ) ) {
		update_post_meta( $product_id, '_omef_full_price', $base_price );
		wp_set_object_terms( $product_id, 'variable', 'product_type' );
		$product = new WC_Product_Variable( $product_id );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( $attribute_name );
		$attribute->set_options( array( $full_label, $sample_label ) );
		$attribute->set_position( 10 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		wc_delete_product_transients( $product_id );
	}

	foreach ( $product->get_children() as $variation_id ) {
		wp_delete_post( $variation_id, true );
	}

	$base_price = (float) get_post_meta( $product_id, '_omef_full_price', true );
	if ( ! $base_price ) {
		$base_price = (float) $product->get_regular_price( 'edit' );
	}

	omef_create_sample_variation( $product_id, $attribute_name, $full_label, (float) $base_price, $base_stock, true );
	omef_create_sample_variation( $product_id, $attribute_name, $sample_label, $sample_price, null, false );

	unset( $in_progress[ $product_id ] );
}

function omef_create_sample_variation( int $parent_id, string $attribute_name, string $option_name, float $price, ?int $stock, bool $manage_stock ): void {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_attributes( array( sanitize_title( $attribute_name ) => $option_name ) );
	$variation->set_regular_price( (string) $price );

	if ( $manage_stock && is_numeric( $stock ) ) {
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( (int) $stock );
		$variation->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
	} else {
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'instock' );
	}

	$variation->save();
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


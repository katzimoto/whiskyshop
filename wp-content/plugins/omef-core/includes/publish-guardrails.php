<?php
/**
 * Block publishing products missing required fields.
 */

defined( 'ABSPATH' ) || exit;

function omef_validate_product_publish( array $data, array $postarr ): array {
	if ( ( $data['post_type'] ?? '' ) !== 'product' || ( $data['post_status'] ?? '' ) !== 'publish' ) {
		return $data;
	}

	if ( ( $postarr['action'] ?? '' ) === 'trash' ) {
		return $data;
	}

	$errors = array();

	$thumbnail_id = absint( $postarr['_thumbnail_id'] ?? get_post_thumbnail_id( (int) ( $postarr['ID'] ?? 0 ) ) );
	if ( ! $thumbnail_id ) {
		$errors[] = 'תמונה ראשית';
	} elseif ( ! trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) ) ) {
		$errors[] = 'טקסט חלופי (ALT) לתמונה הראשית';
	}

	$is_variable = ( $postarr['product_type'] ?? '' ) === 'variable';
	if ( ! $is_variable ) {
		$price = wc_format_decimal( (string) ( $postarr['_regular_price'] ?? '' ) );
		if ( (float) $price <= 0 ) {
			$errors[] = 'מחיר';
		}
	}

	$manage_stock = ! empty( $postarr['_manage_stock'] );
	$stock = $postarr['_stock'] ?? '';
	if ( $manage_stock && $stock !== null && trim( (string) $stock ) === '' ) {
		$errors[] = 'סטטוס מלאי';
	}

	if ( $errors ) {
		update_option( 'omef_publish_error_' . get_current_user_id(), implode( ', ', $errors ), false );
		$data['post_status'] = 'draft';
	}

	return $data;
}
add_filter( 'wp_insert_post_data', 'omef_validate_product_publish', 10, 2 );

function omef_show_publish_guardrail_notice(): void {
	$key = 'omef_publish_error_' . get_current_user_id();
	$message = get_option( $key );
	if ( ! $message ) {
		return;
	}
	delete_option( $key );
	echo '<div class="notice notice-error is-dismissible"><p>המוצר נשאר בטיוטה: נדרשים ' . esc_html( $message ) . '.</p></div>';
}
add_action( 'admin_notices', 'omef_show_publish_guardrail_notice' );


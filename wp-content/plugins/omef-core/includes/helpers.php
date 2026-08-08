<?php
/**
 * Shared sanitizers used across modules.
 */

defined( 'ABSPATH' ) || exit;

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


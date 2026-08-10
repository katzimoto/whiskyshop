<?php
/**
 * Product management for the Omef dashboard: list/get/save/delete over AJAX,
 * reusing WooCommerce's own CRUD and omef-core's existing whisky-field and
 * sample-price/variation logic rather than re-implementing them.
 */

defined( 'ABSPATH' ) || exit;

function omef_admin_find_full_bottle_variation( WC_Product $product ): ?WC_Product_Variation {
	if ( ! $product->is_type( 'variable' ) ) {
		return null;
	}

	$labels        = omef_sample_attribute_labels();
	$attribute_key = sanitize_title( $labels['attribute'] );

	foreach ( $product->get_children() as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof WC_Product_Variation ) {
			continue;
		}
		$attributes = $variation->get_attributes();
		if ( ( $attributes[ $attribute_key ] ?? '' ) === $labels['full'] ) {
			return $variation;
		}
	}

	return null;
}

function omef_admin_effective_price_and_stock( WC_Product $product ): array {
	$full_variation = omef_admin_find_full_bottle_variation( $product );

	if ( $full_variation ) {
		return array(
			'regularPrice'  => (float) get_post_meta( $product->get_id(), '_omef_full_price', true ),
			'manageStock'   => $full_variation->get_manage_stock( 'edit' ),
			'stockQuantity' => $full_variation->get_stock_quantity( 'edit' ),
			'stockStatus'   => $full_variation->get_stock_status( 'edit' ),
		);
	}

	return array(
		'regularPrice'  => (float) $product->get_regular_price( 'edit' ),
		'manageStock'   => $product->get_manage_stock( 'edit' ),
		'stockQuantity' => $product->get_stock_quantity( 'edit' ),
		'stockStatus'   => $product->get_stock_status( 'edit' ),
	);
}

/**
 * Writes submitted stock onto the "full bottle" variation after
 * omef_ensure_sample_variations() has (re)created it. That helper only knows
 * how to copy stock from the parent, which is meaningless once a product is
 * already variable, so this fills the gap for edits made after the first
 * conversion.
 */
function omef_admin_sync_full_bottle_stock( int $product_id, bool $manage_stock, ?int $quantity, string $stock_status ): void {
	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product_Variable ) {
		return;
	}

	$variation = omef_admin_find_full_bottle_variation( $product );
	if ( ! $variation ) {
		return;
	}

	$variation->set_manage_stock( $manage_stock );
	if ( $manage_stock ) {
		$variation->set_stock_quantity( $quantity ?? 0 );
		$variation->set_stock_status( ( $quantity ?? 0 ) > 0 ? 'instock' : 'outofstock' );
	} else {
		$variation->set_stock_status( $stock_status ?: 'instock' );
	}
	$variation->save();

	if ( class_exists( 'WC_Product_Variable' ) ) {
		WC_Product_Variable::sync( $product_id );
	}
	wc_delete_product_transients( $product_id );
}

function omef_admin_shape_product_summary( WC_Product $product ): array {
	$discount = omef_discount( $product->get_id() );
	$effective = omef_admin_effective_price_and_stock( $product );
	$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );

	return array(
		'id'            => $product->get_id(),
		'title'         => $product->get_name(),
		'image'         => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: '',
		'priceHtml'     => wp_kses_post( $product->get_price_html() ),
		'regularPrice'  => $effective['regularPrice'],
		'manageStock'   => $effective['manageStock'],
		'stockStatus'   => $effective['stockStatus'],
		'stockQuantity' => $effective['stockQuantity'],
		'categories'    => is_wp_error( $categories ) ? array() : $categories,
		'hasSample'     => (bool) get_post_meta( $product->get_id(), '_omef_sample_price', true ),
		'onSale'        => (bool) $discount,
		'saleNote'      => $discount['note'] ?? '',
		'status'        => $product->get_status(),
		'editUrl'       => '#/products/' . $product->get_id(),
	);
}

function omef_products_list(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$page     = max( 1, absint( $_GET['page'] ?? 1 ) );
	$per_page = 24;

	$args = array(
		'status'   => array( 'publish', 'draft' ),
		'limit'    => $per_page,
		'page'     => $page,
		'paginate' => true,
		'orderby'  => 'title',
		'order'    => 'ASC',
	);

	$search = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
	if ( $search !== '' ) {
		$args['s'] = $search;
	}

	$stock_status = sanitize_key( $_GET['stock_status'] ?? '' );
	if ( in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
		$args['stock_status'] = $stock_status;
	}

	$category = absint( $_GET['category'] ?? 0 );
	if ( $category ) {
		$args['category'] = array( $category );
	}

	if ( ( $_GET['on_sale'] ?? '' ) === '1' ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => '_omef_sale_price',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'DECIMAL',
			),
		);
	}

	$result   = wc_get_products( $args );
	$products = array_map( 'omef_admin_shape_product_summary', $result->products );

	wp_send_json_success(
		array(
			'products'   => $products,
			'page'       => $page,
			'totalPages' => max( 1, (int) $result->max_num_pages ),
			'total'      => (int) $result->total,
		)
	);
}
add_action( 'wp_ajax_omef_products_list', 'omef_products_list' );

function omef_categories_list(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		)
	);

	$categories = is_wp_error( $terms ) ? array() : array_map(
		static fn( WP_Term $term ): array => array( 'id' => $term->term_id, 'name' => $term->name ),
		$terms
	);

	wp_send_json_success( array( 'categories' => $categories ) );
}
add_action( 'wp_ajax_omef_categories_list', 'omef_categories_list' );

function omef_categories_create(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	if ( $name === '' ) {
		wp_send_json_error( array( 'message' => 'יש להזין שם קטגוריה.' ) );
	}

	$result = wp_insert_term( $name, 'product_cat' );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'id' => $result['term_id'], 'name' => $name ) );
}
add_action( 'wp_ajax_omef_categories_create', 'omef_categories_create' );

function omef_products_get(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id      = absint( $_GET['id'] ?? 0 );
	$product = $id ? wc_get_product( $id ) : null;
	if ( ! $product ) {
		wp_send_json_error( array( 'message' => 'המוצר לא נמצא.' ), 404 );
	}

	$effective  = omef_admin_effective_price_and_stock( $product );
	$is_sample  = (bool) get_post_meta( $id, '_omef_sample_price', true );
	$categories = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );

	$omef_values = array();
	foreach ( omef_fields()['product'] as $key => $field ) {
		$value                = get_post_meta( $id, $key, true );
		$omef_values[ $key ]  = $field['type'] === 'boolean' ? (bool) $value : $value;
	}

	$gallery = array_map(
		static fn( int $attachment_id ): array => array(
			'id'  => $attachment_id,
			'url' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ?: '',
		),
		$product->get_gallery_image_ids()
	);

	wp_send_json_success(
		array(
			'id'                 => $id,
			'title'              => $product->get_name(),
			'description'        => $product->get_description(),
			'shortDescription'   => $product->get_short_description(),
			'imageId'            => $product->get_image_id(),
			'imageUrl'           => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: '',
			'gallery'            => $gallery,
			'categoryIds'        => is_wp_error( $categories ) ? array() : array_map( 'intval', $categories ),
			'regularPrice'       => $effective['regularPrice'],
			'nativeSalePrice'    => $is_sample ? 0 : (float) $product->get_sale_price( 'edit' ),
			'nativeSaleSupported' => ! $is_sample,
			'manageStock'        => $effective['manageStock'],
			'stockQuantity'      => $effective['stockQuantity'],
			'stockStatus'        => $effective['stockStatus'],
			'omef'               => $omef_values,
		)
	);
}
add_action( 'wp_ajax_omef_products_get', 'omef_products_get' );

function omef_products_save(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id    = absint( $_POST['id'] ?? 0 );
	$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
	if ( $title === '' ) {
		wp_send_json_error( array( 'message' => 'יש להזין שם מוצר.' ) );
	}

	$product = $id ? wc_get_product( $id ) : null;
	if ( $id && ! $product ) {
		wp_send_json_error( array( 'message' => 'המוצר לא נמצא.' ), 404 );
	}
	if ( ! $product ) {
		$product = new WC_Product_Simple();
	}

	$product->set_name( $title );
	$product->set_description( wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ) );
	$product->set_short_description( wp_kses_post( wp_unslash( $_POST['short_description'] ?? '' ) ) );
	$product->set_status( 'publish' );

	$category_ids = array_filter( array_map( 'absint', (array) ( $_POST['category_ids'] ?? array() ) ) );
	$product->set_category_ids( $category_ids );

	$image_id = absint( $_POST['image_id'] ?? 0 );
	if ( $image_id ) {
		$product->set_image_id( $image_id );
	}
	$gallery_ids = array_filter( array_map( 'absint', (array) ( $_POST['gallery_ids'] ?? array() ) ) );
	$product->set_gallery_image_ids( $gallery_ids );

	$regular_price = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['regular_price'] ?? '0' ) ) );
	$sample_price  = (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['sample_price'] ?? '0' ) ) );
	$manage_stock  = ! empty( $_POST['manage_stock'] );
	$stock_qty     = isset( $_POST['stock_quantity'] ) && $_POST['stock_quantity'] !== '' ? absint( $_POST['stock_quantity'] ) : null;
	$stock_status  = in_array( $_POST['stock_status'] ?? '', array( 'instock', 'outofstock', 'onbackorder' ), true )
		? sanitize_key( $_POST['stock_status'] )
		: 'instock';

	if ( $sample_price <= 0 ) {
		$product->set_regular_price( $regular_price );
		$native_sale = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['native_sale_price'] ?? '' ) ) );
		$product->set_sale_price( $native_sale !== '' && (float) $native_sale > 0 ? $native_sale : '' );
		$product->set_manage_stock( $manage_stock );
		if ( $manage_stock ) {
			$product->set_stock_quantity( $stock_qty ?? 0 );
		}
		$product->set_stock_status( $manage_stock ? ( ( $stock_qty ?? 0 ) > 0 ? 'instock' : 'outofstock' ) : $stock_status );
	} else {
		// Sample pricing keeps the shop's own price/stock on the "full
		// bottle" variation; the parent just needs to exist and save first.
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
	}

	$product_id = $product->save();
	if ( ! $product_id ) {
		wp_send_json_error( array( 'message' => 'שמירת המוצר נכשלה.' ) );
	}

	foreach ( omef_fields()['product'] as $key => $field ) {
		$posted = $_POST[ $key ] ?? ( $field['type'] === 'boolean' ? false : '' );
		update_post_meta( $product_id, $key, omef_sanitize_value( wp_unslash( $posted ), $field['type'] ) );
	}

	if ( $sample_price > 0 ) {
		update_post_meta( $product_id, '_omef_full_price', (float) $regular_price );
		omef_ensure_sample_variations( $product_id, $sample_price );
		omef_admin_sync_full_bottle_stock( $product_id, $manage_stock, $stock_qty, $stock_status );
	}

	// The publish guardrail (publish-guardrails.php) reads its raw
	// $_POST-style fields from $postarr, which only exist for the classic
	// product edit screen's form submission — never for CRUD/AJAX saves like
	// this one. Re-issue the publish with the same field names it expects,
	// now reflecting the product's true saved state, so the guardrail
	// evaluates it correctly instead of always seeing "missing" fields.
	delete_option( 'omef_publish_error_' . get_current_user_id() );
	$fresh = wc_get_product( $product_id );
	$effective = omef_admin_effective_price_and_stock( $fresh );
	wp_update_post(
		array(
			'ID'             => $product_id,
			'post_status'    => 'publish',
			'product_type'   => $fresh->get_type(),
			'_thumbnail_id'  => $fresh->get_image_id(),
			'_regular_price' => $effective['regularPrice'],
			'_manage_stock'  => $effective['manageStock'] ? '1' : '',
			'_stock'         => $effective['manageStock'] ? $effective['stockQuantity'] : '',
		)
	);

	$warning = get_option( 'omef_publish_error_' . get_current_user_id() );
	if ( $warning ) {
		delete_option( 'omef_publish_error_' . get_current_user_id() );
	}

	$fresh = wc_get_product( $product_id );
	wp_send_json_success(
		array(
			'product' => omef_admin_shape_product_summary( $fresh ),
			'warning' => $warning ? 'המוצר נשמר כטיוטה: נדרשים ' . $warning . '.' : null,
		)
	);
}
add_action( 'wp_ajax_omef_products_save', 'omef_products_save' );

/**
 * Lightweight price/stock update for the product list's inline quick-edit,
 * so the admin doesn't have to open the full edit form just to bump a price
 * or restock count. Mirrors the price/stock branch of omef_products_save()
 * for sample-priced (variable) vs. plain products, leaving every other
 * field untouched.
 */
function omef_products_quick_update(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id      = absint( $_POST['id'] ?? 0 );
	$product = $id ? wc_get_product( $id ) : null;
	if ( ! $product ) {
		wp_send_json_error( array( 'message' => 'המוצר לא נמצא.' ), 404 );
	}

	$regular_price = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['regular_price'] ?? '0' ) ) );
	$has_quantity  = isset( $_POST['stock_quantity'] ) && '' !== trim( wp_unslash( $_POST['stock_quantity'] ) );
	$stock_qty     = $has_quantity ? absint( $_POST['stock_quantity'] ) : null;
	$manage_stock  = $has_quantity;
	$stock_status  = in_array( $_POST['stock_status'] ?? '', array( 'instock', 'outofstock', 'onbackorder' ), true )
		? sanitize_key( $_POST['stock_status'] )
		: 'instock';

	$is_sample = (bool) get_post_meta( $id, '_omef_sample_price', true );

	if ( $is_sample ) {
		update_post_meta( $id, '_omef_full_price', (float) $regular_price );
		omef_ensure_sample_variations( $id, (float) get_post_meta( $id, '_omef_sample_price', true ) );
		omef_admin_sync_full_bottle_stock( $id, $manage_stock, $stock_qty, $stock_status );
	} else {
		$product->set_regular_price( $regular_price );
		$product->set_manage_stock( $manage_stock );
		if ( $manage_stock ) {
			$product->set_stock_quantity( $stock_qty ?? 0 );
			$product->set_stock_status( ( $stock_qty ?? 0 ) > 0 ? 'instock' : 'outofstock' );
		} else {
			$product->set_stock_status( $stock_status );
		}
		$product->save();
	}

	wc_delete_product_transients( $id );

	wp_send_json_success( array( 'product' => omef_admin_shape_product_summary( wc_get_product( $id ) ) ) );
}
add_action( 'wp_ajax_omef_products_quick_update', 'omef_products_quick_update' );

function omef_products_delete(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id      = absint( $_POST['id'] ?? 0 );
	$product = $id ? wc_get_product( $id ) : null;
	if ( ! $product ) {
		wp_send_json_error( array( 'message' => 'המוצר לא נמצא.' ), 404 );
	}

	$product->delete( true );
	wp_send_json_success();
}
add_action( 'wp_ajax_omef_products_delete', 'omef_products_delete' );

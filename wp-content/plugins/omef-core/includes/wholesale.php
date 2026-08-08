<?php
/**
 * Wholesale tier: a dedicated "wholesale" role with a store-wide discount.
 * Discounted prices surface everywhere — cards, product page, range and cart.
 */

defined( 'ABSPATH' ) || exit;

function omef_wholesale_pct(): int {
	return max( 0, min( 60, (int) get_option( 'omef_wholesale_discount', 0 ) ) );
}

function omef_is_wholesale_user( int $user_id = 0 ): bool {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	return $user instanceof WP_User && in_array( 'wholesale', (array) $user->roles, true );
}

function omef_wholesale_multiplier(): float {
	return 1 - ( omef_wholesale_pct() / 100 );
}

function omef_wholesale_price( $price, $product ) {
	if ( ! omef_is_wholesale_user() || ! is_a( $product, 'WC_Product' ) ) {
		return $price;
	}

	$base = $product->get_regular_price( 'edit' );
	if ( ! is_numeric( $base ) || (float) $base <= 0 ) {
		return $price;
	}

	return wc_format_decimal( (float) $base * omef_wholesale_multiplier() );
}
add_filter( 'woocommerce_product_get_price', 'omef_wholesale_price', 99, 2 );
add_filter( 'woocommerce_product_get_regular_price', 'omef_wholesale_price', 99, 2 );
add_filter( 'woocommerce_product_variation_get_price', 'omef_wholesale_price', 99, 2 );
add_filter( 'woocommerce_product_variation_get_regular_price', 'omef_wholesale_price', 99, 2 );

function omef_wholesale_variation_price( $price, $variation ) {
	if ( ! omef_is_wholesale_user() || ! is_a( $variation, 'WC_Product_Variation' ) ) {
		return $price;
	}

	$base = $variation->get_regular_price( 'edit' );
	if ( ! is_numeric( $base ) || (float) $base <= 0 ) {
		return $price;
	}

	return wc_format_decimal( (float) $base * omef_wholesale_multiplier() );
}
add_filter( 'woocommerce_variation_prices_price', 'omef_wholesale_variation_price', 99, 2 );
add_filter( 'woocommerce_variation_prices_regular_price', 'omef_wholesale_variation_price', 99, 2 );

function omef_wholesale_ensure_role(): void {
	add_role(
		'wholesale',
		'סיטונאי',
		array(
			'read'    => true,
			'level_0' => true,
		)
	);
}

function omef_wholesale_menu(): void {
	add_submenu_page(
		'woocommerce',
		'סיטונאות',
		'סיטונאות',
		'manage_options',
		'omef-wholesale',
		'omef_render_wholesale_settings'
	);
}
add_action( 'admin_menu', 'omef_wholesale_menu' );

function omef_wholesale_register_settings(): void {
	register_setting( 'omef_wholesale', 'omef_wholesale_discount', array( 'type' => 'integer', 'sanitize_callback' => static fn( $value ) => max( 0, min( 60, absint( $value ) ) ) ) );
}
add_action( 'admin_init', 'omef_wholesale_register_settings' );

function omef_render_wholesale_settings(): void {
	$wholesale_users = count( get_users( array( 'role' => 'wholesale', 'fields' => 'ID' ) ) );
	?>
	<div class="wrap">
		<h1>סיטונאות</h1>
		<form action="options.php" method="post">
			<?php settings_fields( 'omef_wholesale' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="omef_wholesale_discount">הנחה סיטונאית (%)</label></th>
					<td><input id="omef_wholesale_discount" name="omef_wholesale_discount" type="number" min="0" max="60" value="<?php echo esc_attr( (string) omef_wholesale_pct() ); ?>"><p class="description">0–60%. תחול על כל המוצרים ועל כל משתמש בעל תפקיד סיטונאי.</p></td>
				</tr>
			</table>
			<?php submit_button( 'שמירה' ); ?>
		</form>
		<p>משתמשים פעילים בעלי תפקיד סיטונאי: <strong><?php echo (int) $wholesale_users; ?></strong>. צרו משתמשים חדשים עם תפקיד <code>סיטונאי</code> כדי לאפשר להם רכישות במחיר המונזה.</p>
	</div>
	<?php
}
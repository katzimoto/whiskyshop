<?php
/**
 * Shop manager role dashboard and capability restrictions.
 */

defined( 'ABSPATH' ) || exit;

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


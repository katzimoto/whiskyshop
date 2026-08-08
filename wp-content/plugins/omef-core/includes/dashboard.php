<?php
/**
 * The Omef control panel: a single custom wp-admin page that replaces the
 * scattered Settings/Tools/CPT submenus and WooCommerce's native product and
 * order screens with one branded dashboard. WooCommerce's own CRUD stays the
 * data layer underneath; only the admin UI on top is custom.
 */

defined( 'ABSPATH' ) || exit;

const OMEF_DASHBOARD_SLUG  = 'omef-dashboard';
const OMEF_DASHBOARD_NONCE = 'omef_dashboard';

function omef_dashboard_admin_menu(): void {
	$hook = add_menu_page(
		'לוח הבקרה של אומף',
		'אומף',
		'manage_woocommerce',
		OMEF_DASHBOARD_SLUG,
		'omef_render_dashboard_page',
		'dashicons-store',
		3
	);
	add_action( 'load-' . $hook, 'omef_dashboard_enqueue_assets' );
}
add_action( 'admin_menu', 'omef_dashboard_admin_menu' );

function omef_dashboard_enqueue_assets(): void {
	add_action( 'admin_enqueue_scripts', 'omef_dashboard_register_assets' );
}

function omef_dashboard_register_assets(): void {
	$plugin_file = __DIR__ . '/../omef-core.php';
	$version     = (string) ( filemtime( __DIR__ . '/../assets/dashboard.js' ) ?: '0.1.0' );

	wp_enqueue_media();

	wp_enqueue_style(
		'omef-dashboard',
		plugins_url( 'assets/dashboard.css', $plugin_file ),
		array(),
		$version
	);

	wp_enqueue_script(
		'omef-dashboard',
		plugins_url( 'assets/dashboard.js', $plugin_file ),
		array(),
		$version,
		true
	);

	wp_localize_script(
		'omef-dashboard',
		'omefDashboard',
		array(
			'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
			'nonce'              => wp_create_nonce( OMEF_DASHBOARD_NONCE ),
			'currencySign'       => '₪',
			'wcOrderEditUrlBase' => admin_url( 'post.php?action=edit&post=' ),
			'siteUrl'            => home_url( '/' ),
		)
	);
}

function omef_render_dashboard_page(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה לצפות בלוח הבקרה.' );
	}
	?>
	<div id="omef-app" class="omef-app">
		<div class="omef-app__loading">טוען את לוח הבקרה…</div>
	</div>
	<?php
}

/**
 * Shared AJAX guard: verifies the dashboard nonce and a capability, sending a
 * JSON error and terminating the request when either check fails.
 */
function omef_dashboard_guard( string $capability = 'manage_woocommerce' ): void {
	check_ajax_referer( OMEF_DASHBOARD_NONCE, 'nonce' );
	if ( ! current_user_can( $capability ) ) {
		wp_send_json_error( array( 'message' => 'אין הרשאה מתאימה לפעולה זו.' ), 403 );
	}
}

function omef_dashboard_stats(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$today_orders = wc_get_orders(
		array(
			'status'       => array( 'processing', 'completed', 'on-hold' ),
			'date_created' => '>=' . strtotime( 'today midnight' ),
			'limit'        => -1,
			'return'       => 'ids',
		)
	);
	$today_total = 0.0;
	foreach ( $today_orders as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$today_total += (float) $order->get_total();
		}
	}

	$out_of_stock = wc_get_products(
		array(
			'stock_status' => 'outofstock',
			'limit'        => -1,
			'return'       => 'ids',
		)
	);

	$processing = wc_get_orders(
		array(
			'status' => array( 'processing' ),
			'limit'  => -1,
			'return' => 'ids',
		)
	);

	$backups     = function_exists( 'omef_backup_list' ) ? omef_backup_list() : array();
	$last_backup = $backups ? array_key_first( $backups ) : null;

	$abandoned_pending = 0;
	if ( function_exists( 'omef_abandoned_table' ) ) {
		global $wpdb;
		$table             = omef_abandoned_table();
		$abandoned_pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE created_at < %s AND reminded_at IS NULL AND converted_at IS NULL",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}

	$leads_count = 0;
	if ( function_exists( 'omef_leads_table' ) ) {
		global $wpdb;
		$leads_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . omef_leads_table() );
	}

	$discount_count = count(
		get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_omef_sale_price',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'DECIMAL',
					),
				),
			)
		)
	);

	wp_send_json_success(
		array(
			'todayOrders'      => count( $today_orders ),
			'todayRevenue'     => $today_total,
			'outOfStockCount'  => count( $out_of_stock ),
			'processingCount'  => count( $processing ),
			'lastBackup'       => $last_backup,
			'lastBackupSize'   => $last_backup ? ( $backups[ $last_backup ] ?? 0 ) : 0,
			'abandonedPending' => $abandoned_pending,
			'discountCount'    => $discount_count,
			'leadsCount'       => $leads_count,
		)
	);
}
add_action( 'wp_ajax_omef_stats', 'omef_dashboard_stats' );

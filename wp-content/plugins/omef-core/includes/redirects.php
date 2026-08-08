<?php
/**
 * SEO redirect manager: store owners move old bottle URLs to new paths and
 * keep a clean 301 trail instead of stale 404s.
 */

defined( 'ABSPATH' ) || exit;

function omef_redirect_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'omef_redirects';
}

function omef_redirect_create_table(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = omef_redirect_table();
	dbDelta(
		"CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(191) NOT NULL,
			target VARCHAR(191) NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY source (source)
		) {$wpdb->get_charset_collate()};"
	);
}

function omef_normalize_redirect_source( string $source ): string {
	$path = wp_parse_url( trim( $source ), PHP_URL_PATH );
	if ( $path === null || $path === '' ) {
		return '';
	}
	return strtolower( rtrim( ltrim( $path, '/' ), '/' ) );
}

function omef_current_request_path(): string {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $path, PHP_URL_PATH );
	return strtolower( rtrim( ltrim( (string) $path, '/' ), '/' ) );
}

function omef_apply_redirect(): void {
	global $wpdb;

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	$source = omef_current_request_path();
	if ( $source === '' ) {
		return;
	}

	$target = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT target FROM ' . omef_redirect_table() . ' WHERE active = 1 AND source = %s',
			$source
		)
	);

	if ( $target ) {
		wp_safe_redirect( home_url( '/' . ltrim( $target, '/' ) ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'omef_apply_redirect', 0 );

function omef_redirect_admin_menu(): void {
	add_submenu_page(
		'options-general.php',
		'הפניות (301)',
		'הפניות',
		'manage_options',
		'omef-redirects',
		'omef_render_redirects'
	);
}
add_action( 'admin_menu', 'omef_redirect_admin_menu' );

function omef_render_redirects(): void {
	global $wpdb;

	$notice = get_transient( 'omef_redirect_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'omef_redirect_notice_' . get_current_user_id() );
	}

	$rows = $wpdb->get_results( 'SELECT * FROM ' . omef_redirect_table() . ' ORDER BY id DESC' );
	?>
	<div class="wrap">
		<h1>הפניות (301)</h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input name="action" type="hidden" value="omef_redirect_add">
			<?php wp_nonce_field( 'omef_redirect_add' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="omef_source">נתיב ישן (מקור)</label></th>
					<td><input class="regular-text code" id="omef_source" name="source" type="text" placeholder="old-bottle/"></td>
				</tr>
				<tr>
					<th><label for="omef_target">יעד</label></th>
					<td><input class="regular-text code" id="omef_target" name="target" type="text" placeholder="shop/"></td>
				</tr>
			</table>
			<?php submit_button( 'הוספת הפנייה' ); ?>
		</form>
		<hr>
		<table class="widefat striped">
			<thead><tr><th>מקור</th><th>יעד</th><th>מצב</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="4">אין הפניות.</td></tr>
			<?php endif; ?>
			<?php foreach ( (array) $rows as $row ) : ?>
				<tr>
					<td><code>/<?php echo esc_html( $row->source ); ?></code></td>
					<td><code>/<?php echo esc_html( $row->target ); ?></code></td>
					<td><?php echo $row->active ? 'פעילה' : 'מושבתת'; ?></td>
					<td>
						<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline">
							<input name="action" type="hidden" value="omef_redirect_delete">
							<input name="id" type="hidden" value="<?php echo esc_attr( (string) $row->id ); ?>">
							<?php wp_nonce_field( 'omef_redirect_delete' ); ?>
							<button class="button-link delete">מחיקה</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function omef_redirect_add_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'omef_redirect_add' );

	$source = omef_normalize_redirect_source( isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : '' );
	$target = omef_normalize_redirect_source( isset( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : '' );

	if ( $source && $target ) {
		global $wpdb;
		$wpdb->replace(
			omef_redirect_table(),
			array( 'source' => $source, 'target' => $target, 'active' => 1 )
		);
		set_transient( 'omef_redirect_notice_' . get_current_user_id(), 'ההפניה נשמרה.', MINUTE_IN_SECONDS );
	}

	wp_safe_redirect( admin_url( 'options-general.php?page=omef-redirects' ) );
	exit;
}
add_action( 'admin_post_omef_redirect_add', 'omef_redirect_add_action' );

function omef_redirect_delete_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'omef_redirect_delete' );

	global $wpdb;
	$wpdb->delete(
		omef_redirect_table(),
		array( 'id' => absint( $_POST['id'] ?? 0 ) )
	);

	wp_safe_redirect( admin_url( 'options-general.php?page=omef-redirects' ) );
	exit;
}
add_action( 'admin_post_omef_redirect_delete', 'omef_redirect_delete_action' );

add_action( 'admin_head', static function (): void {
	echo '<style>.button-link.delete{color:#b32d2e}</style>';
} );
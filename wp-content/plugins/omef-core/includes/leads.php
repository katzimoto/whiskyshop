<?php
/**
 * Contact-page lead capture: a shortcode form, a submit handler that stores
 * entries and notifies the admin, and the data other files (dashboard-leads)
 * read to list them.
 */

defined( 'ABSPATH' ) || exit;

function omef_leads_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'omef_leads';
}

function omef_leads_create_table(): void {
	global $wpdb;
	$table = omef_leads_table();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		"CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(191) NOT NULL,
			last_name VARCHAR(191) NOT NULL,
			phone VARCHAR(64) NOT NULL,
			interest TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$wpdb->get_charset_collate()};"
	);
}

function omef_contact_form_shortcode(): string {
	$notice = '';
	if ( isset( $_GET['omef_contact'] ) && $_GET['omef_contact'] === 'sent' ) {
		$notice = '<div class="omef-form-notice">תודה! קיבלנו את הפנייה ונחזור אליכם בהקדם.</div>';
	}

	ob_start();
	?>
	<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<form class="omef-contact-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="omef_contact_submit">
		<?php wp_nonce_field( 'omef_contact_submit' ); ?>
		<input type="hidden" name="omef_redirect" value="<?php echo esc_url( get_permalink() ); ?>">
		<p>
			<label for="omef_contact_first_name">שם פרטי</label>
			<input type="text" id="omef_contact_first_name" name="first_name" required>
		</p>
		<p>
			<label for="omef_contact_last_name">שם משפחה</label>
			<input type="text" id="omef_contact_last_name" name="last_name" required>
		</p>
		<p>
			<label for="omef_contact_phone">טלפון</label>
			<input type="tel" id="omef_contact_phone" name="phone" required>
		</p>
		<p>
			<label for="omef_contact_interest">אז במה בעצם אתם מעוניינים?</label>
			<textarea id="omef_contact_interest" name="interest" rows="4" required></textarea>
		</p>
		<p><button type="submit" class="wp-element-button">שליחה</button></p>
	</form>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'omef_contact_form', 'omef_contact_form_shortcode' );

function omef_contact_submit_action(): void {
	check_admin_referer( 'omef_contact_submit' );

	$first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
	$last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
	$phone      = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
	$interest   = sanitize_textarea_field( wp_unslash( $_POST['interest'] ?? '' ) );
	$redirect   = wp_unslash( $_POST['omef_redirect'] ?? '' );
	$redirect   = $redirect ? esc_url_raw( $redirect ) : home_url( '/contact/' );

	if ( $first_name === '' || $phone === '' ) {
		wp_safe_redirect( $redirect );
		exit;
	}

	global $wpdb;
	$wpdb->insert(
		omef_leads_table(),
		array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'phone'      => $phone,
			'interest'   => $interest,
			'created_at' => current_time( 'mysql' ),
		)
	);

	if ( function_exists( 'omef_email_admin_recipients' ) ) {
		wp_mail(
			omef_email_admin_recipients(),
			'פנייה חדשה מהאתר — חנות הוויסקי',
			sprintf(
				"התקבלה פנייה חדשה דרך טופס יצירת הקשר:\n\nשם: %s %s\nטלפון: %s\n\nמעוניין/ת ב:\n%s",
				$first_name,
				$last_name,
				$phone,
				$interest
			)
		);
	}

	wp_safe_redirect( add_query_arg( 'omef_contact', 'sent', $redirect ) );
	exit;
}
add_action( 'admin_post_omef_contact_submit', 'omef_contact_submit_action' );
add_action( 'admin_post_nopriv_omef_contact_submit', 'omef_contact_submit_action' );

<?php
/**
 * Podcast RSS import and admin settings.
 */

defined( 'ABSPATH' ) || exit;

function omef_register_podcast_settings(): void {
	register_setting(
		'omef_podcast',
		'omef_podcast_feed_url',
		array(
			'sanitize_callback' => 'omef_sanitize_feed_url',
		)
	);
}
add_action( 'admin_init', 'omef_register_podcast_settings' );

function omef_sanitize_feed_url( string $url ): string {
	$url = esc_url_raw( trim( $url ) );
	return str_starts_with( $url, 'https://' ) ? $url : '';
}

function omef_add_podcast_import_page(): void {
	add_submenu_page(
		'edit.php?post_type=omef_episode',
		'ייבוא פרקים',
		'ייבוא פרקים',
		'edit_posts',
		'omef-podcast-import',
		'omef_render_podcast_import_page'
	);
}
add_action( 'admin_menu', 'omef_add_podcast_import_page' );

function omef_render_podcast_import_page(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$last_import = get_option( 'omef_podcast_last_import', array() );
	$notice = get_transient( 'omef_podcast_import_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'omef_podcast_import_notice_' . get_current_user_id() );
	}
	?>
	<div class="wrap">
		<h1>ייבוא פרקים מהפיד</h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<p>הייבוא יוצר פרקים כטיוטות בלבד. טקסט שכתבתם בפרק ובקבוקים שבחרתם אינם משתנים בייבוא חוזר.</p>
		<form action="options.php" method="post">
			<?php settings_fields( 'omef_podcast' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="omef_podcast_feed_url">כתובת RSS של Spotify</label></th>
					<td><input class="regular-text code" id="omef_podcast_feed_url" name="omef_podcast_feed_url" type="url" value="<?php echo esc_attr( get_option( 'omef_podcast_feed_url', '' ) ); ?>" placeholder="https://..."></td>
				</tr>
			</table>
			<?php submit_button( 'שמירת כתובת הפיד' ); ?>
		</form>
		<hr>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input name="action" type="hidden" value="omef_import_podcast">
			<?php wp_nonce_field( 'omef_import_podcast' ); ?>
			<?php submit_button( 'ייבוא עכשיו', 'primary', 'submit', false, array( 'disabled' => ! get_option( 'omef_podcast_feed_url' ) ) ); ?>
		</form>
		<?php if ( $last_import ) : ?>
			<p><strong>ייבוא אחרון:</strong> <?php echo esc_html( $last_import['message'] ?? '' ); ?> <?php echo esc_html( $last_import['time'] ?? '' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

function omef_import_podcast_admin_action(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( 'אין הרשאה לייבא פרקים.' );
	}

	check_admin_referer( 'omef_import_podcast' );
	$result = omef_import_podcast_feed();
	$notice = is_wp_error( $result ) ? $result->get_error_message() : sprintf( 'הייבוא הסתיים: %d פרקים חדשים, %d פרקים עודכנו.', $result['created'], $result['updated'] );
	set_transient( 'omef_podcast_import_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'edit.php?post_type=omef_episode&page=omef-podcast-import' ) );
	exit;
}
add_action( 'admin_post_omef_import_podcast', 'omef_import_podcast_admin_action' );

function omef_import_podcast_feed() {
	$feed_url = get_option( 'omef_podcast_feed_url', '' );
	if ( ! $feed_url ) {
		return new WP_Error( 'omef_missing_feed', 'יש לשמור כתובת RSS לפני הייבוא.' );
	}

	require_once ABSPATH . WPINC . '/feed.php';
	$feed = fetch_feed( $feed_url );
	if ( is_wp_error( $feed ) ) {
		omef_record_podcast_import( $feed->get_error_message() );
		return $feed;
	}

	$result = array( 'created' => 0, 'updated' => 0 );
	foreach ( $feed->get_items( 0, 100 ) as $item ) {
		$guid = (string) $item->get_id();
		if ( ! $guid ) {
			continue;
		}

		$existing = get_posts(
			array(
				'post_type'      => 'omef_episode',
				'post_status'    => 'any',
				'meta_key'       => '_omef_feed_guid',
				'meta_value'     => $guid,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$title = wp_strip_all_tags( (string) $item->get_title() );
		$excerpt = wp_trim_words( wp_strip_all_tags( (string) $item->get_description() ), 55 );
		$post_data = array(
			'post_type'    => 'omef_episode',
			'post_title'   => $title ?: 'פרק ללא כותרת',
			'post_excerpt' => $excerpt,
		);

		if ( $existing ) {
			$post_id = (int) $existing[0];
			if ( get_post_status( $post_id ) === 'draft' ) {
				$post_data['ID'] = $post_id;
				wp_update_post( $post_data );
				++$result['updated'];
			}
		} else {
			$post_data['post_status'] = 'draft';
			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			update_post_meta( $post_id, '_omef_feed_guid', $guid );
			++$result['created'];
		}

		if ( ! empty( $post_id ) ) {
			$link = (string) $item->get_permalink();
			if ( preg_match( '~episode/([A-Za-z0-9]+)~', $link, $matches ) ) {
				update_post_meta( $post_id, '_omef_spotify_id', $matches[1] );
			}
		}
	}

	omef_record_podcast_import( sprintf( '%d פרקים חדשים, %d פרקים עודכנו.', $result['created'], $result['updated'] ) );
	return $result;
}

function omef_record_podcast_import( string $message ): void {
	update_option(
		'omef_podcast_last_import',
		array(
			'message' => $message,
			'time'    => current_time( 'mysql' ),
		),
		false
	);
}

function omef_schedule_podcast_import(): void {
	if ( ! get_option( 'omef_podcast_feed_url' ) || ! function_exists( 'as_schedule_recurring_action' ) || as_next_scheduled_action( 'omef_import_podcast_feed', array(), 'omef' ) ) {
		return;
	}

	as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'omef_import_podcast_feed', array(), 'omef' );
}
add_action( 'init', 'omef_schedule_podcast_import', 30 );
add_action( 'omef_import_podcast_feed', 'omef_import_podcast_feed' );


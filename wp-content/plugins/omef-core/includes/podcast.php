<?php
/**
 * Podcast RSS import and admin settings.
 */

defined( 'ABSPATH' ) || exit;

function omef_sanitize_feed_url( string $url ): string {
	$url = esc_url_raw( trim( $url ) );
	return str_starts_with( $url, 'https://' ) ? $url : '';
}

function omef_podcast_settings_get(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	wp_send_json_success(
		array(
			'feedUrl'    => get_option( 'omef_podcast_feed_url', '' ),
			'lastImport' => get_option( 'omef_podcast_last_import', array() ),
		)
	);
}
add_action( 'wp_ajax_omef_podcast_settings_get', 'omef_podcast_settings_get' );

function omef_podcast_settings_save(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$url = omef_sanitize_feed_url( sanitize_text_field( wp_unslash( $_POST['feed_url'] ?? '' ) ) );
	update_option( 'omef_podcast_feed_url', $url );

	wp_send_json_success( array( 'feedUrl' => $url ) );
}
add_action( 'wp_ajax_omef_podcast_settings_save', 'omef_podcast_settings_save' );

function omef_podcast_import_ajax(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$result = omef_import_podcast_feed();
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success(
		array(
			'message'    => sprintf( 'הייבוא הסתיים: %d פרקים חדשים, %d פרקים עודכנו.', $result['created'], $result['updated'] ),
			'lastImport' => get_option( 'omef_podcast_last_import', array() ),
		)
	);
}
add_action( 'wp_ajax_omef_podcast_import', 'omef_podcast_import_ajax' );

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


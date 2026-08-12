<?php
/**
 * 18+ age verification gate.
 */

defined( 'ABSPATH' ) || exit;

/**
 * robots.txt and the WordPress core XML sitemaps are also plain page
 * requests as far as template_redirect is concerned, so without this
 * exclusion the gate (hooked at priority 0, ahead of core's own handlers for
 * them) served its HTML confirmation page in place of both -- meaning no
 * search engine could ever discover a crawlable robots.txt or sitemap for
 * this store. Neither route exposes any age-restricted content, so
 * exempting them doesn't weaken the gate's actual purpose.
 */
function omef_age_gate_bypassed_request(): bool {
	return is_robots() || (bool) get_query_var( 'sitemap' ) || (bool) get_query_var( 'sitemap-stylesheet' );
}

function omef_age_gate_required(): bool {
	return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! defined( 'REST_REQUEST' )
		&& ! omef_age_gate_bypassed_request()
		&& empty( $_COOKIE['omef_age_verified'] );
}

function omef_handle_age_gate(): void {
	if ( ! omef_age_gate_required() ) {
		return;
	}

	$age_gate_error = false;
	if ( isset( $_POST['omef_age_gate'] ) ) {
		$nonce = isset( $_POST['omef_age_gate_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['omef_age_gate_nonce'] ) ) : '';
		if ( wp_verify_nonce( $nonce, 'omef_age_gate' ) && isset( $_POST['omef_confirmed_18'] ) ) {
			setcookie(
				'omef_age_verified',
				'yes',
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ?: '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE['omef_age_verified'] = 'yes';
			$redirect = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : home_url( '/' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$age_gate_error = true;
	}

	nocache_headers();
	$template = get_theme_file_path( 'age-gate.php' );
	if ( file_exists( $template ) ) {
		require $template;
	} else {
		wp_die( 'הכניסה לאתר היא מגיל 18 ומעלה.' );
	}
	exit;
}
add_action( 'template_redirect', 'omef_handle_age_gate', 0 );


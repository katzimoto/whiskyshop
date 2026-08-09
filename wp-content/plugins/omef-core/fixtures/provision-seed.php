<?php
/**
 * One-shot content provisioning from the seed fixtures.
 *
 * Run inside the WordPress container:
 *   docker compose exec -T wordpress wp --allow-root eval-file \
 *     wp-content/plugins/omef-core/fixtures/provision-seed.php
 *
 * Idempotent: existing posts are matched by slug and updated in place.
 */

defined( 'ABSPATH' ) || exit;

$fixtures_dir = __DIR__;

function seed_json( string $file ): array {
	$path = __DIR__ . '/' . $file;
	if ( ! file_exists( $path ) ) {
		WP_CLI::warning( 'Missing fixture: ' . $path );
		return array();
	}
	$data = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $data ) ? $data : array();
}

function seed_upsert_post( array $args ): int {
	$existing = get_page_by_path( $args['post_name'], OBJECT, $args['post_type'] );
	if ( $existing && (int) $existing->ID > 0 ) {
		$args['ID'] = (int) $existing->ID;
		$id         = wp_update_post( wp_slash( $args ) );
		WP_CLI::log( 'updated ' . $args['post_type'] . ' #' . $existing->ID . ' ' . $args['post_title'] );
	} else {
		$id = wp_insert_post( wp_slash( $args ) );
		WP_CLI::log( 'created ' . $args['post_type'] . ' #' . $id . ' ' . $args['post_title'] );
	}
	return (int) $id;
}

function seed_ensure_term( string $taxonomy, string $name, string $slug, int $parent = 0 ): int {
	$term = term_exists( $slug, $taxonomy );
	if ( $term && ! is_wp_error( $term ) ) {
		return (int) ( is_array( $term ) ? $term['term_id'] : $term );
	}
	$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug, 'parent' => $parent ) );
	if ( is_wp_error( $created ) ) {
		WP_CLI::warning( 'term ' . $slug . ': ' . $created->get_error_message() );
		$term = term_exists( $slug, $taxonomy );
		return $term ? (int) ( is_array( $term ) ? $term['term_id'] : $term ) : 0;
	}
	return (int) $created['term_id'];
}

function seed_attachment_id( string $mid, string $extension, string $attachment_name ): int {
	$rel_y     = gmdate( 'Y' );
	$rel_m     = gmdate( 'm' );
	$filename  = $mid . '~mv2.' . $extension;
	$uploads   = wp_upload_dir();
	$target    = trailingslashit( $uploads['path'] ) . $filename;
	$basesearch = array(
		trailingslashit( $uploads['basedir'] ) . '2026/08/' . $filename,
		$target,
	);

	$path = '';
	foreach ( $basesearch as $candidate ) {
		if ( file_exists( $candidate ) ) {
			$path = $candidate;
			break;
		}
	}

	if ( ! $path ) {
		$url = 'https://static.wixstatic.com/media/' . $filename;
		$tid = media_sideload_image( $url, 0, $attachment_name, 'id' );
		if ( is_wp_error( $tid ) ) {
			WP_CLI::warning( 'sideload ' . $filename . ': ' . $tid->get_error_message() );
			return 0;
		}
		WP_CLI::log( 'sideloaded ' . $filename . ' -> #' . $tid );
		return (int) $tid;
	}

	$by_basename = seed_find_attachment_by_basename( $filename );
	if ( $by_basename ) {
		return (int) $by_basename;
	}

	$filetype = wp_check_filetype( $filename );
	$attach   = array(
		'post_mime_type' => $filetype['type'] ?? 'image/png',
		'post_title'     => sanitize_file_name( $attachment_name ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$id = wp_insert_attachment( $attach, $path, 0 );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = wp_generate_attachment_metadata( $id, $path );
	wp_update_attachment_metadata( $id, $metadata );
	WP_CLI::log( 'registered existing ' . $filename . ' -> #' . $id );
	return (int) $id;
}

function seed_find_attachment_by_basename( string $basename ): ?int {
	global $wpdb;
	$sql = $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
		 WHERE p.post_type = 'attachment' AND pm.meta_value = %s LIMIT 1",
		$basename
	);
	$id = $wpdb->get_var( $sql );
	return $id ? (int) $id : null;
}

/** Import static pages from seed-pages.json. */
function seed_import_pages( array $pages ): void {
	foreach ( $pages as $p ) {
		$content = seed_pretty_paragraphs( $p['content'] );
		seed_upsert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $p['title'],
			'post_name'    => $p['slug'],
			'post_content' => '<!-- wp:paragraph -->' . $content . '<!-- /wp:paragraph -->',
		) );
	}
}

function seed_pretty_paragraphs( string $text ): string {
	$lines = preg_split( '/\n+/', trim( $text ) );
	$out   = '';
	foreach ( array_filter( $lines ) as $l ) {
		$l = trim( $l );
		if ( '' === $l ) {
			continue;
		}
		$out .= '<p>' . esc_html( $l ) . '</p>';
	}
	return $out;
}

/** Import workshops (omef_workshop) from seed-workshops.json. */
function seed_import_workshops( array $workshops ): void {
	foreach ( $workshops as $w ) {
		$id = seed_upsert_post( array(
			'post_type'    => 'omef_workshop',
			'post_status'  => 'publish',
			'post_title'   => $w['title'],
			'post_name'    => $w['slug'],
			'post_content' => seed_pretty_paragraphs( $w['body'] ),
			'post_excerpt' => $w['intro'] ?? '',
		) );
		update_post_meta( $id, '_omef_duration', $w['duration'] ?? '' );
		update_post_meta( $id, '_omef_group_size', $w['group_size'] ?? '' );
		update_post_meta( $id, '_omef_price_range', $w['price_range'] ?? '' );
		update_post_meta( $id, '_omef_inclusions', $w['inclusions'] ?? '' );
		update_post_meta( $id, 'omef_workshop_intro', $w['intro'] ?? '' );
	}
}

/** Import tasting posts + their linked ticket product. */
function seed_import_tastings(): void {
	$tastings = array(
		array(
			'title' => 'סדנת אזורי הוויסקי',
			'slug'  => 'tasting-whisky-regions',
			'date'  => '2026-08-12T20:00',
			'venue' => 'תל אביב',
			'price' => 300,
			'seats' => 24,
			'body'  => 'טעימה פתוחה סביב אזורי הוויסקי של סקוטלנד – כיצד נוצרה החלוקה לאזורים ומה מאפיין כל אחד. נטעם וויסקי מכל האזורים.',
		),
		array(
			'title' => 'סדנת מעושנים וסיגר',
			'slug'  => 'tasting-peat-and-cigar',
			'date'  => '2026-08-24T20:00',
			'venue' => 'הרצליה',
			'price' => 320,
			'seats' => 24,
			'body'  => 'ערב למטעמי הוויסקי המעושן בשילוב סיגר – טעימה של מעושנים ממספר מזקקות לצד חיבור לסיגר.',
		),
		array(
			'title' => 'סדנת גלנמורנג\'י',
			'slug'  => 'tasting-glenmorangie',
			'date'  => '2026-08-26T20:00',
			'venue' => 'מזקקת M&H, תל אביב',
			'price' => 360,
			'seats' => 30,
			'body'  => 'ביקור שני בגלנמורנג\'י – השנה נטעם את הקור ריינג\' שהתחדש בהצהרות גיל ושינויים בחביות, וגם מהדורות מעבר לקור ריינג\'. 10 טעימות, כיבוד קל וכשר. בבקשה לא לשלם על משלוח.',
		),
	);

	foreach ( $tastings as $t ) {
		$id = seed_upsert_post( array(
			'post_type'    => 'omef_tasting',
			'post_status'  => 'publish',
			'post_title'   => $t['title'],
			'post_name'    => $t['slug'],
			'post_content' => '<p>' . esc_html( $t['body'] ) . '</p>',
		) );
		update_post_meta( $id, '_omef_tasting_date', $t['date'] );
		update_post_meta( $id, '_omef_tasting_venue', $t['venue'] );
		update_post_meta( $id, '_omef_tasting_price', (string) $t['price'] );
		update_post_meta( $id, '_omef_tasting_seats', (string) $t['seats'] );

		$product_id = absint( get_post_meta( $id, '_omef_tasting_product_id', true ) );
		if ( ! $product_id || get_post_type( $product_id ) !== 'product' ) {
			$product_id = seed_ensure_ticket_product( $t, $id );
			update_post_meta( $id, '_omef_tasting_product_id', $product_id );
		}
	}
}

function seed_ensure_ticket_product( array $t, int $tasting_id ): int {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return 0;
	}
	$slug = get_post_field( 'post_name', $tasting_id ) . '-tasting';
	$existing = get_page_by_path( $slug, OBJECT, 'product' );
	if ( $existing ) {
		return (int) $existing->ID;
	}
	$product = new WC_Product_Simple();
	$product->set_name( $t['title'] );
	$product->set_slug( $slug );
	$product->set_status( 'publish' );
	$product->set_regular_price( (string) $t['price'] );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( (int) $t['seats'] );
	$product->set_stock_status( 'instock' );
	$product->set_description( '<p>' . esc_html( $t['body'] ) . '</p>' );
	$product->add_meta_data( '_omef_tasting_id', $tasting_id );
	$pid = $product->save();
	WP_CLI::log( 'created ticket product #' . $pid . ' ' . $t['title'] );
	return (int) $pid;
}

/** Import WooCommerce products from seed-products.json. */
function seed_import_products(): void {
	if ( ! function_exists( 'wc_get_product' ) ) {
		WP_CLI::warning( 'WooCommerce not active, skipping products' );
		return;
	}

	$tree = array(
		'וויסקי'   => 'whisky',
		'סדנאות'   => 'workshops',
		'רום'      => 'rum',
		'מרצ׳נדייז' => 'merchandise',
		'כיף'      => 'fun',
	);
	foreach ( $tree as $name => $slug ) {
		seed_ensure_term( 'product_cat', $name, $slug );
	}

	// Brand collections live under וויסקי.
	$brand_slugs = array(
		'ג\'יין סטריט'      => 'jane-street',
		'וודרו\'ס מאדינברו'  => 'woodrows-of-edinburgh',
		'ריגר\'ס סלקשן'     => 'rigers-selection',
	);
	$whisky = (int) term_exists( 'whisky', 'product_cat' )['term_id'] ?? 0;
	foreach ( $brand_slugs as $name => $slug ) {
		seed_ensure_term( 'product_cat', $name, $slug, $whisky );
	}

	$products = seed_json( 'seed-products.json' );
	foreach ( $products as $p ) {
		seed_upsert_product( $p, $brand_slugs );
	}
}

function seed_upsert_product( array $p, array $brand_slugs ): void {
	$existing = get_page_by_path( $p['slug'], OBJECT, 'product' );
	if ( $existing ) {
		$id = (int) $existing->ID;
		WP_CLI::log( 'exists product #' . $id . ' ' . $p['name'] );
	} else {
		$product = new WC_Product_Simple();
		$product->set_name( $p['name'] );
		$product->set_slug( $p['slug'] );
		$product->set_status( 'publish' );
		$product->set_regular_price( (string) $p['price'] );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_description( seed_pretty_paragraphs( $p['description'] ) );
		$id = (int) $product->save();
		WP_CLI::log( 'created product #' . $id . ' ' . $p['name'] . ' @ ' . $p['price'] );
	}

	$parent_slug = $brand_slugs[ $p['category'] ] ?? 'whisky';
	$term = term_exists( $parent_slug, 'product_cat' );
	if ( $term ) {
		$tid = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		wp_set_object_terms( $id, array( $tid ), 'product_cat' );
	}

	if ( ! empty( $p['image'] ) ) {
		$m = preg_match( '#/media/(d32e7e_[a-z0-9]+)~mv2\.([a-z0-9]+)$#', $p['image'], $mm );
		if ( $m ) {
			$aid = seed_attachment_id( $mm[1], $mm[2], $p['name'] );
			if ( $aid ) {
				set_post_thumbnail( $id, $aid );
			}
		}
	}

	// Mirror the hex spec fields into omef product meta.
	seed_apply_product_meta( $id, $p['description'] );
}

function seed_apply_product_meta( int $product_id, string $description ): void {
	$mapping = array(
		'_omef_distillery' => 'מזקקה',
		'_omef_age'        => 'גיל',
		'_omef_abv'        => 'חוזק',
		'_omef_cask_type'  => 'יישון',
	);
	foreach ( $mapping as $key => $label ) {
		if ( preg_match( '/^' . $label . ':\s*(.+)$/mu', $description, $m ) ) {
			$value = trim( $m[1] );
			$value = preg_split( '/[\n]/', $value )[0];
			$value = preg_replace( '/\s*(גיל|חוזק|יישון|זוקק|בוקבק|גודל בקבוק|עבר לחבית).*$/u', '', $value );
			$value = trim( $value );

			if ( $key === '_omef_age' ) {
				$age = (int) $value;
				if ( $age > 0 && $age < 100 ) {
					update_post_meta( $product_id, $key, (string) $age );
				}
				continue;
			}

			if ( $key === '_omef_abv' ) {
				if ( preg_match( '/(\d+(?:\.\d+)?)%/u', $value, $am ) ) {
					update_post_meta( $product_id, $key, $am[1] );
				}
				continue;
			}

			if ( $value && strpos( $value, 'אחוז אלכוהול' ) === false ) {
				update_post_meta( $product_id, $key, $value );
			}
		}
	}

	if ( preg_match( '/גודל בקבוק:\s*([0-9]+)\s*"?מ"?[״"]?ל?/u', $description, $m ) ) {
		if ( ! get_post_meta( $product_id, '_omef_bottle_size', true ) ) {
			update_post_meta( $product_id, '_omef_bottle_size', $m[1] . ' מ"ל' );
		}
	}
}

/** Create the contact page and the workshops hub page if missing. */
function seed_hub_pages(): void {
	$contact_content = '<p>רוצים להתעדכן על הסדנאות הפתוחות? רוצים לשמוע פרטים נוספים על סדנה פרטית? יש לכם שאלות על וויסקי? צריכים המלצות או יעוץ? אני לרשותכם.ן!</p><p><a href="https://chat.whatsapp.com/DK7m3OW6sxu1LHtzqA1B6H" target="_blank" rel="noopener">הצטרפו לקבוצת הוואטסאפ של חוזק חבית לכל העדכונים</a></p><p>סדנת וויסקי יכולה להיות קצרה, ארוכה, לזוג או לעשרות אנשים. לא בטוחים מה נכון לכם? יש לכם רעיון או דרישות מיוחדות? פנו אלי ויחד ניצור את הסדנה המושלמת עבורכם!</p><p><strong>בואו לטעום יחד איתי!</strong></p>';
	seed_upsert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'יצירת קשר',
		'post_name'    => 'contact',
		'post_content' => '<!-- wp:paragraph -->' . $contact_content . '<!-- /wp:paragraph -->' . PHP_EOL . '<!-- wp:shortcode -->[omef_contact_form]<!-- /wp:shortcode -->',
	) );
}

if ( 'cli' !== PHP_SAPI ) {
	return;
}

if ( ! class_exists( 'WP_CLI' ) ) {
	fwrite( STDERR, "Run with wp eval-file.\n" );
	exit( 1 );
}

WP_CLI::log( 'Seeding pages…' );
seed_import_pages( seed_json( 'seed-pages.json' ) );
seed_hub_pages();

WP_CLI::log( 'Seeding workshops…' );
seed_import_workshops( seed_json( 'seed-workshops.json' ) );

WP_CLI::log( 'Seeding tastings…' );
seed_import_tastings();

WP_CLI::log( 'Seeding products…' );
seed_import_products();

WP_CLI::log( 'Flushing rewrite rules.' );
flush_rewrite_rules();
WP_CLI::success( 'Seed provisioning complete.' );
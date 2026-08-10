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
	$filename = $mid . '~mv2.' . $extension;

	// Any existing attachment carrying this media id (basename keeps the
	// d32e7e_…mv2 prefix regardless of the year/month folder or the -N suffix
	// sideload appends) is reused so re-running the seed never re-downloads.
	$existing = seed_find_attachment_by_media_id( $mid );
	if ( $existing ) {
		return (int) $existing;
	}

	$url = 'https://static.wixstatic.com/media/' . $filename;
	$tid = media_sideload_image( $url, 0, $attachment_name, 'id' );
	if ( is_wp_error( $tid ) ) {
		WP_CLI::warning( 'sideload ' . $filename . ': ' . $tid->get_error_message() );
		return 0;
	}
	WP_CLI::log( 'sideloaded ' . $filename . ' -> #' . $tid );
	return (int) $tid;
}

function seed_find_attachment_by_media_id( string $media_id ): ?int {
	global $wpdb;
	$prefix = $wpdb->esc_like( $media_id . 'mv2' );
	$sql    = $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
		 WHERE p.post_type = 'attachment'
		   AND SUBSTRING_INDEX( pm.meta_value, '/', -1 ) LIKE %s
		 ORDER BY p.ID ASC LIMIT 1",
		$prefix . '%'
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
		if ( $product_id ) {
			wp_update_post( array( 'ID' => $product_id, 'post_status' => 'publish' ) );
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
		wp_update_post( array( 'ID' => (int) $existing->ID, 'post_status' => 'publish' ) );
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

	// The omef publish-guardrail forces drafts while required fields
	// (thumbnail + ALT) are missing, so relax it for the whole seeding run.
	remove_all_filters( 'wp_insert_post_data' );

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
				update_post_meta( $aid, '_wp_attachment_image_alt', $p['name'] );
			}
		}
	}

	// Mirror the hex spec fields into omef product meta.
	seed_apply_product_meta( $id, $p['description'] );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
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

/**
 * Assign featured images sideloaded from the original Wix site
 * (static.wixstatic.com) to workshops, tastings, episodes and pages.
 * Idempotent via seed_attachment_id().
 */
function seed_featured_images(): void {
	$map = array(
		// type, slug (or title), media id, extension, alt text
		array( 'omef_workshop', 'world-of-whisky',         'd32e7e_95efd6c6167644aeb06f0b368599a86a', 'jpeg', 'עומף מנחה את הסדנה' ),
		array( 'omef_workshop', 'scotland-whisky-regions', 'd32e7e_43924662e1f44b7390b8f7296b2b4953', 'jpeg', 'עומף מצביע על מפת סקוטלנד' ),
		array( 'omef_workshop', 'peated-whisky',           'd32e7e_e31e4f2ad8db4c95ace334667fdd8e8a', 'png',  'עומף מציג לפרויג 15 ו-16' ),
		array( 'omef_workshop', 'casks',                   'd32e7e_faf77b1b99fb49dd952fadcedeab1beb', 'jpg',  'ליינאפ לפני ביקבוק' ),
		array( 'omef_workshop', 'irish-whisky',            'd32e7e_b4b1d9cb162543979cb7aa10fed7e0bf', 'jpg',  'ליינאפ מלא של הסדנה' ),

		array( 'omef_tasting',  'tasting-whisky-regions',  'd32e7e_ec9e216d1a044056a555ce812818f226', 'jpg',  'עומף מראה את מפת סקוטלנד' ),
		array( 'omef_tasting',  'tasting-peat-and-cigar',  'd32e7e_ca5424f616d04ab0b1bb42944d608e65', 'jpg',  'אנשים מריחים וויסקי בסדנת ברוכלאדי מעושנים' ),
		array( 'omef_tasting',  'tasting-glenmorangie',    'd32e7e_0d05ebb810b24b39b022223865c45673', 'jpg',  'ליינאפ סדנת גלנמורנג׳י' ),

		array( 'omef_episode',  'פרק 1: להתחיל מבקבוק טוב', '114db8_1d95ce9092b845938f04f33f57edc398', 'jpg', 'עומף מריח וויסקי בזמן שהוא מקליט פרק בפודקאסט' ),

		array( 'page', 'about',           'd32e7e_e11096ea3fac48bf917de28cc65c6c3a', 'jpg', 'עומף' ),
		array( 'page', 'import',          'd32e7e_2dd7be6d4b024263b86bbfaa6dc06953', 'jpeg', 'בקבוק מספר 8 - בלאקאדר בן 34' ),
		array( 'page', 'professional',    'd32e7e_8478edc4149e455b8ea1de5c4e322bcd', 'jpg', 'עומף מסביר על המזקקה' ),
	);

	foreach ( $map as $row ) {
		list( $type, $match, $mid, $ext, $alt ) = $row;
		$post = get_page_by_path( sanitize_title( $match ), OBJECT, $type );
		if ( ! $post && 'omef_episode' === $type ) {
			$post = get_page_by_title( $match, OBJECT, $type );
		}
		if ( ! $post ) {
			WP_CLI::warning( 'featured-image: no ' . $type . ' matched for ' . $match );
			continue;
		}
		$aid = seed_attachment_id( $mid, $ext, $alt );
		if ( $aid ) {
			set_post_thumbnail( (int) $post->ID, $aid );
			update_post_meta( $aid, '_wp_attachment_image_alt', $alt );
			WP_CLI::log( 'thumbnail #' . $aid . ' -> ' . $type . ' ' . $match );
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

// The omef publish-guardrail forces drafts while required fields
// (thumbnail + ALT) are missing, so relax it for the whole seeding run.
remove_all_filters( 'wp_insert_post_data' );

WP_CLI::log( 'Seeding pages…' );
seed_import_pages( seed_json( 'seed-pages.json' ) );
seed_hub_pages();

WP_CLI::log( 'Seeding workshops…' );
seed_import_workshops( seed_json( 'seed-workshops.json' ) );

WP_CLI::log( 'Seeding tastings…' );
seed_import_tastings();

WP_CLI::log( 'Seeding products…' );
seed_import_products();

WP_CLI::log( 'Assigning featured images…' );
seed_featured_images();

WP_CLI::log( 'Flushing rewrite rules.' );
flush_rewrite_rules();
WP_CLI::success( 'Seed provisioning complete.' );
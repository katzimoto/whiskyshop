# Whisky Shop Bug Audit

## Executive Summary
Live URL: https://whiskyshop.148-113-191-120.sslip.io/ (confirmed to be this repo's actual deploy target, via `.github/workflows/deploy.yml`)
Audit date: 2026-08-12
Commit audited: e45955a (main)
Platform: WordPress + WooCommerce, custom block theme `omef` (pure PHP/CSS/block-markup, no JS, no build system), custom plugin `omef-core` (22 include files), two mu-plugins (`omef-rtl`, `omef-airwallex-checkout-scope`)
Ruflo topology: hierarchical-mesh (swarm-1786482926466-lmq5gj), registered via `swarm_init` + baseline `memory_store`; the Ruflo MCP connection dropped shortly after and was not needed further — actual multi-agent execution ran via three parallel background agents (Agent tool)
Agents used: coordinator (this session, owns all fixes + the bug database) + theme/frontend static auditor (read-only) + plugin/backend/security auditor (read-only) + live browser QA agent (read-only, local + live)
Pages/routes discovered & tested: home, shop archive, 3+ individual products (varied price/stock), cart, checkout, search (matching + zero-result), tastings archive, robots.txt, wp-sitemap.xml (+ sub-sitemaps), age gate itself, plus footer/nav link inventory
Total confirmed bugs: **13**
P0: 2 — P1: 2 — P2: 5 — P3: 4
Fixed: **12**
Remaining: **1** (BUG-009, deliberately not fixed — see below)
Rejected hypotheses: stray checkout markup outside `/checkout` (verified absent on both environments); global-hook-scope leak beyond the already-fixed Airwallex case (none found — every `wp_head`/`wp_footer`/`template_redirect`/`admin_enqueue_scripts` hook is properly context-guarded); AJAX endpoints missing nonce/capability checks (none found — all gated); SQL injection (none found — all `$wpdb` calls use `prepare()` or internal-only values); path traversal in the backup download action (protected via `sanitize_file_name()`+`basename()`); abandoned-cart reminder double-send (idempotent by design); missing search UI entry point (a design characteristic on both environments, not a regression)

## Baseline: known-fixed issues, re-verified still fixed
- Sticky header (commit 74b6aa3): re-verified via real 500px scroll — header stays pinned.
- Mobile nav overlay (commit 30baffe): re-verified at 390×844 — all 8 links visible/reachable, overlay fully opaque.
- Airwallex currency-conversion modal scope leak (commit 7fc22d7 / `mu-plugins/omef-airwallex-checkout-scope.php`): re-verified absent from homepage/shop/cart on both local and live.
- RTL/locale (`WPLANG=he_IL`): confirmed active locally (`dir="rtl"`, `lang="he-IL"`, `direction: rtl`). **Live environment note (not a code bug):** live renders WooCommerce/WP-core UI strings ("Shop", "Checkout", "Billing details"...) in English despite correct `dir`/`lang`, while local renders them in Hebrew — this points to a missing/different WP core translation pack on the live server (config/environment, not a repo issue) per the `omef-rtl-locale` skill's warning that this isn't version-controlled.

## Critical Findings
- **BUG-005 (P0, FIXED, VERIFIED):** full database backups and WooCommerce/Airwallex debug logs were publicly downloadable with zero authentication.
- **BUG-006 (P0, FIXED, VERIFIED):** editorial "sale price" was displayed everywhere but never actually charged at checkout — customers would see one price and be billed another.
- **BUG-010 (P1, FIXED, VERIFIED):** the age gate hijacked `/robots.txt` and `/wp-sitemap.xml`, making the entire store unindexable by any search engine.

## Fixed Bugs

### BUG-005 — Full DB backups and WooCommerce/Airwallex debug logs publicly downloadable, unauthenticated
Severity: P0
Source: plugin-auditor (static) + live-verified
Route: `/wp-content/uploads/omef-backups/*.sql.gz`, `/wp-content/uploads/wc-logs/*.log`
Reproduction: `curl http://localhost:8080/wp-content/uploads/omef-backups/omef-db-20260808-200558.sql.gz` → HTTP 200, full gzip'd DB dump (users incl. password hashes, all customer PII/addresses/orders) served with zero auth. Same for wc-logs (WooCommerce/Airwallex payment-gateway debug logs).
Expected: 403/404, unreachable without authentication.
Actual (before fix): 200, full file content served.
Root cause (confirmed): `omef_backup_dir()` never wrote a protective file into the directory it creates, and WooCommerce's own `.htaccess` "deny from all" in `wc-logs`/`woocommerce_uploads` is inert because this stack's web server is **nginx** (`docker/nginx/default.conf`), which never reads `.htaccess` (an Apache-only mechanism). There was no nginx-level rule for these directories either.
Files changed: `docker/nginx/default.conf` (new `location` block denying direct access to `wc-logs`, `omef-backups`, `woocommerce_uploads` — deployed to production via the same file per `.github/workflows/deploy.yml`), `wp-content/plugins/omef-core/includes/backup.php` (`omef_backup_dir()` now also writes `.htaccess`/`index.html` stubs as defense-in-depth for any Apache-served environment).
Regression test: n/a (infra-level fix, verified live).
Independent verification: **VERIFIED.** Re-curled the previously-open backup and log files after the fix → both now `403`. A legitimate uploaded image and the homepage were re-checked and still return `200` (no over-blocking). The authenticated `admin-post.php?action=omef_backup_download` path is untouched (different URL prefix, routed through the PHP location block, not the new static-file deny rule).
**⚠️ Action needed from the store owner, outside what a code fix can do:** production runs this exact nginx config (synced by the same deploy workflow) and has very likely had this same exposure since the site went live. Recommend: (1) deploy this fix immediately, (2) review production access/error logs for GET requests to `/wp-content/uploads/omef-backups/` or `/wp-content/uploads/wc-logs/` from unfamiliar IPs, (3) as a precaution, rotate WordPress admin/user passwords (dumps contain hashes, not plaintext, but cracking is a real risk) and any API keys stored in `wp_options` that a full DB dump would expose.

### BUG-006 — Editorial "sale price" displayed but never charged at checkout
Severity: P0
Source: plugin-auditor (static)
Route: any product with `_omef_sale_price` set (product page, shop loop card, cart, checkout)
Reproduction: admin sets an editorial sale price below regular price → product page/card renders `<del>base</del> <strong>sale</strong>` → add to cart → cart/checkout total computed from the untouched regular price.
Expected: customer is charged the price shown on the product page.
Actual (before fix): customer charged the full regular price regardless of the displayed sale price.
Root cause (confirmed): `_omef_sale_price`/`_omef_full_price`/`_omef_sale_note` are a display-only custom-meta mechanism (`omef_discount()`), entirely separate from WooCommerce's native `_sale_price` field (a *different*, correctly-wired "native sale price" input already sets that one via `$product->set_sale_price()`). No code path ever applied the editorial discount to what WooCommerce actually charges.
Files changed: `wp-content/plugins/omef-core/includes/discounts.php` (new `omef_apply_discount_to_price()` hooked to `woocommerce_product_get_price` / `woocommerce_product_variation_get_price`; scoped to only the "full bottle" variation on whisky-sample-split variable products, leaving the 30 ml sample's own price untouched).
Regression test: `wp-content/plugins/omef-core/tests/DiscountsTest.php` — 4 new tests (simple-product discount applied, no discount leaves price untouched, full-bottle variation discounted, sample variation unaffected). Extended `tests/wp-stubs.php` (`Omef_Test_Product::get_id()/get_parent_id()/get_attributes()`, `sanitize_title()` stub); `tests/bootstrap.php` now also loads `product-meta.php`.
Independent verification: **VERIFIED**, including the highest-risk edge case. Ran a real end-to-end check via `wp eval` against the actual WooCommerce object system (not just stubs): set `_omef_full_price`/`_omef_sale_price` on a real product → `get_price()` correctly returned the sale price while `get_regular_price()` stayed unchanged; reverted cleanly on meta removal. Then split the same product into the sample-size variable-product structure (`omef_ensure_sample_variations`) and re-applied the discount: the full-bottle variation's price dropped from ₪380→₪300 while the 30 ml sample variation stayed at its own ₪90, completely unaffected — confirming the scoping logic is correct against real WooCommerce variation objects, not just the test stub.

### BUG-010 — Age gate hijacked robots.txt and the XML sitemap, blocking all search-engine crawling
Severity: P1 (business impact: the entire store was silently unindexable by any search engine)
Source: coordinator (SEO spot-check, static + live)
Route: `/robots.txt`, `/wp-sitemap.xml` (and sub-sitemaps/stylesheets)
Reproduction: `curl http://localhost:8080/robots.txt` → returned the age-gate's full HTML page (`Content-Type: text/html`) instead of `User-agent: * / Disallow: ... / Sitemap: ...` as `text/plain`. Same for `/wp-sitemap.xml`.
Root cause (confirmed): `omef_handle_age_gate()` is hooked to `template_redirect` at priority 0; WordPress core's `do_robots()` and `WP_Sitemaps::render_sitemaps()` are hooked to the same action at the default priority 10 — the gate's hook runs first and `exit`s before core's handlers ever run. `omef_age_gate_required()` excluded admin/AJAX/cron/REST but had no exclusion for `is_robots()` or the sitemap query vars.
Files changed: `wp-content/plugins/omef-core/includes/age-gate.php` — new `omef_age_gate_bypassed_request()` (checks `is_robots()`, `get_query_var('sitemap')`, `get_query_var('sitemap-stylesheet')`), wired into `omef_age_gate_required()`.
Regression test: `wp-content/plugins/omef-core/tests/AgeGateTest.php` (new, 4 tests). Added `is_robots()`/`get_query_var()` stubs to `tests/wp-stubs.php`.
Independent verification: **VERIFIED.** `robots.txt` now returns real `text/plain` directives (including WooCommerce's own `Disallow` rules and a `Sitemap:` pointer); `/wp-sitemap.xml` returns the real sitemap-index XML. Confirmed real content pages (home, shop) **still** show the age gate to a fresh visitor — the exemption is scoped precisely to the two crawler-only routes.

### BUG-001 — Cart/checkout table content can clip off-screen on mobile
Severity: P1
Source: theme-auditor (static)
Route: `/cart`, `/checkout`, phone viewports (~375–390px)
Root cause (confirmed): the WooCommerce cart/checkout table had no responsive stacking and no `overflow-x:auto`; because `body{overflow-x:hidden}` (required by the sticky-header fix) clips rather than scrolls, any table overflow was silently cut off — qty input, subtotal, or remove control could become unreachable.
Files changed: `wp-content/themes/omef/style.css` — `.omef-cart-page table.shop_table` / `.omef-checkout-page table.shop_table` get `display:block; overflow-x:auto` under the existing 600px mobile breakpoint.
Independent verification: **VERIFIED.** Added a real product to cart, emulated 390×844 (verified `innerWidth === 390`, not trusting `resize_page`), navigated to `/cart/`: `getComputedStyle` on the live table confirmed `display:block; overflow-x:auto` is in effect and `document.documentElement.scrollWidth > clientWidth` is `false` (no page-level overflow).

### BUG-002 — RTL select-arrow icon on wrong side
Severity: P2
Source: theme-auditor (static)
Route: checkout country/state selects, shop sort dropdown
Root cause (confirmed): `background-position: left 0.9rem center` is physical, not logical, while the icon's reserved padding is on the logical/RTL-right side — arrow renders on the wrong edge on this genuinely-RTL site.
Files changed: `wp-content/themes/omef/style.css` — added `[dir="rtl"]` overrides mirroring `background-position` (and, for the ordering select, the physical padding) to the right edge.
Independent verification: **VERIFIED.** On `/shop/`, `getComputedStyle` on the live sort dropdown showed `backgroundPositionX: calc(100% - 14.4px)` (icon now anchored to the right edge) and `padding: 8.8px 17.6px 8.8px 32px` (small gap now on the right, generous gap moved to the left) — correctly mirrored under `dir="rtl"`.

### BUG-003 — Tastings gallery mobile 2-column override was dead CSS (specificity loss)
Severity: P2
Source: theme-auditor (static)
Route: `/tastings` archive, mobile
Root cause (confirmed): the global `main .wp-block-post-template.is-layout-grid{...!important}` rule (specificity 0,2,1) always beat the mobile `.omef-workshop-gallery{...!important}` override (specificity 0,1,0) regardless of source order — intended 2-up mobile gallery rendered single-column.
Files changed: `wp-content/themes/omef/style.css` — raised the override's selector to `main .wp-block-post-template.omef-workshop-gallery.is-layout-grid` (0,3,1).
Independent verification: **VERIFIED.** At 390×844 on `/tastings/`, `getComputedStyle` on the live gallery grid showed `gridTemplateColumns: "179.484px 179.484px"` — genuinely 2 columns, not 1.

### BUG-004 — Age-gate error text hardcoded hex instead of design token
Severity: P3
Source: theme-auditor (static)
Files changed: `wp-content/themes/omef/style.css` — `.omef-age-gate__error` now uses `var(--omef-clay)` instead of the hardcoded `#9c2d1e` (same color family, now token-consistent).
Independent verification: verified by code inspection (no visible-behavior change; purely a maintainability fix).

### BUG-007 — Podcast admin AJAX endpoints gated at `edit_posts` instead of `manage_woocommerce` (SSRF-adjacent)
Severity: P2
Source: plugin-auditor (static)
Route: wp-admin dashboard, podcast settings/import AJAX
Scenario: every other dashboard AJAX handler requires `manage_woocommerce`/`manage_options`; podcast settings/import alone used the broader `edit_posts` (any Editor, not just admins/managers — contradicts the plugin's own access model). Import triggers a server-side `fetch_feed()` against an admin-editable URL restricted only to the `https://` scheme (no host allowlist) — an Editor could point the server at an arbitrary HTTPS URL.
Files changed: `wp-content/plugins/omef-core/includes/podcast.php` — all three handlers now gate on `manage_woocommerce`.
Independent verification: verified by code review + full PHP lint pass; no live repro attempted (would require creating a lower-privileged Editor account, out of scope for a capability-check fix this direct).

### BUG-008 — Customer name interpolated unescaped into HTML order emails
Severity: P3
Source: plugin-auditor (static)
Scenario: `{customer_name}` was substituted into the HTML email body/subject without `esc_html()`, unlike every other placeholder in the same function. WooCommerce's own checkout sanitizer strips `<`/`>` before the name is saved, so this wasn't a demonstrated live XSS, but was inconsistent defense-in-depth.
Files changed: `wp-content/plugins/omef-core/includes/email-settings.php` — wrapped in `esc_html()`.
Independent verification: verified by code review + lint.

### BUG-011 — Checkout validation error summary was invisible (paper-colored text on light-pink banner)
Severity: P2
Source: browser-qa (live, both environments)
Route: `/checkout/`, any validation failure
Scenario: the error summary heading was visible, but every list item/link underneath rendered in the sitewide "paper" link color on the notice's light-pink background — invisible. Users could still complete checkout via the separately-rendered dark-red inline per-field messages, so this wasn't a hard blocker, but the primary error summary was broken.
Root cause (confirmed): same bug class as the age-gate invisible-link issue fixed earlier this project — `theme.json`'s sitewide link color is tuned for the dark page background. `style.css` had a light-background override for `.wc-block-components-notice-banner.is-info` but none for `.is-error`.
Files changed: `wp-content/themes/omef/style.css` — added `color: var(--omef-ink)` for `.wc-block-components-notice-banner.is-error` (+ its links) and `.woocommerce-error`.
Independent verification: **VERIFIED.** Reproduced live via a real checkout submission (added a product to cart, submitted with all fields empty). `getComputedStyle` showed banner background unchanged (`rgb(255,240,240)`) and text/link color now `rgb(28,23,18)` (`--omef-ink`, was invisible paper color). Screenshot `.qa/checkout-error-summary-fixed.png` shows all 5 error items clearly legible.

### BUG-012 — Zero-result search rendered a completely blank page
Severity: P2
Source: browser-qa (live, both environments)
Route: `/?s=<term>` for any term with no matches
Root cause (confirmed): the theme has no dedicated `search.html` template, so WordPress falls back to `templates/index.html`, whose `wp:query`/`wp:post-template` block renders nothing on zero posts — no `core/query-no-results` fallback existed.
Files changed: `wp-content/themes/omef/templates/index.html` — added a `wp:query-no-results` block (Hebrew message + link back to the shop) alongside `wp:post-template`.
Independent verification: **VERIFIED.** Curl-confirmed a zero-match search now renders the message while a real Hebrew search (12 matches) still renders normal results, not the no-results branch. Screenshot `.qa/search-no-results-fixed.png` shows the message and a working "לחנות" link.

### BUG-013 — Age-gate page has no `<title>` tag
Severity: P3
Source: coordinator (SEO/UX spot-check)
Scenario: every first-time visitor (and any crawler reaching a page before verifying age) got a blank browser-tab title — the theme declares no `add_theme_support('title-tag')` and the age-gate template never emitted a `<title>` element itself.
Files changed: `wp-content/themes/omef/age-gate.php` — added `<title><?php echo esc_html( wp_get_document_title() ); ?></title>`, so the tab title reflects whatever destination was actually requested.
Independent verification: verified live — fresh homepage visit shows `<title>Omef</title>`; a gated visit to `/shop/` shows `<title>החנות – Omef</title>`.

## Confirmed, Not Fixed (documented, low priority)

### BUG-009 — Backup DB password passed on mysqldump command line
Severity: P3
Source: plugin-auditor (static)
File: `wp-content/plugins/omef-core/includes/backup.php`, `omef_backup_now()`
Scenario: `-p%s` interpolates the raw DB password into a shell command run via `exec()`, briefly visible to other local users/processes via `ps aux`/`/proc/<pid>/cmdline` during the dump.
Why not fixed: the safe fix (switch `exec()`→`proc_open()` to pass the password via environment instead of argv) is a more invasive rewrite of a working, core backup path, with real regression risk, for a P3 that requires an already-compromised host to exploit. Recommended as an independently-tested follow-up.

## Age Gate Findings
Fully exercised: first visit, accept, reject, reload, fresh isolated context, back button, direct-URL navigation trap, mobile layout (390×844), full keyboard flow (Tab→Space→Tab→Enter submits). All correct. The "under 18" link (a known past bug, already fixed) remains visible with good contrast. One new issue found and fixed: the gate page itself had no `<title>` tag (BUG-013). One more found and fixed, with major SEO consequences: the gate was intercepting `/robots.txt` and the sitemap for every crawler (BUG-010).

## Product / Catalog Findings
3+ products spot-checked (varied price/stock): title/image/gallery/price/description/related-products all correct; listing price = product-page price = cart price consistently. The one real defect found here was not visual but financial: the editorial sale-price display was disconnected from the actual charge (BUG-006, P0, fixed).

## Search Findings
Sorting works correctly (verified genuinely monotonic price ordering, not just visually plausible). Real Hebrew-term searches return correct results. Zero-result searches rendered a blank page with no messaging (BUG-012, fixed). No dedicated search UI (icon/box) exists in the header or footer on either environment — present on both local and live, so this is a design characteristic rather than a regression; noted but not treated as a bug.

## Cart Findings
Add-to-cart, quantity update (recalculates correctly), re-adding the same product (merges into one line rather than duplicating), quantity-0 removal — all correct. Mobile table-overflow risk found and fixed (BUG-001).

## Checkout Findings
Required-field validation blocks empty submission correctly (both client- and server-side); totals/currency display correctly; no real payment was submitted. The validation error **summary** was unreadable due to an invisible-text bug (BUG-011, fixed) — the per-field inline messages were fine throughout, so checkout was never actually blocked, just harder to diagnose.

## Mobile / Responsive Findings
No horizontal overflow (`scrollWidth > clientWidth`) at 360/390/430/768/1440 on home/shop/cart/checkout/product, both before and after this session's fixes. Sticky header and mobile nav overlay (previously-fixed regressions) re-verified solid. New table-overflow risk found and fixed (BUG-001); RTL select-arrow and dead-CSS gallery-grid issues found and fixed (BUG-002, BUG-003).

## RTL / Hebrew Findings
`dir="rtl"`/`lang="he-IL"`/`direction: rtl` all correctly active locally (WPLANG=he_IL). RTL select-arrow icon mismatch found and fixed (BUG-002). Live environment shows WP-core/WooCommerce UI strings in English despite correct mirroring — a locale/translation-pack difference on that server, not a code defect (see Baseline section).

## Browser Console Findings
No console errors across all pages tested on either environment, before or after fixes.

## Network Findings
No 4xx/5xx beyond a benign 302 favicon redirect. No stray Airwallex/checkout-modal network calls outside `/checkout` on either environment (re-verified the existing fix holds). One accessibility advisory (missing `autocomplete` on one checkout field) — not a functional bug, not separately fixed in this pass.

## Accessibility Findings
Full keyboard flow verified through the age gate. Missing `autocomplete` attribute noted on one checkout field (advisory, not fixed). No other accessibility defects surfaced by either static or live review.

## Security Findings
Two P0s: public, unauthenticated database backup/log exposure (BUG-005) and a pricing-integrity gap letting a displayed discount go uncharged (BUG-006, a "client trusts displayed price" class of bug, though the discount was never client-submitted — the server itself just never applied it). One P2 (BUG-007, capability mismatch enabling admin-tier SSRF-adjacent behavior), two P3s (BUG-008 unescaped email field, BUG-009 password-on-cmdline, not fixed). Everything else explicitly checked and found sound: AJAX nonce/capability coverage, SQL injection, path traversal, cookie flags, hook-scope leaks beyond the already-known Airwallex case.

## Performance Findings
No systemic issues found in this pass; not a deep focus area given the higher-severity findings elsewhere. No duplicate JS bundles or excessive third-party scripts observed (theme has no JS at all).

## SEO Findings
The most significant finding of the audit: the age gate was silently blocking `/robots.txt` and the entire XML sitemap from ever reaching a search engine (BUG-010, fixed). Titles, canonical tags, and structured data (`application/ld+json`) were spot-checked on shop/product pages and found correct and unique per page. Age-gate page itself lacked a title tag (BUG-013, fixed).

## Regression Tests Added
- `wp-content/plugins/omef-core/tests/DiscountsTest.php` — 4 new tests for BUG-006 (editorial discount pricing).
- `wp-content/plugins/omef-core/tests/AgeGateTest.php` — new file, 4 tests for BUG-010 (robots/sitemap bypass).
- `wp-content/plugins/omef-core/tests/wp-stubs.php` — extended with `sanitize_title()`, `is_robots()`, `get_query_var()` stubs and additional `Omef_Test_Product` methods.
- `wp-content/plugins/omef-core/tests/bootstrap.php` — now also loads `product-meta.php` and `age-gate.php`.
- Full suite: **22/22 passing.**
- CSS-only and block-markup fixes (BUG-001–004, 011–013) have no unit-test equivalent in this codebase's PHPUnit harness; independently verified live instead (see each bug's entry).

## Files Changed
- `docker/nginx/default.conf`
- `wp-content/plugins/omef-core/includes/age-gate.php`
- `wp-content/plugins/omef-core/includes/backup.php`
- `wp-content/plugins/omef-core/includes/discounts.php`
- `wp-content/plugins/omef-core/includes/email-settings.php`
- `wp-content/plugins/omef-core/includes/podcast.php`
- `wp-content/plugins/omef-core/tests/DiscountsTest.php`
- `wp-content/plugins/omef-core/tests/AgeGateTest.php` (new)
- `wp-content/plugins/omef-core/tests/bootstrap.php`
- `wp-content/plugins/omef-core/tests/wp-stubs.php`
- `wp-content/themes/omef/age-gate.php`
- `wp-content/themes/omef/style.css`
- `wp-content/themes/omef/templates/index.html`

12 files modified, 1 new file. No unrelated files touched; no dependency/version changes.

## Commands Run
- `git status`, `git log`, `docker ps` (baseline)
- `find wp-content/plugins/omef-core wp-content/themes/omef wp-content/mu-plugins -name '*.php' | xargs -n1 php -l` (via `docker exec`) — full lint, run repeatedly through the session, clean every time
- `docker exec whiskyshop-wordpress-1 php /tmp/phpunit.phar --bootstrap wp-content/plugins/omef-core/tests/bootstrap.php wp-content/plugins/omef-core/tests` (standalone PHPUnit 11 phar — composer/vendor not available in this environment)
- `wp eval` (via `docker exec`) for live WooCommerce object-system verification of BUG-006
- `curl` against local (`localhost:8080`) for route/header/status verification throughout
- chrome-devtools MCP (`emulate`, `evaluate_script`, `take_screenshot`, `navigate_page`) for live browser verification of CSS/markup fixes
- `docker restart whiskyshop-nginx-1` (recovery after an accidental `docker cp` onto the read-only bind-mounted nginx config — no data loss, host file was always the source of truth)

## Final Validation
Build: N/A (no JS build system)
Lint: **PASS** — full PHP lint across `omef-core`, `omef` theme, and mu-plugins, clean
Typecheck: N/A (PHP, untyped)
Unit: **PASS — 22/22** (PHPUnit, run via standalone phar; composer/vendor unavailable in this environment per the project's own documented limitation)
Integration: not run (no WP-integration test harness exists in this repo)
E2E: not run (Playwright is available per project tooling but no test files exist yet in `.playwright`; not authored in this pass — see recommendations)
Browser smoke test: **PASS** — age gate, homepage, shop, product, cart, checkout (to the safe pre-payment boundary), search, tastings, robots.txt, sitemap.xml, and mobile viewport all manually re-verified live after fixes landed

## Deployment Verification
All fixes were validated against the **local** Docker environment (`localhost:8080`), which runs this exact repo's code. The live site (sslip.io) is this repo's real deploy target (confirmed via the CI/CD workflow) but was **not modified** — no deployment was performed or authorized in this session. The nginx and age-gate/pricing fixes are especially time-sensitive for production (see BUG-005's action-needed note) and should be deployed and re-verified against the live site as a follow-up.

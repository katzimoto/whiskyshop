# The Omef

Local development environment for the The Omef WordPress and WooCommerce rebuild.

## Stack

- nginx and PHP-FPM WordPress (with wp-cli built in)
- MariaDB
- Redis object cache
- Mailpit for local email inspection
- Custom theme and site plugin mounted from `wp-content/`

WooCommerce remains the source of truth for products, stock, orders, payments and refunds. The custom theme and `omef-core` plugin contain presentation and site-specific functionality respectively.

## Start locally

1. Install and start OrbStack (the preferred Docker backend). Docker Desktop also works.
2. Copy `.env.example` to `.env` and replace the local database passwords.
3. Run `docker-compose up --build`.
4. Open `http://localhost:8080` and complete the WordPress installer.
5. Activate the `Omef` theme and the `Omef Core` plugin after they are added.
6. First boot only, enable the object cache backed by Redis:
   `docker-compose exec wordpress wp plugin install redis-cache --activate`
   `docker-compose exec wordpress wp redis enable`

Mail sent locally is visible at `http://localhost:8025`.

## Repository layout

```text
docker/                 Local nginx, WordPress and Redis configuration
wp-content/themes/      Version-controlled custom theme
wp-content/plugins/     Version-controlled site-specific plugin (omef-core)
wp-content/uploads/     Local media uploads and backups, excluded from Git
```

## What omef-core does

- Two content types (podcast episodes, workshops, tastings) with product/metadata editorial fields
- 30 ml sample pricing on any product, turned into a WooCommerce variable product
- Editorial discounts (sale price with a reason) shown as strikethrough pricing
- Age gate (18+), alcohol notice, and a read-only shop-manager dashboard
- Tightened product publishing: image, ALT text, price and stock are required
- One-tap checkout: guest sales, COD or bank transfer, and a preselect payment
- Abandoned-cart capture with a single 24h reminder email
- Self-service accounts: customers register and buy, only admins/managers can edit
- SEO 301 redirect manager for moved bottle pages
- Scheduled gzip DB backups with retention and an admin download page

## Safety

- `.env` contains local-only secrets and is never committed.
- Docker volumes preserve the local database and WordPress installation, plus Redis keys, between restarts.
- Use `docker-compose down -v` only when intentionally discarding local data.
- Staging and production use separate credentials and do not share customer data with local development.

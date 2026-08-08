# The Omef

Local development environment for the The Omef WordPress and WooCommerce rebuild.

## Stack

- nginx and PHP-FPM WordPress
- MariaDB
- Mailpit for local email inspection
- Custom theme and site plugin mounted from `wp-content/`

WooCommerce remains the source of truth for products, stock, orders, payments and refunds. The custom theme and `omef-core` plugin will contain presentation and site-specific functionality respectively.

## Start locally

1. Install and start OrbStack (the preferred Docker backend). Docker Desktop also works.
2. Copy `.env.example` to `.env` and replace the local database passwords.
3. Run `docker-compose up --build`.
4. Open `http://localhost:8080` and complete the WordPress installer.
5. Activate the `Omef` theme and the `Omef Core` plugin after they are added.

Mail sent locally is visible at `http://localhost:8025`.

## Repository layout

```text
docker/                 Local nginx and WordPress image configuration
wp-content/themes/      Version-controlled custom theme
wp-content/plugins/     Version-controlled site-specific plugin
wp-content/uploads/     Local media uploads, excluded from Git
```

## Safety

- `.env` contains local-only secrets and is never committed.
- Docker volumes preserve the local database and WordPress installation between restarts.
- Use `docker-compose down -v` only when intentionally discarding local data.
- Staging and production use separate credentials and do not share customer data with local development.

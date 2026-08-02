# the-omef.com

Rebuild of חוזק חבית / The Omef: WordPress + WooCommerce, self-hosted, RTL-native.
See the project epic and phase issues in this repo's Issues tab for the full plan.

## Local development

```bash
cp .env.example .env.local
docker compose --env-file .env.local up -d
```

Site is served at http://localhost:8080. WordPress core comes from the
`wordpress:php8.3-fpm-alpine` base image; only `wp-content` is bind-mounted
from this repo, so themes, mu-plugins and (later) plugins are version
controlled while core stays out of the repo.

Environments (`local` / `staging` / `production`) are selected by which
`.env.*` file and compose overlay you pass — see `docker-compose.staging.yml`
and `docker-compose.production.yml`. Integration adapters (payment, invoicing,
podcast feed, mail) default to their mock drivers everywhere except
production; see `PAYMENT_DRIVER` / `INVOICE_DRIVER` in `.env.example`. An
mu-plugin (`wp-content/mu-plugins/omef-env-guard.php`) refuses to boot if a
mock driver is ever active in production.

## Code quality

```bash
composer install
composer run lint    # php -l syntax check
composer run phpcs   # WordPress Coding Standards
composer run test    # PHPUnit
```

All three run in CI on every push and pull request (`.github/workflows/ci.yml`),
alongside a Docker Compose config validation for all three environments.

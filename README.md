# Coachwood Tools

Internal tools for Coachwood Group, served at https://tools.coachwoodgroup.com. This app replaces the old tools app (`Dh42/CompanyTools`, https://tools.biosteel.com). It is being rebuilt in phases; see `docs/phase-0-plan.md` for the current phase.

## Stack

- Laravel 13 on PHP 8.4
- MariaDB 10.11 (production); SQLite in tests
- Blade and Vite
- PHPUnit 12 with ParaTest

## Local setup

Requirements: PHP 8.4 with `pdo_mysql` and `pdo_sqlite`, Composer, Node 22 with npm.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build        # or `npm run dev` while working on assets
php artisan serve
```

`.env.example` uses SQLite by default. To match production, point `DB_*` at a local MariaDB 10.11 database instead.

### The legacy connection

The `legacy` connection (`config/database.php`) reads the old app's database, `tools_db`. Only the `legacy:import-*`, `legacy:sync` and `compare:*` commands use it.
- **Locally,** point it at a disposable restore of an export, never at production.
- **On the server,** it uses `tools_legacy_ro`, a user with `SELECT` on `tools_db` only. That grant is what keeps the old database safe.
- **In tests,** `phpunit.xml` forces it to an empty in-memory SQLite database.

### External writes

`TOOLS_EXTERNAL_WRITES` switches every write to an outside system (Shopify, Klaviyo, Amazon). It stays `false` everywhere until cutover. Code checks it only through `App\Support\ExternalWrites`. Tests force it off.

## Tests

```bash
php artisan test --parallel
vendor/bin/pint --test
```

Tests run on in-memory SQLite by default. To run them on MariaDB, as CI's second leg does, set real environment variables. They win over `phpunit.xml`:

```bash
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=coachwood_tools_test \
DB_USERNAME=... DB_PASSWORD=... php artisan test --parallel
```

Parallel runs create `coachwood_tools_test_test_N` databases, so the user needs rights on `coachwood_tools_test%`.

No test may make a live call. `Tests\TestCase` calls `Http::preventStrayRequests()`, so any request not matched by `Http::fake()` fails the test.

CI (`.github/workflows/ci.yml`) runs Pint, the Vite build, and the suite on SQLite and on MariaDB 10.11.14.

## Deploying

Plesk pulls `main` into `/var/www/vhosts/tools.coachwoodgroup.com/httpdocs`. The document root is `httpdocs/public`.

**One-time setup:**
1. Place `.env` in `httpdocs/`, made from `docs/server.env.example`, before the first deploy.
2. Add one cron entry, every minute:
   ```
   /opt/plesk/php/8.4/bin/php /var/www/vhosts/tools.coachwoodgroup.com/httpdocs/artisan schedule:run
   ```

**Deploy actions** (Plesk > Git > Additional deployment actions). Use the same Composer path as the old site:

```bash
/opt/plesk/php/8.4/bin/php <path-to-composer.phar> install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
/opt/plesk/php/8.4/bin/php artisan migrate --force
/opt/plesk/php/8.4/bin/php artisan optimize
```

`artisan optimize` caches config. After editing `.env` on the server, run `php artisan optimize` again, or deploy.

Migrations run on every deploy. A migration must never call an outside API or run a heavy backfill. Those go in Artisan commands that Lesley starts on the server.

## Documents

| File | Purpose |
| --- | --- |
| `docs/build-spec.md` | The build specification |
| `docs/decisions.md` | Decisions as they close. Overrides the spec where they differ |
| `docs/phase-0-plan.md` | The approved Phase 0 plan |
| `docs/found-issues.md` | Bugs found in the old code, and their status |
| `docs/context.md` | Dated log of changes and anything verified live |
| `docs/server.env.example` | Template for the production `.env` |

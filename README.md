# Coachwood Tools

Internal tools for Coachwood Group, served at https://tools.coachwoodgroup.com. This app replaces the old tools app (`Dh42/CompanyTools`, https://tools.biosteel.com). It is being rebuilt in phases; see `docs/phase-0-plan.md` for the current phase.

## Stack

- Laravel 13 on PHP 8.4
- MariaDB 10.11 (production); SQLite in tests
- Blade and Vite
- PHPUnit 12 with ParaTest

## Local setup

Requirements: PHP 8.4 with `pdo_mysql` and `pdo_sqlite`, Composer, Node 22 or later with npm. The server builds with Node 26.

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

**Auto-merge.** `.github/workflows/auto-merge.yml` merges a pull request into `main`, and so deploys it, once CI passes on its latest commit. It also deletes the branch. To stop one merging, open it as a draft or add the `hold` label. A merge made this way does not re-run CI on `main`, because GitHub does not start workflows from its own token. The Plesk webhook still fires.

## Deploying

Plesk pulls `main` into `/var/www/vhosts/tools.coachwoodgroup.com/httpdocs`. The document root is `httpdocs/public`.

**One-time setup:**
1. Place `.env` in `httpdocs/`, made from `docs/server.env.example`, before the first deploy.
2. Add one cron entry, every minute:
   ```
   /opt/plesk/php/8.4/bin/php /var/www/vhosts/tools.coachwoodgroup.com/httpdocs/artisan schedule:run
   ```

**Deploy actions** (Plesk > Git > Additional deployment actions), one per line. These follow the old site's actions, with PHP 8.4 in place of 8.3:

```bash
export PATH="/opt/plesk/node/26/bin:/opt/plesk/php/8.4/bin:$PATH" && php /opt/psa/var/modules/composer/composer.phar install --no-dev --optimize-autoloader --no-interaction
export PATH="/opt/plesk/node/26/bin:/opt/plesk/php/8.4/bin:$PATH" && npm ci
export PATH="/opt/plesk/node/26/bin:/opt/plesk/php/8.4/bin:$PATH" && npm run build
export PATH="/opt/plesk/node/26/bin:/opt/plesk/php/8.4/bin:$PATH" && php artisan migrate --force
export PATH="/opt/plesk/node/26/bin:/opt/plesk/php/8.4/bin:$PATH" && php artisan storage:link
export PATH="/opt/plesk/node/26/bin:/opt/plesk/php/8.4/bin:$PATH" && php artisan optimize
```

`artisan optimize` caches config. After editing `.env` on the server, run `php artisan optimize` again, or deploy.

Migrations run on every deploy. A migration must never call an outside API or run a heavy backfill. Those go in Artisan commands that Lesley starts on the server.

## One-time commands

### `amazon:probe` (Phase 0)

The probe is a read-only check of what the Amazon account can call. It tries 25 capabilities once each: reports, Data Kiosk field groups, Finance Remittance, Vendor Orders per vendor code, and seller offers on Amazon.ca. The results go into the `amazon_capabilities` table, which later code checks before relying on an endpoint or field.

```bash
php artisan amazon:probe --list                       # what it will try; calls nothing
nohup php artisan amazon:probe --asin=<one Amazon.ca ASIN> --no-interaction > storage/logs/probe.log 2>&1 &
php artisan amazon:probe --only=report.traffic        # re-run one capability
```

**Safety:**
- `App\Amazon\Probe\ProbeClient` refuses any write before a request is sent.
- The only POSTs it allows create a report or a Data Kiosk query.

**Run time:** up to about 45 minutes. Run it on a weekday between 10:00 and 22:00 UTC, when the old app's SP-API tasks are quiet. Those tasks run 03:00–08:00 UTC daily, plus Sunday from 00:00. `nohup` keeps it running if SSH drops.

**Results:**
- **Summary,** safe to share: `storage/app/private/probe/summary.json`. It holds statuses, error codes, which fields came back and how often they are empty, and the ranges of rate fields. It contains no ASINs, amounts, IDs or credentials.
- **Raw responses** are in `storage/app/private/probe/raw/`. They stay on the server and are deleted after Phase 1.

## Documents

| File | Purpose |
| --- | --- |
| `docs/build-spec.md` | The build specification |
| `docs/decisions.md` | Decisions as they close. Overrides the spec where they differ |
| `docs/phase-0-plan.md` | The approved Phase 0 plan |
| `docs/found-issues.md` | Bugs found in the old code, and their status |
| `docs/context.md` | Dated log of changes and anything verified live |
| `docs/server.env.example` | Template for the production `.env` |

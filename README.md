# Coachwood Tools

Internal tools for Coachwood Group, served at https://tools.coachwoodgroup.com. This app replaces the old tools app (`Dh42/CompanyTools`, https://tools.biosteel.com). It is being rebuilt in phases; see `docs/phase-0-plan.md` for the current phase.

## Stack

- Laravel 13 on PHP 8.4
- MariaDB 10.11 (production); SQLite in tests
- Blade and Vite, with Tailwind CSS 4 and Chart.js
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

CI (`.github/workflows/ci.yml`) runs on every push to every branch: Pint, the Vite build, and the suite on SQLite and on MariaDB 10.11.14.

**Shipping a change.** Make a branch from `main`, commit, and push it. Nothing else:
1. CI runs on the branch.
2. When it passes, `.github/workflows/auto-merge.yml` opens a pull request for the branch, titled with the latest commit's first line, and merges it into `main`. It also deletes the branch.
3. The merge deploys through the Plesk webhook.

A failing run merges nothing. Fix it and push again.

**To stop a branch merging:**
- name it `wip/...`, or
- open its pull request yourself as a draft, or add the `hold` label, before CI finishes.

**It also skips:**
- a branch that is behind `main`. Rebase it, or merge `main` into it, and push again.
- a commit that is no longer the branch's latest. The newer commit's run decides.

Never push to `main` directly. Plesk deploys `main` whether or not CI passed.

A merge made this way doesn't re-run CI on `main`, because GitHub doesn't start workflows from its own token. The Plesk webhook still fires.

## Front end

The page shell and themes come from the Claude Design canvas "Coachwood Tools Redesign".
- **Tokens:** `resources/css/tokens.css` is the only place with literal colours, sizes and fonts. It holds four themes: Midnight (default), Graphite, Daylight and Evergreen. `tests/Unit/View/TokensOnlyTest.php` fails if a view hard-codes a colour or pixel size.
- **Styles:** `resources/css/app.css` holds the component classes (`.btn`, `.card`, `.tone-*`, `.table` and so on) and maps the tokens to Tailwind utilities (`bg-surface`, `text-muted`).
- **Layouts:** `<x-layouts.app>` (sidebar and page header) and `<x-layouts.guest>` (sign-in and errors).
- **Shared components** in `resources/views/components`:
  - page header, card, stat row and stat tile, status badge, freshness badge
  - notice, empty state, data state (loading, empty, error, partial, complete)
  - filter bar, tabs, data table with `x-th`, modal, toasts
  - chart card, incident card, tool tile, icon
- **Gallery:** run `php artisan serve` and open `/dev/components` to see every component with made-up values. The route exists only in the local and testing environments.
- **Theme and text size** are plain cookies (`theme`, `font_size`), so the first response renders the right theme. Changing the theme fires `theme-changed` on `document`.
- **Charts:** use `window.themedChart(canvas, config)`. It colours datasets from the six-colour series palette and recolours them when the theme changes. `chartTheme()`, `seriesColor(i)` and `onThemeChange(fn)` are also global.

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

## Signing in

Sign-in is **Google only** (decision 2026-11-27): no passwords, no password reset, no registration.

- **Who can get in:** the Google OAuth client is internal to the org. On top of that, the app only accepts Workspace accounts (Google's `hd` claim) on a domain in `google_workspace_domains`. With no domain in that table, nobody can sign in. `legacy:import-platform` copies the domains; Admin > Google Sign-In (3f) manages them.
- **First sign-in:** an imported account is linked to the Google account with the same email. Someone with no account gets one with the `user` role, or `admin` if their email is `INITIAL_ADMIN_EMAIL`. An email already linked to a different Google account is refused.
- **Roles:** `admin` and `user` are created by a migration. Admins pass every permission check (`Gate::before` in `AppServiceProvider`). Everyone else needs the permission itself, for example `tool.<slug>`.
- **Disabled accounts** (`users.disabled_at`) are refused at sign-in and signed out on their next request, remember-me cookie or not.
- **Profile:** the name can be changed. The email comes from Google. Users can't delete their own account.
- **Theme and text size:** saved to the user when signed in, so they follow them to other devices. The cookies are the fallback, and keep the sign-in page in the last theme used on that browser.

**Server settings:** `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI` (`https://tools.coachwoodgroup.com/auth/google/callback`, which must be an authorised redirect URI on the OAuth client), and `INITIAL_ADMIN_EMAIL`.

## Imports from the old app

### `legacy:import-platform`

Copies the platform tables from `tools_db` through the read-only `legacy` connection. It is safe to run again: each run brings this app back in line with the old one. It prints counts only.

```bash
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-platform --dry-run   # reports, then rolls back
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-platform
```

| Table | How |
| --- | --- |
| `users` | IDs preserved. Name, email, Google ID, verified and disabled dates are overwritten from the old app; the theme and text size chosen here are kept. Password hashes and remember tokens are not copied, and any here are cleared |
| `roles`, `permissions` | Matched by name and guard |
| User roles and direct permissions, role permissions | Replaced with the old app's for each imported user, re-mapped to the new IDs |
| `google_workspace_domains` | Matched by domain |

It stops before writing anything, and names the IDs, if an old user would overwrite a different person here: their email belongs to another ID, or their ID belongs to someone with a different email and Google ID (a person who signed in here first). Later platform slices add tools, teams, the activity log and scheduled tasks to this command.

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

### `amazon:history-grab` (one-off, late 2024)

This saves Data Kiosk history from before the old app's weekly data starts on 2024-12-29. Amazon refuses dates more than two years back, so these days age out one by one:
- Black Friday 2024 goes after 2026-11-28
- the last whole week, 2024-12-29 to 2025-01-04, goes in early January 2027

```bash
php artisan amazon:history-grab --list     # the queries and dates; calls nothing
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan amazon:history-grab --no-interaction
```

- **What it fetches:** nine read-only Data Kiosk queries, run one at a time:
  - daily sales
  - weekly sales and costs, orders, traffic, sourcing, returns (not inventory: Amazon keeps weekly inventory for only 8 weeks)
  - weekly sales by vendor code, province and city
- **Run time:** about one to two minutes per query.
- **Output:** files go to `storage/app/private/amazon/history-2024/`. They stay on the server; Phase 1 loads them.
- **Safe to send:** `manifest.json`, which holds the queries, statuses and counts.
- **Failures:** running the command again fetches only what failed.
- **Safety:** nothing is written to the database. The client refuses any write.

## Documents

| File | Purpose |
| --- | --- |
| `docs/build-spec.md` | The build specification |
| `docs/decisions.md` | Decisions as they close. Overrides the spec where they differ |
| `docs/phase-0-plan.md` | The approved Phase 0 plan |
| `docs/found-issues.md` | Bugs found in the old code, and their status |
| `docs/context.md` | Dated log of changes and anything verified live |
| `docs/server.env.example` | Template for the production `.env` |

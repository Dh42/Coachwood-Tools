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

CI (`.github/workflows/ci.yml`) runs on every push: Pint, the Vite build, and the suite on SQLite and on MariaDB 10.11.14.

**Shipping a change.** Commit to `main` and push (decision 2026-12-02). Nothing else:
1. CI runs on the commit.
2. When it passes, `.github/workflows/release.yml` moves the `release` branch to that exact commit.
3. Plesk deploys `release` through its webhook.

A failing run releases nothing. Fix it and push again. If two pushes come close together, CI cancels the older run and only the newer commit is released. `release` only moves forward, and is never committed to by hand.

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

Plesk pulls the `release` branch (see "Shipping a change") into `/var/www/vhosts/tools.coachwoodgroup.com/httpdocs`. The document root is `httpdocs/public`.

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

- **Who can get in:** the Google OAuth client is internal to the org. On top of that, the app only accepts Workspace accounts (Google's `hd` claim) on a domain in `google_workspace_domains`. With no domain in that table, nobody can sign in. `legacy:import-platform` copies the domains; Admin > Google Sign-In manages them.
- **First sign-in:** an imported account is linked to the Google account with the same email. Someone with no account gets one with the `user` role, or `admin` if their email is `INITIAL_ADMIN_EMAIL`. An email already linked to a different Google account is refused.
- **Roles:** `admin` and `user` are created by a migration. Admins pass every permission check (`Gate::before` in `AppServiceProvider`). Everyone else needs the permission itself, for example `tool.<slug>`.
- **Disabled accounts** (`users.disabled_at`) are refused at sign-in and signed out on their next request, remember-me cookie or not.
- **Profile:** the name can be changed. The email comes from Google. Users can't delete their own account.
- **Theme and text size:** saved to the user when signed in, so they follow them to other devices. The cookies are the fallback, and keep the sign-in page in the last theme used on that browser.

**Server settings:** `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI` (`https://tools.coachwoodgroup.com/auth/google/callback`), and `INITIAL_ADMIN_EMAIL`. The OAuth client needs two authorised redirect URIs: that one, and `https://tools.coachwoodgroup.com/admin/settings/mail/callback` for Admin > Mail.

## Activity log

[spatie/laravel-activitylog](https://spatie.be/docs/laravel-activitylog), table `activity_log`, kept 365 days (`config/activitylog.php`; the daily clean-up is scheduled in 3h). The viewer is Admin > Activity (3e).

The `auth` log records every sign-in outcome:
- `Login succeeded` (with `method: google`)
- `Google account created`
- `Login blocked - domain not allowed`, `- no domain configured`, `- account disabled` and `- linked to a different Google account`. The refused address is in the entry's properties.
- `Logged out`
- `Signed out - account disabled`

Code anywhere can log its own events under its own name:

```php
activity('tool.invoicing')->causedBy($user)->performedOn($invoice)->log('Invoice approved');
```

## Tools registry

Each tool is a row in `tools` with a `tool.<slug>` permission. The home launcher and the sidebar show the active tools a user may open, grouped by `group` (empty shows under "Tools") and in Admin > Tools order. Admins see every active tool. An inactive tool is hidden from everyone.

**Adding a tool:** register it in a migration, so the deploy that adds the tool also adds its entry and permission:

```php
public function up(): void
{
    ToolRegistry::register([
        'slug' => 'po-checker',               // permission: tool.po-checker
        'name' => 'PO Checker',
        'description' => 'Check a POItemExport against Cin7 stock.',
        'group' => 'Amazon · Purchase orders',
        'icon' => 'box',                      // an App\View\Icons name, or an emoji
        'url' => '/tools/po-checker',
    ]);
}

public function down(): void
{
    ToolRegistry::unregister('po-checker');
}
```

Registering only inserts, so it never overwrites what an admin changed. A new tool goes to the end of the order.

**Admin > Tools** (`/admin/tools`): add, edit and delete tools, and reorder them by dragging or with the Move up and Move down buttons. Deleting a tool removes its permission and every grant of it.

## Admin pages

All under `/admin`, for the `admin` role only. The sidebar's Admin group links them.

- **Users** (`/admin/users`): invite people, and set the role, tool access and teams for each person. Only addresses on a company domain can be invited; the invitation is a "Sign in with Google" email, and Resend invite sends it again while they haven't signed in. A failed send leaves the person set up and says why. Every tool is listed, inactive ones marked; saving changes only `tool.*` permissions, so access to an inactive tool comes back when it is switched on. Deactivate signs someone out and keeps them out; delete is offered only once they are deactivated. You can't deactivate yourself or remove your own admin role. Someone who signs in without an invitation appears here with the `user` role and no tools.
- **Teams** (`/admin/teams`): named groups of people. No tool uses them yet.
- **Tools** (`/admin/tools`): see Tools registry.
- **Scheduler** (`/admin/schedules`): every background task, with its schedule, last result and next run. Edit the schedule and timezone (the command is fixed by the tool), enable or disable, Run Now, and see each run's status, duration, who started it and its output. A failed run emails every active admin.
- **Activity** (`/admin/activity`): the activity log, newest first, 25 a page, filtered by category.
- **Mail** (`/admin/settings/mail`): connect the Gmail account the app sends from (send-only access), and send yourself a test email. Every email goes through it (`MAIL_MAILER=gmail_api`, `App\Mail\Transport\GmailApiTransport`). If Google rejects the cached access token, it is dropped and the send is tried once more.
- **Google Sign-In** (`/admin/settings/google-domains`): the Workspace domains allowed to sign in. With none, nobody can sign in, so removing the last one warns that it locks everyone out, admins included. Adding and removing are logged.

## Scheduler

Database-driven, as in the old app. The server has **one** cron entry (Deploying, one-time setup) running `schedule:run` every minute; `withSchedule` in `bootstrap/app.php` registers every enabled `scheduled_tasks` row onto Laravel's scheduler, with `withoutOverlapping()`. It runs only for scheduler commands, so other Artisan commands (and `composer install`) don't need the database. Each run is recorded in `scheduled_task_runs`.

**Adding a task:** register it in the tool's migration, like a tool:

```php
TaskRegistry::register([
    'command' => 'price-monitor:scan',
    'name' => 'Price Monitor Scan',
    'description' => 'Scrapes Amazon.ca prices for every tracked ASIN.',
    'cron_expression' => '0 */2 * * *',
    'timezone' => null,      // UTC
    'enabled' => true,
]);
```

Registering only inserts (`command` is unique), so it never overwrites an admin's changes. A task's command must run without questions: add `--force` to commands that ask for confirmation in production.

Tasks due in the same minute run one after another, so they are registered shortest first, by each task's last finished run; a long task (the Walmart audit) no longer holds up the rest (found issue 23).

**Run Now** starts `schedule:execute-run` as a detached background process (`App\Services\Scheduler\BackgroundProcessLauncher`), so a slow task can't time out the web request. It needs `SCHEDULER_PHP_CLI_BINARY` in `.env` (`/opt/plesk/php/8.4/bin/php`): inside PHP-FPM, `PHP_BINARY` is the FPM binary and nothing would run. A command that throws is recorded as a failed run.

**Registered now:** `scheduler:heartbeat` (every 5 minutes, proves the pipeline runs) and `activitylog:clean --force` (daily 03:15 UTC, the 365-day retention).

## Tools

### BioSteel Teams App Stats (`/tools/team-app-stats`, `tool.team-app-stats`)

Ported from the old app (plan section 5.1). Reads only: nothing is written to an outside system.

- **Data:**
  - `team-app-stats:snapshot`, hourly: user and team totals and the sport breakdown from the BioSteel Teams API.
  - `team-app-stats:collect-ads`, at 06:00, 12:00 and 18:00 UTC: yesterday's Google Ad Manager numbers. It runs three times because Ad Manager revises a day for hours afterwards.
  - `team-app-stats:snapshot-social`, at 23:55 Toronto time: the week's Social Insights leaderboards, stamped with the Toronto date. These rows hold app users' emails and phone numbers, so treat the tables as confidential.
- **Live on page load, never stored:** Google Analytics (GA4) for the app and for the sponsorship QR campaign (GA4 counts each user once per query window, so stored daily numbers can't be summed), and the live Social Insights leaderboards.
- **Page:** date range and granularity, KPIs, charts, sport breakdown, ads, GA4, the QR campaign, social leaderboards and their history, and the raw data table. Also:
  - **Tutorial:** the guided tour.
  - **Settings:** a display multiplier for screenshots, kept in a cookie. It scales counts, never ratios.
  - **PDF and CSV export** of what's on screen.
- **Settings (`.env`):** `BIOSTEEL_TEAMS_BASE_URL`, `BIOSTEEL_TEAMS_USERNAME` and `BIOSTEEL_TEAMS_PASSWORD`, plus `GOOGLE_TEAM_APP_STATS_KEY_FILE`, the path to the service-account JSON key, outside `public/`. The Ad Manager report and the GA4 property IDs are in `config/team_app_stats.php`.
- **Import and comparison:** `legacy:import-team-app-stats` (with `--dry-run`) and `compare:team-app-stats` (`--from`, `--to`; by default the 30 days to yesterday). See below.

### Walmart US Listing Auditor (`/tools/walmart-us-listing-auditor`, `tool.walmart-us-listing-auditor`)

Ported from the old app (plan section 5.2). Reads only. Requesting Walmart's LISTING_QUALITY report changes no listing.

- **Data:** `walmart-us:listing-audit`, daily at 06:00 UTC. It takes 10 to 20 minutes: it syncs the catalog, requests and polls the LISTING_QUALITY report, and falls back to the Insights endpoints when the report fails or comes back empty. It then writes one audit row and its issues per SKU, plus one catalog score. `--sku=` audits one SKU; `--dry-run` writes nothing.
- **Pages:** the catalog rollup with how fresh it is, the 30-day Listing Quality trend, top issues, every audited SKU (with each score's band in words: Good 80+, Fair 50+, Poor below), and a per-SKU page with its issues.
- **Settings (`.env`):** `WALMART_US_CLIENT_ID`, `WALMART_US_CLIENT_SECRET` and `WALMART_US_ENVIRONMENT` (`config/walmart.php`). The `WALMART_CA_*` block is for Phase 3.
- **Not used yet:** `walmart_us_audit_params` (copied by the import) and `our_rule_score` are for the later rule engine. The old app's hand-run `WalmartUsAuditParamsSeeder` is not ported.

### US Product Reviews (`/tools/us-product-reviews`, `tool.us-product-reviews`)

Ported from the old app (plan section 5.3). Reads only.

- **Data:** two weekly collectors call Amazon's Customer Feedback API for every ASIN in `products` and `sc_products`, against the US marketplace, using the seller token, one request a second:
  - `feedback:collect` on Sundays at 01:00 UTC: topics, subtopics and snippets
  - `feedback:collect-trends` on Sundays at 02:00 UTC: the 6-month trend per topic

  Raw responses are kept compressed (`raw_response_gz`).
- **Page:** a summary of every ASIN (catalog, pull status, topic counts, top topics; sortable, filterable, CSV, Copy ASINs) and a detail page per ASIN (`?asin=`). Each topic is labelled positive or negative, with occurrence and star impact against the category, the 6-month trend, snippets and subtopics. The page has a Tutorial tour.
- **Settings:** the SP-API settings from step 2 (`SP_API_SELLER_REFRESH_TOKEN` and the client ID and secret).

### ASP Tracker (`/tools/asp-tracker` and `/tools/asp-tracker-bs`, `tool.asp-tracker`, `tool.asp-tracker-bs`)

Ported from the old app (plan section 5.6): one tool per store, Canadian Protein and BioSteel. What each product and variant actually sold for after every discount, by day, week or month. Reads only, until cutover.

- **Data:** every Shopify order line in `asp_sales_lines` (keyed on Shopify's line item ID), its discounts in `asp_line_discounts`, and pre-summed daily tables (`asp_daily_sales`, `asp_daily_discounts`) the page reads.
- **Scheduled, per store:** `asp:process-pending` every minute (re-fetches queued orders), `asp:catch-up` nightly (queues orders updated in the last 48 hours; until cutover the only way orders reach this app, since no webhook points here), `asp:rebuild-rollups` nightly, and `asp:backfill`, **disabled until cutover**.
- **Writes, run by hand and only after cutover:** the backfill's `bulkOperationRunQuery` and `asp:register-webhooks`' `webhookSubscriptionCreate`. Both go through `ExternalWrites` and refuse while `TOOLS_EXTERNAL_WRITES` is off.
- **Webhooks:** `POST /webhooks/shopify/asp/cp` and `/bs`, each checked against its store's secret (`SHOPIFY_CP_WEBHOOK_SECRET`, `SHOPIFY_BS_WEBHOOK_SECRET`).
- **Page:** filters (dates, day/week/month, product with search, variant, order type, channel, free items) and date presets; six headline figures; a key; ASP, product ASP and list ASP over time with units underneath (click a point, bar or period row for the discounts behind it); by-variant, by-product and by-period tables with CSV; how each number is worked out; data status; a Tutorial tour. The script is `resources/js/tools/asp-tracker.js`.
- **Settings:** `SHOPIFY_CP_STORE`, `SHOPIFY_CP_ACCESS_TOKEN`, `SHOPIFY_STORE`, `SHOPIFY_ACCESS_TOKEN` (the brands in `config/combined_shipping.php`, shared with Combined Shipping later), and the two webhook secrets.

### CDQ Listing Quality Report (`/tools/cdq-report`, `tool.cdq-report`)

Ported from the old app (plan section 5.5). Our own content quality score (0 to 100, deduction based) for every active Vendor Central ASIN. Reads only from Amazon.

- **Scheduled, Sundays (UTC):** `cdq:sync-schemas` 04:00 (product type definitions), `cdq:snapshot` 04:15 (catalog items, A+ and listing issues, deduplicated by content hash), `cdq:score` 04:45 (no API calls), `cdq:consistency` 05:15 (Anthropic, cached per content hash, model and prompt version). `legacy:import-vc-traffic` copies `vc_traffic_current` daily at 00:40 for the glance-view weights.
- **Consistency (found issue 13):** `cdq:score` gives every listing a provisional 10 of 100 for consistency; `cdq:consistency` replaces it with a real result and sets `cdq_score.consist_checked_at`. Until then the page shows "Not checked". When Anthropic gives no usable answer (no key, a failed call, an unreadable reply) the task fails and the alert email goes out, instead of passing silently.
- **Page:** grade counts, score distribution, week-over-week movement, top defects, every scored ASIN (sortable, filterable, Copy ASINs) and a work-queue CSV (`/export.csv`, ranked by points lost times glance views). `/{asin}` shows one listing's scores and defects.
- **Settings:** the SP-API vendor settings from step 2, and `ANTHROPIC_API_KEY` (optionally `ANTHROPIC_CDQ_MODEL`).
- **Check config:** `cdq_check_config_version` and its checks, `cdq_search_critical_attribute` and `cdq_title_length_band` are data, copied from the old app. The seeders are for tests and local use only.

### BioSteel Shopify Lost Sales (`/tools/biosteel-shopify-lost-sales`, `tool.biosteel-shopify-lost-sales`)

Ported from the old app's Stockout Lost Sales Estimator and renamed (decision D7). `/tools/stockout` redirects here (301, keeping the date range). Internal names stay: `config/stockout.php`, the `stockout:*` commands and the `STOCKOUT_*` settings. Reads only.

- **Page:** the latest batch of estimates, ranked by revenue lost, for a date range (presets 7/14/30/60 days or All time). A stockout overlapping the range counts only for its days inside it. Totals, confidence counts, a "How this is calculated" card, sortable and filterable table, CSV, and a Tutorial tour.
- **Scheduled:** `stockout:snapshot-inventory` every 15 minutes (a read-only sweep of every tracked variant's stock into `inventory_snapshots`) and `stockout:prune-snapshots` hourly at :17 (keeps `STOCKOUT_SNAPSHOT_RETENTION_DAYS`, default 90). The clean-up runs wherever the sweep does.
- **By hand:** `stockout:import-shopifyql-history` and `stockout:import-shopifyql-sales-history` (backfill the daily history from ShopifyQL), then `stockout:derive-intervals` and `stockout:estimate-lost-sales`. Not run here before cutover: the import copies the old app's results.
- **Webhook:** `POST /webhooks/shopify/inventory-levels-update`, HMAC-checked with `STOCKOUT_SHOPIFY_CLIENT_SECRET`, one primary-key read and one insert. No subscription points at this app until cutover. `stockout:register-webhook` creates one only when `TOOLS_EXTERNAL_WRITES=true`.
- **Settings:** `SHOPIFY_STORE`, and either `STOCKOUT_SHOPIFY_ACCESS_TOKEN` (used as it is) or `STOCKOUT_SHOPIFY_CLIENT_ID` and `STOCKOUT_SHOPIFY_CLIENT_SECRET` (the tool's own custom app; a token is then fetched and cached for 20 hours). The client secret is also what the webhook's signature is checked with, so it's needed at cutover.

### Shared product lists

`products` (Vendor Central, .ca) and `sc_products` (Seller Central, US) are read-only copies of the old app's tables until Phase 1 rebuilds the catalog (plan section 5, "Shared tables"). `legacy:import-products` mirrors them, IDs included, and runs daily at 00:30 UTC as the "Products Sync From Old App" task.

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
| `activity_log` | Matched on `legacy_id` (the old row's ID). This app's own entries are kept. Read 1,000 rows at a time with a short pause between |
| `tools` | Matched by slug. The old app's order and active flag are copied onto tools already registered here. Tools not ported yet are not created. A renamed tool (`stockout` is now `biosteel-shopify-lost-sales`) takes the old slug's order and flag, and the old `tool.stockout` grants become `tool.biosteel-shopify-lost-sales` |
| `teams`, `team_user` | Team IDs preserved. Each imported team's members are brought in line with the old app. A team name already used by a different ID here stops the import |
| `scheduled_tasks` | Matched by command (`DISABLED_UNTIL_CUTOVER` by the command's name, so `asp:backfill CP` and `asp:backfill BS` both count). The old app's enabled flag, schedule and timezone are copied onto tasks registered here; tasks not ported yet are not created. `asp:backfill` always stays disabled until cutover, and `stockout:prune-snapshots` always takes `stockout:snapshot-inventory`'s flag (decision 2026-12-02). Run history is not copied |

It stops before writing anything, and names the IDs, if an old user would overwrite a different person here: their email belongs to another ID, or their ID belongs to someone with a different email and Google ID (a person who signed in here first). Each ported tool adds its own tables to it.

### `legacy:import-team-app-stats` and `compare:team-app-stats`

```bash
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-team-app-stats --dry-run
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-team-app-stats
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan compare:team-app-stats
```

| Table | How |
| --- | --- |
| `team_app_snapshots` | Upserted on `captured_at`; the old app's reading wins for an hour both apps captured. IDs are this app's own |
| `team_app_category_snapshots` | Each imported snapshot's sport rows brought in line with the old app's, re-mapped to the new snapshot IDs |
| `team_app_ad_snapshots` | Upserted on `date` |
| `team_app_social_post_snapshots`, `team_app_social_follow_snapshots` | Upserted on (`captured_at`, `period`, `rank`) |
| `team_app_snapshot_teams_backup`, `team_app_category_snapshots_backup` | The old team-totals correction's backups, re-mapped to the new snapshot IDs |

The comparison runs the page's own report (`TeamAppStatsService`) against both databases for the same period. It prints row counts and a hash per section, and exits non-zero if any section differs.

### `legacy:import-walmart-us` and `compare:walmart-us`

```bash
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-walmart-us --dry-run
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-walmart-us
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan compare:walmart-us
```

| Table | How |
| --- | --- |
| `walmart_us_items` | Upserted on `sku` |
| `walmart_us_listing_snapshot` | Upserted on (`sku`, `content_hash`) |
| `walmart_us_listing_audits` | Upserted on (`sku`, `run_at`), snapshot re-mapped. This app's own runs (a different `run_at`) are left alone |
| `walmart_us_listing_audit_issues` | Each imported audit's issues brought in line with the old app's |
| `walmart_us_catalog_scores` | Matched on `run_at` |
| `walmart_us_audit_params` | Matched on `effective_from`; `changed_by` kept when that user exists here |

The comparison covers catalog scores, SKU audits and their issues for a period (by default the 60 days to yesterday), and the catalog items as they are now. It matches rows on their natural keys. Rows from this app's own nightly runs are counted as "only here", not as a difference.

### `legacy:import-products`, `legacy:import-us-product-reviews` and `compare:us-product-reviews`

```bash
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-products
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-us-product-reviews --dry-run
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-us-product-reviews
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan compare:us-product-reviews
```

Both imports mirror the old tables exactly, IDs included (`App\Legacy\TableMirror`): rows are upserted on `id`, and rows the old app doesn't have are deleted, children first. For the six `feedback_*` tables, this means rows this app's own collectors wrote during shadow running are replaced by the old app's, so the two apps' IDs never collide (plan section 6, "The old app wins").

The comparison runs the summary page's query against both databases and hashes every row of the six tables, raw responses included. Run it right after the import: once this app's collector has run again, it differs until the next import.

### `legacy:import-lost-sales` and `compare:lost-sales`

```bash
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-lost-sales --dry-run
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-lost-sales
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan compare:lost-sales
```

| Table | How |
| --- | --- |
| `inventory_history_daily`, `sales_history_daily` | Upserted on (product title, day); the old app's values win. IDs are this app's own |
| `variant_availability_intervals`, `lost_sales_estimates` | An exact copy, IDs included (`TableMirror`) |
| `inventory_snapshots` | Not copied (spec 14.1). This app's sweep fills it |

The comparison hashes every row the page reads in both databases: the history tables on (product title, day), sorted in PHP so the two databases' text sorting can't matter, and intervals and estimates whole, in ID order. Counts and hashes only.

### `legacy:import-cdq-report` and `compare:cdq-report`

```bash
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-vc-traffic
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-cdq-report --dry-run
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-cdq-report
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan compare:cdq-report
```

The eight `cdq_*` tables are mirrored with their IDs (`TableMirror`, in small batches for the large snapshot and schema rows), so the old app's rows replace this app's own Sunday runs. After the copy every score is the old app's, so `consist_checked_at` is cleared. `cdq_llm_cache` is only added to, never pruned: this app's Anthropic answers stay valid. The comparison hashes the eight tables in ID order, large documents by md5, and leaves out `consist_checked_at` and the cache.

### `legacy:import-asp-tracker` and `compare:asp-tracker`

```bash
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-asp-tracker --dry-run
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan legacy:import-asp-tracker
sudo -u "$(stat -c %U .)" /opt/plesk/php/8.4/bin/php artisan compare:asp-tracker
```

The largest import (about 1.4 million lines). It works 1,000 orders at a time, each batch in its own short transaction with a pause between, so the shared server never holds one long transaction:

| Table | How |
| --- | --- |
| `asp_sales_lines` | Upserted on the line item ID for every order the old app has. A line this app has for one of those orders and the old app doesn't is removed. Orders only this app has are left alone |
| `asp_line_discounts` | Brought in line with the old app's for each of those orders (only the difference is written) |
| `asp_pending_orders` | Upserted on (store, order ID) |
| `asp_ingest_log` | An exact copy, IDs included. Without the old backfill rows, the backfill would start over at cutover |
| `asp_daily_sales`, `asp_daily_discounts` | Rebuilt for both stores at the end, not copied |

`--dry-run` reads and counts only and writes nothing. The comparison covers orders placed more than three days ago (`--days`), hashing lines, discounts and the rebuilt rollups as sets (`App\Legacy\MultisetHash`), since IDs differ between the apps.

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

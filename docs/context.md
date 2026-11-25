# Context log

A dated log of changes, findings and anything verified live, newest first. It continues the old app's log at `CompanyTools/context.md` in the `Dh42/CompanyTools` repository, which stays the reference for history up to 2026-11-24.

Labels follow the old practice:
- **verified live:** seen on the real system or real data
- **from docs:** taken from documentation
- **assumed:** not yet checked

## 2026-11-25: step 1, skeleton

### What changed
Branch `phase-0/skeleton`:
- **App:** Laravel 13.44.1 on PHP 8.4 (`"php": "^8.4"`), with ParaTest and Pint. Tailwind was removed; styling arrives with the platform and the Redesign.
- **`legacy` connection** in `config/database.php`, for the old `tools_db`. It defaults to the `tools_legacy_ro` user. `phpunit.xml` forces it to in-memory SQLite.
- **`TOOLS_EXTERNAL_WRITES` switch:** `config/tools.php` and `App\Support\ExternalWrites`. Only a real `true` turns writes on, and tests force it off.
- **`Tests\TestCase`** turns on `Http::preventStrayRequests()`, so a test fails on any unfaked request.
- **Home page:** the starter welcome page is replaced with a plain "Coachwood Tools" page. It loads no Vite assets.
- **CI** (`.github/workflows/ci.yml`): Pint, the Vite build, and the suite on SQLite and on MariaDB 10.11.14.
- **Docs:** `README.md` covers setup, tests and the Plesk deploy steps. `CLAUDE.md` carries the working rules.
- **Plan change.** Ported code keeps its old namespaces so its tests carry over unchanged. Domain folders (spec 6.2) start with Phase 1 code. Recorded in `docs/phase-0-plan.md`.

### Test status
9 tests, 22 assertions. All pass:
- on SQLite, serial and parallel
- on local MariaDB 10.11.14, serial and parallel

Pint is clean and `npm run build` succeeds.

### Verified
- On the local restore, the `tools_legacy_ro` user (`SELECT` on `tools_db`) gets error 1142 when it tries `CREATE TABLE`. **Verified locally.** The same grant must be set on the server.
- CI has not run yet. It runs when the branch is pushed.

### Assumed
- The Plesk deploy actions in `README.md` need the server's Composer path. Lesley to copy it from the old site's actions.

### Next step
Lesley prepares the server (README "Deploying") and merges. The first deploy proves the pipeline. Then step 2: `amazon:probe`.

## 2026-11-25: Phase 0 plan approved

### What changed
- Lesley approved `docs/phase-0-plan.md` and answered its six questions; recorded in `docs/decisions.md`.
- Bugs in ported code are now fixed as found, not ported unchanged. `docs/found-issues.md` marks 25 as `to fix` and 2 as `needs input`:
  - #3: the confirmed Canadian Protein thresholds
  - #6: what reactivating a tool should restore
- Cutover now registers the Shopify webhooks at the new hostname after the final sync.

### Test status
No code yet. Unchanged from 2026-11-24.

### Verified
- The OAuth callbacks the new hostname needs in Google Cloud are `/auth/google/callback` and `/admin/settings/mail/callback`. **From code**, old app `routes/auth.php` and `routes/admin.php`.
- The old app ignores `public/build`, so assets are built on the server by the Plesk deploy actions. **From code.**

### Next step
Step 1, the skeleton, as the first pull request.

## 2026-11-24: brief review, decisions and Phase 0 plan

### What changed
- Reviewed the brief against the spec and the old code. Lesley answered the open questions; they are recorded in `docs/decisions.md`. The main ones:
  - the Zendesk sidebar is dropped
  - one cutover at the end
  - the probe goes in the new app
  - both vendor codes are in scope
- Added:
  - `docs/build-spec.md`, exported from the spec doc
  - `docs/decisions.md`
  - `docs/server.env.example`, the production `.env` template
  - `docs/found-issues.md`, 27 issues in the old code, all open
  - `docs/phase-0-plan.md`, a draft awaiting approval
- No application code yet.

### Test status
- Old app, run on a copy with SQLite: 1,296 tests, 1,296 passed, 3,411 assertions, 46 s (**verified**).
- The old app has no ParaTest, so `php artisan test --parallel` fails there.
- About 694 of these tests belong to the Phase 0 tools and platform. That count comes from reading the test files.

### Verified against real data
Checked on a local restore of the 2026-11-24 production export (MariaDB 10.11.14, 147 tables).

| Data | History |
| --- | --- |
| `nrp_cost_current` and `geo_sales_current` | 98 weekly periods, 2024-12-29 to 2026-11-14 |
| `mkt_amazon_forecast_snapshots` | 14 generation dates, 2026-08-16 to 2026-11-15 |
| Largest restored tables | `asp_sales_lines` 1.43M rows, `geo_sales_snapshots` 1.26M |

- The restore leaves out the `inventory_snapshots` rows and the cache, session, job and credential tables.
- The old app's `routes/console.php` queries `scheduled_tasks` on every artisan start, so artisan fails without a database connection.

### From reading code (not executed)
Inventories of the platform and the ten port-unchanged tools are summarised in `docs/phase-0-plan.md` section 5. Coupling found:
- Four ported tools read `products`, which the Price Monitor (rebuilt later) maintains.
- CDQ reads `vc_traffic_current`.
- Team Sponsorship's `Order` model is needed by App Stats.
- The Combined Shipping GraphQL client is needed by the ASP Tracker.

### Incident: tokens exposed during the first restore
- **What happened.** The first restore attempt filtered out only the first line of multi-line `INSERT` statements. The orphaned `cache` rows failed, and the database client echoed them into a local log.
- **What was exposed.** Part of that log was printed into the working session. It included:
  - a long-lived BioSteel Teams API token
  - short-lived Gmail and SP-API access tokens
  - two staff email addresses
- **What was done.** Lesley was told the same day.
  - The restore was re-run with whole statements skipped and error lines cut to 160 characters.
  - The BioSteel Teams token needs revoking by whoever runs that API.
  - Lesley removes the two local files that hold the values.
- **Lesson.** Treat the production export as a secret. Filter dumps by whole statements, and never print a database client's error output unclipped.

### Open questions for Lesley
These are in `docs/phase-0-plan.md` section 8, each with the default built to:
1. cutover through the old hostname
2. no extra webhooks during shadow running
3. syncing every tool at cutover
4. porting found issues unchanged
5. shadow Anthropic spend
6. where products are edited before Phase 1

### Next step
Lesley approves or amends `docs/phase-0-plan.md`. Then step 1, the skeleton, as the first pull request.

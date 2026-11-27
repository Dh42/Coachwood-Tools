# Context log

A dated log of changes, findings and anything verified live, newest first. It continues the old app's log at `CompanyTools/context.md` in the `Dh42/CompanyTools` repository, which stays the reference for history up to 2026-11-24.

Labels follow the old practice:
- **verified live:** seen on the real system or real data
- **from docs:** taken from documentation
- **assumed:** not yet checked

## 2026-11-26: Amazon probe results

### What changed
- **The probe ran on the server** at 2026-11-26 15:16 UTC, covering all 25 capabilities. Earlier the same day, a run with `--only=report.traffic` also passed.
- **Results:** 20 pass, 1 empty, 1 fail, 1 rate limited, 1 role missing, 1 skipped. They are in the server's `amazon_capabilities` table and in `summary.json`. The summary is not committed.
- **First deploy confirmed:** the site serves the placeholder home page. `APP_KEY` was generated on the server with `key:generate --force`.
- No code changed.

### Test status
Unchanged: 40 tests passing.

### Verified live on the account
**Passed:**
- Vendor reports:
  - weekly inventory, both views
  - weekly traffic
  - net PPM
  - promotions
  - all three real-time reports
  - Brand Analytics market basket and search terms (search terms checked for readiness only, not downloaded)
- Data Kiosk:
  - daily grain
  - orders
  - sourcing in both views
  - forecasting
  - customer returns
  - grouping by vendor code
- Other APIs:
  - Vendor Orders
  - seller marketplace participations
  - seller offers on Amazon.ca

**`procurableProductOOS` works under `sourcing`.** This settles the old app's 2026-08-27 failure: the field lives in `Sourcing`, not `ProductAvailability`.

**Inventory report.** All the extra fields the spec wanted return data in the manufacturing view:
- open PO units
- aged 90+ day units
- unhealthy units
- confirmation rate
- receive fill rate
- lead time
- unfilled customer ordered units

Differences between views and fields:
- The sourcing view leaves both out-of-stock rates and unfilled units empty, so collectors use the manufacturing view.
- `uft` is empty in both views.
- `procurableProductOutOfStockRate` and `sourceableProductOutOfStockRate` are 0–1 fractions.

**Value ranges to allow for:**
- `receiveFillRate` reaches 2, so it must not be capped at 1.
- `sellThroughRate` runs from about −0.04 to 3.5.
- These can be negative:
  - `openPurchaseOrderUnits` and Data Kiosk `openPurchaseOrderQuantity`
  - Data Kiosk ordered units and ordered revenue
  - real-time ordered units and revenue
  - net PPM
- Data Kiosk `overallVendorLeadTime` matches the report's `averageVendorLeadTimeDays`. Both run 0 to 136.83 days.

**Data Kiosk:**
- **Daily grain** works. The latest day was 3 days before the run. One row in 1,249 had no ASIN.
- **Forecasting** returns only the current forecast, 48 weeks per ASIN, whatever date range is asked for. Past forecasts can't be fetched later, so they must be saved every week, as the old app's `mkt_amazon_forecast_snapshots` does.
- **Vendor codes.** Grouping by vendor code returns Z3091, GQ8J9 and `UNKNO`. `UNKNO` was 99 of 500 rows: shipped units Amazon doesn't attribute to either code. Totals per ASIN must include it.
- **History limit.** Amazon refuses any start date more than two years back ("Start date must be no more than two years ago"). The window moves forward every week.

**Vendor Orders.** Both vendor codes come back under one authorization (D9 confirmed). There were at least 100 purchase orders in 60 days; the probe read only the first page.

**Seller token:**
- It participates in 7 marketplaces, Amazon.ca among them, so D8 is confirmed.
- One marketplace reports `hasSuspendedListings: true`.

### Not passed
| Capability | Result | Meaning |
| --- | --- | --- |
| Finance Remittance headers | 403 Unauthorized | The app lacks the Finance and Accounting role. The spec expected this (section 8). |
| Finance Remittance detail | skipped | It needs an ID from the headers call. |
| Data Kiosk product availability | 429 twice | Data Kiosk's query quota. Re-run on its own. It still holds the `sourceableROOS` scale question. |
| Data Kiosk 2023 history | fail | The two-year limit. |
| Coupon performance report | empty | The report works, but there were no coupons in 12 months. |

### Assumed
- `UNKNO` is Amazon's placeholder for an unattributed vendor code. Not confirmed with Amazon.
- The vendor-code query returned exactly 500 rows. That's probably chance; Phase 1 compares its totals with the ASIN-only query.

### Probe quirk
In `summary.json`, when an object such as `shippedOrders` is null on some rows, its own entry shows `empty_rate: 1`. The rates on its child fields are the accurate ones. This affects nothing outside the summary.

### Open questions for Lesley
1. **Finance and Accounting role.** Adding it to the SP-API app and re-authorizing the vendor account issues a new vendor refresh token. That token goes into both apps' `.env`. Without the role, the Deductions Tracker uses the remittance upload (spec 12.1).
2. **History before 2024-12-29.** The old database's weekly data starts on 2024-12-29. Data Kiosk can still return:
   - the four weeks from 2024-12-01 to 2024-12-28, the run-up to Christmas 2024
   - Black Friday and Cyber Monday 2024, at daily grain only, for a few more days

   The two-year limit drops a day each day. The first of those weeks goes on 2026-12-01 and the last in late December 2026. Grabbing them needs a small one-off read-only command that saves the raw documents for Phase 1.
3. **Suspended listings** in one seller marketplace. Worth a look in Seller Central.

### Next step
- Re-run `data_kiosk.product_availability` on its own.
- Lesley answers the open questions.
- Then step 3, the platform.

## 2026-11-25: auto-merge

### What changed
- **New workflow,** `.github/workflows/auto-merge.yml` (PR #2, merged by hand). When CI passes on a pull request, it merges the pull request with a merge commit and deletes the branch. Plesk then deploys `main` as usual.
- **When it doesn't merge:**
  - the pull request is a draft
  - it has the `hold` label
  - its branch has moved past the commit CI tested
  - it comes from a fork
- **Step 2 went straight to `main`** (commit `fac9861`) without a pull request, so the probe is already deployed.
- **README** has an "Auto-merge" note under Tests.

### Test status
No application code changed. CI passed on PR #2.

### Verified
- PR #2 was merged by hand. A `workflow_run` workflow only runs from the copy on `main`, so it can't merge the pull request that adds it. **Verified live.**
- This entry's pull request is the first one auto-merge should handle. **Assumed** until it merges itself.

### From docs
- Merges made with `GITHUB_TOKEN` don't start other workflows, so CI doesn't run again on `main` after a merge. Repository webhooks still fire, so the Plesk deploy still runs.

### Next step
Lesley runs `amazon:probe` on the server (README "One-time commands") and sends back `summary.json`.

## 2026-11-25: step 2, Amazon capability probe

### What changed
Branch `phase-0/amazon-probe`:
- **Ported unchanged** from the old app:
  - `App\Services\Amazon\SpApiClient`
  - `App\Services\AmazonListings\ListingChannel`
  - the `sp_api` config block, plus `vendor_codes` (D9)
  - `SpApiClientTest`, which passes all 12 tests unchanged, including with `Http::preventStrayRequests()` on
- **New registry:** the `amazon_capabilities` table and the `AmazonCapability` model, with `isUsable()` and `fieldReturned()` for later collectors.
- **The `amazon:probe` command** (`app/Amazon/Probe/`) tries 25 capabilities: 11 reports, 9 Data Kiosk field groups, Finance Remittance headers and detail, Vendor Orders per vendor code, seller marketplace participations, and seller offers on Amazon.ca. It:
  - submits everything first, then polls every 30 s for up to 45 min
  - resubmits a Data Kiosk query once without the fields Amazon rejected
  - retries once after a 429
- **Read-only by construction.** `ProbeClient` refuses every non-GET except creating a report or a Data Kiosk query, before anything is sent. A test proves `patchListing()` sends nothing.
- **Output rules.** The summary describes fields without values:
  - type, how often empty or zero, and whether any are negative
  - ranges only for rate-like fields, and dates
  - counts per value only for currency, vendor code and marketplace
  - Amazon error messages masked for ASINs, long IDs and the `--asin` value

### Test status
40 tests, 126 assertions, all passing on SQLite and on local MariaDB 10.11.14 (parallel). Pint clean. The probe has not run against Amazon yet.

### From docs (Amazon's public models, read 2026-11-25), not yet verified on this account
- Data Kiosk field names come from `schemas/data-kiosk/analytics_vendorAnalytics_2024_09_30.graphql`. It explains the old app's 2026-08-27 failure: `procurableProductOOS` exists, but in the `Sourcing` group, not `ProductAvailability`, where the old query asked for it. The probe requests it under `sourcing`.
- The schema has `groupByKey.vendorCode`, which is how the probe checks both vendor codes.
- `ProductAvailability` also has `sellableInTransitInventory` and `unhealthyInventory`.
- Report options and row fields come from `schemas/reports/*.json`. The inventory report has `openPurchaseOrderUnits`, `aged90PlusDaysSellableInventoryUnits`, `unhealthyInventoryUnits`, `vendorConfirmationRate` and `averageVendorLeadTimeDays`.
- The Finance Remittance API exists as `financeRemittance-2026-03-17`, with `getRemittanceHeaders` and `getRemittance`.

### Assumed
- Vendor reports lag about three days, so the probe's weekly window ends on the last Saturday at least three days back.
- The real-time report options (`currencyCode` for sales, none for inventory and traffic) follow the schemas. A wrong option shows up as `fail` with Amazon's message.

### Old app's SP-API schedule (from the local restore, UTC)
- **Daily:** `amazon:vendor-sales-sync` 04:00, `stock-forecast:run` 05:00, `po:snapshot` 06:00, `listings:audit-run` 08:00.
- **Sunday:** `geo`, `mkt`, `nrp`, `vc-traffic` and `cdq` run from 00:00 to 05:15.
- So the probe should run on a weekday between 10:00 and 22:00 UTC.

### Next step
Merge, deploy, then Lesley runs the probe on the server and sends back `summary.json`.

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

### From Lesley
- The old site's Plesk deploy actions run in this order: Composer install, `npm ci`, `npm run build`, `migrate --force`, `storage:link`, `optimize`. Each one first puts `/opt/plesk/node/26/bin` and `/opt/plesk/php/8.3/bin` on PATH.
- `README.md` copies them for the new site with PHP 8.4 in place of 8.3. CI builds with Node 26 to match the server.

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

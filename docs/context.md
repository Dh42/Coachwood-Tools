# Context log

A dated log of changes, findings and anything verified live, newest first. It continues the old app's log at `CompanyTools/context.md` in the `Dh42/CompanyTools` repository, which stays the reference for history up to 2026-11-24.

Labels follow the old practice:
- **verified live:** seen on the real system or real data
- **from docs:** taken from documentation
- **assumed:** not yet checked

## 2026-12-04: ASP comparison on the server, and a per-order breakdown

### What changed
- **Server run (verified live):** the platform import and `legacy:import-asp-tracker` ran cleanly, with 1,443,107 lines, 929,180 discounts and 9 queued orders. `compare:asp-tracker` matched both rebuilt rollup tables, through the day before the cutoff. The line and discount sections differed: the old app had 17 more lines and 34 more discounts for orders placed before the cutoff. Because the rollups match, the differing orders are all from the last day before the cutoff, which the rollup check leaves out. The old app was saving orders throughout: its queue grew from 6 to 9 between the dry run and the import. That fits orders it saved after the import read them, but this is **assumed**, not shown.
- **`compare:asp-tracker` now breaks a difference down by order.** While hashing, it also sums rows into 4,093 groups by order ID. It then re-reads only the groups that differ (at most 64), finds the orders whose lines or discounts differ, and sorts them by their newest line save time (`updated_at`, which the import copies). It prints counts and save times only. The groups use a prime because Shopify order IDs are all multiples of 2,048 (**verified** on the local copy): with 4,096 groups every order landed in 2 of them, and one difference took 880 s and 405 MB.
- `MultisetHash` gained `rowHash`, `combine` and `addHash`; `add` is unchanged.

### Test status
- 637 tests pass on SQLite, including a new `ImportAspTrackerTest` case that covers three of the five kinds of difference and a re-import that clears them. Pint is clean.
- **Verified on the full local copy,** in a scratch MariaDB that has since been removed:
  - With no differences, the compare still matches, in 137 s (114 s before).
  - With made-up differences inside a rolled-back transaction, it found both orders and sorted them correctly, in 149 s and 83 MB.

### Open questions
- What the 17 lines are. Lesley re-runs the import and compare once this deploys. The breakdown will show whether they were saved in the old app after the copy (expected) or something else.

### Next step
Step 10, Team Sponsorship and Team Sponsorship Stats, once the ASP compare is explained.

## 2026-12-04: step 9, ASP Tracker (Canadian Protein and BioSteel)

### What changed
Step 9 of the plan, section 5.6. Uncommitted in the working copy; Lesley commits.

- **Ported unchanged:** the ten `App\Services\AspTracker` classes, `AspIngestLog`, the five `asp:*` commands, `AspTrackerController`, the webhook controller, `config/asp_tracker.php`, and the shared `CombinedShipping\ShopifyGraphQlClient`. `config/combined_shipping.php` has only the shared brands and API version; the rest comes with Combined Shipping. `tests/Support/AspOrderFixtures.php` was copied too.
- **Tables:** one migration creates the six tables in the old app's final shape: BioSteel added, `kept_order_discount`, `is_draft` renamed to `is_excluded`, and the incident index on `asp_ingest_log`.
- **Registration:** one tool per store, at the old URLs and permissions. Canadian Protein is in a new "Canadian Protein Shopify" sidebar group; BioSteel sits beside Lost Sales in "BioSteel Shopify". Icon `tag`. Four tasks per store at the old schedules and timezones; `asp:backfill` is registered disabled.
- **Routes:** both stores' pages, drill-down and CSV, and both webhook receivers (`/webhooks/shopify/asp/cp` and `/bs`). Nothing points at the receivers before cutover.
- **Guards (plan section 5.6, "not run in the new app until cutover"):**
  - The backfill's `bulkOperationRunQuery` goes through `ExternalWrites`, checked before any Shopify call.
  - So does `asp:register-webhooks`' `webhookSubscriptionCreate`, checked after the read-only list.
  - Tests: `AspTrackerSafetyTest`.
- **Bug fixed in this app:** `PlatformImporter::DISABLED_UNTIL_CUTOVER` compared the whole command, but the old tasks are `asp:backfill CP` and `asp:backfill BS`. The import would have copied the old app's "enabled" onto them. It now matches the command's name. The test (in `ImportPlatformTest`) failed first. It couldn't have happened yet, since no ASP task existed here before this step.
- **Page:** rebuilt on the design.
  - Filters with product search, date presets, six headline figures, the key and the discount mix.
  - Price and units charts on the shared themed chart: product ASP dotted and list ASP dashed, so the lines are told apart without colour. Clicking a point, bar or period row opens the drill-down (now a native dialog).
  - By variant / product and by period tables as link tabs (`?tab=periods`), with CSV.
  - "How this is calculated", data status, and the Tutorial tour.
  - The empty state now says history comes from the import, since the backfill is off until cutover.
  - The script moved to `resources/js/tools/asp-tracker.js`.
  - New CSS: link segmented buttons, `.chart-box-short`, clickable rows (`.row-link`, keyboard too), `.modal-wide`, `.scope-list-flush`.
- **`legacy:import-asp-tracker` (new):**
  - 1,000 orders per batch, each in its own short transaction with a pause.
  - Lines are upserted per order, and lines this app has for an imported order but the old app doesn't are removed. Discounts are synced as a multiset, so a re-run writes nothing.
  - The queue is upserted and the ingest log mirrored. Both stores' rollups are then rebuilt with the tool's own `DailyRollup`.
  - `--dry-run` writes nothing, because rolling back a transaction this size would be heavy on the shared server.
- **`compare:asp-tracker` (new):** compares lines, discounts and both rollup tables for orders older than 3 days, as order-independent set hashes (`App\Legacy\MultisetHash`).

### Test status
636 tests, 2,130 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes. Both new migrations roll back and re-apply on MariaDB. CI's steps were replayed on a clean copy without `.env`.

- **Carried over (33).** Two tests changed on purpose, each with a comment:
  - `AspTrackerIngestTest` turns external writes on in its setup.
  - Its rollup snapshot selected `is_draft`, the column's old name. SQLite read the unknown name as a plain string, so it compared nothing; MariaDB caught it.
- **Moved here:** the `asp_ingest_log` half of a Lost Sales test.
- **New:** `AspTrackerSafetyTest` 3, `ImportAspTrackerTest` 4, and 1 in `ImportPlatformTest`.

### Verified locally
- **Against the restored `tools_db` copy** (into a throwaway MariaDB; the `legacy` settings scoped to each command):
  - `legacy:import-platform`: both backfills disabled, the other six ASP tasks enabled.
  - `legacy:import-asp-tracker`: 1,434,312 lines, 918,438 discounts, 3 queued orders and 54,160 log rows, in 9.5 minutes, at most 118 MB of memory. A second run added and removed nothing.
  - `compare:asp-tracker`: all four sections matched, including the rollups rebuilt here (402,012 daily-sales rows and 385,705 daily-discount rows, the same as the old app's). It took about 2 minutes.
- **In headless Chrome, with made-up data:** the page at desktop and phone widths. The six headline figures wrapped onto two rows at desktop width; fixed with `.stat-row-tight`.
- **The page script, with real input over the DevTools protocol:**
  - Hovering the price chart showed the tooltip ("ASP: …"), and the units chart responded too.
  - Clicking a period row opened the drill-down dialog for that week.
  - Typing in the product search narrowed the list.
- **Not verified:** the drill-down's data inside that dialog in the browser, since the static snapshot isn't signed in. The endpoint itself is covered by the ported test.

### Next step
- Lesley commits and pushes.
- **On the server:** `legacy:import-platform` (registers the ASP task settings), `legacy:import-asp-tracker --dry-run`, then `legacy:import-asp-tracker` (about 10 minutes), then `compare:asp-tracker`. The catch-up and queue tasks start on their own; the backfills stay off.
- Then step 10: Team Sponsorship and Team Sponsorship Stats.

## 2026-12-03: step 8, CDQ Listing Quality Report

### What changed
Committed straight to `main` (decision 2026-12-02). Step 8 of the plan, section 5.5.

- **Ported unchanged:** the nine CDQ models and `VcTrafficCurrent`, the three `App\Services\Cdq` classes, `App\Services\Anthropic\AnthropicClient`, the five `cdq:*` commands (including `cdq:probe`), `CdqReportController`, and the three CDQ seeders (for tests and local use; production data is imported). The `services.anthropic` config block was added. `SpApiClient` already had every call CDQ needs.
- **Tables:** one migration creates the nine `cdq_*` tables in the old app's final shape, plus `vc_traffic_current`. One column is new: `cdq_score.consist_checked_at`.
- **Registration:** the tool under "Amazon · Listings" (icon `check`), its four Sunday tasks at the old times, and a daily `legacy:import-vc-traffic` (00:40 UTC). That makes plan section 5's "imported as is" for `vc_traffic_current` automatic.
- **Found issue 13 fixed (decision 2026-12-03).**
  - **What the old data showed:** the old app's consistency check has never run. There are no CONSIST defects and the Anthropic cache is empty; the old task description says it no-ops "until ANTHROPIC_API_KEY is set". So every scored listing got 10 of 100 points unchecked.
  - **What changes:** scores and grades are unchanged, so the apps still match. `consist_checked_at` records a real check (`cdq:score` clears it whenever it writes the provisional 10). The page shows a notice and "Not checked" in the CONSIST column; the detail page shows "Not checked" with the provisional value.
  - **The task now fails (alert email)** when Anthropic gives no usable answer, where it used to pass silently.
  - Lesley is adding an Anthropic key to this app only.
- **Found issue 14 fixed:** the work-queue CSV goes through `fputcsv`, so an ASIN, SKU or title with a comma or quote can't break a row.
- **`legacy:import-cdq-report` (new):**
  - Mirrors the eight tables with IDs. Snapshots go 100 rows per batch and schemas 5, to stay under the server's packet limit.
  - Then clears `consist_checked_at`: every score left is the old app's, which was never checked.
  - Adds old cache entries without ever deleting this app's, so the Sunday Anthropic answers survive imports and aren't paid for twice.
- **`compare:cdq-report` (new):** hashes the eight tables in ID order, large documents by md5.
- **Page:** rebuilt on the design. Grade counts on one row, the score histogram on the shared themed chart, week-over-week movement, top defects, and a sortable, filterable table with Copy ASINs. Grades are labelled badges and the work-queue CSV link is kept. The detail page shows the dimension scores and a defects table with severity badges. The old pages had no Tutorial tour, so none was added.
- **New CSS:** `.truncate-cell-sm`, and `.stat-row-tight` for five or six short numbers on one line.

### Test status
595 tests, 1,908 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes. Both new migrations roll back and re-apply on MariaDB. CI's steps were replayed on a clean copy without `.env`.

- **Carried over (61):** 55 CDQ and 6 `AnthropicClientTest`. Two fixtures were changed on purpose, each with a comment: `CdqReportServiceTest` and `CdqScoringServiceTest` used made-up ASINs of 11 to 13 characters, longer than the 10-character `asin` column. Only SQLite let them through; the MariaDB run caught them.
- **New:** `CdqFixesTest` 6, `ImportCdqReportTest` 6.

### Verified locally
- **Against the restored `tools_db` copy** (into a throwaway MariaDB; the `legacy` settings were scoped to each import command, never exported):
  - `legacy:import-vc-traffic`: 3,510 rows.
  - `legacy:import-cdq-report`: 9 schemas, 1,510 snapshots, 1 config version with 22 checks, 85,275 defects, 1,509 scores, 34 search-critical attributes and 5 title bands, in about 34 seconds. A second run added and removed nothing.
  - `compare:cdq-report` matched in all eight sections. All 1,509 imported scores show as not checked.
- **In headless Chrome, with made-up data:** the summary and detail pages. The first screenshots caught the CONSIST column running off the table and the grade tiles wrapping onto two rows. Both were fixed and checked again.
- **Verified live:** Lesley ran the imports on the server: 3,510 traffic rows and the same CDQ counts as locally. `compare:cdq-report` matched in all eight sections, with the same hashes as the local run.
- **Not verified:** a live run of the CDQ tasks or of Anthropic. That needs the server's settings; the first scheduled run is Sunday 04:00 UTC.

### Open questions
- The plan says the check config versions v1 to v4 are data in production. The old database has one version (with 22 checks), and it is imported as is.

### Next step
- Lesley commits and pushes.
- **On the server:** add `ANTHROPIC_API_KEY` to `.env`, then run `optimize`, `legacy:import-vc-traffic`, `legacy:import-cdq-report` (`--dry-run` first) and `compare:cdq-report`.
- Then step 9: ASP Tracker, Canadian Protein and BioSteel.

## 2026-12-02: Lost Sales comparison order, and a hole in the test safeguards

### What changed
Branch `fix/lost-sales-compare-order`, from `main` after #21 (step 7) merged.

- **`compare:lost-sales` reported two false differences on the server.**
  - Lesley ran the step 7 imports on the server; the four tables were imported in full. The comparison then said `inventory_history_daily` and `sales_history_daily` differed, with equal row counts.
  - The old app's hashes were the same as on the local copy. Locally, building this app's database with a byte-order collation (`utf8mb4_bin`) gave exactly the server's two new hashes. So the rows are identical: the two databases sorted the product titles differently (the old one ignores case).
  - **Fix:** those two tables are now sorted in PHP before hashing (`RowHasher::sorted`), so the database's sorting can't matter. Checked on the real data under both collations: all four sections match, with the same hashes.
  - The intervals and estimates are compared in ID order and already matched.
- **The test safeguards didn't stop exported shell variables.**
  - While checking the above, I ran the suite in a shell where `LEGACY_TOOLS_DB_*` was exported for the local import. The tests reached the local restored copy of the old database (the `coachwood-legacy-db` container, not production), and the import tests' set-up dropped and recreated tables in it.
  - **Cause:** `phpunit.xml`'s `<env force="true">` sets `getenv()` and `$_ENV` but not `$_SERVER`, which Laravel reads first. So the forced values only ever beat `.env`. The same gap applied to `TOOLS_EXTERNAL_WRITES`, though `Http::preventStrayRequests()` would still have blocked any call.
  - **Fix:** matching `<server force="true">` entries, and `Tests\TestCase` now sets the legacy connection to in-memory SQLite and external writes off directly, whatever the environment says.
  - `TestSafeguardsTest` (written first and seen failing) runs PHPUnit in a child process with hostile values exported and checks that they don't reach a test. The full suite also passes with them exported.
  - The production old database can't be dropped through this app's legacy user, which has only `SELECT, SHOW VIEW`.
  - The local copy was rebuilt from the 2026-11-24 export.
- **README:** the Lost Sales settings now say either `STOCKOUT_SHOPIFY_ACCESS_TOKEN` or the client ID and secret. The old app uses the access token.

### Test status
522 tests passing on SQLite (parallel), and on MariaDB 10.11.14 with both `utf8mb4_unicode_ci` and `utf8mb4_bin`. Pint clean. New: `TestSafeguardsTest` 2, and 1 in `ImportLostSalesTest` (an old table that sorts ignoring case).

### Verified
- **Verified live:** Lesley ran the step 7 imports on the server, and after this fix `compare:lost-sales` matched in all four sections.
  - `legacy:import-platform` moved 6 grants to the new permission (6 new, 6 removed).
  - `legacy:import-lost-sales` copied 53,697, 22,150, 258 and 414 rows.
  - Intervals and estimates matched; the two history tables were the false differences above.
- **Local:** the cause was reproduced exactly (same hashes) and the fix was checked on the real data.

### Open questions
- **Corrected 2026-12-02:** the byte-order sorting wasn't a MariaDB collation. A check on the server showed this app's default connection there is SQLite (`database/database.sqlite`), which compares text by bytes. The plan assumes MariaDB, and the `coachwood_tools` MariaDB database exists. Confirmed: the server `.env` had `DB_CONNECTION=sqlite` and no other `DB_*` lines, unlike `docs/server.env.example`, so since the first deploy the app has run on a 24 MB SQLite file. Plan agreed: switch `.env` to MariaDB, migrate, re-run every import and comparison, and reconnect Gmail. **Done and verified live:** Lesley switched `.env`, all 39 migrations ran on MariaDB (`coachwood_tools`), all six imports ran, and all four comparisons matched (App Stats 6/6, Walmart 4/4, US Product Reviews 7/7, Lost Sales 4/4). The `cache` failure in `optimize:clear` came before the migrations had created the table, and is harmless. The SQLite file is kept as `database/database.sqlite.before-mariadb` for a week. History grab's output is in storage, not the database, so it's unaffected. What starts fresh is only this app's own extras: scheduler run history, theme preferences, sessions, the Gmail connection, `amazon_capabilities` (the probe's findings are in this log), and App Stats rows collected only by this app (the old app's replace them anyway). The comparison fix stands either way: `compare:lost-sales` matched on the server after it.

### Git flow changed (decision 2026-12-02)
Lesley doesn't work with git from the command line, and the per-step pull requests weren't their flow. From now on:
- Everything is committed to `main`. Lesley commits and pushes; Claude leaves changes uncommitted.
- `release.yml` moves a `release` branch to each commit that passes CI, and Plesk deploys `release`.
- `auto-merge.yml` is removed. `CLAUDE.md`, the plan and the README are updated.
- The merged local branches were deleted.
- **Lesley, once, after this push:** in Plesk > Git, set the deployed branch to `release`. Until then, Plesk still deploys `main` on every push.

### Next step
Lesley switches Plesk to `release`, then re-runs `compare:lost-sales` on the server (no new import needed). Then step 8: CDQ Listing Quality Report.

## 2026-12-02: step 7, BioSteel Shopify Lost Sales

### What changed
Branch `phase-0/biosteel-shopify-lost-sales`, from `main` after #20 (step 6) merged. Step 7 of the plan, section 5.4.

- **Ported unchanged:**
  - `config/stockout.php` and `App\Support\HttpRetry`.
  - The seven `App\Services\Stockout` classes and three models.
  - The seven `stockout:*` commands.
  - The webhook controller and `VerifyShopifyWebhook` (alias `verify.shopify-webhook`; `webhooks/*` is exempt from CSRF).
  - `StockoutController`. Its class name stays, as the plan's rename scope says.
- **Tables:** one migration creates the six tables in the old app's final shape, including the `inv_snap_item_time_variant` index from the 2026-11-16 incident and `inventory_item_variants`.
- **Renamed (decision D7):** the tool is now `biosteel-shopify-lost-sales`, under a new "BioSteel Shopify" sidebar group with the `trend-down` icon. `/tools/stockout` redirects with a 301, keeping the date range.
- **Platform import:**
  - `PlatformImporter::RENAMED_TOOLS` moves the old slug's order and active flag to the new slug, and turns `tool.stockout` grants into `tool.biosteel-shopify-lost-sales`. The old permission is not created here.
  - `ENABLED_WITH` gives `stockout:prune-snapshots` the sweep's enabled flag.
- **Lesley's decision (2026-12-02):** the snapshot clean-up runs wherever the sweep runs. In the old app the sweep is on and the clean-up is off. Recorded in `decisions.md`, and plan 5.4 is updated.
- **Page:** rebuilt on the design.
  - Date range with server-side preset links, replacing the old inline script.
  - Totals, with the confidence counts as labelled badges.
  - "How this is calculated" as a collapsible card.
  - A sortable, filterable table with CSV export.
  - The RTD→PET rows say "PET cover" and show the note in the row, where the old page had a hover-only icon.
  - The Tutorial tour is kept.
- **Found issue #20 (Lost Sales part) fixed:** a malformed date returned a 500. It is now ignored: that side of the range has no bound, as when it's left blank. Tests written first and seen failing.
- **`stockout:register-webhook` now goes through `ExternalWrites`** before `webhookSubscriptionCreate`. Plan 5.4 says it isn't run here until cutover. The list query, a read, still runs.
- **`legacy:import-lost-sales` (new):**
  - Daily history is upserted on (product title, day).
  - Intervals and estimates are mirrored with their IDs.
  - Snapshots are not copied.
- **`compare:lost-sales` (new):** compares the four tables by hash. The normalising and hashing moved to `App\Legacy\RowHasher`, now shared with `compare:us-product-reviews`.

### Test status
519 tests, 1,689 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes. Both new migrations roll back and re-apply on MariaDB. CI's steps were replayed on a clean copy without `.env`.

- **Carried over (85).** Pint reformatted the copied code; behaviour is unchanged. Four tests were changed on purpose, each with a comment:
  - `StockoutTest`: the new slug and route name.
  - `StockoutPruneSnapshotsCommandTest`: the `asp_ingest_log` index check moves to the ASP Trackers step, and the clean-up is registered enabled, like the sweep.
  - `StockoutRegisterWebhookCommandTest`: external writes are turned on in its setup.
- **New:** `LostSalesFixesTest` 5, `ImportLostSalesTest` 6, and 2 in `ImportPlatformTest`.

### Verified locally
- **Against the restored `tools_db` copy** (into a throwaway MariaDB, since removed):
  - `legacy:import-platform`: 6 users' `tool.stockout` grants became `tool.biosteel-shopify-lost-sales`, with no `tool.stockout` permission created. The tool took the old order (22) and active flag. Both tasks are enabled.
  - `legacy:import-lost-sales`: 53,697 inventory days, 22,150 sales days, 258 intervals and 414 estimates (two batches), in about 25 seconds. A second run added and removed nothing.
  - `compare:lost-sales` matched in all four sections.
- **In headless Chrome, with made-up data:** the page at desktop and phone widths, with the card open and closed. The first screenshots caught doubled spacing between sections and an unstyled "How this is calculated" heading. Both were fixed and checked again.
- **Not verified:** a live sweep. It needs the server's `STOCKOUT_SHOPIFY_*` settings; the first scheduled run after deploy shows it.

### Open questions
- The old app's local copy has no `inventory_snapshots` rows (0). That doesn't matter here, because snapshots aren't imported.
- **Verified live (step 6):** Lesley ran the step 6 imports and comparison on the server: all seven sections matched, with the same hashes as the local copy.

### Next step
- Lesley pushes the branch; it merges itself.
- **On the server:**
  - Check `SHOPIFY_STORE`, `STOCKOUT_SHOPIFY_CLIENT_ID` and `STOCKOUT_SHOPIFY_CLIENT_SECRET` are in `.env`.
  - Run `legacy:import-platform` (moves access and settings to the new name), then `legacy:import-lost-sales` (`--dry-run` first), then `compare:lost-sales`.
  - Then Run Now on "Lost Sales Inventory Snapshot" in Admin > Scheduler, as the live check of the sweep.
- Then step 8: CDQ Listing Quality Report.

## 2026-12-01: step 6, US Product Reviews

### What changed
Branch `phase-0/us-product-reviews`. Step 6 of the plan, section 5.3. The chart tooltips fix merged as #19; this branch was started on it while it waited for CI, then moved onto `main`.

- **Backend, ported unchanged:** the six `feedback_*` tables (one migration, including the LONGBLOB raw-response columns), the six models, `FeedbackService`, `FeedbackController`, and `feedback:collect` and `feedback:collect-trends`. `SpApiClient` already had the two Customer Feedback calls from step 2.
- **One change to the service:** an optional database connection, used only by `getLatestSummary()`, so the comparison can run the summary query against the old database. The default is this app's.
- **Shared product lists:**
  - New `products` and `sc_products` migrations in the old app's final shape, plus `Product` (with its factory) and `ScProduct`. The models' relations to unported tools come back with those tools.
  - `legacy:import-products` mirrors both with their IDs, and is registered as a daily 00:30 UTC task, so the collectors' ASIN lists stay current without anyone running it. This is plan section 5, "Shared tables", made automatic.
- **Registration:** the tool under "Amazon · Listings" (icon `star`, as in the design's sidebar), with its two Sunday collectors at the old schedules.
- **Pages:** rebuilt on the design.
  - Catalog and pull status are labelled badges.
  - The scope notes are a collapsible card, open on the summary and closed on a detail page.
  - Each topic card says Positive or Negative, with a matching coloured edge.
  - The Tutorial tour is kept (decision 2026-11-29).
  - The summary table sorts, filters, exports CSV and copies ASINs.
- **`App\Legacy\TableMirror` (new):** makes tables here an exact copy of the old app's, IDs included: upsert on `id`, parents first, then delete rows the old app doesn't have, children first. Used by `legacy:import-products` and `legacy:import-us-product-reviews`.
- **Plan change:** the plan said the reviews import preserves IDs and "must run before the collectors first run, to avoid ID clashes". That holds only for the first run: during shadow running both apps keep adding rows and their IDs would collide. Mirroring makes every run safe, and matches the plan's own rule that the old app's rows replace this app's shadow rows. Plan section 5.3 is updated.
- **`compare:us-product-reviews`:** runs the summary query against both databases and hashes every row of the six tables, raw responses included (by md5). Counts and hashes only.

### Test status
421 tests, 1,439 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes. The four new migrations roll back and re-apply on MariaDB. CI's steps were replayed on a clean copy without `.env`.

- **Carried over unchanged (15):** `FeedbackTest` 6, `FeedbackServiceTest` 9.
- **New:** `ImportProductsAndReviewsTest` 8, `UsProductReviewsPageTest` 3.

### Verified locally
- **Against the restored `tools_db` copy** (into a throwaway MariaDB, since removed):
  - `legacy:import-products`: 200 products and 200 SC products.
  - `legacy:import-us-product-reviews`: 3,286 raw snapshots, 3,394 topics, 3,434 subtopics, 4,753 snippets, 3,127 trend snapshots and 17,431 trend points, in about 13 seconds. A second run added and removed nothing.
  - `compare:us-product-reviews` matched in all seven sections, including the summary page (239 ASINs).
- **In headless Chrome, with made-up data:** the summary and detail pages. The detail screenshot caught the topic cards' coloured edge not showing: the rule was earlier in the stylesheet than `.card`. Fixed with a more specific selector, then checked again.
- **Not verified:** a live collector run (it needs the server's SP-API seller token; the first scheduled run is Sunday 01:00 UTC).

### Next step
- Lesley pushes the branch; it merges itself.
- **On the server:** `legacy:import-platform`, `legacy:import-products`, `legacy:import-us-product-reviews` (`--dry-run` first), then `compare:us-product-reviews`. Run the reviews import before Sunday 01:00 UTC if possible. Either way, re-running it later replaces whatever this app collected.
- Then step 7: BioSteel Shopify Lost Sales (renamed from Stockout; decision D7, the old URL redirects).

## 2026-11-30: chart tooltips on hover

### Verified live (Lesley)
- The line-chart scale fix (#18) works: the users and teams lines show the daily growth again.
- Google Analytics not loading was a typo in the key file's name. Fixed by Lesley; `team-app-stats:check` was available to confirm it.
- **New report:** the charts showed no tooltips on hover. They need them.

### What changed
Branch `fix/chart-tooltips`, in the shared chart helper (`resources/js/chart-theme.js`), so every chart in every tool gets it:
- **Hover anywhere along the axis** shows that point's tooltip (`interaction: index`, `intersect: false`). Chart.js's default needs the pointer on the point itself, and the line charts draw no points, so it almost never fired. Horizontal bar charts use the vertical axis. A dot marks the hovered point.
- **Tooltips are styled from the theme tokens** (surface, text, border), so they read well in all four themes. Numbers show with thousands separators.

### Verified locally
Headless Chrome driven over the DevTools protocol with real mouse moves, a page of made-up App Stats data:
- users, teams and growth charts: tooltip with that day's value (for example "27,180")
- sport bars: hovering the row shows "SOCCER 1,117"
- ads chart: both series ("Ad requests: 1,500 / Impressions: 1,000")

### Test status
395 tests passing; JavaScript only, so no PHP test changed. Pint clean. `npm run build` passes.

### Next step
- Lesley pushes the branch; it merges itself.
- Then step 6: US Product Reviews.

## 2026-11-30: App Stats chart scale, connection check; steps 4 and 5 verified live

### Verified live (Lesley, on the server)
- **`legacy:import-platform`, `legacy:import-team-app-stats` and `legacy:import-walmart-us` ran.**
  - App Stats: 3,598 snapshots, 16,401 sport rows, 142 ad days, 1,956 and 1,975 social rows, and the backups, all new. None was "updated", so the new app's own hourly snapshot hadn't recorded an hour yet (assumed: the BioSteel Teams login had only just been added).
  - Walmart: 27 items, 79 audits, 440 issues, 85 catalog scores. The old app still has the same 79 audits from 2026-10-06, which confirms found issue 11 live.
- **`compare:team-app-stats`** (2026-10-28 to 2026-11-26) matched in all six sections. **`compare:walmart-us`** (2026-09-28 to 2026-11-26) matched in all four.
- **Lesley compared the App Stats page side by side with the old one and found two problems:**
  - The users and teams lines looked flat. The values were the same (27,210 users, 3,727 teams, +242 and +25 over 7 days), but my line charts started the y-axis at zero; the old charts scaled to the data.
  - Google Analytics and the QR campaign didn't load ("Couldn't load ...").

### What changed
Branch `fix/team-app-stats-charts-and-check`.
- **Line charts** (users and teams over time) no longer start at zero, as in the old app, so a few dozen a day on a 27,000 total is visible. Bar charts still start at zero.
- **`team-app-stats:check` (new):** checks each outside connection in turn and says which step fails:
  - the key file (set, present, readable by the site user, a service-account key)
  - Google's token exchange for Analytics and for Ad Manager
  - a one-day `runReport` on each GA4 property, showing Google's error status (for example `PERMISSION_DENIED`) if it fails
  - the BioSteel Teams login

  It prints the service account's address, which is needed to grant access, and nothing else: no keys, no tokens, no data.

### Test status
395 tests passing on SQLite. Pint clean. `npm run build` passes. New: `TeamAppStatsCheckCommandTest` (4). The chart change is JavaScript and isn't covered by PHP tests.

### Next step
- Lesley pushes the branch; it merges itself. Then run `team-app-stats:check` on the server and share the table, which says why GA4 doesn't load.
- Then step 6: US Product Reviews.

## 2026-11-30: step 5, Walmart US Listing Auditor

### Step 4 on the server (reported by Lesley)
- The App Stats settings are in place. For `GOOGLE_TEAM_APP_STATS_KEY_FILE`, Lesley asked whether the sign-in client ID and secret would do; they won't (a service account is needed), so the old app's key file was copied. Lesley reports the set-up is done. The `compare:team-app-stats` output hasn't been shared yet; log it when it is.

### What changed
Branch `phase-0/walmart-us-auditor`. Step 5 of the plan, section 5.2.
- **Backend, ported unchanged:** `config/walmart.php` (US, plus the unused CA block), the six table migrations, the six models, `InteractsWithWalmartApi`, `WalmartUsApiClient`, the catalog, report, listing-audit and audit-report services, the `walmart-us:listing-audit` command, the controller and its two routes.
- **Registration:** the tool under a new "Walmart" group (icon `cart`), with the old name and description, and its 06:00 UTC task.
- **Pages:** rebuilt on the design. Each score shows its band in words (Good 80+, Fair 50+, Poor below), and the SKU table sorts and exports by the number. The catalog rollup shows how fresh it is. The trend uses the shared themed chart. The old page had no tour, so none was added.
- **Fixes** (`docs/found-issues.md`), each test failing on the old code first:
  - **11:** the report download now checks its HTTP status, and a report with no SKU rows also falls back to the Insights endpoints. Before, Walmart's 520 error body was read as an empty report, so the run audited 0 SKUs and still wrote a catalog score. **The old app's data shows this:** 82 daily catalog scores, but SKU audits on only three days, the last on 2026-10-06. Every run since has audited nothing.
  - **23, in the scheduler:** `ScheduledTask::registerAllOnto` registers tasks shortest first, by their last finished run. Laravel runs the tasks due in one minute one after another, so the 10 to 20 minute Walmart audit at 06:00 held up the rest. Running tasks in the background was not used, because it would break the run-history tracking (`before`/`after` run in different processes).
- **Not ported:** `WalmartUsAuditParamsSeeder`, which had to be run by hand. Nothing reads the params yet, and the import copies the one row.
- **`legacy:import-walmart-us`:** see the README table. Audits and scores this app records itself keep their own `run_at` and are left alone.
- **`compare:walmart-us`:** matches rows on their natural keys and counts this app's own nightly rows as "only here" rather than as a difference. Every number is normalised to 4 places, because MariaDB returns `70.0000` where SQLite returns `70`; the MariaDB test run caught this.

### Test status
391 tests, 1,331 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes. The seven new migrations roll back and re-apply on MariaDB.
- **Carried over unchanged (30):** `WalmartUsAuditTest` 6, `WalmartUsListingAuditCommandTest` 4, `WalmartUsAuditReportServiceTest` 8, `WalmartUsApiClientTest` 4, `WalmartUsListingAuditServiceTest` 4, `WalmartUsCatalogServiceTest` 4.
- **New:** `WalmartUsFixesTest` 2, `ScheduledTaskOrderTest` 1, `WalmartUsPageTest` 3, `ImportWalmartUsTest` 6.

### Verified locally
- **Against the restored `tools_db` copy** (into a throwaway MariaDB, since removed): the import copied 27 items, 79 snapshots, 79 audits, 440 issues, 82 catalog scores and 1 params row. A second run added and removed nothing. `compare:walmart-us` for 2026-09-01 to 2026-11-26 matched in all four sections.
- **In headless Chrome, with made-up data:** the index page.
- **Not verified:** a live Walmart run (it needs the server's credentials). After the first nightly run, check that Admin > Scheduler shows SKUs processed and `source=report` or `source=insights` in the run output.

### Next step
- Lesley pushes the branch; it merges itself.
- **On the server:** add `WALMART_US_CLIENT_ID`, `WALMART_US_CLIENT_SECRET` and `WALMART_US_ENVIRONMENT=production` to `.env` (copy from the old app), then `php artisan optimize`. Then run `legacy:import-platform`, then `legacy:import-walmart-us` (`--dry-run` first), then `compare:walmart-us`.
- Then step 6: US Product Reviews.

## 2026-11-29: step 4, BioSteel Teams App Stats (first tool)

### Verified live (3h, reported by Lesley)
- The `schedule:run` cron entry and `SCHEDULER_PHP_CLI_BINARY` are in place. Admin > Scheduler shows the heartbeat succeeding, and Run Now works. 3h merged as #15 after the CI fix (found issue 31).

### What changed
Branch `phase-0/team-app-stats`. Step 4 of the plan, section 5.1.

- **Backend, ported unchanged:**
  - `BioSteelTeamsClient` (shared with Team Sponsorship, step 10)
  - `GoogleServiceAccountAuth`, `AdManagerClient`, `Ga4Client`, `SocialInsightsClient`, `TeamAppStatsService`
  - the three commands, the controller and its seven routes, `config/team_app_stats.php`
  - the five models
  - `Order` and `OrderItem` with their two empty tables, since the client needs `Order::normalizeTeamName()`
- **Registration** (`ToolRegistry`, `TaskRegistry`): the tool, under the "BioSteel Teams" group with the design's `phone` icon, uses the name and description the old app's admins last set. Its three tasks are registered with the old schedules. The social task runs in America/Toronto.
- **Page:** rebuilt on the design tokens and shared components, keeping every element ID, `data-raw` attribute and `mult-*` class the old page and tests use. The script moved from inline to its own Vite entry, `resources/js/tools/team-app-stats.js`.
  - Charts use the shared chart theme and recolour in place.
  - GA4 charts are created once and fed new data, never rebuilt.
  - html2canvas and jsPDF load only when PDF export is clicked.
  - Changes use `hidden` instead of inline display styles, and a toast instead of `alert()`.
  - Gains and losses show their sign as well as their colour.
- **Tours (decision 2026-11-29, Lesley: keep them):** `resources/js/tour.js` (intro.js 8.5.0, styled from the tokens) and `<x-tour-button>`. The invitation email's "Tutorial button" line, left out in 3g, is back.
- **Shared fixes found while building this:**
  - `themedChart` coloured datasets after creating the chart and then refreshed with `update('none')`, which left bar charts Chart.js's default grey. The 3a gallery only had a line chart. Datasets are now coloured before creation, and a theme change does a full update.
  - Cards in a grid could be pushed wider than the screen by a chart canvas on a phone; `.card` now has `min-width: 0`.
- **Fixes** (`docs/found-issues.md`, each test failing on the old code first):
  - **12:** the social snapshot is stamped with the Toronto date it ran on, not the UTC date (already the next day at 23:55 Toronto). Rows the old app wrote keep their dates.
  - **20 (App Stats part):** malformed date filters fall back to the default range instead of a 500, and a backwards range is swapped. The Lost Sales part waits for step 7.
- **Not ported:** the old team-totals correction migration (2026-09-30) and its 5 tests. The imported data is already corrected, and its source CSV holds real daily figures that must not be committed. Its two backup tables are created and copied, so the original values are kept.
- **`legacy:import-team-app-stats`:**
  - snapshots upserted on `captured_at`, with the old app's reading winning for an hour both apps captured
  - sport rows brought in line and re-mapped to the new snapshot IDs
  - ads on `date`, social rows on (`captured_at`, period, rank), and the backups re-mapped
  - chunked with pauses, `--dry-run`, counts only
- **`compare:team-app-stats`:** runs `TeamAppStatsService` (now able to take a connection) against both databases for a fixed period. Sections: hourly and daily series, sport breakdown, ads, and both social leaderboards. Counts and hashes only; it exits non-zero on any difference.

### Test status
349 tests, 1,203 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes, and the page's own script is about 15 kB. The six new migrations roll back and re-apply on MariaDB. CI's steps were replayed on a clean copy without `.env` before handing over.

- **Carried over (64):** `BioSteelTeamsClientTest` 20, `TeamAppStatsTest` 21, `Ga4ClientTest` 5, `SocialInsightsClientTest` 5, the three command tests 3 + 3 + 4, and `AdManagerClientTest` 3. The social command test's "today" is now the Toronto date (fix 12); nothing else changed.
- **New:** `TeamAppStatsFixesTest` 3, `TeamAppStatsPageTest` 4, `ImportTeamAppStatsTest` 8. `SchedulerPagesTest` now checks that the two platform tasks are present, rather than that they are the only ones.

### Verified locally
- **Against the restored `tools_db` copy** (into a throwaway MariaDB, since removed): the import copied 3,536 snapshots, 15,719 sport rows, 139 ad days, 1,892 and 1,900 social rows, and 2,188 and 20,277 backup rows, in about 13 seconds. A second run added nothing. `compare:team-app-stats` for 2026-10-01 to 2026-11-23 matched in all six sections.
- **In headless Chrome, with made-up numbers:** the page at 1280px and at 390px, with the charts coloured, the GA4 and social error notices (no credentials locally) and the phone layout.
- **Not verified:** live GA4, Ad Manager and Social Insights calls (they need the server's credentials), the tour, and PDF export in a real browser.

### Next step
- Lesley pushes the branch; it merges itself.
- **On the server**, in `.env`:
  - `BIOSTEEL_TEAMS_USERNAME` and `BIOSTEEL_TEAMS_PASSWORD`. **Rotate the password first** (the open security item about the exposed BioSteel Teams credential), then update it in the old app's `.env` too.
  - `GOOGLE_TEAM_APP_STATS_KEY_FILE`, with the key file copied to that path outside `httpdocs/` and readable by the site user.
  - Then `php artisan optimize`.
- **Then on the server:** `legacy:import-platform` (to copy the tool's order and task settings), `legacy:import-team-app-stats --dry-run`, the import itself, then `compare:team-app-stats`.
- Then step 5: Walmart US Listing Auditor.

## 2026-11-29: step 3h, scheduler (platform complete)

### What changed
Branch `phase-0/scheduler`. Slice 3h, the last of the platform (plan section 3). 3g merged as #14.

- **Database scheduler:** the old `scheduled_tasks` and `scheduled_task_runs` tables, models, `routes/console.php` registration, `schedule:execute-run`, `scheduler:heartbeat`, the failure email, `BackgroundProcessLauncher` and `config/scheduler.php`, all ported. The heartbeat registration migration is the old one.
- **Admin > Scheduler** (`/admin/schedules`): the old controller and routes. The list shows the schedule, the last run's status and the next run. The edit form, the enable/disable buttons, and Run Now with the run history (status, duration, who started it, output in a collapsible block) are all there. The sidebar links it (new `calendar` and `play` icons).
- **`App\Scheduling\TaskRegistry`:** the registration convention for tasks, matching `ToolRegistry`. It is insert-only.
- **Fixes** (`docs/found-issues.md`), each test failing on the old code first:
  - **17:** a Run Now whose command throws is now closed as failed, with the error in its output, and the admins are emailed. Before, it stayed "running" until it went stale, with no email.
  - **18:** `scheduled_tasks.command` is unique, so registrations are idempotent, and failure emails skip disabled admins.
  - **30:** `activitylog:clean --force` runs daily at 03:15 UTC. `--force` is needed because Spatie's command asks for confirmation in production and gives up when nobody answers.
  - **New:** a failure email that can't be sent (for example, Gmail not connected) is logged instead of breaking the run record. `App\Scheduling\FailureAlert` sends for both the cron path and Run Now.
- **CI fix (found issue 31):** the first push failed CI (run #20: Pint and both test jobs). The old `routes/console.php` queried `scheduled_tasks` on every Artisan command. CI's `composer install` runs `artisan package:discover` before any database exists, so the install failed. Registration moved to `withSchedule` in `bootstrap/app.php`, which runs only for scheduler commands. `ArtisanWithoutDatabaseTest` runs a real `artisan package:discover` against a missing database; it failed before the fix. Local runs never caught it, because the local `.env` points at a live database. CI's steps were then replayed on a clean copy without `.env`: install, Pint and tests all pass, and `schedule:list` still lists both tasks.
- **Import:** `legacy:import-platform` copies each old task's enabled flag, schedule and timezone onto tasks registered here, matched by command. `asp:backfill` always stays disabled (plan section 2, rule 6). Tasks not ported yet are not created, and run history is not copied (plan section 3). Today only the heartbeat matches, and its settings are the same, so nothing changes.

### Test status
270 tests, 960 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes. The four new migrations roll back and re-apply on MariaDB.

- **Carried over (16):** `ScheduleManagementTest` 6, `ScheduleExecuteRunCommandTest` 3, `BackgroundProcessLauncherTest` 4, `ScheduledTaskRegistrationTest` 2, `SchedulerHeartbeatRunHistoryTest` 1. In two of them the setup now removes the heartbeat row the migration registers before adding its own, because `command` is unique (fix 18). The assertions are unchanged.
- **New:**
  - `SchedulerFixesTest` (6): five failed on the old code first. The sixth, "clean-up keeps 365 days", already passed, since Spatie's command works; it was simply never scheduled.
  - `SchedulerPagesTest` (7)
  - one `ImportPlatformTest` case, plus a `scheduled_tasks` row in the two count-table cases

### Verified locally
- On a throwaway MariaDB: after the real import, `schedule:list` showed both tasks, a real `schedule:run` ran the heartbeat, and its run was recorded as succeeded with its output.
- In headless Chrome: the scheduler list and the heartbeat's run history.

**Not verified:** Run Now under the server's PHP-FPM. It needs `SCHEDULER_PHP_CLI_BINARY`, which `docs/server.env.example` already sets.

### Platform status
All eight platform slices (3a to 3h) are done, and `legacy:import-platform` covers every platform table in plan section 3.

### Next step
- Lesley pushes the branch; it merges itself.
- **On the server:** check the one cron entry exists (README, Deploying), running every minute as the site owner:
  ```
  /opt/plesk/php/8.4/bin/php /var/www/vhosts/tools.coachwoodgroup.com/httpdocs/artisan schedule:run
  ```
  Then check `.env` has `SCHEDULER_PHP_CLI_BINARY=/opt/plesk/php/8.4/bin/php`. Within 5 minutes Admin > Scheduler should show a successful heartbeat. Run Now on the heartbeat checks the background launcher.
- Then step 4: the first tool, BioSteel App Stats (plan section 5.1).

## 2026-11-29: step 3g, mail and invitations

### What changed
Branch `phase-0/mail-invitations`. Slice 3g of the platform (plan section 3). It was started from the 3f commit while 3f waited for CI, and moved onto `main` once 3f merged.

- **Gmail transport:** the old `GmailApiTransport`, `gmail_connections` table and `GmailConnection` model. The refresh token is encrypted with `APP_KEY`, as before. `Mail::extend('gmail_api')` is in `AppServiceProvider`, and the `gmail_api` mailer is in `config/mail.php`. The old connection is not imported (plan section 3), so it must be reconnected.
- **Admin > Mail** (`/admin/settings/mail`): the old controller and routes. Connect asks Google for `gmail.send` only, offline, with consent. Reconnect and Send Test Email are there too. The sidebar links it (new `mail` icon).
- **Invitations (Google only, decision 2026-11-27):**
  - Only addresses on a company domain can be invited; others get a form error naming Admin > Google Sign-In.
  - Every invitation is the old "Sign in with Google" email, without its "Tutorial button" line (the new pages have no tutorial).
  - `UserInvitationNotification` (the password link) is not ported.
  - Resend invite is refused, with a message, for a pending address outside the company domains (an imported external account).
  - A failed send still leaves the person set up and says why, as before.
- **Users list:** an Invite User button, and Resend invite on rows for people who haven't signed in yet.
- **Fixes** (`docs/found-issues.md`):
  - **19:** the test-email button showed a 500 when Gmail failed; it now shows the error. A rejected (401) cached access token is dropped and the send retried once, instead of failing for up to 55 minutes. Both tests failed on the old code first.
  - **10:** gone, since there is no invitation link to expire.

### Test status
239 tests, 873 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes. The `gmail_connections` migration rolls back and re-applies on MariaDB.

**`UserInvitationTest`** (ported):
- **Unchanged:** the two company-domain invite cases, the company resend, the resend failure, the two permission-cache cases and the admin-only case.
- **Changed on purpose:**
  - "invite an external user" became "invite a colleague with tools and teams" (company address, Google email).
  - "resend regenerates token" became "resend sends again" with a company address.
  - The failed-invitation case uses a company address.
- **Removed:** "set a password via the emailed link".
- **Added:** "an outside address can't be invited" and "resend to an outside address explains why not".

The old assertions that the password-link notification was not sent are kept.

**`ActivityLogTest`:** the invitation case uses a company address. All four old cases are now ported.

**New:** `MailSettingsTest` (11), with every Google request faked.

### Verified locally
In headless Chrome: Admin > Mail with nothing connected, and the invite form.

**Not verified:** a real Gmail connection and send. That needs the server's OAuth client.

### Next step
- Lesley pushes the branch; it merges itself.
- **On Google Cloud:** add `https://tools.coachwoodgroup.com/admin/settings/mail/callback` as an authorised redirect URI on the OAuth client, and make sure the Gmail API is enabled in its project.
- **On the site:** Admin > Mail > Connect Gmail as the sending account, then Send Test Email.
- Then 3h: the database scheduler, run history, Run Now, failure emails, heartbeat, and the scheduled `activitylog:clean` (found issue 30).

## 2026-11-28: step 3f, Admin > Google Sign-In

### What changed
Branch `phase-0/google-domains`. Slice 3f of the platform (plan section 3). Google sign-in itself came in 3b, so this is the domain list only.

- **Admin > Google Sign-In** (`/admin/settings/google-domains`): the old controller, routes and names unchanged. You can list, add and remove domains. Domains are normalised to lower case, duplicates are rejected regardless of case, and adding or removing one is logged. The sidebar's Admin group links it (new `globe` icon).
- **Warnings for the 3b behaviour:**
  - With no domain, the page says nobody can sign in, admins included. The old page warned that any Google account was accepted, which stopped being true with found issue 28.
  - Removing the last domain opens a dialog that says it locks everyone out. Removing one of several names who is affected.
  - There is no server-side block on removing the last domain. The old tests remove the only domain directly, and people already signed in stay signed in, so an admin can add it back.

### Test status
215 tests passing on SQLite. Pint clean. `npm run build` passes. No migrations in this step.

- **Carried over (8):** `GoogleDomainTest`. Seven cases are unchanged. The empty-state case now expects "Nobody can sign in with Google" instead of "accepts any Google account", because the behaviour changed on purpose in 3b.
- **New:** `GoogleDomainPageTest` (5).

### Verified locally
In headless Chrome: the page with no domain (the red warning) and with two domains.

### Next step
- 3e (#12) was pushed and was waiting for CI when this started. This branch was started from it and moved onto `main` once it merged.
- Lesley pushes this branch; it merges itself.
- Then 3g: the Gmail transport, Admin > Mail settings and invitations (without password links, decision 2026-11-27).

## 2026-11-28: step 3e, Admin > Users, Teams and Activity

### What changed
Branch `phase-0/admin-users-teams`. Slice 3e of the platform (plan section 3). 3d merged as #11.

- **Admin > Users:** the old controller's index, edit, update, deactivate, activate and delete, with the same routes and names. A sortable, filterable table shows a status badge for each person ("Active", "Deactivated" or "Not signed in yet"). Deactivate and delete each ask for confirmation in a dialog. Inviting people (`create`, `store`, `resend-invitation`) moves to 3g with mail; until then a person appears after their first Google sign-in.
- **Admin > Teams:** the old controller unchanged: create, edit, delete, add and remove members. The `teams` and `team_user` migrations are the old ones. `User::teams()` is back.
- **Admin > Activity:** the old viewer, filtered by category, 25 a page, with a new `<x-pagination>` component. Entries logged in the same second are now ordered by ID; the old viewer showed them in an arbitrary order.
- **`/admin`** redirects to Users, as before. The sidebar's Admin group has Users, Teams, Tools and Activity.
- **Fixes** (`docs/found-issues.md`):
  - **6:** Lesley chose to list every tool, inactive ones marked. The edit form now does that, and saving changes only `tool.*` permissions, so access to an inactive tool survives and comes back when the tool is switched on, and other permissions are never dropped.
  - **7:** an admin can't deactivate their own account (403) or remove their own admin role (form error).
  - **26:** the Teams page says no tool uses teams yet.
- **Wording fix:** the old role hint said the admin role "does not grant tool access by itself". `Gate::before` gives admins every tool, so the hint now says so.
- **Import:** `legacy:import-platform` copies teams (IDs preserved) and brings each imported team's members in line with the old app. Unchanged memberships keep their rows. A team name already used by a different ID here stops the import. The old app has no teams and no non-tool direct permissions, so neither the teams import nor fix 6 changes live data today.

### Test status
202 tests, 755 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes. The two team migrations roll back and re-apply on MariaDB.

- **Carried over unchanged (10):** `UserManagementTest` 5, `TeamManagementTest` 3, and the deactivation and viewer cases of the old `Admin\ActivityLogTest`. Only the invitation case of that file remains, for 3g.
- **New:**
  - `UserAccessFixesTest` (5): four failed on the old code first. The fifth (saving with an inactive tool ticked keeps it) already passed, because the old bug was only that the form never offered that tool.
  - `AdminPagesTest` (11)
  - two `ImportPlatformTest` cases, plus `teams` and `team_user` rows in the two count-table cases

### Verified locally
- Against the restored `tools_db` copy (into a throwaway MariaDB, since removed): `teams` 0 and `team_user` 0, and a re-run changed nothing.
- In headless Chrome, with made-up people: Admin > Users and a user's edit page, with an inactive tool marked.

### Next step
- Lesley pushes the branch; it merges itself. No server command is needed (the old app has no teams).
- Then 3f: Admin > Google Sign-In (the domain list). Google sign-in itself came in 3b.

## 2026-11-28: step 3d, tools registry and launcher

### What changed
Branch `phase-0/tools-registry`. Slice 3d of the platform (plan section 3). 3c merged as #10 and is deploying.

- **`tools` table:** the old app's three migrations folded into one, plus a new nullable `group` column. The design groups the sidebar and launcher ("Amazon · Pricing", "BioSteel Teams"); the old table had no grouping. Tools without a group show under "Tools".
- **Icons:** a tool's `icon` can be an `App\View\Icons` name (the design's stroke icons) or any other text, such as the old app's emoji. `<x-tool-icon>` draws one or shows the other. New icons: `chevron-up`, `chevron-down`, `grip`.
- **Self-registration (`App\Tools\ToolRegistry`):**
  - `register()` inserts the tool and its `tool.<slug>` permission and never overwrites an existing row. A new tool goes to the end of the order.
  - `unregister()` removes the tool, its permission and its grants.
  - It uses the query builder, so old migrations keep working as the models change.
  - The old app wrote raw inserts in each tool's migration.
- **Launcher (`/dashboard`):** the design's "All tools" section, grouped. It shows an empty state for someone with no tools. The design's "Needs attention" and KPI rows come later, from the tools themselves.
- **Sidebar:** Home, then the user's tools by group (the current one marked), then Admin for admins.
- **Admin > Tools:** the old controller, routes and names, plus `group`, with the old SortableJS drag reordering. New: Move up and Move down buttons that work from the keyboard, with a screen-reader announcement and a toast. A failed save puts the rows back. Deleting a tool uses `<x-modal>`.
- **Spatie middleware aliases** (`role`, `permission`, `role_or_permission`), as in the old app. `/admin/*` is behind `role:admin`.
- **Import:** `legacy:import-platform` copies each old tool's order and active flag onto tools registered here, matched by slug. It does not create tools that aren't ported yet, because their links would lead nowhere in this app. All 26 old tools are active.

### Test status
174 tests, 660 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. `npm run build` passes. The tools migration rolls back and re-applies on MariaDB.

- **Carried over unchanged (16):** `ToolManagementTest` 10, `ToolPermissionGatingTest` 5, and the tool case of the old `Admin\ActivityLogTest`. That file gains its other cases in 3e and 3g.
- **New:** `ToolRegistryTest` (13) and one `ImportPlatformTest` case. A `tools` row is added to the two import count-table cases.

### Verified locally
In headless Chrome, with made-up tools: the launcher with four groups (including an emoji-icon tool under "Tools") and Admin > Tools.

**Not verified:** dragging and the Move buttons in a real browser. The reorder endpoint is covered by the ported tests; the script is not.

### Next step
- Lesley pushes the branch; it merges itself.
- There are no tools here yet, so the launcher shows its empty state until the first tool is ported (step 4). Admins can try Admin > Tools now.
- Then 3e: Admin > Users, Teams and Activity.

## 2026-11-28: step 3c, activity log; 3b live

### Verified live (3b, reported by Lesley)
- **#9 (Google-only sign-in) deployed.** Google sign-in works on tools.coachwoodgroup.com.
- **`tools_legacy_ro` fixed.** It had `SELECT, SHOW VIEW` on `coachwood_tools` (the new app's database) and nothing on `tools_db`, so the import was refused (1044). Lesley moved the grant. `SHOW GRANTS` now lists only `USAGE` and `SELECT, SHOW VIEW` on `tools_db`. The security item "confirm `tools_legacy_ro` is SELECT only" is closed.
- **Password rotated.** The account's password hash was pasted into the chat, so Lesley changed the password.
- **`legacy:import-platform` run on the server:** 2 roles, 26 permissions, 4 domains, 20 users, 20 role grants, 145 permission grants. The counts match the local test against the restored copy. It was re-run under #9 so that no password hashes remain.

### What changed
Branch `phase-0/activity-log`. Slice 3c of the platform (plan section 3).
- **Package:** `spatie/laravel-activitylog` 4.12.3, the same version as the old app. Its three table migrations and `config/activitylog.php` (365 days) are copied unchanged.
- **`auth` log lines:**
  - The old Google sign-in lines are back: `Login succeeded`, `Google account created`, `Login blocked - domain not allowed` and `Login blocked - account disabled`.
  - New, for the 3b fixes: `Login blocked - no domain configured`, `Login blocked - linked to a different Google account` (logged against the account it tried to reach) and `Signed out - account disabled` (from the middleware).
  - `Logged out` is new and fixes found issue 24.
- **`activity_log.legacy_id` (new column):** `legacy:import-platform` now copies the old activity log, matched on the old row's ID. The plan said to preserve the IDs. That would collide, because this app logs sign-ins from its first deploy, and nothing points at an activity ID. The plan is updated.
- **Found issue 30 (new):** the old app's 365-day retention was never applied, because `activitylog:clean` is never scheduled. It will be fixed in 3h with the scheduler. Nothing is near 365 days old yet.
- **Plan change:** the 4 old `ActivityLogTest` cases test Admin > Tools, invitations, deactivation and the viewer. Each moves to its own slice (3d, 3g, 3e, 3e).

### Test status
144 tests, 581 assertions, passing on SQLite (parallel) and MariaDB 10.11.14 (a throwaway container). Pint clean. The four new migrations roll back and re-apply on MariaDB.

`AccountDeactivationTest` (3) is ported. Its cases signed in with a password, so they now go through the Google callback, and they assert the same activity-log rows. The failed case asserts the old Google controller's line, `Login blocked - domain not allowed`, instead of the password `Login failed`. New tests:
- `Platform\ActivityLogTest` (7)
- one `ImportPlatformTest` case
- an `activity_log` row in the two count-table cases

### Verified locally
The real import from the restored `tools_db` copy into a throwaway MariaDB (since removed): 431 activity rows copied, a re-run added none, and no passwords were left.

### Next step
- Lesley pushes the branch; it merges itself. Then re-run `legacy:import-platform` on the server to copy the activity log.
- Then 3d: the tools registry, the home launcher, Admin > Tools with drag ordering, and self-registration.

## 2026-11-27: step 3b, users, Google sign-in, profile, roles

### What changed
Slice 3b of the platform (plan section 3), in two pull requests:
- `phase-0/users-and-login` (#8, merged and deployed) ported password sign-in.
- `phase-0/google-only-sign-in` replaces it after Lesley's decision below. Until it deploys, the live site still has the password pages.

**Decision (Lesley):** sign-in is Google only, through the org's internal OAuth client (`docs/decisions.md`, 2026-11-27). Google sign-in moves here from 3f.

- **Users:** the old app's columns (`google_id`, nullable `password`, `disabled_at`) added by a new migration, since the users table is already deployed. `User` gains `HasRoles`, `isDisabled()` and `isPending()`. `teams()` waits for 3e. `password` stays as a nullable column but nothing uses it.
- **Roles and permissions:** `spatie/laravel-permission` 8.5.1 (the old app is on ^8.3, same config and table migration). The `admin` and `user` roles are created by a migration instead of the old hand-run `RoleSeeder`. `Gate::before` makes admins pass every check, as before.
- **Google sign-in:** `laravel/socialite` 5.33. `GoogleAuthController` ported, with fixes (below). Routes: `GET /login` (the sign-in page, a Google button only), `auth/google/redirect`, `auth/google/callback`, `POST /logout`. `/` sends visitors to `/login` and signed-in users to `/dashboard`. The password, reset, confirmation, verification and registration routes are gone.
- **Google Workspace domains:** table, model and `App\Support\GoogleWorkspace` ported. The allowed domains come only from this table; `GOOGLE_ALLOWED_DOMAIN` is no longer read and is removed from `docs/server.env.example`.
- **Profile:** name only. The email is shown read-only, as it comes from Google. Self-delete is removed.
- **Views:** sign-in and profile rebuilt on the 3a tokens. New `<x-form-field>` component (linked label, `aria-invalid`, error with an icon). The Google "G" is `public/images/google-g.svg`, since its brand colours can't be tokens. The sidebar's user chip links to the profile and has a log-out button.
- **Per-user theme (new):** `users.theme` and `users.font_size`. Picking one while signed in also saves it to the user (`PUT /preferences`). A saved choice wins over the cookie.
- **`legacy:import-platform` (new):** copies users (IDs preserved), roles and permissions (by name), user and role grants (re-mapped, replaced each run) and the workspace domains. It copies no password hashes or remember tokens, and clears any already here. Re-runnable; `--dry-run` rolls back; counts only. It stops before writing if an old user would overwrite a different person here (same email on another ID, or same ID with a different email and Google ID).

**Bug fixes** (`docs/found-issues.md`, each test failing on the old code first; the 9 ported Google tests passed on the old code unchanged):
- **28 (new):** with no domain configured, any Google account got in. Now nobody does.
- **29 (new):** without the Workspace `hd` claim, the check fell back to the email's domain, which a consumer Google account can also have. `hd` is now required.
- **8:** an email already linked to a different Google ID is refused instead of signed in to that account; the profile email is read-only; self-delete removed.
- **5:** `SignOutDisabledUsers` middleware signs a disabled user out on their next request, remember-me cookie or not.
- **27:** new Google users are marked verified (`forceFill`).

**Left for 3c:** the old activity-log lines in Google sign-in ("Login succeeded", "Login blocked - …", "Google account created"), with the activity log itself.

### Test status
133 tests, 547 assertions, passing on SQLite (parallel). Pint clean. `npm run build` passes. The password version (#8) also passed on MariaDB 10.11.14 (a throwaway container), and its migrations rolled back and re-applied; this version adds no migrations.

- **Carried over unchanged (16):** `GoogleAuthenticationTest` 9, `GoogleWorkspaceTest` 5, and in `AuthenticationTest` the screen and logout cases.
- **Changed on purpose (Google-only decision):**
  - `AuthenticationTest`: the two password cases became "no password sign-in" and "no reset, change, confirm, verify or register routes".
  - `ProfileTest` (6): name-only update, email can't change, no self-delete, and the Google notice for everyone. The Google-only notice case is unchanged.
- **Dropped:** `PasswordResetTest`, `PasswordUpdateTest`, `PasswordConfirmationTest`, `EmailVerificationTest`.
- **Moved to 3c:** `AccountDeactivationTest` (asserts activity-log rows). Disabled accounts are covered here by `GoogleAuthenticationTest` and `GoogleSignInSecurityTest`.
- **New:** `GoogleSignInSecurityTest` 6, `SignInTest` 7, `RolesTest` 4, `PreferencesTest` 7, `ImportPlatformTest` 12, two `ComponentsTest` cases. `ShellTest` now signs in and uses `/dashboard`.

### Verified
- **Locally, against the restored copy of `tools_db`** (the #8 importer, into a throwaway MariaDB, since removed): read 2 roles, 26 permissions, 4 workspace domains, 20 users, 20 role grants, 145 direct permission grants, 0 role permissions. A second run added and removed nothing. 3 admins, 1 disabled user.
- **Locally, in headless Chrome:** the sign-in page and the profile page.
- **Not verified:** a real Google sign-in (needs the server's OAuth credentials), and the theme save from the sidebar in a real browser.

### Open questions
- None blocking. `docs/server.env.example` says the redirect URI for this hostname is already on the OAuth client; confirm on the first sign-in.

### Next step
- Lesley pushes the branch; it merges itself when CI passes.
- On the server: check `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` and `INITIAL_ADMIN_EMAIL` in `.env`; confirm `tools_legacy_ro` has `SELECT` only; run `legacy:import-platform --dry-run`, then without it; then sign in with Google.
- Then 3c: activity log (365-day retention), with `AccountDeactivationTest` and the sign-in log lines.

## 2026-11-27: history grab complete, merge-on-push verified

### Verified live
- **History grab complete.** `weekly.traffic` saved with 798 rows over 5 weeks (2024-12-01 to 2025-01-04), with no fields rejected. All nine queries are now saved in `storage/app/private/amazon/history-2024/` on the server. Traffic has about twice as many rows as the sales queries, presumably because ASINs with glance views but no sales still appear (assumed, not checked).
- **Merge-on-push works.** Lesley pushed `phase-0/platform-shell` without opening a pull request. After CI passed, `auto-merge.yml` opened #6 and merged it (`0c0006c`).

### Next step
- Lesley checks the new shell on tools.coachwoodgroup.com: switching themes and text size.
- Then 3b: users, password login, profile, roles and Spatie permissions.

## 2026-11-27: step 3a, page shell and design tokens

### What changed
Branch `phase-0/platform-shell`. Slice 3a of the platform (plan section 3).
- **Tokens:** `resources/css/tokens.css` holds the type scale, spacing, sizes, radii, elevation and four themes, taken from the Claude Design canvas "Coachwood Tools Redesign" (Themes and Home artboards):
  - Midnight (default), Graphite, Daylight, Evergreen
  - each theme sets `color-scheme`, so charts and native controls know if it's light or dark
- **Chart palette:** not in the design. I added a six-colour series palette based on Okabe-Ito, which stays distinguishable for colour-blind users, adjusted per theme.
- **Tailwind CSS 4** through `@tailwindcss/vite`. The canvas is titled "Laravel + Tailwind", and decision D6 is Blade and Vite with the design's layout. Component classes are in `resources/css/app.css`; the tokens are mapped to Tailwind utilities.
- **Layouts:**
  - `<x-layouts.app>`: the design's sidebar with brand, grouped navigation, theme swatches, text size and user chip. Below 56rem it collapses to a top bar with a menu button.
  - `<x-layouts.guest>`: for sign-in and errors.
  - Both include a skip link.
- **Navigation:** `App\View\Navigation` has only Home until the tools registry (3d).
- **Theme and text size:**
  - Stored in plain cookies (`theme`, `font_size`), excluded from cookie encryption, so the first response has the right theme. The old app kept the theme in localStorage and font size in a cookie.
  - Changing the theme dispatches `theme-changed`, as before, with `detail.theme` and `detail.scheme`.
  - 3b adds a per-user theme, as the design asks.
- **Components (spec section 15):**
  - page header, card, stat row and tile, status badge, freshness badge
  - notice, empty state, data state (the five states), filter bar, tabs
  - data table and `x-th`: sort, filter, CSV export, copy ASINs
  - modal (native dialog), toasts (including session flashes)
  - chart card, incident card, tool tile, icon
- **Chart helper:** `resources/js/chart-theme.js`. `themedChart()` colours and recolours charts from the tokens. The old app built a palette on each chart page.
- **500 page:** ported with its wording unchanged, on the guest layout. The old `ErrorPageTest` is carried over unchanged and passes.
- **Home** (`/`): the new shell with an empty state until 3b and 3d.
- **Gallery:** `/dev/components` shows every component with made-up values. Local and testing only.
- **Tests:** `Tests\TestCase` calls `withoutVite()`, because CI's test jobs don't build assets.

### Test status
71 tests, 317 assertions, passing on SQLite (parallel). Pint clean. `npm run build` passes.

New tests:
- `ShellTest`: theme and text-size cookies, fallbacks, the gallery, flash toasts
- `ComponentsTest`: badge labels, No data instead of zero, freshness, the five states, tabs, table tools, icon accessibility
- `TokensOnlyTest`: no hard-coded colours or pixel sizes in views or `app.css`

### Verified
- **Locally, in headless Chrome:**
  - the gallery in Midnight and Daylight at 1440px
  - the home page at 390px, where the sidebar collapses to the top bar
  - the chart draws with the series palette
- **Not verified:** theme switching and the `theme-changed` recolour in a real browser, and the modal, toast, copy and CSV buttons. They aren't covered by PHP tests.

### Decided by default (Lesley to confirm)
- Tailwind CSS 4 for utilities, as the design's title says.
- Fonts (Inter and JetBrains Mono) from fonts.bunny.net, as the old app loaded its font.
- The six-colour chart palette above.
- The ⌘K search in the design waits for the tools registry (3d).

### Next step
- Lesley pushes the branch; it should merge itself, which is the first live test of merge-on-push.
- Then 3b: users, password login, profile, roles and Spatie permissions (26 old tests).

## 2026-11-27: history grab run, traffic re-run

### What changed
Branch `phase-0/history-grab-traffic`.
- **Plan:** `weekly.traffic_availability` is now `weekly.traffic`, with glance views and lost featured offer only. Amazon keeps weekly inventory (`productAvailability`) for only 8 weeks, so late-2024 availability can't be fetched.
- **Bug fix, FATAL error text:** Amazon's error document is a bare GraphQL errors array, not `{"errors": [...]}`. The runner saved it as raw JSON. It now reads either shape. The feature test's fake now returns the live shape; it failed before the fix.
- **README:** the fetch list says traffic, not traffic and availability.
- **Merge on push.** Lesley asked for branches to merge themselves once CI passes, as in the other projects, without opening a pull request by hand.
  - **CI:** `ci.yml` now runs on a push to any branch. The `pull_request` trigger is gone, so CI doesn't run twice.
  - **Merging:** `auto-merge.yml` acts on a passing push run. It opens the pull request if the branch has none, then merges it and deletes the branch. The pull request is still the record of each step.
  - **Skipped:** `wip/` branches, a draft pull request, the `hold` label, a branch behind `main`, and a commit that's no longer the branch head.
  - **Repo setting needed:** "Allow GitHub Actions to create and approve pull requests". Without it, `gh pr create` fails.
  - This is in the same pull request as the traffic fix, so only one pull request has to be opened by hand.

### Test status
52 tests, 253 assertions, passing on SQLite (parallel). Pint clean. The workflow YAML parses; the new auto-merge flow isn't verified live yet. It runs from `main`'s copy, so the first branch pushed after this merges tests it.

### Verified live
**First run on the server, 2026-11-27.** Amazon accepted dates from 2024-11-28. Eight of nine queries saved:

| Query | Rows | Periods |
| --- | --- | --- |
| `daily.sales` | 2651 | 38 (2024-11-28 to 2025-01-04) |
| `weekly.sales_costs` | 359 | 5 |
| `weekly.orders` | 376 | 5 |
| `weekly.sourcing` | 381 | 5 |
| `weekly.customer_returns` | 83 | 5 |
| `weekly.vendor_codes` | 390 | 5 |
| `weekly.geo_province` | 3285 | 5 |
| `weekly.geo_city` | 18227 | 5 (over 5 minutes to run) |

- **Failed:** `weekly.traffic_availability` ended FATAL: "Weekly Inventory data is only available for 8 weeks, including the current week."
- **Black Friday 2024 is saved,** in `daily.sales`. 2024-11-24 to 2024-11-27 had already aged out.
- **Phase 1 note:** Data Kiosk weekly inventory can't be backfilled past 8 weeks. Inventory history must come from our own saved snapshots.

### Open questions
- None new. The manifest keeps the old failed `weekly.traffic_availability` entry; `weekly.traffic` replaces it.

### Next step
- Lesley turns on the repo setting. The last pull request opened by hand merges (auto); from then on, pushing a branch is enough. Lesley re-runs the command; only `weekly.traffic` is fetched. Week 2024-12-01 ages out around 2026-12-02.
- Then step 3, the platform.

## 2026-11-27: finance role, auto-merge confirmed, history grab

### What changed
Branch `phase-0/history-grab`.
- **New command, `amazon:history-grab`,** a one-off. It saves Data Kiosk history from before 2024-12-29 while Amazon's two-year limit still allows it. Lesley chose this on 2026-11-26 (open question 2 of the probe results).
- **What it fetches:** nine queries, run one at a time:
  - daily sales from the earliest day allowed (Black Friday 2024 is included if it runs by 2026-11-28 UTC)
  - weekly from 2024-12-01: sales and costs, orders, traffic and availability, sourcing, returns, vendor codes, province, city
- **Overlap:** both grains end on 2025-01-04, so the week of 2024-12-29 overlaps the old app's data and Phase 1 can check them against each other.
- **Fields:** every query uses fields the old app or the probe has run successfully.
- **Storage:**
  - raw documents, gzipped, in `storage/app/private/amazon/history-2024/`
  - a manifest of queries, statuses and counts
  - nothing in the database
  - a re-run fetches only what failed
- **Code:** `App\Amazon\Backfill\HistoryGrabPlan` and `HistoryGrabRunner`. They reuse the probe's read-only `ProbeClient` and `DataKioskQuery`. `ProbeClient`'s refusal message no longer names `amazon:probe`.
- **README:** a "One-time commands" entry.

### Test status
51 tests, 199 assertions, all passing on SQLite and on local MariaDB 10.11.14 (parallel). Pint clean. The command hasn't run against Amazon yet.

### Verified live
- **Auto-merge works.** The `docs/probe-follow-up` pull request passed CI, and `github-actions[bot]` merged it and deleted the branch, with no clicks. The previous docs commit went to `main` directly, because a branch I created tracked `origin/main`. Branches are now created with `--no-track`.
- **Finance and Accounting role.** Amazon approved it for the developer profile, and it's on the Listing Optimizer app under Sellers and Vendors. Lesley re-authorized the vendor account, and the new refresh token is in the `.env` of both apps.
- **Finance Remittance passes** (2026-11-27 00:38 UTC):
  - **`getRemittanceHeaders`:** 50 rows dated 2026-10-22 to 2026-11-25. Asked for 90 days, so 50 is probably one page and Phase 1 must page through. 62% of payment amounts are zero.
  - **`getRemittance`:** 3 line items for one payment, with invoice number, type, date, totals, net paid, two breakdown types and related identifiers. Amounts can be negative. Withholding is always zero.
- **Result:** the Deductions Tracker can use the API (spec 12.1). The upload stays as the fallback.

### Assumed
- Each Data Kiosk query finishes in one to two minutes, as in the probe.
- The city grain stays a manageable size over five weeks. The old app notes that geography rows are close to order level.

### Next step
- Merge (auto). Then Lesley runs `amazon:history-grab` on the server by Saturday 2026-11-28 UTC and sends back `manifest.json`.
- Then step 3, the platform.

## 2026-11-26: Amazon probe results

### What changed
- **The probe ran on the server** at 2026-11-26 15:16 UTC, covering all 25 capabilities. Earlier the same day, a run with `--only=report.traffic` also passed. Data Kiosk product availability was rate limited in the full run and passed on its own at 23:51 UTC.
- **Results:** 21 pass, 1 empty, 1 fail, 1 role missing, 1 skipped. They are in the server's `amazon_capabilities` table and in `summary.json`. The summary is not committed.
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
| Data Kiosk 2023 history | fail | The two-year limit. |
| Coupon performance report | empty | The report works, but there were no coupons in 12 months. |

### Product availability (re-run, verified live)
- 648 rows, 162 ASINs, 4 weeks. No fields rejected.
- **`sourceableROOS` is a 0–1 fraction:** range 0 to 1, zero on 91% of rows. The old code's unverified assumption was right.
- **`ultraFastTrack` is 0 on every row,** so it carries no signal for this account.
- **In-transit inventory works.** `sellableInTransitInventory` and `unsellableInTransitInventory` are filled on 80% of rows, the same rows as on-hand inventory.
- `sellThroughRate` matches the inventory report: −0.04 to 3.5.

### Assumed
- `UNKNO` is Amazon's placeholder for an unattributed vendor code. Not confirmed with Amazon.
- The vendor-code query returned exactly 500 rows. That's probably chance; Phase 1 compares its totals with the ASIN-only query.

### Probe quirk
In `summary.json`, when an object such as `shippedOrders` is null on some rows, its own entry shows `empty_rate: 1`. The rates on its child fields are the accurate ones. This affects nothing outside the summary.

### Open questions for Lesley
1. **Finance and Accounting role.** Lesley wants it (2026-11-26). The app's role list (Listing Optimizer) doesn't offer it under Vendors or Sellers, so the developer profile has to request it from Amazon first. Then:
   - tick it on the app
   - re-authorize the vendor account, which issues a new vendor refresh token
   - put that token in both apps' `.env`
   - re-run the two Finance Remittance checks

   Until then, the Deductions Tracker uses the remittance upload (spec 12.1).
2. **History before 2024-12-29.** The old database's weekly data starts on 2024-12-29. Data Kiosk can still return:
   - the four weeks from 2024-12-01 to 2024-12-28, the run-up to Christmas 2024
   - Black Friday and Cyber Monday 2024, at daily grain only, for a few more days

   The two-year limit drops a day each day. The first of those weeks goes on 2026-12-01 and the last in late December 2026. Grabbing them needs a small one-off read-only command that saves the raw documents for Phase 1.
3. **Suspended listings:** answered. They are on the Amazon.ca seller account, which BioSteel doesn't really use (Canada sells through Vendor Central, the US through Seller Central). No action needed.

### Next step
- Lesley requests the Finance and Accounting role.
- Decide on the December 2024 history grab (question 2).
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

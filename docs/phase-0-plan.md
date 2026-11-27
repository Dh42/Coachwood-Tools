# Phase 0 plan

Status: **approved by Lesley on 2026-11-25**, with the answers in section 8.

Phase 0 builds the new app's platform, adds the Amazon capability probe, and ports ten tools from the old app (tools.biosteel.com). It ends at the gate in section 7. The order is lowest risk first: the platform, then read-only tools, then tools that write to Shopify or Amazon, with the money-moving tool last.

Sources: `docs/build-spec.md`, `docs/decisions.md`, the old code at `~/PhpstormProjects/CompanyTools`, its README and the context.md entries for these tools. The old suite passes 1,296 of 1,296 tests (run 2026-11-24 on a copy, SQLite, 46 s).

## 1. Order of work

| Step | Work | Writes outside? | Tests carried over |
| --- | --- | --- | --- |
| 1 | Skeleton: Laravel 13 on PHP 8.4, Pint, PHPUnit with ParaTest, CI on SQLite and MariaDB 10.11, read-only `legacy` connection, the external-writes switch, docs | No | 0 |
| 2 | First deploy, then `amazon:probe` with the ported `SpApiClient` | No (reads only) | 12 (`SpApiClientTest`) |
| 3 | Platform, in 8 slices (section 3) | Gmail sends only | 111 |
| 4 | BioSteel App Stats | No | 44 + 20 shared (the old team-totals correction migration and its 5 tests are not ported; see the step 4 context entry) |
| 5 | Walmart US Listing Auditor | No | 30 |
| 6 | US Product Reviews | No | 15 |
| 7 | BioSteel Shopify Lost Sales (renamed Stockout) | No | 85 |
| 8 | CDQ Listing Quality Report | No (Anthropic calls cost money) | 61 |
| 9 | ASP Tracker, Canadian Protein and BioSteel | Shopify bulk operations | 33 |
| 10 | Team Sponsorship and Team Sponsorship Stats | Shopify draft orders, invoice emails, Klaviyo | 58 |
| 11 | VC and SC Listings Editors | Amazon listing submissions | 101 |
| 12 | VC Image Manager | Amazon image submissions | 24 |
| 13 | Combined Shipping Refund | Shopify refunds (money), Klaviyo | 95 |
| 14 | Cutover rehearsal: final sync, comparisons, gate | No | – |

About 694 of the 1,296 old tests carry over. The rest cover tools rebuilt in later phases, or the Zendesk sidebar, which is dropped. Counts come from reading the test files and are approximate until the tests run in the new repo.

Each step is one pull request. Each tool's pull request contains:
- its tests, carried over first
- the ported code
- its registration migration
- its idempotent `legacy:import-<tool>` command
- its `compare:<tool>` command
- a `docs/context.md` entry

## 2. Rules for every port

1. **Tests first.** Copy the tool's tests, see them fail, port the code, and make them pass unchanged. A test is changed only where the tool's name, URL or permission changed on purpose (Lost Sales), or where it asserted a bug that is now fixed (rule 2).
   Ported code keeps its old class names and namespaces, so its tests carry over unchanged. The domain folders in spec 6.2 start with new Phase 1 code.
2. **Behaviour stays identical, except for bugs.** Bugs are fixed as they are found (decision 2026-11-25). Each fix gets a test that fails on the old behaviour first, is listed in `docs/found-issues.md`, and is called out in its pull request. Where the right behaviour is not clear from the code or README, the fix waits for Lesley's answer. Everything else behaves as the old app does.
3. **External writes are guarded.** Every call that writes to Shopify, Klaviyo or Amazon goes through one check of `TOOLS_EXTERNAL_WRITES`. While it is false, the action runs its existing preview path and returns a clear "writes disabled" result. A test proves no write request is sent while the flag is off.
4. **Imports are idempotent.** Each import upserts on a natural key, or preserves IDs where other rows point at them. It reads in chunks of 1,000 to 5,000 rows, pauses between chunks, and prints row counts in and out.
5. **Comparisons print no data.** `compare:<tool>` runs the same query or report in both apps for a fixed past period. It prints row counts, totals and a hash per section, never customer values.
6. **Scheduled tasks** keep their old command, cron and timezone. Imports copy each task's enabled flag from the old app, matched by command. The exception is `asp:backfill`, which stays disabled until cutover.

## 3. The platform (step 3)

Ported in this order, from the platform inventory:

| Slice | Contents | Old tests |
| --- | --- | --- |
| 3a | Page shell on design tokens: layouts, navigation, theme switch with the `theme-changed` event, font size, the 500 page, shared Blade components (spec section 15), a shared chart-theme helper | 1 |
| 3b | Users, Google sign-in (the only way in, decision 2026-11-27), profile, roles `admin` and `user` (seeded by migration this time, not by a manual seeder), Spatie permissions, `Gate::before` for admins, the user part of `legacy:import-platform` | 26 (password tests replaced, Google tests added) |
| 3c | Activity log (365-day retention as today), the `auth` log lines, the activity-log import | 3 (`AccountDeactivationTest`). The 4 `ActivityLogTest` cases each need a later slice and move with it: Tools (3d), deactivate and the viewer (3e), invitations (3g) |
| 3d | Tools registry, the home launcher, Admin > Tools with drag ordering, the self-registration convention | 16 (`ToolManagementTest` 10, `ToolPermissionGatingTest` 5, the tool case of `ActivityLogTest`) |
| 3e | Admin > Users, Teams and Activity. Inviting users (create, store, resend) moves to 3g with mail | 10 (`UserManagementTest` 5, `TeamManagementTest` 3, two `ActivityLogTest` cases). `AccountDeactivationTest` came in 3c |
| 3f | Admin > Google Sign-In (the domain list). Google sign-in itself moved to 3b | 8 (`GoogleDomainTest`; its empty-state case changed on purpose after found issue 28) |
| 3g | Gmail transport, Admin > Mail settings, invitations (no password link, decision 2026-11-27) | 12 (`UserInvitationTest` 11 and the invitation case of `ActivityLogTest`). The password-link cases changed or were removed, see the context entry |
| 3h | Database scheduler, run history, "Run Now", failure emails, heartbeat, the single cron entry | 16 (`ScheduleManagementTest` 6, `ScheduleExecuteRunCommandTest` 3, `BackgroundProcessLauncherTest` 4, `ScheduledTaskRegistrationTest` 2, `SchedulerHeartbeatRunHistoryTest` 1) |

Routes, names and permissions stay as listed in the platform inventory (`/admin/*` behind `role:admin`, `/dashboard`, `/auth/google/*`, no registration route).

**Shell and design.** Pages are built with tokens and components first. The Claude Design artifact "Coachwood Tools Redesign" is applied to the page shell in 3a, so tools ported later land in the new layout.

**Platform import** (`legacy:import-platform`):

| Table | Matching rule |
| --- | --- |
| `users` | IDs preserved, because tool tables reference them |
| `roles`, `permissions` | Matched by name |
| User role and permission grants | Re-mapped to the new IDs |
| `tools` | Matched by slug. The order and active flags are copied from the old app onto tools already registered here; tools not ported yet are not created |
| `scheduled_tasks` | Matched by command, except `asp:backfill` (section 2, rule 6) |
| `teams`, `team_user` | IDs preserved |
| `google_workspace_domains` | Copied |
| `activity_log` | Matched on `legacy_id`, the old row's ID. Not IDs preserved: this app logs sign-ins from its first deploy, so its own rows would collide. Nothing points at an activity ID |

Not imported:
- `scheduled_task_runs`. Run history starts fresh, and the old rows stay readable in the old database.
- `gmail_connections`, `sessions`, `cache` and `jobs`. Gmail is reconnected in Admin > Mail.

## 4. The Amazon probe (step 2)

The probe goes in the new app, as decided. It needs only the ported `SpApiClient`, so it ships in the first deploy.

- **What it is:** `amazon:probe`, read-only. It tries 25 capabilities once each (11 reports, 9 Data Kiosk queries, 5 synchronous calls), plus their status polls. `php artisan amazon:probe --list` shows them.
- **How it runs:** it submits everything first, then polls together every 30 seconds, for up to 45 minutes in total. It never retries in a loop.
- **What it covers:** the spec section 8 table, plus:
  - both vendor codes, Z3091 and GQ8J9
  - offer lookups on Amazon.ca with the seller token (decision D8)
- **Data Kiosk fields:** these are tested one query per field group. Amazon names every rejected field in one validation error, so a rejected query is resubmitted once without those fields, and the rest still get tested.
- **Results:**
  - The `amazon_capabilities` table in the new database.
  - `storage/app/private/probe/summary.json`, which is what Lesley sends back. It holds pass or fail, HTTP and error codes, fields requested and returned, empty-value rate, row count, and field type and range. It holds no ASINs and no amounts.
  - Raw responses in `storage/app/private/probe/raw/`, which is ignored by git. They are deleted after Phase 1.
- **When it runs:** Lesley runs it once, at an hour when the old app's SP-API tasks are quiet.

## 5. The ten tools

R = reads, W = writes. "Sync at cutover" names what the final sync must bring across (section 6).

### 5.1 BioSteel App Stats

| Item | Detail |
| --- | --- |
| Route and permission | `/tools/team-app-stats` and 6 JSON endpoints. `tool.team-app-stats` |
| Tables | `team_app_snapshots`, `team_app_category_snapshots`, `team_app_ad_snapshots`, two social snapshot tables (hold app users' emails and phones), two backup tables |
| Scheduled | `team-app-stats:snapshot` hourly, `:collect-ads` 6/12/18 UTC, `:snapshot-social` 23:55 Toronto |
| External | R: BioSteel Teams API, GA4 (live on page load, never stored), Ad Manager report run. No writes |
| Shared code | `BioSteelTeamsClient`, which needs the `Order` model from Team Sponsorship. Both are ported here |
| Import | Upsert on `captured_at`, on `date`, and on (`captured_at`, period, rank). Category rows are re-mapped to the new snapshot IDs |
| Sync at cutover | Yes, all snapshot tables |

### 5.2 Walmart US Listing Auditor

| Item | Detail |
| --- | --- |
| Route and permission | `/tools/walmart-us-listing-auditor`, `/{sku}`. `tool.walmart-us-listing-auditor` |
| Tables | 6 `walmart_us_*` tables |
| Scheduled | `walmart-us:listing-audit` at 06:00 UTC |
| External | R: Walmart Marketplace items, insights and the listing-quality report (creating the report changes no listing). No writes |
| Import | Upsert on SKU and audit date keys |
| Sync at cutover | Yes |

### 5.3 US Product Reviews

| Item | Detail |
| --- | --- |
| Route and permission | `/tools/us-product-reviews`. `tool.us-product-reviews` |
| Tables | 6 `feedback_*` tables. Reads `products` and `sc_products` |
| Scheduled | `feedback:collect` Sunday 01:00, `feedback:collect-trends` Sunday 02:00 |
| External | R: SP-API Customer Feedback for the US marketplace with the seller token, 1 request per second. No writes |
| Import | IDs preserved, as the old import did. Must run before the collectors first run, to avoid ID clashes |
| Sync at cutover | Yes |

### 5.4 BioSteel Shopify Lost Sales (renamed from Stockout)

| Item | Detail |
| --- | --- |
| Route and permission | New: `/tools/biosteel-shopify-lost-sales`, `tool.biosteel-shopify-lost-sales`. `/tools/stockout` redirects. Webhook `/webhooks/shopify/inventory-levels-update` unchanged |
| Rename scope | Only the name, URL, route names, permission and view folder change. Internal names stay: `config/stockout.php`, `stockout:*` commands, `STOCKOUT_*` env keys. Existing user grants move to the new permission on import |
| Tables | `inventory_snapshots` (history **not** imported, per spec 14.1), `inventory_item_variants`, `inventory_history_daily`, `sales_history_daily`, `variant_availability_intervals`, `lost_sales_estimates` |
| Scheduled | `stockout:snapshot-inventory` every 15 min, `stockout:prune-snapshots` hourly. Enabled flags copied from the old app, so the prune runs wherever the sweep runs |
| External | R: Shopify GraphQL and ShopifyQL on its own custom app. W: `webhookSubscriptionCreate`, run by hand. Not run in the new app until cutover |
| Import | Upsert the daily history tables on (product title, day). Copy intervals and estimates. Do not copy snapshot history |
| Sync at cutover | Daily history and estimates |

### 5.5 CDQ Listing Quality Report

| Item | Detail |
| --- | --- |
| Route and permission | `/tools/cdq-report`, `/export.csv`, `/{asin}`. `tool.cdq-report` |
| Tables | 9 `cdq_*` tables. Reads `products` and `vc_traffic_current` |
| Scheduled | `cdq:sync-schemas`, `:snapshot`, `:score`, `:consistency`, Sundays 04:00 to 05:15 UTC |
| External | R: SP-API (vendor). Anthropic API on `cdq:consistency`, cached per content hash. No writes. Shadow runs double the Anthropic spend until cutover |
| Import | All `cdq_*` tables, IDs preserved. The config versions v1 to v4 are data in production, not seeds |
| Sync at cutover | Yes |

### 5.6 ASP Tracker (Canadian Protein and BioSteel)

| Item | Detail |
| --- | --- |
| Routes and permissions | `/tools/asp-tracker`, `/tools/asp-tracker-bs`, each with `/drilldown` and `/export.csv`. `tool.asp-tracker`, `tool.asp-tracker-bs`. Webhooks `/webhooks/shopify/asp/cp` and `/bs` |
| Tables | `asp_sales_lines` (1.43M rows), `asp_line_discounts`, `asp_pending_orders`, `asp_ingest_log`, `asp_daily_sales`, `asp_daily_discounts` |
| Scheduled | Per store: `asp:process-pending` every minute, `asp:catch-up` daily, `asp:rebuild-rollups` daily, `asp:backfill` every 10 min (**disabled until cutover**) |
| External | R: Shopify GraphQL orders. W: `bulkOperationRunQuery` (one at a time per store) and `webhookSubscriptionCreate`, run by hand. Neither runs in the new app before cutover |
| Shared code | `CombinedShipping\ShopifyGraphQlClient` and the brands config, ported here |
| Import | Upsert lines on `line_item_id`, discounts by order, pending on (store, order). `asp_ingest_log` copied (without it the scheduler restarts a full backfill). Rollups rebuilt, not copied. Chunked: this is the largest import |
| Sync at cutover | Yes |

### 5.7 Team Sponsorship and Team Sponsorship Stats

| Item | Detail |
| --- | --- |
| Routes and permissions | `/tools/team-sponsorship` (index, search, orders), `tool.team-sponsorship`. `/tools/team-sponsorship/stats` and `/export`, `tool.team-sponsorship-stats` |
| Tables | `orders` and `order_items` (coach emails), `team_sponsorship_activity` (emails) |
| Scheduled | `team-sponsorship:refresh-activity` at 01:00 UTC |
| External | R: BioSteel Teams API, Shopify REST. W: Shopify draft order and invoice email, Klaviyo "Claimed Sponsorship Package". All user-triggered, all behind the writes flag |
| Import | Orders on `shopify_order_id`, items by parent order, activity on (email, team name) |
| Sync at cutover | Yes |

### 5.8 VC and SC Listings Editors

| Item | Detail |
| --- | --- |
| Routes and permissions | `/tools/vc-listings` (index, submit, audit run/batch/config, export), `tool.vc-listings`. `/tools/sc-listings` (adds sync start/poll), `tool.sc-listings` |
| Tables | VC: `listing_edits`, `listing_audit`, `listing_audit_config`, `listing_audit_attribute_catalog`. SC: `sc_products` and the four `sc_listing_*` tables. Reads `products` and CDQ scores |
| Scheduled | `listings:audit-run` at 08:00 UTC (4 SP-API reads per product) |
| External | R: Listings Items, Catalog, A+, restrictions, schemas. W: listing PATCH after `VALIDATION_PREVIEW`, user-triggered. With writes off, preview still runs and the real PATCH is skipped |
| Import | All tables, IDs preserved (`listing_edits` is shared with the Image Manager) |
| Sync at cutover | Yes, especially `listing_edits` |

### 5.9 VC Image Manager

| Item | Detail |
| --- | --- |
| Route and permission | `/tools/vc-image-manager` (index, upload, remove, audit run/batch). `tool.vc-image-manager` |
| Tables | `image_audits`. Writes `listing_edits` |
| External | W: file stored, then listing PATCH with a public image URL. User-triggered, behind the writes flag |
| Files | `storage/app/public/vc-image-manager/` exists only on the server. It is copied there by a command Lesley runs. Stored URLs (`https://tools.biosteel.com/storage/...`) are never rewritten, because the live-status check matches them as strings |
| Import | `image_audits`, IDs preserved |
| Sync at cutover | Yes, plus any new image files |

### 5.10 Combined Shipping Refund

| Item | Detail |
| --- | --- |
| Route and permission | `/tools/combined-shipping` (index, preview, show, confirm-refund). `tool.combined-shipping` |
| Tables | `combined_shipping_batches`, `_groups`, `_group_orders` (customer emails), `_refunds` |
| External | R: Shopify orders and suggested refunds, BS and CP. W: `refundCreate` (money) and Klaviyo events, user-triggered, behind the writes flag |
| Import | All four tables with **IDs preserved**. The refund idempotency key is built from the refund ID, and the duplicate check needs the full refund history |
| Sync at cutover | **Required.** Refunds made in the old app before cutover must be present, or the new app could refund the same order again |

### Shared tables the old app keeps owning in Phase 0

- **`products` and `sc_products`.** These are read by four ported tools. They are maintained today by the Price Monitor and SC sync, which are rebuilt in later phases. They are imported read-only and refreshed by the sync command. Product editing stays in the old app until Phase 1 builds the catalog.
- **`vc_traffic_current`.** CDQ reads it for glance-view weights. It is imported as is, until Phase 1 replaces it with weekly metrics.

## 6. Shadow running, sync and cutover

- **Before cutover.** The new app runs its scheduled pulls beside the old app, as agreed. No Shopify webhooks point at it (decision 2026-11-25), so stock-out snapshots come only from the 15-minute sweep, and ASP orders arrive through the daily catch-up. That is enough to prove the collectors work.
- **Sync.** `legacy:sync` re-runs every tool's import, in the step order. It is safe to run any number of times.
  - **The old app wins.** For tables both apps write (App Stats snapshots, ASP, Lost Sales history, CDQ, reviews, Walmart), the final sync replaces the new app's shadow rows with the old app's rows, in batched deletes. Shadow data exists only to prove the collectors work. This avoids duplicate chart points from two collectors.
  - **More than the two Team tools need syncing.** Combined Shipping refunds, listing edits and image edits made in the old app also need it, which is why every tool has a "sync at cutover" row.
- **Cutover, proposed as one evening.**
  1. Put the old app in maintenance mode, and disable its scheduled tasks.
  2. Run `legacy:sync`.
  3. Set `TOOLS_EXTERNAL_WRITES=true` and enable `asp:backfill` in the new app.
  4. Point `tools.biosteel.com` at the new app as a domain alias.
  5. Register the Shopify webhooks at `tools.coachwoodgroup.com`, check that deliveries arrive, then remove the old subscriptions.
  6. Open access to staff.

  Making the old hostname an alias means three things keep working with nothing to re-register:
  - the Shopify webhooks, which point at `tools.biosteel.com/webhooks/...`
  - the image URLs already given to Amazon, at `tools.biosteel.com/storage/...`
  - old bookmarks

  Rollback is reversing step 4 and taking the old app out of maintenance.

## 7. Phase 0 gate

1. Every carried-over test passes on SQLite and MariaDB, with none skipped.
2. Each `compare:<tool>` shows the same counts and totals as the old app for the same past period. Differences are explained in `docs/context.md`.
3. Each write path is shown, by test and by one preview run on the server, to send nothing while writes are off.
4. Probe results are recorded in `docs/context.md`, and the summary is reviewed with Lesley.
5. A full `legacy:sync` has been rehearsed on the server, with its run time recorded.
6. Lesley signs off.

## 8. Answers from Lesley (2026-11-25)

1. **Cutover through the old hostname.** Yes. `tools.biosteel.com` becomes an alias of the new app at cutover.
2. **Webhooks.** None during shadow running. At cutover, sync the database first, then turn on the new app's webhooks.
3. **Final sync scope.** Every ported tool, with the old app's data winning.
4. **Found issues.** They are bugs, and are fixed as they are found (section 2, rule 2).
5. **Shadow Anthropic spend.** Not a concern. `cdq:consistency` runs in both apps until cutover.
6. **Product editing.** Products stay edited in the old app until Phase 1, and are synced across.

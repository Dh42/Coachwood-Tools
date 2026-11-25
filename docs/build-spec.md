# Coachwood Tools Rebuild: Build Document for Claude Code

Nov 23, 2026 · @Lesley Paone

## 1. How to use this document

This is the build specification for rebuilding the Coachwood Group internal tools app from scratch. It is written for Claude Code, with Lesley as the decision owner.

You have three inputs: this document, the existing codebase (`CompanyTools-main`), and the existing production database on the same server. The existing code is the reference for behaviour. This document says what to keep, what to change, and what to add.

Read in this order:

1. Sections 2 to 5 for goals, open decisions, constraints and scope.
2. Appendix A and Appendix B before writing any Amazon code. A lists verified facts you must keep. B lists defects you must not copy.
3. Sections 6 to 12 for the design of each area.
4. Section 13 for the phase order and the gate each phase must pass.

Evidence labels used throughout:

| Label | Meaning |
| --- | --- |
| \[C\] | Verified by reading the existing code or its comments |
| \[D\] | Taken from Amazon or vendor documentation, not tested on this account |
| \[A\] | A design proposal or starting value. Make it a parameter, not a constant |
| \[OPEN\] | Waiting on a decision or input from Lesley. Use the stated default until answered |

One rule overrides the rest. Documentation is not proof for Amazon Canada. The account is Vendor Central on Amazon.ca, where fewer APIs and fields work than the documentation lists. Nothing new is built on an Amazon endpoint or field until the capability probe in section 8 has passed for it.

## 2. Goals and non-goals

The rebuild exists to fix two business problems on Amazon.ca Vendor Central, for about 200 BioSteel SKUs: knowing why Amazon dropped a price, and forecasting accurately enough to plan production 120 days ahead.

### Goals

1. **One trusted source of Amazon data.** Every tool reads the same validated, versioned fact tables. A failed or partial Amazon report can never appear as zero sales.
2. **Price intelligence, not just a price monitor.** Record every Amazon price change as an event, with the evidence for what it may have matched: other sellers on the listing, other retailers, a funded promotion, or Costco.
3. **A forecast that is measured.** Separate consumer demand, Amazon purchase orders and the supply export. Score each against real outcomes before it replaces the current method.
4. **Exceptions instead of dashboards.** Tell the team which products need attention today, why, and how much money is exposed.
5. **Carry forward what already works.** Tools that work as intended are ported with identical behaviour. Verified Amazon API knowledge is preserved as tests.
6. **Current stack.** PHP 8.4 and the latest Laravel 13 release, structured so the Laravel 14 upgrade is routine.

### Non-goals

- No machine-learning forecasting. Methods are simple statistical baselines that can be explained and backtested.
- No per-ASIN price elasticity. There are too few clean price changes per SKU to estimate it.
- No automated production commitments. The supply output is decision support for a person.
- No all-channel supply planner in this build. See decision D2.
- No workflow for pressuring retailers on price. Retailer price data is evidence only. This has legal implications in Canada and needs counsel before any such use.
- No behaviour changes to the tools listed as "port unchanged" in section 5.

### The three things to protect above all

If scope must be cut, these stay:

1. Data correctness: complete, signed, versioned Amazon facts with provenance.
2. Decision quality at the 120-day horizon: forecasts that have passed a backtest gate.
3. Cross-channel price risk: a reliable Costco-versus-Amazon comparison on comparable products.

## 3. Open decisions and their defaults

Nine decisions are still open. Build to the default in each row, keep the choice behind a config value or interface, and do not block on an answer unless the row says so.

| ID | Decision | Default to build | Blocks |
| --- | --- | --- | --- |
| D1 | How third-party retailer prices are collected | No automated collection from third-party retailer sites. Build the `RetailPriceProvider` interface, with three providers: manual entry and CSV import, the Costco table, and BioSteel's own Shopify store. Automated collectors are added per retailer only after Lesley confirms the method with counsel | Automated retailer collectors only |
| D2 | Scope of the supply plan | Amazon only. Export expected Amazon wholesale units by ASIN and month with a low and high range. No all-channel planner | Nothing |
| D3 | Exact wording of Costco's 20% member-value clause | Formula in section 10.6, comparator configurable and versioned. Show an "unconfirmed comparator" banner until Lesley supplies the clause | Turning the Costco exception on in production |
| D4 | Whether the non-Amazon tools and the listing tools change | Port unchanged. See section 5 | Nothing |
| D5 | Whether a persistent queue worker can run on the server | Database queue, drained by a scheduled `queue:work --stop-when-empty --max-time=50` every minute. Switch to a supervised worker if Lesley confirms it is possible | Nothing |
| D6 | Front-end stack under the new design | Blade, Vite and Chart.js as today. All colour, type and spacing through CSS variables so the Claude Design system drops in. See section 15 | Final styling only |
| D7 | New name for the Stockout Lost Sales Estimator | "Shopify Lost Sales (Stockouts)". Slug and permission name stay stable through a redirect | Nothing |
| D8 | Whether the dormant Seller Central Canada account can authorize an app | Not used. If Lesley confirms access, add one probe for offer lookups by ASIN | Nothing |
| D9 | Which vendor codes and ASINs are in scope | Every ASIN the configured SP-API vendor code returns. Lesley to confirm the expected count of about 200 | Catalog reconciliation sign-off |

When a default is replaced, record the decision and date in `docs/decisions.md` in the new repository.

## 4. Environment and constraints

The new app runs on the same server as the old one, beside it, with its own directory and its own database. That server is shared, and one bad query has already taken every site on it down.

### Stack

| Layer | Choice | Note |
| --- | --- | --- |
| Language | PHP 8.4 | Installed on the server, confirmed by Lesley. The old app requires 8.3 or higher |
| Framework | Laravel 13, latest release | Old app is on 13.26 \[C\]. Laravel 14 will require PHP 8.4, so this build is upgrade-ready \[D\] |
| Database | MariaDB | Production runs 10.11.14, confirmed by Lesley |
| Auth | Google OAuth through Socialite, plus password accounts for a few external users | Port unchanged |
| Permissions and audit | spatie/laravel-permission and spatie/laravel-activitylog | Port unchanged |
| Mail | Gmail API over OAuth, no SMTP | Port unchanged |
| Front end | Blade, Vite, Chart.js, Intro.js tours | See D6 and section 15 |
| Spreadsheets | PhpSpreadsheet | Known memory limits. See Appendix A |
| Tests | Whatever runner the old suite uses, carried over. The old suite has about 1,300 passing tests \[C\] | No live API calls in tests |

### Server constraints \[C\]

- **Shared disk and database.** The CS platform uses the same MariaDB and disk. On 2026-11-16 an unindexed query inside a Shopify webhook drove I/O wait to about 80% and timed out every site.
- **No queue worker in production today.** Long work runs as scheduled Artisan commands. See D5.
- **One crontab entry.** `* * * * * php artisan schedule:run`. All other schedules live in the database and are managed in Admin > Scheduler.
- **"Run Now" spawns a detached CLI process** from inside PHP-FPM. It needs the real CLI binary path in `SCHEDULER_PHP_CLI_BINARY`, because `PHP_BINARY` resolves to the FPM binary there.
- **Deploys go through the Plesk git pipeline** and run `migrate --force`. Live, rate-limited API pulls never run inside a migration.

### Rules that follow from the constraints

1. Every query on a table expected to pass one million rows needs an index that fits it. Check with `EXPLAIN` and include the plan in the pull request.
2. Every append-only table has a retention rule and a batched prune command from day one.
3. Webhook handlers do a primary-key read and one insert at most. Anything heavier is queued.
4. Bulk deletes and backfills run in small paced batches, never one statement.
5. Raw Amazon documents are stored gzip-compressed, with a disk budget and a retention period \[A: 24 months\].
6. While both apps run, only one of them may scrape Amazon.ca, and Amazon report requests are staggered. Both apps share one IP address and one set of SP-API credentials. See section 14.
7. Never run `migrate:fresh` or any destructive command against a database that is not a disposable local one. The old log records one accidental run \[C\].

## 5. Scope: what happens to each tool

Every existing tool falls into one of three groups: ported unchanged, rebuilt on the new data foundation, or replaced by a new tool. Eight tools are new.

### Port unchanged

"Unchanged" means identical behaviour, routes, permissions and data. Carry the existing tests over and make them pass. Code may be tidied to the new structure, but logic is not redesigned.

| Tool | Old route | Note |
| --- | --- | --- |
| Platform: login, roles, Admin (users, tools, teams, scheduler, activity log, mail, Google sign-in domains) | `/admin/*` | Admins remain superusers through `Gate::before` |
| BioSteel Team Sponsorship | `/tools/team-sponsorship` | Confirmed working as intended |
| Team Sponsorship Stats | `/tools/team-sponsorship/stats` | Confirmed working as intended |
| BioSteel App Stats | `/tools/team-app-stats` | Confirmed working as intended |
| Stockout Lost Sales Estimator | `/tools/stockout` | Rename only. See D7. It covers the BioSteel Shopify store, not Amazon |
| Combined Shipping Refund | `/tools/combined-shipping` | D4 default |
| ASP Tracker, Canadian Protein and BioSteel | `/tools/asp-tracker`, `/tools/asp-tracker-bs` | D4 default |
| Coachwood CS Sidebar (Zendesk app) | `routes/zendesk.php`, `zendesk-app/` | D4 default |
| Walmart US Listing Auditor | `/tools/walmart-us-listing-auditor` | D4 default |
| VC Listings Editor, SC Listings Editor | `/tools/vc-listings`, `/tools/sc-listings` | Amazon tools, but not part of the gaps |
| VC Image Manager | `/tools/vc-image-manager` | Uploaded image URLs must keep resolving. See section 14 |
| CDQ Listing Quality Report | `/tools/cdq-report` | Uses the Anthropic API for one check |
| US Product Reviews | `/tools/us-product-reviews` | US marketplace only. The API is blocked for Canada \[C\] |

### Rebuild on the new data foundation

These keep their purpose and their verified business rules. They stop calling Amazon themselves and read the shared fact tables instead. Defects in Appendix B are fixed.

| Old tool | Becomes | Main change |
| --- | --- | --- |
| Amazon.ca Price Monitor | Pricing Intelligence (section 10) | Adds other sellers' offers, retailer prices, price events, Costco guard, change-based alerts |
| PO Forecasting, Marketplace Forecast, Out-of-Stock / Stock Forecast | One Forecast tool with three layers (section 9) | Shared calculation, backtested, corrected simulator |
| PO Checker | PO Checker | Same Cin7 logic. Over-forecast warning reads the stored forecast and price events |
| PO Classification | PO Classification | Same rules. Review-queue POs stay "review", not algorithmic |
| PO Stats | PO Stats | Fill rate and lost revenue only where fulfilment is known |
| Net Realized Price | Net Realized Price | Reads weekly metrics table. Actual deductions shown beside modelled ones |
| Net PPM Monitor | Net PPM Monitor | Reads weekly metrics table. CRaP watchlist gains price-event and weight signals |
| Amazon ASP and Deal Funding | Deal Funding | Reads promo actuals when available |
| Geo Sales Intelligence | Geo Sales Intelligence | Keeps its own geographic tables |
| Amazon Sales Trends | Amazon Sales Trends | Reads daily facts and rollups |
| VC Birdseye | Control Centre (section 11) | Incident-first view. The per-product cards remain |
| VC Traffic, VC Replenishment (no pages) | Ingestion collectors | Folded into the pipeline |

### New

| New tool | Section | Phase |
| --- | --- | --- |
| Amazon capability probe and registry | 8 | 0 |
| Ingestion ledger and Data Reliability page | 6, 7 | 1 |
| Catalog Reconciliation | 12 | 1 |
| Pricing Intelligence: offers, retailers, price events, Costco guard | 10 | 2 |
| Forecast Accuracy Lab | 9 | 3 |
| Supply export for production planning | 9 | 3 |
| Exception engine and Control Centre | 11 | 4 |
| Deductions Tracker and promotion actuals | 12 | 4 |

### Deferred until after cutover

Amazon Ads data, family-level price elasticity, hourly Amazon data, competing-brand tracking, Brand Analytics search terms, automated collectors for smaller retailers, and an all-channel supply planner. Leave clean extension points and build none of them.

## 6. Architecture

Only the ingestion layer talks to Amazon. Every tool, forecast and alert reads validated tables that the ingestion layer publishes. In the old app, three services pull the same sales report separately and each handles failure differently \[C\].

### Layers

1. **Clients.** One SP-API client for the Reports API, Data Kiosk, Vendor Orders, Replenishment, Listings, Catalog and Finance Remittance. One client each for Cin7, Shopify, Walmart, Klaviyo and Zendesk. Port the existing client behaviour listed in Appendix A.
2. **Capability registry.** A table of what this Canadian vendor account can call, filled by the probe. A collector checks it before running and uses its fallback when a capability is absent.
3. **Ingestion pipeline.** Request, store the raw document, validate, measure coverage, publish, record revisions. Section 6.1.
4. **Fact tables.** Daily facts, weekly metrics, forecast vintages, PO lines, price and offer observations, remittance lines. Section 7.
5. **Derived data.** Price events, demand events, forecasts, forecast scores, incidents. Always rebuildable from facts and versioned parameters.
6. **Tools.** Controllers and views. They never call an external API during a page load, with the existing exceptions that are already live by design, such as the Cin7 stock check.

### 6.1 Ingestion pipeline

Every Amazon pull follows these steps, in a queued job:

1. Create an `amazon_report_requests` row with status `queued`.
2. Submit the request and save Amazon's identifier.
3. Download the full document. Store it compressed and unchanged, with its SHA-256.
4. Validate: parses, required fields present, currency is CAD, dates inside the requested window, grain as requested.
5. Record coverage per period and metric family in `amazon_ingestion_coverage`.
6. Compare with the previous pull of the same period. Unexplained large differences are quarantined, not published \[A: over 20% change in a period's total units\].
7. Publish the whole period for that source in one transaction.
8. Where a published value changes, append a row to `amazon_fact_revisions`.
9. Only then mark the period available to forecasts and exceptions.

Rules for the pipeline:

- **Missing, zero and not applicable are three different states.** A report that fails, times out or returns no document produces `missing`. It never produces zero.
- **A zero-row success needs a reason.** Accept it only when the period is plausibly empty. Otherwise quarantine.
- **An ASIN absent from a report is not a failure.** Inactive products are legitimately omitted.
- **Store two times for every value:** the period it describes, and when it was observed. Backtests depend on the second.
- **Recent periods are provisional.** Amazon restates recent days \[C\]. Provisional values may drive alerts. They are excluded from accuracy scoring.
- **Never split a weekly value into daily values.** Weekly metrics stay weekly.
- **Retries use the existing backoff helper.** A request that still fails raises a Data Reliability incident.

### 6.2 Code organization \[A\]

Group code by domain, not by tool: `Amazon/Ingestion`, `Amazon/Catalog`, `Pricing`, `Forecast`, `PurchaseOrders`, `Margin` (NRP, PPM, Deal Funding), `Exceptions`, `Deductions`, plus one namespace per ported tool. A tool's controller depends on domain services. Domain services do not depend on controllers.

### 6.3 Conventions carried over \[C\]

- **Versioned parameters.** Business thresholds live in tables with `effective_from`. A change inserts a new row. Past computations stay reproducible.
- **Self-registering tools and tasks.** A migration inserts the `tools`, `permissions` and `scheduled_tasks` rows. It must call `forgetCachedPermissions()` after a query-builder insert.
- **Read-only dashboards.** Viewing a page never triggers a scrape or an Amazon request.
- **Batched queries.** One query across all products, never one per ASIN.
- **Every tool has an Intro.js tutorial**, built with the `addStep` helper pattern described in the old README.

## 7. Data model

Amazon data arrives at different grains, so it is stored at those grains: daily facts, weekly metrics, forecast vintages, PO lines and timestamped price observations. Column lists below are the required minimum \[A\]. Ported tools keep their existing tables unchanged.

### 7.1 Ingestion ledger

| Table | Key columns | Purpose |
| --- | --- | --- |
| `amazon_capabilities` | `capability_key`, `endpoint_or_report`, `fields_json`, `status` (pass, fail, partial, untested), `http_status`, `error_code`, `raw_error`, `tested_at` | What this account can call. Filled by the probe |
| `amazon_report_requests` | `source_system`, `report_type`, `options_json`, `requested_from`, `requested_to`, `requested_grain`, `amazon_request_id`, `document_id`, `status`, `attempt`, `error_code`, `error_message`, `document_sha256`, `document_path`, `parser_version`, `row_count`, `submitted_at`, `completed_at` | One row per request to Amazon |
| `amazon_ingestion_coverage` | `request_id`, `metric_family`, `period_start`, `period_end`, `expected_grain`, `observed_asins`, `valid_row_count`, `invalid_row_count`, `status` (complete, provisional, partial, missing, quarantined), `validated_at` | The completeness gate that forecasts and alerts check |
| `amazon_fact_revisions` | `fact_table`, `fact_key_hash`, `field_name`, `old_value`, `new_value`, `previous_request_id`, `replacement_request_id`, `detected_at` | Field-level history of restatements |

Job run history stays in the existing `scheduled_task_runs` table. Do not add a second run log.

### 7.2 Amazon facts

| Table | Unique key | Columns | Replaces |
| --- | --- | --- | --- |
| `amazon_asin_daily_facts` | `marketplace_id`, `vendor_code`, `asin`, `fact_date`, `selling_program`, `distributor_view` | `ordered_units`, `ordered_revenue`, `shipped_units`, `shipped_revenue`, `shipped_cogs`, `customer_returns` (all nullable, units signed), `sellable_on_hand_units`, `open_po_units`, `aged_90_day_units` and other inventory fields that pass the probe, `inventory_observed_for_date`, `sales_request_id`, `inventory_request_id`, `sales_quality`, `inventory_quality`, `sales_observed_at`, `inventory_observed_at` | `amazon_vendor_sales`, plus the daily pulls that were never stored |
| `amazon_asin_weekly_metrics` | `marketplace_id`, `vendor_code`, `asin`, `week_start` | `glance_views`, `lost_featured_offer_pct`, `sourceable_roos_pct`, `sell_through_rate_pct`, `asp`, `net_ppm`, `shipped_units`, `shipped_revenue`, `shipped_cogs`, `contra_cogs`, `contra_cogs_per_unit`, `sales_discount`, `average_sales_discount`, `active_subscriptions`, `subscription_units`, `subscription_revenue`, `subscription_oos_lost_revenue`, one request id and quality status per field family | `vc_traffic_*`, `nrp_cost_*`, `vc_replenishment_*` |
| `amazon_forecast_vintages` | `generation_date`, `asin`, `target_week_start`, `selling_program`, `revision_number` | `target_week_end`, `mean_units`, `p70_units`, `p80_units`, `p90_units`, `request_id`, `pulled_at`, `payload_hash` | `mkt_amazon_forecast_snapshots` |
| `amazon_po_lines` | Append-only snapshots with a latest-state view, as today | Same columns as `vc_po_lines`, but `accepted_quantity`, `asn_quantity`, `received_quantity` and `cancelled_quantity` are nullable | `vc_po_lines` |

Notes:

- Keep signed units. Amazon has returned a negative net ordered-unit value for a real period \[C\].
- Store `distributor_view` but pull only `MANUFACTURING`. It is a strict superset of `SOURCING` on this account \[C\].
- Weekly and monthly sales views are rollups of daily facts, reconciled against Amazon's own weekly report where both exist.
- Geographic sales keep their own tables (`geo_sales_*`). Do not flatten geography into ASIN facts.
- Do not sum point-in-time counts such as `active_subscriptions` across weeks.

### 7.3 Catalog and product identity

| Table | Columns | Purpose |
| --- | --- | --- |
| `products` (extended) | Existing columns plus `lifecycle_status`, `first_seen_at`, `last_seen_at`, `ship_weight_g` | Every ASIN the account returns gets a row, with or without a vendor SKU |
| `product_families` | Reuse the existing `product_groups` and `product_group_members` | Family is the pooling level for seasonality and promotions |
| `product_unit_specs` | `product_id`, `family_id`, `form` (tub, stick pack, RTD), `flavour`, `gtin`, `unit_basis` (serving, ml, gram), `units_per_pack`, `total_servings`, `total_volume_ml`, `net_weight_g`, `valid_from`, `valid_to` | Makes per-unit price comparison possible across pack sizes |
| `retailer_listings` | `retailer`, `channel` (online, warehouse, store), `retailer_product_id`, `url`, `title`, unit spec columns as above | A product as sold by a retailer |
| `retailer_product_matches` | `product_id`, `retailer_listing_id`, `equivalence_type` (exact, equivalent\_pack, related), `match_status` (verified, probable, needs\_review), `verified_by`, `valid_from`, `valid_to` | Which listings may be compared, and how |

### 7.4 Pricing

| Table | Columns | Replaces |
| --- | --- | --- |
| `price_observations` | `product_id`, `province_code`, `scan_run_id`, `observed_at`, `price`, `page_list_price`, `seller`, `status` (ok, no\_offer, unavailable, fetch\_failed, parse\_failed, blocked), `delivery_text`, `delivery_days`, `coupon_text`, `deal_badge`, `sns_price`, `availability_text` | `price_checks` |
| `offer_observations` | `product_id`, `province_code`, `observed_at`, `seller_name`, `seller_id`, `price`, `shipping`, `fulfilment`, `is_featured_offer` | New |
| `retailer_price_observations` | `retailer_listing_id`, `observed_at`, `region`, `regular_price`, `promo_price`, `promo_start`, `promo_end`, `provider`, `evidence_type` (api, feed, manual, import), `evidence_url`, `entered_by` | New |
| `price_events` | `product_id`, `province_scope`, `last_old_observed_at`, `first_new_observed_at`, `ended_at`, `old_price`, `new_price`, `depth_pct`, `vs_30d_median_pct`, `classification`, `candidates_json`, `algorithm_version`, `status` | New |

`scan_runs`, `scan_queue`, `provinces` and `province_cookies` are kept.

### 7.5 Forecast

| Table | Columns | Replaces |
| --- | --- | --- |
| `demand_events` | `product_id` or `family_id`, `event_type` (deal, coupon, price\_event, prime\_day, stockout, other), `start_date`, `end_date`, `source`, `expected_multiplier`, `note` | Manual adjustment reason codes, Deal Funding event dates |
| `forecast_runs` | `layer` (demand, po, supply), `model_key`, `origin_date`, `training_cutoff`, `params_version_id`, `source_versions_json`, `code_version`, `is_shadow`, `created_at` | `po_forecast_runs`, `mkt_forecast_runs` |
| `forecast_results` | `run_id`, `asin`, `target_period_start`, `target_period_end`, `horizon_weeks`, `mean_units`, `p10_units`, `p50_units`, `p80_units`, `p90_units`, `components_json` (algorithmic, bulk, new\_product, total), `status` (ok, insufficient\_history, source\_incomplete, suppressed) | `po_forecast_results`, `mkt_forecast_results`, `stock_forecast` |
| `forecast_scores` | `run_id`, `horizon_weeks`, `scope` (asin, family, portfolio, top80), `metric`, `value`, `actual_observed_at` | New |

Keep and migrate with full history: `po_coverage_targets`, `po_bulk_buy_scenarios`, `po_velocity_modifiers`, `po_month_overrides`, `po_holiday_calendar`, `po_line_classification`, `po_overrides`, `mkt_forecast_adjustments`, `mkt_new_product_ramps`, `mkt_eol_candidates`, `product_cost_changes`, and every versioned params table. Legacy forecast runs and results are imported read-only so old forecasts can still be scored.

### 7.6 Exceptions and deductions

| Table | Columns |
| --- | --- |
| `incidents` | `product_id`, `family_id`, `window_start`, `window_end`, `primary_type`, `status` (open, acknowledged, resolved), `owner_id`, `realized_loss`, `weighted_exposure`, `gross_at_risk`, `rank_score`, `rule_version`, `opened_at`, `resolved_at` |
| `incident_signals` | `incident_id`, `signal_type`, `detected_at`, `evidence_json`, `source_table`, `source_id` |
| `remittances`, `remittance_lines` | Header and line fields as returned by the Finance Remittance API or the Vendor Central export, plus `category` (payment, shortage, chargeback, co-op, return, advertising, other), `invoice_ref`, `po_number`, `amount`, `dispute_status` |
| `promotion_performance`, `coupon_performance` | Fields as returned by the two reports, keyed by promotion or coupon id and ASIN |

## 8. Phase 0: Amazon Canada capability probe

The first deliverable is a command, `amazon:probe`, that calls each untested Amazon endpoint once against the real account and records what happened. It can be written against the old codebase first, since the credentials and client already exist there.

The account has already contradicted the documentation twice. The Customer Feedback API is blocked for Canada. The Data Kiosk field `procurableProductOOS` is documented, and the live schema rejected it \[C\].

### What the probe does

1. For each capability below, make the smallest real request possible.
2. Record status, HTTP code, Amazon's error code and the raw error in `amazon_capabilities`.
3. For Data Kiosk and reports, test each wanted field, not only the endpoint. Record which fields returned data.
4. Save one sample response per capability as a test fixture, with any confidential values left intact but the file kept out of version control.
5. Print a pass and fail summary. Never retry in a loop, and respect rate limits.

### How it runs

The probe runs on the server, as an Artisan command in the old app, using the SP-API credentials already in that app's `.env`. Lesley starts it. No credential leaves the server, and the output contains none.

Every call is a read. The probe creates report and query requests and downloads their results. It submits nothing to listings, orders, invoices or shipments. About 20 requests in total, made once.

A new credential is needed only if a capability needs a role the app does not have. The Finance Remittance API needs the Finance and Accounting role \[D\]. Adding a role means re-authorizing the app, which issues a new refresh token.

### Already confirmed working in Canada \[C\]

`GET_VENDOR_SALES_REPORT`, `GET_VENDOR_INVENTORY_REPORT`, `GET_VENDOR_FORECASTING_REPORT`, Data Kiosk `analytics_vendorAnalytics_2024_09_30` at weekly grain for sales, costs, net PPM, traffic, availability and geography, Vendor Orders `getPurchaseOrders`, Replenishment `listOfferMetrics`, Listings Items, Catalog Items, A+ Content, Product Type Definitions and Listings Restrictions.

### To probe

| Capability | Wanted for | Fallback if it fails |
| --- | --- | --- |
| Inventory report extra fields: open PO units, aged 90-day units, unhealthy units, vendor confirmation rate, receive fill rate, average vendor lead time, unfilled ordered units | PO simulator, markdown cause, fill-rate signal | Open PO units computed from `amazon_po_lines`. Lead time stays a parameter |
| Data Kiosk at daily grain | Daily facts with ASP, finer price-change timing | Daily sales report for units and revenue. Weekly Data Kiosk for the rest |
| Data Kiosk open PO quantity, in-transit inventory, forecast fields | Cross-check of position and vintages | Reports API, already working |
| `sourceableROOS` value scale | Stock-out correction | Confirm whether it returns a 0 to 1 fraction or a percentage. The old code assumes a fraction and says it was never verified \[C\] |
| Finance Remittance API `getRemittanceHeaders`, `getRemittance` | Deductions Tracker | Upload of the Vendor Central remittance export |
| `GET_PROMOTION_PERFORMANCE_REPORT`, `GET_COUPON_PERFORMANCE_REPORT` | Promotion actuals, demand events | Hand-logged Deal Funding asks, as today |
| `GET_VENDOR_REAL_TIME_SALES_REPORT` and its inventory and traffic siblings | Deferred hourly signals | None needed. Deferred |
| `GET_VENDOR_TRAFFIC_REPORT`, `GET_VENDOR_NET_PURE_PRODUCT_MARGIN_REPORT` | Alternative source if Data Kiosk fields fail | Data Kiosk, already working |
| Brand Analytics search terms and market basket reports | Deferred competing-brand visibility | None needed. Deferred |
| Deeper history: Data Kiosk weekly back to 2023 | Seasonality needs more than 23 months | Use what exists. See section 9.3 |

Seller-only APIs are out of scope for Canada: Product Pricing, the `ANY_OFFER_CHANGED` and `PRICING_HEALTH` notifications, the Finances API and Search Query Performance \[D\]. Do not build on them.

Required app roles to confirm first \[OPEN\]: Brand Analytics, and Finance and Accounting for remittances \[D\].

## 9. Forecasting

The forecast is three linked layers: what customers will buy, what Amazon will order from BioSteel, and what BioSteel should expect to supply. Today all three rest on one flat trailing average with no seasonality, no stock-out correction and no accuracy measurement \[C\].

A new model replaces the current one only after it passes the gates in 9.7. Until then the current method keeps running and the new one runs in shadow.

### 9.1 Layer 1: consumer demand

**Target.** Finalized net ordered units per ASIN per week, Sunday to Saturday. Shipped units are kept for reconciliation. PO quantities are never the demand target.

**Step 1: mark weeks that are not normal.** A week is excluded from the baseline when a `demand_events` row covers it: a funded deal, a coupon, or a price event deeper than a threshold \[A: 8%\]. This step matters most, because Amazon-initiated price drops inflate the baseline and trigger POs BioSteel cannot fill.

**Step 2: correct for stock-outs.** With availability fraction a and floor f \[A: 0.5\]:

```latex
y^{*}_{i,t} = \frac{\max(0,\; y_{i,t})}{\max(a_{i,t},\; f)}
```

- Where availability is below a minimum \[A: 0.4\] or unknown, do not divide. Impute from the nearest valid weeks, scaled by family seasonality, and mark the week low-confidence.
- Availability source for history is the weekly out-of-stock rate, only after its scale is verified (section 8). Going forward, use the price monitor's own availability observations. Store which source was used.
- Do not assume the out-of-stock rate equals 1 minus availability. Validate it against the scraper over the period where both exist.
- An ASIN with fewer than a minimum number of usable in-stock weeks \[A: 8\] falls back toward its family.

**Step 3: baseline candidates.** Implement all of these behind one interface, each a named `model_key`:

| Key | Method |
| --- | --- |
| `flat90` | Trailing 90-day average, as the old code computes it. The incumbent and the benchmark |
| `b13` | Mean of the last 13 cleaned weeks |
| `e13` | Exponentially weighted 13-week mean, decay 0.90 |
| `damped` | Damped-trend exponential smoothing, alpha chosen in 0.15 to 0.30 by backtest, phi 0.85 |
| `seasonal_naive` | Same weeks last year, scaled by recent level |
| `amazon_mean` | Amazon's own mean forecast from the latest vintage |
| `blend` | Weighted mix of an internal model and `amazon_mean`. Start at 0.3 on Amazon, labelled experimental |

Missing weeks are never counted as zero weeks in any average.

**Step 4: family seasonality.** Estimate at product-family level, never per ASIN. Use 12 monthly or 13 four-week blocks, not 52 weekly factors. Shrink toward 1:

```latex
S_{f,m} = \exp\left(\lambda \,\log S^{raw}_{f,m}\right), \quad \lambda \le 0.5
```

Normalize so the factors average 1. Apply as the ratio of the target period's factor to the baseline period's factor. The existing geographic seasonality index is a province-level display figure. Do not reuse it here.

**Step 5: known events.** Multiply by an event factor for future weeks that have a planned `demand_events` row. Use the manual value when given. Otherwise use the family's measured past lift, shrunk toward 1. Large events need a person to confirm.

**Step 6: price.** No fitted elasticity. A family-level elasticity may exist as a scenario parameter, default off.

**Step 7: trend cap.** Any trend multiplier is capped \[A: 0.8 to 1.2\].

**Every active ASIN gets a result row.** With under 12 weeks of history, use an assigned ramp profile if one exists. Otherwise write status `insufficient_history`. Never drop the ASIN silently.

**Ranges.** Until Amazon's percentiles have enough vintage history to be checked, compute low and high from the model's own backtest residuals, by family and horizon. Store Amazon's P70, P80 and P90 beside them. The old fixed band of plus or minus 25% is removed.

### 9.2 What the data cannot support

| Cannot be estimated reliably | Reason |
| --- | --- |
| Per-ASIN price elasticity | Too few independent price changes, and Amazon sets price in response to the market |
| Per-ASIN yearly seasonality | Under two full seasonal cycles |
| Calibration of Amazon's percentiles at 17 weeks | Too few completed vintages. Confirm the earliest vintage date on production |
| Substitution between flavours and sizes | Too few clean events |
| Error at 17 weeks with statistical confidence | About five non-overlapping 17-week blocks in the history |

Two things can extend the data and should be tried in Phase 1: a deeper Data Kiosk backfill before January 2025, and the BioSteel Shopify sales history, which reaches back to September 2023, as a prior for family seasonal shape \[A\].

### 9.3 Layer 2: Amazon purchase orders

Port the existing simulator's verified rules, and fix its defects.

Keep \[C\]:

- Group same-day PO lines into one reorder event per ASIN before any averaging.
- Respect manual PO overrides when deciding which events are algorithmic.
- Evaluate a reorder only on the replenishment weekday, with holiday shifting. Never daily.
- Bulk buys are entered manually as scenarios and layered on. They are not forecast statistically.
- Coverage target precedence: override, then inferred, then default. Inferred values outside 0.25 to 12 weeks are discarded.

Change:

1. **Inventory position.** Start from on-hand plus open PO units. The old code starts from on-hand only \[C\].
2. **Pipeline.** A predicted order joins the pipeline and counts toward position at once. It reaches on-hand only after a lead time \[A: per-ASIN parameter, seeded from Amazon's reported vendor lead time if the probe passes\].
3. **Suppression per reorder date.** Check the overstock rule at each reorder date. The old code checks once at the start and can return zero orders for nine months \[C\].
4. **Demand input.** Use Layer 1's forecast for each future week, not a flat rate.
5. **Order size.** Target coverage weeks times forecast weekly demand, minus position, floored at zero, rounded up to the case pack from `product_case_pack_history`.
6. **One calculation.** Algorithmic, bulk, new-product and total are components of one result. Every page and export reads the same result.
7. **Months.** A week that crosses a month end is split by day.
8. **Completeness gate.** If the sales or inventory source for the run is not complete, write `source_incomplete` and no numbers.
9. **Lag.** The velocity window ends at today minus the reporting lag of 3 days. The old window includes unreported days and understates velocity by about 3% \[C\].
10. **Low-margin suppression** is a hypothesis, not a rule. Test it with the existing PPM-versus-PO correlation before adding it.

### 9.4 Stock risk

Replace the old Stock Forecast with a walk-forward on the same engine: deplete Amazon's on-hand by forecast demand, credit confirmed open POs on their expected arrival, and report the first day the balance goes negative.

Keep \[C\]: drop open POs whose estimated arrival is already in the past. About a quarter of open POs were stale, and counting them caused wrong "ok" verdicts. The arrival estimate of ship-window end plus 35 days becomes a parameter.

### 9.5 Layer 3: supply export

Export expected Amazon wholesale units per ASIN per month, with mean, low and high, for at least 26 weeks ahead. That covers the 120-day lead time plus a review period and post-receipt cover. Formats: CSV and XLSX.

The export states its model, run date and data completeness on the first row. It is decision support. It commits nothing automatically. See D2.

### 9.6 Backtest harness

1. Origins are weekly, each Sunday, after the first 52 weeks of history.
2. At each origin, fit using only values observed on or before that date.
3. Forecast totals for 4, 8, 13 and 17 weeks ahead. Score 26 weeks as a diagnostic only.
4. Score only horizons whose actuals are complete and final.
5. Where historical versions of the data were not saved, label the result "retrospective reconstruction", not a true point-in-time test.
6. Amazon's forecast can only be scored from its first stored vintage onward.

Metrics, with F forecast and A actual over the same horizon:

```latex
\mathrm{WAPE} = \frac{\sum |F - A|}{\sum |A|}, \qquad \mathrm{Bias} = \frac{\sum (F - A)}{\sum |A|}
```

Also report under-forecast-only error, results for the ASINs making up the top 80% of revenue, and results by family. For POs, score algorithmic unit WAPE, PO count error, and the share of reorder events predicted within 7 days, compared against a naive "orders every week" predictor.

The Forecast Accuracy Lab is the page that shows these scores by model, horizon and family, and which model is live.

### 9.7 Gates before a model goes live \[A\]

All thresholds are parameters. A model must pass every row.

| Gate | Rule |
| --- | --- |
| Overall | At least 7% lower WAPE than `flat90` across the 4, 8, 13 and 17-week horizons |
| Lead-time horizon | At least 10% lower WAPE at 17 weeks, or clearly lower under-forecast error without worse WAPE |
| No harm | No horizon more than 3% worse |
| Bias | Portfolio bias within 5% at 13 and 17 weeks. Each major family within 10% |
| Big sellers | No material regression on the top-80%-of-revenue ASINs |
| Stability | Four consecutive weekly shadow runs with no data-quality or integrity failure |
| Traceability | Every result traces to model, training cutoff, parameters and source versions |
| PO layer, scored separately | At least 10% lower algorithmic PO quantity error than the current simulator |

The demand layer may go live before the PO layer. A better demand score never promotes the PO model on its own.

## 10. Pricing Intelligence

The old Price Monitor watches only BioSteel's own ASINs on Amazon.ca, once a day, and reports the state of each product. The new tool records every price change as an event and gathers evidence for what Amazon may have matched.

Amazon's seller pricing APIs are not available to a vendor account \[D\]. Amazon.ca data keeps coming from the existing page collector.

### 10.1 Amazon.ca first-party observations

Port `AmazonScraperProvider` verbatim: the XPath lists, the guest-location cookie handshake with three retries, the bot-check strings, the user-agent rotation, wave size and delays \[C\]. Add recorded HTML fixtures and parser tests before changing anything.

Keep these behaviours \[C\]:

- Dashboards never trigger a fetch.
- A run stops after 5 consecutive blocked pages and is marked throttled.
- A failed cookie refresh falls back to the previous valid cookie for that province. A bad cookie would mark a whole province unavailable for a day.
- Four provinces: ON, BC, AB, QC.

Change:

1. **Statuses.** Separate `no_offer`, `unavailable`, `fetch_failed`, `parse_failed` and `blocked`. The old code stores a failed request as "no offer" \[C\].
2. **More fields, best effort and nullable:** coupon text, deal badge, Subscribe & Save price, availability text.
3. **Reference price.** Measure a drop against the product's own trailing 30-day median, as well as against list price and cost.
4. **Parser health.** If more than a threshold of results in a run are `parse_failed` or `no_offer` \[A: 30%\], raise a Data Reliability incident. A markup change must not read as mass unavailability.

### 10.2 Other sellers on BioSteel listings

New. For each ASIN, collect the offers list: seller name, price, shipping, fulfilment type, and which offer is featured. Store in `offer_observations`.

This is the cheapest new evidence. A third-party seller undercutting Amazon on the same listing is a common trigger for an Amazon price drop, and the old tool reads only the featured offer.

Build the parser from saved fixtures. Keep added request volume inside the budget in 10.3.

### 10.3 Scan cadence and request budget

The old cron fires every two hours but exits once the day's run is complete, so each product is seen once a day \[C\].

| Tier | Products | Provinces | Frequency \[A\] |
| --- | --- | --- | --- |
| A | Top 30 ASINs by trailing revenue | ON only | Every 4 hours |
| B | All active ASINs | All four | Daily |
| Offers | Tier A daily, the rest twice a week | ON only | As stated |

Set one configurable daily request ceiling for Amazon.ca and schedule inside it. Keep the 25-minute runtime cap per invocation and the resume-from-queue behaviour \[C\].

### 10.4 Retailer prices

All sources implement one interface, `RetailPriceProvider`, and write to `retailer_price_observations` with an evidence type. See D1.

| Provider | Phase | Source |
| --- | --- | --- |
| Costco table | 2 | Maintained by staff: item number, pack, warehouse regular price, warehouse promotion price and dates, Costco.ca price. CSV import and an edit screen |
| BioSteel Shopify | 2 | The store's own API. Current price and compare-at price per variant, and active sale periods from the ASP Tracker data |
| Manual and CSV | 2 | Any retailer. A person records a price with a URL or screenshot as evidence |
| Automated or licensed feeds | After D1 | One provider class per retailer. Candidates named so far: Walmart.ca, Well.ca, Healthy Planet, Popeye's, Supplement King, Shoppers Drug Mart, Loblaw banners, Sobeys, Metro, Canadian Tire, Sport Chek |

Costco warehouse and Costco.ca prices differ and are stored separately. The warehouse price is the one that matters for the contract check, and it cannot be collected from a website.

### 10.5 Product matching

A comparison is only as good as the match. Use three classes, set by a person and stored in `retailer_product_matches`:

| Class | Meaning | Allowed use |
| --- | --- | --- |
| Exact | Same GTIN, or verified same product, flavour, format and quantity | Price-match attribution, direct price comparison |
| Equivalent pack | Same product and format, different pack count | Per-unit value comparison only |
| Related | Different format, flavour assortment or formula | Context only. Never an alert |

Per-unit price is price divided by `units_per_pack` on the listing's unit basis. A tub and a stick pack stay different formats even though both have a per-serving price.

### 10.6 Costco value guard

Assumed reading of the clause, to be confirmed under D3: Costco must offer at least 20% better value per comparable unit than the comparator.

```latex
V = 1 - \frac{\text{Costco price per unit}}{\text{Comparator price per unit}}
```

- Warning when V is below 22%. Review required when V is below 20% \[A\].
- The comparator is configurable and versioned: which retailers count, regular or promotional price, and which products are comparable.
- Only exact and equivalent-pack matches are evaluated.
- The page shows the banner "comparator not confirmed against the contract" until D3 is closed.

### 10.7 Price events

A price event is one sustained change in Amazon's first-party price for an ASIN.

- **Open** when the price falls by a threshold against the previous observation or against the 30-day median \[A: log at 2%, alert at 8% in 24 hours or 10% against the median\].
- **Store the interval, not a moment:** the last time the old price was seen and the first time the new price was seen.
- **Update** if the price falls further. **Close** when the price returns to within 2% of the prior median, or after a maximum age.
- Each event writes a `demand_events` row so the forecast baseline excludes those weeks.

### 10.8 Attribution

Attribution proposes candidates with evidence. It never claims to know Amazon's reason. Wording in the UI is "consistent with", never "caused by".

Check in this order and keep every candidate that qualifies:

1. **Funded promotion or coupon** active on the ASIN. Known from Deal Funding records or the promotion reports.
2. **Other seller on the same listing** at or below Amazon's new price, seen in the 48 hours before the event.
3. **Exact-match retailer price** within the larger of $0.50 or 2% of Amazon's new price, observed before the event and no more than 72 hours earlier.
4. **Equivalent-pack retailer price** matching on a per-unit basis.
5. **Aged inventory at Amazon**, if the aged-stock field passed the probe and is high for the ASIN.
6. **Unknown.**

Classify each candidate as strong, moderate or weak:

| Class | Conditions |
| --- | --- |
| Strong | Exact product, retailer or seller change clearly observed before Amazon's, close price, observation under 24 hours old, no competing candidate |
| Moderate | Right product and price, but observation intervals overlap or several sources qualify |
| Weak | Per-unit similarity only, stale observation, or a better-fitting alternative exists |

Do not show numeric probabilities. Store the algorithm version with each result so a person can audit it.

### 10.9 Alerts

- **Change-based.** Send when an event opens, deepens or closes. The old email lists every product in a category every day \[C\].
- **Daily digest** keeps the state view, with days-in-state for each product.
- **Say which run was used.** If the latest scan was throttled or incomplete, the email says so. The old email silently falls back to the last completed run \[C\].
- Recipients, categories and send time stay admin-editable, with the test-send box \[C\].

## 11. Exception engine and Control Centre

The engine turns signals from every tool into a short ranked list of incidents: which product, what is happening, the likely cause, and the money exposed. Today the data exists but sits in separate pages, and only one alert emails anyone \[C\].

### 11.1 First-release exception types

Start with these nine. Each is a versioned rule with parameters. All thresholds are starting values \[A\] unless marked.

| Type | Trigger | Exposure basis |
| --- | --- | --- |
| Costco value shortfall | Section 10.6 value below 20%. Early warning below 22% | Flag with affected Costco items. No invented penalty figure |
| Amazon price event | Section 10.7 alert threshold | Projected Amazon retail sales affected, and the PPM impact |
| Other-seller displacement | Another seller undercuts Amazon by 2% or more, or Amazon's offer disappears | Expected wholesale units at risk |
| Low Amazon margin | Net PPM below 15% warning, below 5% critical, with at least 30 shipped units \[C: existing defaults\] | Wholesale PO value exposed to reduced ordering |
| Projected Amazon stock-out | Stock-out within 14 days with no confirmed PO arriving in time | Expected lost wholesale units times contribution |
| PO surge | An incoming PO exceeds the stored forecast for its period by a threshold, or exceeds eligible Cin7 stock | Shortfall units times contribution |
| PO shortfall | Algorithmic POs under 60% of forecast over four weeks while demand is at least 80% of forecast | Missing units times contribution |
| Forecast divergence | Internal and Amazon next-four-week demand differ by more than 30% on a material ASIN | Unit difference times contribution, marked as decision exposure |
| Data reliability | A source is overdue by its normal cadence plus 48 hours, a period is quarantined, or the scraper health check fails | Value of the decisions depending on that source, marked unverified |

Add a tenth, deduction anomaly, once the Deductions Tracker has data.

### 11.2 Rules for building incidents

1. **Group signals.** Signals for the same ASIN in the same time window form one incident with a primary type. A price event, a margin warning and a PO surge on one product are one incident with three signals.
2. **Never count the same money twice.** An incident's exposure is the largest of its signals' exposures, not their sum.
3. **Keep three money figures apart:** realized loss, probability-weighted exposure, and gross sales at risk. Never add them together.
4. **Costco incidents always rank for review**, whether or not a dollar figure exists.
5. **Exposure window is 120 days**, matching the production decision.
6. **Every incident links to its evidence:** the observations, the forecast run, the report request.
7. **Suppress during incomplete data.** A rule does not fire on a period the completeness gate has not passed, except the data reliability rule itself.

Contribution per unit is BioSteel's own margin: net vendor proceeds minus manufacturing cost. It is never Amazon's net PPM. `products.cost` is what Amazon pays BioSteel, and `products.cogs` is BioSteel's manufacturing cost \[C\].

### 11.3 Ranking \[A\]

```latex
R = 0.45\,E_{pct} + 0.30\,U + 0.15\,Q + 0.10\,I
```

E is the exposure percentile among open incidents, U urgency, Q evidence quality and I irreversibility, each scaled 0 to 1. An issue that can no longer be fixed inside the 120-day window scores highest on irreversibility. Weights are parameters.

### 11.4 Workflow and delivery

- Three states: open, acknowledged, resolved. One optional owner. One free-text note on resolution. No further workflow.
- A daily digest email lists new and worsened incidents, top ranked first. An optional Slack webhook posts the same list.
- New incident types run in shadow, visible to admins only, for two weeks before they notify anyone.

### 11.5 Control Centre

VC Birdseye becomes the Control Centre. The top of the page is the ranked incident list. Below it, the existing per-product cards remain, each built from batched queries across all products \[C\]. The nightly AI summary per card may stay as it is.

## 12. Other new tools and changes to rebuilt tools

Four smaller tools are new: Deductions Tracker, promotion actuals, Catalog Reconciliation and the Data Reliability page. Six rebuilt tools get specific changes.

### 12.1 Deductions Tracker

Shows what Amazon actually paid and deducted, against what Net Realized Price models. The old app models deductions with five rate boxes and has no actual deduction data \[C\].

- **Source.** The Finance Remittance API if the probe passes. It is documented as vendor-only and returns payments, deductions, vendor returns and advertising transactions \[D\]. Otherwise, an upload of the Vendor Central remittance export. Build the upload parser either way, as the fallback.
- **Categorize** each line: payment, shortage, chargeback, co-op, return, advertising, other.
- **Show** deduction rate by month and category, the gap between modelled and actual rates, and lines not yet disputed with their age.
- **Dispute tracking** is a status field and a note per line. The API is not expected to expose dispute actions.

### 12.2 Promotion actuals

If the probe passes, ingest the promotion and coupon performance reports into `promotion_performance` and `coupon_performance`. Use them to write `demand_events` and to feed Deal Funding's learned funding margin. Keep manual entry of funding asks, and the agreement import, as they are \[C\].

### 12.3 Catalog Reconciliation

One page listing every ASIN from every source and where it is missing.

- Sources: the Vendor Central catalog import, sales reports, Amazon's forecast, PO lines, the price monitor.
- An ASIN with no vendor SKU gets a product row and appears here. The old importer skips it, and the old forecast only covers ASINs with recent sales or PO history \[C\].
- Flags: active with no forecast row, in Amazon's forecast but not tracked, selling but not in the catalog, missing unit spec, missing family, missing cost or COGS.
- Keep the existing end-of-life candidate detection and its human review queue \[C\].

### 12.4 Data Reliability page

A view over the ingestion ledger: each source's last successful period, freshness against its normal cadence, quarantined periods, recent revisions, scraper health and capability registry status.

### 12.5 Changes to rebuilt tools

| Tool | Change |
| --- | --- |
| PO Checker | Cin7 logic ported verbatim: FEFO batching, 120-day minimum expiry, caddy conversion, largest-remainder distribution \[C\]. The over-forecast warning reads the stored forecast, so no spreadsheet upload is needed. A PO that follows a price event shows that event |
| PO Classification | Rules ported verbatim. PO-level rollup gains a "review" outcome so unclassified POs are not counted as algorithmic |
| PO Stats | Fill rate, units short and lost revenue computed only where accepted quantity is known. Unknown is shown as unknown |
| Net Realized Price | Shows actual deduction rates beside the modelled rate boxes once remittance data exists |
| Net PPM Monitor | CRaP watchlist adds two signals: an open price event, and a ship-weight cost proxy per ASIN, since heavy ready-to-drink cases cost Amazon more to fulfil than stick packs |
| Subscribe & Save | Already collected weekly. Shown on the product card, and available to the demand model as a candidate floor to test |

## 13. Phases and gates

Six phases. Each ends at a gate that must pass before the next phase's output is trusted. The largest risk to this project is scope: building everything at once and never cutting over. Finish and gate each phase.

| Phase | Deliverables | Gate |
| --- | --- | --- |
| 0. Foundation | New Laravel 13 app on PHP 8.4. Platform: login, roles, admin, scheduler, mail, audit log. Design token scaffold. `amazon:probe` run and results stored. All "port unchanged" tools with their data import commands | Carried-over tests pass. Each ported tool gives the same output as the old app for the same real input. Probe results reviewed with Lesley |
| 1. Data foundation | Ingestion pipeline and ledger. Fact tables. Historical import from the old database. Product families and unit specs. Catalog Reconciliation. Data Reliability page. Deeper-history backfill attempts | Matched ASIN-period rows agree with the old app within 0.5% for 14 consecutive days, with every larger difference explained. A simulated failed report yields `missing` and blocks the forecast. A restated value produces a revision row |
| 2. Pricing Intelligence | Collector port with fixtures. Split statuses. Offers on own listings. Scan tiers. Costco table, Shopify provider, manual provider. Matching. Costco guard. Price events and attribution. Change-based alerts | Parser tests pass on saved pages. Replaying old `price_checks` history produces sensible events. Real Costco items loaded. Two weeks of shadow alerts reviewed. Only one app is scraping |
| 3. Amazon tools and forecast | PO suite, NRP, PPM, Deal Funding, Geo Sales and Sales Trends reading the new tables, with Appendix B fixes. Forecast engine in shadow. Accuracy Lab. Supply export | Golden tests pass, including the classification counts in Appendix A. Each tool's figures reconcile with the old app for the same period, except documented fixes. Section 9.7 decides whether any new model goes live |
| 4. Exceptions and deductions | Exception engine. Control Centre. Deductions Tracker. Promotion actuals | Two weeks in shadow. False positives reviewed and thresholds tuned. Digest format approved |
| 5. Cutover | Section 14 checklist. Old app set read-only, then retired | Rollback rehearsed. Lesley signs off each tool |

### Order and parallel work

- Phase 2 does not depend on Phase 1. It can start as soon as Phase 0 is done, and it addresses the top business problem.
- Inside Phase 3, re-pointing the existing tools comes before any new forecast model.
- New forecast models never block cutover. If no model passes its gate, cut over with `flat90` running on the corrected simulator and clean data.

### Definition of done for any change

1. Tests cover the new behaviour and any Appendix A fact it touches.
2. No test makes a live external call.
3. Queries on large tables have a checked query plan.
4. New thresholds are parameters with a default, not constants.
5. New tools and scheduled tasks self-register through a migration.
6. The page has a tutorial and an empty state, and shows data freshness.
7. `docs/context.md` in the new repository gets a dated entry: what changed, why, and anything verified live.

## 14. Data migration and cutover

The old app stays live and authoritative until each tool is signed off. The new app sits beside it on the same server, with its own directory, hostname and database, and reads the old database through a read-only connection. The new hostname is tools.coachwoodgroup.com.

### 14.1 Importing the old data

- Configure the old database as a second connection named `legacy`, with a read-only database user. The old app used the same pattern for its own migration \[C\].
- One idempotent import command per domain, safe to re-run, reporting row counts in and out.
- Preserve original primary keys where other rows reference them, original timestamps, and user attribution.
- Read large tables in chunks. The largest is geographic sales, at about 940,000 rows \[C\].
- Do not import `inventory_snapshots` history. It held over 16 million rows and no page reads it \[C\]. Import `inventory_history_daily` and the derived tables instead.
- Some history cannot be re-downloaded. Amazon's weekly sales report only reaches back 49 days, and the forecast report has no history at all \[C\]. Treat the old database as the only copy.
- The old database is about 10 GB, nearly all of it the inventory\_snapshots table. Back it up before the first import by dumping every table except that one's rows, at a quiet time, and keep the backup restorable throughout.

Claude Code is never given a full dump. Imports run on the server, from the `legacy` connection. For local development, use a trimmed extract that leaves out:

- `inventory_snapshots` rows, for size.
- Tables holding stored credentials or sessions: `zendesk_tenant_credentials`, `gmail_connections`, `sessions`, `password_reset_tokens`, `cache`, `jobs`. On the server, these connections are re-entered in the new app, or imported with the old app key if they are encrypted with it.
- Customer and order rows, unless anonymized first: `orders`, `order_items`, `team_sponsorship_activity`, the `combined_shipping_*` tables, `asp_sales_lines`, `asp_pending_orders`, `zendesk_sidebar_audit_log`.

### 14.2 Sharing one server

Both apps use the same IP address and the same credentials. Without care, running two copies doubles the load on Amazon and on the disk.

| Shared resource | Rule during parallel running |
| --- | --- |
| Amazon.ca page collection | Exactly one app scrapes. Until Phase 2's gate, the new app reads the old `price_checks`. At the gate, disable the old scan task and enable the new one in the same change |
| SP-API reports | Stagger schedules so the two apps do not request the same report in the same hour. Where possible the new app ingests a period once and the old app is left to its own cadence |
| Shopify webhooks | Registered against one URL per store. They stay pointed at the old app until that tool cuts over, then are re-registered |
| Zendesk sidebar app | Points at one base URL. Switch at that tool's cutover |
| Google OAuth and Gmail | Add the new hostname's redirect URIs. The Gmail sending connection is made once per app |
| Cin7, Klaviyo, Walmart | Read-mostly. No special handling, but respect rate limits |
| Disk | Watch free space. Raw document storage and a second database both add to it |
| Uploaded listing images | Files under `storage/app/public/vc-image-manager/` were given to Amazon as public URLs \[C\]. Copy them and keep those URLs resolving after cutover |

### 14.3 Cutover sequence per tool

1. Import its data and verify counts.
2. Run it in the new app in shadow, with no writes to external systems.
3. Compare outputs with the old app on the same inputs.
4. Lesley signs off.
5. Disable the tool's scheduled tasks in the old app. Enable them in the new app.
6. Move webhooks and external callbacks where the tool has them.
7. Deactivate the tool in the old app's Admin > Tools so users are not split across two versions.
8. Run a final delta import for anything written between steps 1 and 5.

Tools that write to outside systems need extra care in shadow: Team Sponsorship creates Shopify draft orders, Combined Shipping issues refunds and Klaviyo events, the Listings editors and Image Manager submit to Amazon, and the Zendesk sidebar changes Recharge and Rivo records. In shadow these run read-only or against preview modes only.

### 14.4 Rollback

- The old app and database stay intact and runnable until the final sign-off.
- Rolling back a tool means re-enabling its old scheduled tasks, pointing webhooks back, and reactivating it in the old Admin.
- Rollback must not require any new historical download from Amazon.
- After final sign-off, set the old app read-only for an agreed period \[A: 60 days\] before removing it.

## 15. UI and design handoff

The visual design is being produced separately in Claude Design. Claude Code builds working pages on a neutral token layer, so the design can be applied without touching logic.

### Rules for building pages now

1. **Tokens only.** Every colour, font, size, spacing, radius and shadow comes from CSS variables defined in one file. No literal colour or pixel value in a view or component.
2. **Shared Blade components.** Build each of these once and use it everywhere: page shell, navigation, data table, stat tile, filter bar, status badge, chart card, tabs, modal, toast, empty state, incident card, freshness badge.
3. **Light and dark themes** both work, switched at runtime. Charts read their colours from the variables and update on the existing `theme-changed` event.
4. **Every data page handles five states:** loading, empty, complete, provisional or partial data, and error. Never show a blank page or a bare zero for missing data.
5. **Every data page shows freshness:** the period covered and when it was last updated, from the ingestion ledger.
6. **Tables are the primary surface.** Sortable, filterable, with CSV export and a "copy ASINs" action where the old tool has one.
7. **Never rely on colour alone** for status. Pair it with a label or icon.

### What Claude Design should supply

- A token set: colour roles for both themes, type scale, spacing scale, radii, elevation.
- A spec for each shared component above, including states.
- Layouts for the key screens below.
- A categorical chart palette that stays distinguishable for colour-blind users, and how many series it supports.

### Screen inventory

| Area | Screens |
| --- | --- |
| Home | Tool launcher with per-user access |
| Control Centre | Ranked incident list, incident detail with evidence, per-product cards |
| Pricing Intelligence | Price dashboard by province, product price timeline, price events list, event detail with candidates, other sellers on a listing, Costco value guard, retailer price entry and import, product match review, alert settings |
| Forecast | ASIN by month overview, ASIN detail showing the three layers and components, adjustments and events, Accuracy Lab, supply export |
| Purchase orders | PO Checker, PO Classification, PO Stats |
| Margin | Net Realized Price, Net PPM Monitor with its seven tabs, Deal Funding, Deductions Tracker |
| Sales | Amazon Sales Trends, Geo Sales Intelligence |
| Data | Catalog Reconciliation, Data Reliability, capability registry |
| Listings | VC and SC Listings Editors, Image Manager, CDQ report, US Product Reviews |
| Other tools | Team Sponsorship and its stats, BioSteel App Stats, Shopify Lost Sales, Combined Shipping, ASP Trackers, Walmart US auditor |
| Admin | Users, tools, teams, scheduler and run history, activity log, mail, Google sign-in domains |

The Zendesk sidebar has its own compact layout inside Zendesk and is outside the new design system unless Lesley says otherwise.

## 16. Working rules for Claude Code

Twelve rules apply to every session on this project.

1. **Read before you write.** For any area you rebuild, read the old service, its tests and the matching `context.md` entries first. The old comments record what was verified live.
2. **Port tests first.** Carry the old tests for an area into the new repository and make them pass before changing behaviour.
3. **Do not change ported tools.** If you find a bug in a "port unchanged" tool, record it in `docs/found-issues.md` and ask Lesley. Do not fix it silently.
4. **Check the capability registry.** Never build on an Amazon endpoint or field that has not passed the probe. Never rely on documentation alone for Canada.
5. **No live writes without sign-off.** Do not submit listings, create Shopify orders, issue refunds, change subscriptions or send customer notifications from a development or shadow environment.
6. **Protect the shared server.** Follow the rules in section 4. Run heavy imports and index builds at quiet times, in batches.
7. **Never run a destructive database command** against anything but a disposable local database.
8. **The database dump is confidential.** It holds customer, order and financial data. Do not commit it, paste it into fixtures, or include real values in documentation. Build fixtures from anonymized samples.
9. **Secrets stay in `.env`.** Claude Code never receives production credentials. Lesley places the real file on the server. Anything that needs live credentials runs as an Artisan command on the server, started by Lesley, and prints no secrets. Never commit credentials. The old `.env.example` lists every key the app needs.
10. **Respect the Amazon.ca request budget.** Develop parsers against saved pages. Never loop requests against the live site to test.
11. **Label what you know.** In code comments and in `docs/context.md`, keep the old practice: say whether something was verified live, taken from documentation, or assumed.
12. **Use the default, then ask.** When an \[OPEN\] item affects your work, build the default from section 3 behind a config value, and list the question in your summary.

### Documents to keep in the new repository

| File | Purpose |
| --- | --- |
| `docs/build-spec.md` | This document, exported |
| `docs/context.md` | Dated log of changes, findings and anything verified live, continuing the old one |
| `docs/decisions.md` | Each open decision as it closes |
| `docs/found-issues.md` | Bugs found in ported tools, awaiting a decision |
| `README.md` | Setup, deploy, one-time commands and tool descriptions, kept current |

## 17. Inputs needed from Lesley

Phase 0 can start with the first five items below. Everything else has a default and can arrive later.

### Needed to start

| Input | Why |
| --- | --- |
| A read-only database user on the old database, on the server. For local development, the trimmed extract described in section 14.1 | Reference data, import source and test fixtures |
| The production `.env` file, placed on the server by Lesley. Claude Code never sees the values | Credentials for every integration |
| Confirmed: PHP 8.4 and MariaDB 10.11.14 on the server | Stack |
| Confirmed hostname: tools.coachwoodgroup.com. Still needed: the directory, and the old app's hostname | Side-by-side running |
| Permission to run `amazon:probe` against the live account, and the list of roles granted to the SP-API app | Section 8 |

### Decisions (section 3)

| ID | Question |
| --- | --- |
| D1 | With counsel: how may third-party retailer prices be collected? Which retailers matter most, in order? |
| D2 | Is an Amazon-only supply export enough, or is an all-channel plan wanted later? |
| D3 | The exact text of Costco's member-value clause: comparator, regular or promotional prices, which products |
| D4 | Confirm the listed tools are port-unchanged |
| D5 | Can a persistent queue worker run on the server? |
| D6 | Keep Blade and Vite, or does the new design call for something else? |
| D7 | Final name for the Shopify lost-sales tool |
| D8 | Does the dormant Seller Central Canada account still exist, and can it authorize an app? |
| D9 | Which vendor codes and how many ASINs are expected in scope? |

### Facts to check on production

| Check | Why |
| --- | --- |
| `SELECT MIN(generation_date) FROM mkt_amazon_forecast_snapshots` | How much Amazon forecast history exists to score |
| Earliest week in `nrp_cost_current` and `geo_sales_current` | How much seasonality history exists |
| Free disk space and current database size | Budget for raw documents and a second database |
| Whether the legacy monolith's database is still reachable | Possible older history |

### Business data

| Input | Needed for | Phase |
| --- | --- | --- |
| Product family for each ASIN, reviewed. The existing Sales Trends groups are the starting point | Seasonality pooling | 1 |
| Unit spec per ASIN: format, servings or volume per pack, GTIN, ship weight | Per-unit comparison, weight signal | 1 |
| Costco item list: item number, pack, warehouse price, promotion calendar | Costco guard | 2 |
| Known promotions and deals, past and planned, with dates | Baseline cleaning, event lift | 3 |
| Amazon's replenishment lead time, if known, and the production review cycle | PO simulator, supply export | 3 |
| Who receives alerts, by email and Slack, and at what time | Digest | 4 |
| Manufacturing cost per ASIN kept current in `products.cogs` | Contribution and exposure | 4 |

### Design

| Input | Why |
| --- | --- |
| The Claude Design token set and component specs, when ready | Section 15 |
| Whether the Zendesk sidebar is included in the redesign | Scope |

## Appendix A. Verified facts to preserve

Every row below was learned from live behaviour and is recorded in the old code or its `context.md` log \[C\]. Each must survive the rebuild, as a regression test where practical. Read the named file before reimplementing its area. Read `context.md` in full once; it holds more detail than this table.

### A.1 Amazon reports and Data Kiosk

| Fact | Where |
| --- | --- |
| Report polling waits up to 2,700 seconds, polling every 30 seconds. A 600-second wait caused recurring failures | `SpApiClient` |
| Reports API documents declare GZIP in a `compressionAlgorithm` field. Data Kiosk documents are always gzipped and carry no flag. Data Kiosk output is JSONL and must be streamed line by line | `SpApiClient`, `GeoSalesService` |
| Vendor reports lag about 3 days | `StockForecastService` |
| Daily sales requests are chunked into windows of at most 13 days | `PoForecastService`, `StockForecastService` |
| Weekly sales requests must start on a Sunday and end on a Saturday. An unaligned request fails outright | `VendorSalesDateRangePlanner` |
| Weekly grain allows at most 7 periods per request and looks back at most 49 days. Default trailing window is 6 weeks. Monthly grain allows at most 15 periods | `VendorSalesDateRangePlanner` |
| A month Amazon has not published yet fails the whole request with FATAL "not yet available". On a scheduled run, step the window back one month and continue | `context.md`, 2026-11-01 |
| Use `distributorView: MANUFACTURING` and `sellingProgram: RETAIL`. `SOURCING` never returns ordered units or revenue, and its ASIN set was a strict subset (100 of 113) | `VendorSalesSyncService` |
| Ordered units, shipped units and returns can be negative for a real period. Columns must be signed | `amazon_vendor_sales` migration |
| Amazon restates recent periods for several days. The newest pull is the truth | `amazon_vendor_sales` migration |
| Requesting a single day of inventory is fragile: one day returned 117 ASINs, the next returned none. Request a trailing 7-day window and take the freshest row per ASIN | `StockForecastService` |
| The forecasting report accepts only `sellingProgram`, not `reportPeriod`. It has no history endpoint. A missed week is lost for good | `MarketplaceForecastService` |
| Data Kiosk needs `currencyCode: "CAD"`. Without it, amounts come back in USD. A $36.00 cost appeared as $26.23 | `NrpService` |
| Data Kiosk lump-sum fields `contraCogs` and `salesDiscount` are always null in real data. Use the per-unit fields times units | `NrpService` |
| Data Kiosk field `procurableProductOOS` is rejected by the live schema. `sourceableROOS` is the out-of-stock rate | `VcTrafficService` |
| Net PPM, reverse-derived to ten decimal places: (ASP − shipped COGS per unit + contra-COGS per unit − average sales discount) ÷ ASP | `PpmService` |
| Geographic rows are close to order grain, so every geographic view needs a volume floor. FSA is a real three-character Amazon aggregation. US data has no geographic fields | `GeoSalesService` |
| Province and city normalization returns null on a lookup miss, with no error. Load the lookup tables before any geographic pull | `GeoSalesService`, README |
| Replenishment `listOfferMetrics` allows 20 ASINs per call at 1 request per second | `VcReplenishmentService` |
| The Customer Feedback API is blocked for Canada. Review data is brand-level and pulled from the US marketplace with the seller token, paced at 1 request per second | `FeedbackService`, README |

### A.2 Purchase orders and Cin7

| Fact | Where |
| --- | --- |
| Amazon splits one reorder across fulfilment centres into several same-day POs. Group by ASIN and order date first. One ASIN went from 349 lines to 76 real events | `PoForecastService` |
| Classification precedence: discount at or below threshold is bulk buy, else long lead time is bulk buy, else replenishment weekday (holiday-shifted) is algorithmic, else review queue | `PoClassificationService` |
| Golden result: 20,148 PO lines classify as 3,508 bulk buy, 13,818 algorithmic and 612 review queue | `PoClassificationService` |
| `born_to_run` and `manual_order` exist only as manual overrides. Every consumer of classifications must apply overrides. Three separate places once forgot to | `PoForecastService`, `PpmService` |
| Evaluating reorders daily instead of on the replenishment weekday produced about 365 false micro-orders per ASIN | `PoForecastService` |
| PO quantities in cases convert to units with the line's unit size. PO states are New, Acknowledged and Closed. API rows carry no product title | `PoSnapshotCommand`, `StockForecastService` |
| About a quarter of open POs have an estimated arrival already in the past. Exclude them from incoming supply | `StockForecastService` |
| PO delivery windows can be in the future, so PO Stats date filters need both ends bounded | README |
| Cin7 stock for Amazon needs at least 120 days to expiry, FEFO batching, caddy conversion of 6 for four named ASINs, and largest-remainder distribution across PO lines | `Cin7StockService` |
| In Cin7, vendor code GQ8J9 is Canadian Protein and Z3091 is BioSteel. They share one Cin7 account. Neither is stale data | `context.md`, 2026-08-25 |
| PhpSpreadsheet ran out of memory on real PO exports, and an unresolved formula once leaked into the ASIN column. Keep the fixes | `PoXlsParser`, `context.md`, 2026-08-31 |
| Amazon silently refused a bulk-buy accepted-PO re-upload until the total accepted cost was recalculated | `PoAcceptedXlsExporter` |

### A.3 Listings, catalog and pricing

| Fact | Where |
| --- | --- |
| Listing edits go through `VALIDATION_PREVIEW` first, then the real submission, then an edit-history row with the user | `VendorListingController` |
| Seller Central listings are keyed by SKU. One ASIN can have several seller SKUs | README |
| The Vendor Central catalog export describes its own header row in a metadata cell, and column letters shift per sheet. Locate columns by technical attribute name. Never overwrite `active` or `cogs` from it | `VendorCentralCatalogImportParser` |
| Product type schemas expose only a flat `required` array as an importance signal | README, CDQ |
| Amazon's API cannot see FRNT, SIDE and BACK image slots, and exposes no image ownership field | README, Image Manager |
| A failed guest-location cookie must never be cached. Fall back to the last good one | `PriceMonitorScanCommand` |
| A successful location change returns `"successful":1` and sets a fresh `ubid-acbca` cookie | `AmazonScraperProvider` |

### A.4 Shopify, Walmart and other integrations

| Fact | Where |
| --- | --- |
| Shopify `discountedTotalSet` leaves out order-level discounts. Compute net from discount allocations. Recharge bakes subscription discounts into the unit price. Bulk operations reject refund line items | README, ASP Tracker |
| Refunds use presentment currency, not shop currency | README, Combined Shipping |
| Retry only connect-phase failures to Shopify. Never retry a read timeout on a mutation such as a refund | `App\Support\HttpRetry` |
| Inventory webhooks arrive in bursts. The handler must stay a primary-key read plus one insert | `context.md`, 2026-11-16 |
| Walmart authenticates with the `WM_SEC.ACCESS_TOKEN` header, not Bearer | README |
| GA4 active-user counts are deduplicated per query window. Stored daily values cannot be summed | README |

### A.5 Platform

| Fact | Where |
| --- | --- |
| A permission inserted through the query builder is invisible for up to 24 hours unless the migration clears Spatie's permission cache | README |
| `PHP_BINARY` inside PHP-FPM is the FPM binary. Background runs need the CLI path from config, and must be fully detached or FPM kills them | `BackgroundProcessLauncher` |
| Matching on a date-cast column with `updateOrCreate` is unreliable across drivers. Match on raw values | `VendorSalesSyncService` |
| A Blade directive name written inside a JavaScript comment is still compiled and breaks the page. A function declared inside a Blade view fatals on second render | README |
| `ConnectionException` does not extend `RuntimeException`. Catch it explicitly | README |
| On theme change, update an existing Chart.js instance. Do not destroy and recreate it | README |

## Appendix B. Known defects that must not be ported

These twenty-four behaviours exist in the old code \[C\]. Each was confirmed by reading the named file. Write a test for the required behaviour before porting the surrounding logic.

### B.1 Forecasting

| Defect | Where | Required behaviour |
| --- | --- | --- |
| A failed, unfinished or document-less report returns an empty array, and velocity still divides by the full window | `PoForecastService::pullReportRows`, `StockForecastService::pullReportRows`, `VendorSalesSyncService::pullChunk` | Missing data produces `missing`, and the forecast is blocked |
| Overstock suppression is checked once at the start. With the 270-day horizon, an overstocked ASIN shows zero POs for nine months | `PoForecastService::simulateAlgorithmicForecast` | Check at every reorder date |
| Starting inventory is sellable on-hand only. Open POs are ignored | `PoForecastService::run` | Start from inventory position |
| A predicted order is added straight to the balance, with no pipeline or lead time | `simulateAlgorithmicForecast` | Section 9.3, change 2 |
| The velocity window runs to today though the last 3 days are unreported | `PoForecastService::fetchWeeklyVelocity` | End the window at today minus the lag |
| Demand is a flat trailing average. Deal weeks inflate it and stock-out weeks deflate it | `PoForecastService`, `StockForecastService` | Section 9.1 |
| The forecast only covers ASINs with recent sales or algorithmic PO history | `PoForecastService::run` | Every active ASIN gets a row |
| The Marketplace Sales-In export writes the approved value without bulk-buy units. A month with only a bulk buy gets no cell | `MarketplaceForecastService::computeSalesIn`, export | One calculation with explicit components |
| A forecast week is assigned wholly to the month it starts in | `getAmazonMonthlyMeanByAsin` | Split by day |
| The low and high range is a fixed plus or minus 25% | `PoForecastService::run` | Ranges from measured error |
| Amazon's P70, P80 and P90 are stored and never read | `mkt_amazon_forecast_snapshots` | Stored, shown and scored |
| `insertOrIgnore` discards a corrected Amazon forecast under the same generation date | `captureForecastSnapshot` | Revision-numbered vintages |
| The Marketplace run history is saved only on days someone opens the page | `MarketplaceForecastController` | Saved by the scheduled run |
| Amazon's forecast is captured weekly, with no retry if that run fails | `mkt:snapshot` schedule | Capture daily. The write is idempotent |
| Parameters `blend_weight_*`, `instock_censor_floor_pct` and `forecast_band_width_pct` exist and nothing reads them | `MktForecastParams` | Remove or wire up. No dead parameters |

### B.2 Purchase orders

| Defect | Where | Required behaviour |
| --- | --- | --- |
| Accepted, shipped, received and cancelled quantities are written as 0 when the API does not return them | `PoSnapshotCommand` | Nullable. Unknown is not zero |
| The PO-level rollup reduces every PO to bulk buy or algorithmic, so review-queue POs count as algorithmic | `PoClassificationService::rollupType` | Add a review outcome |

### B.3 Pricing

| Defect | Where | Required behaviour |
| --- | --- | --- |
| A failed page request and a parser miss are both stored as "no offer" | `AmazonScraperProvider`, `CheckEvaluator` | Separate statuses |
| A throttled or banned run exits as success. The alert email silently uses the last completed run | `PriceMonitorScanCommand`, `AlertMatcher` | Raise an incident. State the run used |
| Alerts report state only. Nothing detects a change between scans | `AlertMatcher`, `Categorizer` | Price events |
| Only the featured offer is read. Other sellers and other retailers are invisible | `AmazonScraperProvider` | Sections 10.2 and 10.4 |

### B.4 Data

| Defect | Where | Required behaviour |
| --- | --- | --- |
| `selling_program` is stored but left out of the unique key | `amazon_vendor_sales` | Include it |
| The out-of-stock rate is multiplied by 100 on an unverified assumption about its scale | `VcTrafficService` | Verify in the probe, then fix the conversion |
| Three services pull the same sales report separately, and the daily values are never stored | `PoForecastService`, `StockForecastService`, `VendorSalesSyncService` | One ingestion path, daily facts stored |

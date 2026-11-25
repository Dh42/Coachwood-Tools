# Decisions

Each entry records a decision that replaces or adds to a default in `docs/build-spec.md`. Where this file and the spec differ, this file wins. Newest first.

## 2026-11-25: Phase 0 plan approved

Decided by Lesley, answering section 8 of `docs/phase-0-plan.md`.

| Topic | Decision | Replaces |
| --- | --- | --- |
| Phase 0 plan | Approved | |
| Bugs in ported tools | Fixed as they are found, in the tool's port pull request, each with a test that fails on the old behaviour first. Listed in `docs/found-issues.md` | Brief: "port unchanged, log found issues" |
| Cutover hostname | `tools.biosteel.com` becomes an alias of the new app at cutover | |
| Webhooks | No Shopify webhooks point at the new app during shadow running. At cutover the database is synced first, then the new app's webhooks are turned on | |
| Final sync | Covers every ported tool. The old app's data wins | |
| Anthropic spend | `cdq:consistency` runs in both apps until cutover; the doubled cost is accepted | |
| Products | Edited in the old app until Phase 1, and synced across | |

## 2026-11-24: answers to the Phase 0 brief review

Decided by Lesley in the Phase 0 review session.

### Scope

| Topic | Decision | Replaces |
| --- | --- | --- |
| Zendesk sidebar | Dropped from the rebuild. Not ported, not imported (`routes/zendesk.php`, `zendesk-app/`, `zendesk_*` tables). It is no longer used | Spec section 5, 14.2, 14.3, 15 |
| D4: port-unchanged list | Confirmed as spec section 5, minus the Zendesk sidebar | D4 default |
| D7: Stockout tool | Renamed "BioSteel Shopify Lost Sales". New URL `/tools/biosteel-shopify-lost-sales` and a matching permission name. The old URL redirects. Existing user access is mapped on import. A version for the other brand will follow | D7 default (name only, slug kept) |
| D1: third-party retailer prices | Automated collection added after launch | D1 default (unchanged in effect) |
| D2: supply plan | Amazon only. No all-channel plan | D2 default confirmed |
| D3: Costco member-value clause | The 20% value guard is not wanted. Costco stays as one retailer in price tracking, with no value check | Spec 10.6, 11.1 Costco row, D3 |
| D8: Seller Central Canada | The account exists and can authorize an app. Its token is in the old `.env` and will be copied across. The probe tests offer lookups on Amazon.ca with it | D8 default (not used) |
| D9: vendor codes | Z3091 (BioSteel) now, and GQ8J9 (Canadian Protein) going forward. Both are under the same Vendor Central account and SP-API authorization | D9 default (one vendor code) |
| Older monolith database | Still running. Not imported. Use the history the current app already imported | Spec 17, "legacy monolith" check |

### Platform and hosting

| Topic | Decision |
| --- | --- |
| Hostname and path | tools.coachwoodgroup.com, at `/var/www/vhosts/tools.coachwoodgroup.com`. The old app is tools.biosteel.com |
| Deploys | Plesk push-to-deploy of `main` |
| `.env` | Placed on the server by Lesley before the first push. Template: `docs/server.env.example` |
| D5: queue | No persistent worker. The scheduler drains the database queue every minute |
| D6: front end | Blade and Vite. Layout from the Claude Design artifact "Coachwood Tools Redesign" |
| Stored connections | Gmail is reconnected in the new app. The old app's encryption key is not shared |
| Access before cutover | Admins only |
| Alerts | Sent only to the super admin (Lesley) |
| Tests | Run on SQLite as the old suite does, and also on MariaDB in CI |

### Data and cutover

| Topic | Decision | Replaces |
| --- | --- | --- |
| Production data for development | Lesley supplied a full export of `tools_db` (2026-11-24). It is restored only into a disposable local database, without `inventory_snapshots` rows or the credential and session tables. It is never committed, and real values never go into fixtures or docs | Spec 14.1 "never given a full dump" |
| Cutover | One cutover at the end, not tool by tool. Before it, a sync command re-pulls anything that changed in the old database. Team Sponsorship, its stats and BioSteel App Stats are the main tools that need it; the Amazon and Shopify stock-out collectors fill their own data once running | Spec 14.3 per-tool sequence |
| Shadow running | After import, the new app runs its own scheduled pulls beside the old app. Duplicate admin emails and API calls until cutover are accepted. `asp:backfill` stays disabled until cutover, because Shopify allows one bulk operation per store at a time. Writes to outside systems stay off (`TOOLS_EXTERNAL_WRITES=false`) | Spec 14.3 |
| Matching platform records on import | Roles, permissions, tools and scheduled tasks are matched by name, not by ID | |
| Proving output matches | One comparison command per tool, run on the server, printing counts and totals only | |

### Amazon probe

| Topic | Decision | Replaces |
| --- | --- | --- |
| Where it lives | In the new app, not the old one. The old app and its database are not modified | Brief step 2, spec section 8 |
| What leaves the server | Only the summary: pass or fail, HTTP and error codes, fields requested and returned, empty-value rates, row counts, field types and ranges. No ASINs, no amounts. Raw responses stay on the server and are deleted after Phase 1 | Spec 8 step 4 |
| Roles | The SP-API app should have all roles. A 403 is still recorded as "role missing" | |
| Running it | Approved by Lesley against the live account, at a quiet hour | |

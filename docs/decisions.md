# Decisions

Each entry records a decision that replaces or adds to a default in `docs/build-spec.md`. Where this file and the spec differ, this file wins. Newest first.

## 2026-12-07: Combined Shipping Refund leaves this app

| Topic | Decision | Replaces |
| --- | --- | --- |
| Combined Shipping Refund | Not ported. It belongs in the customer service app and will be rebuilt there. Phase 0 step 13 is dropped, and its found issues (2 and 3) go with it. The shared code this app already has stays: the Shopify GraphQL client and brand settings (ASP Tracker) and the Klaviyo client (Team Sponsorship) | Plan step 13 and section 5.10; build spec section 4 (`/tools/combined-shipping`) |
| Until then | Staff keep using it in the old app. The old app can't be switched off, or `tools.biosteel.com` moved here, while it's the only place the tool runs; see the plan's section 6 | |

## 2026-12-06: Listings Editors

Decided by Lesley while porting the VC and SC Listings Editors.

| Topic | Decision | Replaces |
| --- | --- | --- |
| Rejected submissions (found issue 16) | Stay in the edit history, marked REJECTED with who sent them. Only accepted ones count toward Our Contribution. The page now says every submission is logged | The old help text: "every accepted change is logged" |
| SKUs gone from Seller Central (found issue 15) | After a complete all-listings report, a SKU missing from it is marked `Removed`: it leaves the picker, and its audits and edit history stay. It comes back if a later report has it | Sync only added and updated |
| Daily listings audit until cutover | This app's `listings:audit-run` runs at 08:30 UTC, after the old app's 08:00 run, because both apps share one SP-API rate limit and the old app doesn't retry on 429. The platform import keeps 08:30. At cutover it goes back to 08:00 | Plan section 2, rule 6 (old cron kept) for this task |

## 2026-12-03: CDQ consistency check

Decided by Lesley while porting the CDQ Listing Quality Report.

| Topic | Decision | Replaces |
| --- | --- | --- |
| Unchecked consistency (found issue 13) | Scores and grades stay as the old app computes them (an unchecked listing keeps the provisional 10 of 100), so the two apps still match. The page and detail show consistency as "Not checked", and `cdq:consistency` fails, with the alert email, when Anthropic gives no usable answer | Silent full credit |
| Anthropic key | The new app gets its own `ANTHROPIC_API_KEY`, so the check really runs on Sundays. The old app has none. The import keeps this app's cached Anthropic answers, so unchanged listings aren't paid for again | |

## 2026-12-02: commit straight to main

Decided by Lesley.

| Topic | Decision | Replaces |
| --- | --- | --- |
| Git flow | Everything is committed to `main`: no per-step branches or pull requests. Lesley commits and pushes; Claude leaves changes in the working copy and never commits. CI runs on every push; `.github/workflows/release.yml` moves the `release` branch to a commit only once CI has passed on it, and Plesk deploys `release`. `auto-merge.yml` is removed | One pull request per step (plan, `CLAUDE.md`); branch, auto-merge, deploy `main` |

## 2026-12-02: Lost Sales snapshot clean-up

Decided by Lesley while porting BioSteel Shopify Lost Sales.

| Topic | Decision | Replaces |
| --- | --- | --- |
| Snapshot clean-up | `stockout:prune-snapshots` (keeps 90 days) runs wherever `stockout:snapshot-inventory` runs, in shadow running and after. It is registered enabled, and the platform import gives it the sweep's enabled flag instead of copying the old app's (off). The sweep writes about 333,000 rows a day to a table nothing reads yet | Plan 5.4 "enabled flags copied from the old app" (the old app has the sweep on and the clean-up off) |

## 2026-11-29: tutorial tours

Decided by Lesley while porting BioSteel App Stats.

| Topic | Decision | Replaces |
| --- | --- | --- |
| Tutorial tours | Keep each tool's "Tutorial" tour (intro.js), restyled to the themes. Shared helper `resources/js/tour.js` and `<x-tour-button>`; the invitation email's line about the Tutorial button is back | |

## 2026-11-27: Google-only sign-in

Decided by Lesley during step 3b.

| Topic | Decision | Replaces |
| --- | --- | --- |
| Sign-in | Google only, through the company's internal OAuth client, which only lets people in the org sign in. No password sign-in, password reset, password change, password confirmation, email verification or registration. Google sign-in moves from 3f into 3b | The old app's password sign-in (Breeze), plan section 3 slices 3b and 3f |
| Old passwords | Not imported. Any password hash already in the new database is cleared on the next import | Plan section 3, "Platform import" (`users`) |
| Profile | Name only. The email comes from Google and is read-only. Users can't delete their own account; admins manage accounts (3e) | The old profile page |
| Invitations (3g) | Create the account ahead of the person's first Google sign-in, with no password-set link | The old invitation email with a password link |

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

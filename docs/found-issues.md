# Found issues in ported tools

Bugs found in the old app (`CompanyTools`). Lesley decided on 2026-11-25 that bugs are fixed as they are found, in the pull request that ports the tool. Each fix gets a test that fails on the old behaviour first.

All entries come from reading code, README and context.md on 2026-11-24. None was reproduced by running the app. File paths are in the old app.

Status values:
- `to fix`: fixed when its tool is ported
- `needs input`: the right behaviour needs Lesley's answer first
- `fixed`: done, with the pull request named
- `keep`: Lesley chose to keep it

## Money, customers and duplicate writes

| # | Tool | Issue | Where | Status |
| --- | --- | --- | --- | --- |
| 1 | Team Sponsorship | No server-side "already sent" check. Only the button is greyed out, so a repeat POST creates another real Shopify draft order and invoice email | `TeamSponsorshipController@storeOrder` | fixed in step 10: a request for a team that already has an order (same email and team, either case) is refused before any Shopify call, with the earlier order's ID and date. Test: `TeamSponsorshipFixesTest` |
| 2 | Combined Shipping | `confirm-refund` has no guard against re-posting a finished batch. It relies on the refund service's own local and live checks, which do exist | `CombinedShippingController@confirmRefund` | to fix |
| 3 | Combined Shipping | Canadian Protein thresholds are marked as an unconfirmed TODO in config | `config/combined_shipping.php` | needs input |
| 4 | Team Sponsorship | Shopify REST API version is pinned to 2024-01, likely past Shopify's support window | `ShopifyClient.php` | keep for now (Lesley, step 10): stays at 2024-01 until Lesley asks to move it. Shopify answers a request for an unsupported version with its oldest supported one (**from docs**), so the old app is already running on a newer version in practice. A newer pin can't be checked from development: the stock read can be checked on the server's page, but the draft order and invoice only once writes are on. When it does move: change it, check the stock card, then send one real sponsorship and check it in Shopify |

## Access and accounts

| # | Area | Issue | Where | Status |
| --- | --- | --- | --- | --- |
| 5 | Platform | Deactivating a user deletes their sessions, but Google sign-in always sets a remember-me cookie and nothing checks `disabled_at`, so the user may get straight back in | `UserController.php:145-147`, `GoogleAuthController.php:98` | fixed in 3b: `SignOutDisabledUsers` middleware signs them out on their next request |
| 6 | Platform | Saving a user while a tool is inactive revokes that tool's grant, and removes any non-tool permissions. README says reactivating a tool restores access exactly | `UserController@update` | fixed in 3e. Lesley chose (2026-11-28): the form lists every tool, inactive ones marked, and saving changes only `tool.*` permissions |
| 7 | Platform | An admin can deactivate or demote their own account (only delete is guarded) | `UserController@deactivate`, `@update` | fixed in 3e: an admin can't deactivate their own account (403) or remove their own admin role (form error) |
| 8 | Platform | Any user can change their own email, including to an unclaimed company address that Google sign-in then links to. Self-delete skips the deactivate-first rule and is not logged | `ProfileUpdateRequest`, `GoogleAuthController.php:61-63` | fixed in 3b: email is read-only, self-delete removed, and Google sign-in refuses an email already linked to a different Google ID |
| 28 | Platform | Google sign-in lets any Google account in, personal Gmail included, when no Workspace domain is configured | `GoogleAuthController::isFromAllowedDomain` | fixed in 3b: no domain means nobody gets in |
| 29 | Platform | Without Google's Workspace `hd` claim, the domain check falls back to the email's domain. A consumer Google account can be registered on a company address | `GoogleAuthController::isFromAllowedDomain` | fixed in 3b: the `hd` claim is required |
| 31 | Platform | `routes/console.php` queries `scheduled_tasks` on every Artisan command, so any command fails without a database, `composer install`'s `package:discover` included. The port broke CI run #20 this way | `routes/console.php` | fixed in 3h: registered through `withSchedule`, only for scheduler commands |
| 9 | Listings Editors | The audit column config is described as admin-only, but any tool user can change it | `VendorListingController@auditConfig`, `SellerListingController@auditConfig` | fixed in step 11: saving the columns needs the admin role (403 otherwise), and the settings panel is shown to admins only. Test: `ListingsEditorFixesTest` |
| 10 | Platform | Invitation links expire after 60 minutes (password broker default). This is not documented anywhere | `config/auth.php` | gone in 3g: invitations send no password link (decision 2026-11-27) |

## Data correctness

| # | Tool | Issue | Where | Status |
| --- | --- | --- | --- | --- |
| 11 | Walmart US Auditor | The report download ignores the HTTP status. The known 520 error body parses to zero rows, so the run processes 0 SKUs, skips the fallback, and still writes a catalog score | `WalmartUsApiClient.php:165-175`, `WalmartUsListingAuditService.php:81-101` | fixed in step 5: a non-2xx download, or a report with no SKU rows, falls back to the Insights endpoints. The old app's data shows the bug: daily catalog scores, but no SKU audits after 2026-10-06 |
| 12 | BioSteel App Stats | The social snapshot runs at 23:55 Toronto but stamps the UTC date, which is already the next day | `TeamAppStatsSnapshotSocialCommand` | fixed in step 4: stamped with the Toronto date (`team_app_stats.social_timezone`). Rows the old app wrote keep their old dates |
| 13 | CDQ | If the Anthropic key is missing or a call fails, the listing is scored as having no conflicts and gets full credit | `CdqConsistencyService` | Fixed in step 8 (decision 2026-12-03): an unchecked listing keeps cdq:score's provisional 10, but `cdq_score.consist_checked_at` records a real check, the page and detail show "Not checked", and `cdq:consistency` fails (alert) when Anthropic gives no usable answer. Confirmed live in the old app: no CONSIST defects and an empty `cdq_llm_cache`, so all 1,441 scored listings got the 10 points unchecked. Tests: `CdqFixesTest` |
| 14 | CDQ | The CSV export does not quote ASIN or SKU | `CdqReportController` | Fixed in step 8: every field goes through `fputcsv`. Test: `CdqFixesTest` |
| 15 | SC Listings | Sync only upserts, so SKUs that left the report keep their old status | `SellerListingController` sync | fixed in step 11 (decision 2026-12-06): after a complete report, SKUs missing from it are marked `Removed`, so they leave the picker; audits and edit history stay, and a SKU that reappears gets Amazon's status back. A report without the `seller-sku` column now fails instead of reading the first column as SKUs, which would have marked every real SKU removed. Tests: `ListingsEditorFixesTest` |
| 33 | Team Sponsorship | The Stats export writes team names and emails as typed by coaches, so a team name starting with `=`, `+`, `-` or `@` opens in a spreadsheet as a formula (CSV injection) | `TeamSponsorshipController@exportStats` | fixed in step 10: such cells get a leading apostrophe and open as text (`App\Support\CsvCell`). Test: `TeamSponsorshipFixesTest` |
| 16 | Listings Editors | An edit-history row is written after the real PATCH whatever the result, so rejected submissions appear in the history | `VendorListingController.php:212-219`, `SellerListingController.php:159-166` | keep (decision 2026-12-06): rejected submissions stay in the history, marked REJECTED, and only accepted ones count toward Our Contribution, as the old tests expect. The page's text was what was wrong: it now says every submission is logged with Amazon's answer. Test: `ListingsEditorFixesTest` |
| 34 | This app's comparisons | `RowHasher::normalise` cut every text value to 19 characters (meant for date-times), so the `compare:*` commands couldn't see a difference after a text value's 19th character | `app/Legacy/RowHasher.php` | fixed in step 11: only date-time values are cut to the second. Every earlier comparison was re-run on the local copy of the old database and still matches: Team Sponsorship, US Product Reviews, CDQ, Lost Sales, ASP Tracker. Test: `RowHasherTest` |

## Reliability

| # | Area | Issue | Where | Status |
| --- | --- | --- | --- | --- |
| 17 | Platform | A "Run Now" that throws leaves the run "running", then "stale", with no email | `ScheduleExecuteRunCommand.php:52` | fixed in 3h: a command that throws closes the run as failed (exit code 1, the error in its output) and emails the admins |
| 18 | Platform | `scheduled_tasks` has no unique key, so its `insertOrIgnore` registrations are not idempotent. Failure emails go to disabled admins too | `scheduled_tasks` migration, `ScheduledTask.php:121` | fixed in 3h: `scheduled_tasks.command` is unique, and failure emails skip disabled admins. A failure email that can't be sent is logged instead of breaking the run |
| 19 | Platform | Sending a test mail or a password reset returns a 500 if Gmail fails. A revoked Gmail token stays cached for up to 55 minutes | `MailConnectionController@test`, `PasswordResetLinkController`, `GmailApiTransport` | fixed in 3g: a failed test email is shown on the page, and a rejected cached token is dropped and the send retried once. The password-reset part went with password sign-in in 3b |
| 20 | App Stats, Lost Sales | Date filters are parsed without validation, so bad input returns a 500 | `TeamAppStatsController@index`, `StockoutController` | App Stats part fixed in step 4: malformed dates fall back to the default range and a backwards range is swapped. Lost Sales fixed in step 7: a malformed date is ignored (an unset side means no bound, as before), and the old code already swapped a backwards range. Tests: `LostSalesFixesTest` |
| 21 | Amazon tools | `SpApiClient` has no retry or 429 handling, only fixed pauses. The Image Manager's no-JavaScript audit loop has no pause at all | `SpApiClient::request`, `ImageManagerController.php:201-203` | to fix |
| 22 | VC Image Manager | The image file is stored before Amazon's preview runs, so a rejected upload leaves an orphan file | `ImageManagerController.php:102-152` | to fix |
| 23 | Walmart US Auditor | The audit takes 10 to 20 minutes and runs at 06:00 UTC, so other tasks due at 06:00 wait behind it | `walmart-us:listing-audit` schedule | fixed in step 5, in the scheduler: tasks due in the same minute run shortest first, by their last finished run |
| 32 | Team Sponsorship | A timeout reaching Shopify fails the whole page (500), and a timeout reaching the BioSteel Teams API fails the search: only API errors (`RuntimeException`) are caught, not `ConnectionException`. A blank Shopify store makes a malformed URL, which fails the page too | `TeamSponsorshipController@index`, `@search`, `ShopifyClient` | fixed in step 10: the stock card shows "Couldn't reach Shopify" or "isn't configured", and the search shows "Couldn't reach the BioSteel Teams API". Test: `TeamSponsorshipFixesTest` |
| 30 | Platform | The activity log is configured to keep 365 days, but `activitylog:clean` is never scheduled, so nothing is ever deleted. No row is older than 365 days yet (the oldest is from August 2026) | `config/activitylog.php`, scheduled tasks | fixed in 3h: `activitylog:clean --force` is registered daily at 03:15 UTC |

## Documentation only

| # | Area | Issue | Status |
| --- | --- | --- | --- |
| 24 | Platform | README says logouts are logged; nothing logs them | fixed in 3c ("Logged out") |
| 25 | Platform | README says Google Fonts; the layouts load fonts.bunny.net | to fix |
| 26 | Platform | README says tools use team membership; no tool does | fixed in 3e: Admin > Teams says no tool uses teams yet |
| 27 | Platform | Google sign-in passes `email_verified_at`, which is not fillable and is dropped. This is harmless only because email verification is not enforced | fixed in 3b (set with `forceFill`) |

## Applied without asking

Convention fixes that change no behaviour:

| Issue | Change |
| --- | --- |
| Most tool registration migrations do not clear the permission cache. README says this is required, and missing it caused a production 500 on 2026-09-16 | Every new registration migration clears it |

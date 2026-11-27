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
| 1 | Team Sponsorship | No server-side "already sent" check. Only the button is greyed out, so a repeat POST creates another real Shopify draft order and invoice email | `TeamSponsorshipController@storeOrder` | to fix |
| 2 | Combined Shipping | `confirm-refund` has no guard against re-posting a finished batch. It relies on the refund service's own local and live checks, which do exist | `CombinedShippingController@confirmRefund` | to fix |
| 3 | Combined Shipping | Canadian Protein thresholds are marked as an unconfirmed TODO in config | `config/combined_shipping.php` | needs input |
| 4 | Team Sponsorship | Shopify REST API version is pinned to 2024-01, likely past Shopify's support window | `ShopifyClient.php` | to fix |

## Access and accounts

| # | Area | Issue | Where | Status |
| --- | --- | --- | --- | --- |
| 5 | Platform | Deactivating a user deletes their sessions, but Google sign-in always sets a remember-me cookie and nothing checks `disabled_at`, so the user may get straight back in | `UserController.php:145-147`, `GoogleAuthController.php:98` | fixed in 3b: `SignOutDisabledUsers` middleware signs them out on their next request |
| 6 | Platform | Saving a user while a tool is inactive revokes that tool's grant, and removes any non-tool permissions. README says reactivating a tool restores access exactly | `UserController@update` | needs input |
| 7 | Platform | An admin can deactivate or demote their own account (only delete is guarded) | `UserController@deactivate`, `@update` | to fix |
| 8 | Platform | Any user can change their own email, including to an unclaimed company address that Google sign-in then links to. Self-delete skips the deactivate-first rule and is not logged | `ProfileUpdateRequest`, `GoogleAuthController.php:61-63` | fixed in 3b: email is read-only, self-delete removed, and Google sign-in refuses an email already linked to a different Google ID |
| 28 | Platform | Google sign-in lets any Google account in, personal Gmail included, when no Workspace domain is configured | `GoogleAuthController::isFromAllowedDomain` | fixed in 3b: no domain means nobody gets in |
| 29 | Platform | Without Google's Workspace `hd` claim, the domain check falls back to the email's domain. A consumer Google account can be registered on a company address | `GoogleAuthController::isFromAllowedDomain` | fixed in 3b: the `hd` claim is required |
| 9 | Listings Editors | The audit column config is described as admin-only, but any tool user can change it | `VendorListingController@auditConfig`, `SellerListingController@auditConfig` | to fix |
| 10 | Platform | Invitation links expire after 60 minutes (password broker default). This is not documented anywhere | `config/auth.php` | goes away in 3g: invitations no longer send a password link (decision 2026-11-27) |

## Data correctness

| # | Tool | Issue | Where | Status |
| --- | --- | --- | --- | --- |
| 11 | Walmart US Auditor | The report download ignores the HTTP status. The known 520 error body parses to zero rows, so the run processes 0 SKUs, skips the fallback, and still writes a catalog score | `WalmartUsApiClient.php:165-175`, `WalmartUsListingAuditService.php:81-101` | to fix |
| 12 | BioSteel App Stats | The social snapshot runs at 23:55 Toronto but stamps the UTC date, which is already the next day | `TeamAppStatsSnapshotSocialCommand` | to fix |
| 13 | CDQ | If the Anthropic key is missing or a call fails, the listing is scored as having no conflicts and gets full credit | `CdqConsistencyService` | to fix |
| 14 | CDQ | The CSV export does not quote ASIN or SKU | `CdqReportController` | to fix |
| 15 | SC Listings | Sync only upserts, so SKUs that left the report keep their old status | `SellerListingController` sync | to fix |
| 16 | Listings Editors | An edit-history row is written after the real PATCH whatever the result, so rejected submissions appear in the history | `VendorListingController.php:212-219`, `SellerListingController.php:159-166` | to fix |

## Reliability

| # | Area | Issue | Where | Status |
| --- | --- | --- | --- | --- |
| 17 | Platform | A "Run Now" that throws leaves the run "running", then "stale", with no email | `ScheduleExecuteRunCommand.php:52` | to fix |
| 18 | Platform | `scheduled_tasks` has no unique key, so its `insertOrIgnore` registrations are not idempotent. Failure emails go to disabled admins too | `scheduled_tasks` migration, `ScheduledTask.php:121` | to fix |
| 19 | Platform | Sending a test mail or a password reset returns a 500 if Gmail fails. A revoked Gmail token stays cached for up to 55 minutes | `MailConnectionController@test`, `PasswordResetLinkController`, `GmailApiTransport` | to fix |
| 20 | App Stats, Lost Sales | Date filters are parsed without validation, so bad input returns a 500 | `TeamAppStatsController@index`, `StockoutController` | to fix |
| 21 | Amazon tools | `SpApiClient` has no retry or 429 handling, only fixed pauses. The Image Manager's no-JavaScript audit loop has no pause at all | `SpApiClient::request`, `ImageManagerController.php:201-203` | to fix |
| 22 | VC Image Manager | The image file is stored before Amazon's preview runs, so a rejected upload leaves an orphan file | `ImageManagerController.php:102-152` | to fix |
| 23 | Walmart US Auditor | The audit takes 10 to 20 minutes and runs at 06:00 UTC, so other tasks due at 06:00 wait behind it | `walmart-us:listing-audit` schedule | to fix |
| 30 | Platform | The activity log is configured to keep 365 days, but `activitylog:clean` is never scheduled, so nothing is ever deleted. No row is older than 365 days yet (the oldest is from August 2026) | `config/activitylog.php`, scheduled tasks | to fix in 3h, with the scheduler |

## Documentation only

| # | Area | Issue | Status |
| --- | --- | --- | --- |
| 24 | Platform | README says logouts are logged; nothing logs them | fixed in 3c ("Logged out") |
| 25 | Platform | README says Google Fonts; the layouts load fonts.bunny.net | to fix |
| 26 | Platform | README says tools use team membership; no tool does | to fix |
| 27 | Platform | Google sign-in passes `email_verified_at`, which is not fillable and is dropped. This is harmless only because email verification is not enforced | fixed in 3b (set with `forceFill`) |

## Applied without asking

Convention fixes that change no behaviour:

| Issue | Change |
| --- | --- |
| Most tool registration migrations do not clear the permission cache. README says this is required, and missing it caused a production 500 on 2026-09-16 | Every new registration migration clears it |

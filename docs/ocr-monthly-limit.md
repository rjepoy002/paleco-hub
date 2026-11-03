# Monthly Google Vision application allowance

Default: OCR_MONTHLY_LIMIT=900 potential image-feature units per Asia/Manila calendar month. No .env credential changes are necessary. Optional override: a whole number from 0 through 1000000; 0 disables uncached requests. Invalid values fail closed. The allowance is application-wide across users, web sessions and CLI processes sharing this database, not per browser, user or credential. All deployments that must share one allowance must use the same database and consistent limit configuration.

## Database and deployment

Migration 005_ocr_monthly_usage creates one InnoDB table: ocr_monthly_usage, keyed by YYYY-MM, with units_reserved and updated_at. Run php bin/migrate.php on other deployments. It is additive and preserves announcements, history, OCR caches, authentication and existing WordPress integration records. No destructive migration or credential change is needed. Do not delete/reset the current month row to recover from a timeout or failed request.

There is no retrospective Google Cloud import. Tracking begins at rollout; the initial month starts at zero unless an administrator reconciles earlier app usage. Before enabling OCR in a project already used this month, reconcile prior usage against Google Cloud reporting. This counter is conservative application accounting, not a replica of the provider's billing records.

## Units and metered request path

Google's [Vision pricing](https://cloud.google.com/vision/pricing) counts each feature applied to each image as a billing unit; document pages are separate images in file APIs. Current Hub code validates JPG/PNG/WebP, then sends exactly one image and one DOCUMENT_TEXT_DETECTION feature to images:annotate, consuming one reserved unit. maxResults and returned words/objects are not multipliers.

GoogleVisionTransport::annotate is the shared dispatch gate for web selection scans, saved-poster/draft scans, publication validation and bin/check-ocr.php. It derives the reservation from the outgoing requests/features payload immediately before the annotation HTTP call. Multiple image entries are summed; multiple requested OCR features are counted conservatively even if Google suppresses one feature in a particular combination. Unknown features or a different top-level API payload are rejected before dispatch and need billing review before being enabled. PDF/TIFF/file annotation APIs are not supported here and must not be added without page-aware accounting. OAuth token acquisition is not an image OCR unit and occurs before reservation.

Every annotation attempt reserves again, including manual retry after a timeout, HTTP error or malformed response. Reservations are never refunded. The production HTTP client has no automatic retries and annotation redirects are disabled; future retry middleware must not hide attempts behind one reservation. A crash between reservation and dispatch can overcount, intentionally. Never move the reservation after sending or after response parsing.

The gate uses a dedicated autocommit database connection, independent of publishing/draft transactions. It creates the month row if absent and executes one conditional UPDATE that increments only if sufficient allowance remains. Concurrent processes serialize on that row. Missing/unavailable tracking or invalid configuration prevents annotation dispatch and returns a safe manual-entry message. An in-flight request is attributed to its reservation month, even if its response arrives after midnight.

The month comes from the database UTC clock converted to UTC+08:00 (Asia/Manila), independent of browser/PHP/host timezone and MySQL timezone-table installation. The next month uses a fresh row automatically, with no cron or deletion of previous months.

## Cache and manual fallback

PosterOcr cache hits return before the Google transport and reserve zero additional units. Successful/missing-text parser cache entries remain reusable. Unavailable/quota failures are not cached, so they cannot keep the next month's allowance disabled after rollover. Each genuine provider retry reserves again. Already-recognized attachment results are also reused by the existing publication workflow without calling Vision.

When allowance is exhausted, uncached annotation calls stop and each affected poster receives an actionable unavailable result. Manual date entry and generated titles still work; existing conflict/weekday safeguards remain. Cached recognition continues to work even with zero allowance. The Scheduled interface shows month, used, limit and remaining on page load and refreshes these from scan responses. This is a snapshot; reload for changes made by another tab/CLI or after an aborted browser response. Form reset does not change the counter. The CLI prints the shared usage snapshot; preflight without a poster performs no annotation or reservation.

## Tests and manual verification

php tests/run.php --browser uses isolated temporary databases, fake WordPress/Vision, Guzzle's mock HTTP handler and parallel PHP reservation workers. No Google Vision requests are sent. Coverage includes default limit, image-feature accounting, unknown feature rejection, successful/failed/timed-out attempts, quota denial before dispatch, independent transaction durability, cached scans at exhaustion, manual fallback, exact Manila month rollover, retained historical rows, zero/invalid limits, and 100 attempts across five workers sharing a 25-unit limit. UI and existing publication/reset/history/partial/sharing-helper tests remain in the suite.

On localhost, verify the usage snapshot in Scheduled and php bin/check-ocr.php. On an isolated staging database with approved credentials/posters, set a small allowance, verify an uncached scan consumes one unit, the same image's cached scan consumes none, and another uncached image falls back to manual at exhaustion. Confirm manual publication still succeeds and form reset preserves history and the usage total. Only an explicitly supplied real poster to the CLI sends a Google Vision request; that attempt shares the allowance. Verify the configured month/timezone/allowance on all deployments. Do not exhaust or reset production counters just to test this feature.

**This is not a guaranteed Google Cloud billing cap.** Other applications, projects, APIs, deployments using a different database, pre-rollout requests, and non-Vision resources can incur charges independently. The Asia/Manila application month does not claim to reproduce Google's billing-period boundaries or free-tier accounting. Keep provider-side quotas, billing monitoring and budget alerts in place.

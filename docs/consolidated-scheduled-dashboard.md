# Consolidated scheduled interruption and dashboard update

Implemented incrementally in the existing PHP/MySQL app. Authentication, roles, multiple uploads, draft/publish actions, existing post/media identities, category mappings and the Partial Power Interruption Update workflow remain in place. No live WordPress write or Google Vision request was performed during implementation or tests.

## Poster duplication

The old payload set the first uploaded poster as `featured_media` and also inserted it as the first body `<img>`. A post template that displays featured media above its content consequently displays the first poster twice. The public site's assets identify Astra 4.14.1 with Elementor-related rendering; public scheduled pages show featured-image references in Elementor image widgets and related-post thumbnails. The exact stored Hub post could not be inspected successfully: its read-only REST request returned 404. Its private preview must therefore still be checked manually; no replacement post was created.

`SCHEDULED_FEATURED_DISPLAY=theme` is the default for the reported display behavior. Every image is uploaded/reused and associated with the same post; the first remains featured, and body figures start with the second image. Together the theme's featured image and body images contain each poster once, in order. A single-poster announcement uses only the featured image plus Hub's ownership marker. Saving/updating replaces the generated body rather than appending to it. IDs and pending-operation reconciliation are preserved.

For a different template that **does not display featured media**, deliberately set `SCHEDULED_FEATURED_DISPLAY=content`; the body then contains all posters. Validate the selected mode against the actual single-post preview. It is unsafe to infer every per-post Elementor layout from the theme name alone. No global theme options, WordPress templates or unrelated related-post thumbnail widgets were modified. Existing posts are updated only by an authorized explicit save/publish action.

## OCR provider and configuration

Google Cloud Vision is the single production provider, using `DOCUMENT_TEXT_DETECTION` over its HTTPS REST API. The official `google/auth` library supplies server-side service-account authentication; Composer pins its dependencies. No browser OAuth token, Google Sign-In secret or API credential is exposed to JavaScript.

**Current installed state: OCR_PROVIDER defaults to none; no Vision credential file is configured. Google Vision has not been tested live and OCR is not operational here yet.** Mocked OCR proves the integration/parser/UI behavior, not production provider availability. Manual date entry works in this state.

Administrator setup:

1. Choose an authorized Google Cloud project, enable Cloud Vision API and billing, and set quotas/budget alerts.
2. Create a dedicated service account with API-use permissions appropriate to that project. Follow [Vision authentication guidance](https://docs.cloud.google.com/vision/docs/authentication), including Service Usage Consumer (`roles/serviceusage.serviceUsageConsumer`) when required for API/quota use. Do not grant project-wide Owner merely to enable OCR.
3. For this XAMPP installation, store a service-account JSON file outside **all of `C:/xampp/htdocs`**, for example `C:/xampp/secure/paleco-vision-service-account.json`. Restrict filesystem access to the application service account/administrators. Never put the file in the repo, public assets or uploaded posters. The implementation refuses files inside the web root and validates the credential type and Google token endpoint. Use managed/workload identity rather than long-lived keys when adapting to a hosted Google environment; that credential mode is not implemented in this local-file adapter yet.
4. Edit `.env` locally:

   ```dotenv
   OCR_PROVIDER=google_vision
   GOOGLE_APPLICATION_CREDENTIALS=C:/xampp/secure/paleco-vision-service-account.json
   SCHEDULED_FEATURED_DISPLAY=theme
   ```

5. Run `composer install` on other checkouts, open Scheduled Power Interruption, and select an approved sample poster. Check the per-poster result before relying on automatic dates. No public publishing is necessary to test OCR. Reopen an existing draft's editor to scan its saved files after configuring the provider.

Google bills by image/feature units. At the pricing page checked during implementation, Document Text Detection has the first 1,000 monthly units free, then USD $1.50 per 1,000 units through the next pricing tier; currency/tier rules and other Google services can affect cost. Check [current Cloud Vision pricing](https://cloud.google.com/vision/pricing) before enabling billing. Each uncached distinct image can incur a unit. Authentication credentials/billing are independent of the existing Google Sign-In Web client.

Provider requests use bounded connect/total time, TLS verification and no redirects. Timeout, credential, quota, annotation and unsupported/large-request failures produce sanitized unavailable statuses and manual fallback. Provider exception bodies, tokens and private paths are not logged or returned to the browser. Large inline requests can be rejected by provider limits even though Hub accepts the local image; use manual fallback or smaller optimized posters in that situation.

## Detection, validation and interface

On file selection, `/scheduled/recognize` receives an authenticated CSRF-protected multipart request and scans each selected/kept poster server-side. Saving reuses the scan cache and stores results per attachment: detected date, candidate dates, validation status, heuristic confidence, relevant OCR text, image hash, provider and a safe message. OCR cache data is private database content, never service-worker cached.

The parser prioritizes the date immediately associated with **Interruption Date:** or **Date of Interruption:**. It recognizes full/abbreviated English month dates and month/day/four-digit-year numeric dates. Calendar-invalid dates are discarded; a printed weekday is checked when present. Upload/publication/issued-date labels are excluded. The image filename and today's date are never date inputs. Confidence is a rule/validation score, not a claim of Google model accuracy.

All valid identical dates allow automatic filling. Different valid dates, multiple interruption candidates or weekday mismatches produce per-poster warnings and block WordPress submission until the user corrects/removes the conflicting selection. Missing/unavailable/uncertain OCR requires valid manual date entry; it does not silently approve an automatically chosen date from another poster. A manually entered correction is retained for a consistent/unreadable set. Conflicting sets cannot be bypassed by posting an old checkbox value.

Both old date-confirmation checkboxes and their required frontend/backend checks were removed. No replacement confirmation step was added. The existing **public publication** confirmation modal is unchanged.

New generated titles are `Scheduled Power Interruption [October 25, 2026]`. Changing a date updates a generated title. Customized titles remain intact; clearing the title explicitly resumes generation. Existing saved titles are not renamed merely by opening a page or applying the migration. Explicit edits and publishing actions reuse existing WordPress IDs.

Image-hash/provider/parser cache keys avoid repeated OCR for unchanged files across preview and save. Successful/missing-text results are cached for up to a year, while unavailable results retry after a short TTL. Concurrent scans share a database lock. A future parser-version change may invalidate cached parsing. No second OCR provider was added.

## Year tags

Scheduled WordPress drafts/publications resolve the chosen interruption year as a tag. Hub first queries the exact slug, then exact names within search results (including tags with customized slugs). Ambiguous results block creation. A missing tag is created through `POST /tags`; a destination/year lock and persisted pending creation marker protect retries. An accepted-but-timed-out creation is reconciled by lookup. If the outcome cannot be verified, no blind repeated creation or post write occurs.

Each announcement tracks the auto-managed tag ID/year. Post updates read existing tag IDs, remove only the previous auto-managed ID, retain unrelated manual tags, and include the new year tag. The pending year is finalized with the accepted post result, including during post reconciliation. Errors are actionable and never cause a fallback category or unrelated-tag wipe. The WordPress account must have permission to create terms when a new year tag is needed; administrators can pre-create the exact year tag if the publishing account cannot.

Scheduled category remains INTERRUPTIONS; Partial category remains POWER_ADVISORY. No numeric category or tag ID is hardcoded in production logic.

## Unified History and dashboard reset

The separate Active drafts and pending work section was removed. The main History list includes visible history entries and retained active drafts, unsynchronized edits and uncertain work, even if their older history entries were cleared. Existing actions, status badges, local/WordPress drafts, explicit publication, individual Delete and Administrator Clear History remain. Already-hidden history entries are marked as retained work rather than presented as newly deletable history. Underlying announcements, files, IDs and audit records are not deleted.

Clear History shares one database transaction with a persistent reporting epoch increment and UTC reset timestamp. The confirmation snapshot includes record versions and the epoch, so stale confirmations are refused. Merely applying this migration does not clear history or increment the reporting period.

All five dashboard cards use the same reporting-epoch filter and are labelled **Since Last History Reset**:

- Total Announcements
- Published
- WordPress Drafts
- Local Drafts
- Failed / Pending

**Counting rule:** count unique announcements that have a meaningful local save or publishing transition in the current period, classified by their latest state. A pre-reset draft does not count until it is edited or a new publishing operation is attempted afterward. It then counts once, not once per retry/event. An unsynchronized local edit counts as Local Draft; accepted publication moves that same announcement to Published. Failures/in-flight operations count as Failed / Pending. Exact idempotent replay of an already successful partial request does not create new activity. Historical event/audit rows are not blindly counted as separate announcements.

Recent dashboard activity uses the same epoch rule. New local saves restore their History entry so another reset remains available. Old activity stays excluded after refresh. Retained drafts can remain visible in History while all dashboard counters are zero, because those cards explicitly describe post-reset activity rather than all stored records.

## Migration and installed verification

Migration: `004_scheduled_ocr_reporting` adds per-image OCR metadata, a private OCR cache, automatic-year-tag state, date-source metadata and reporting fields/settings. Original values in existing tables were verified unchanged. A private pre-migration snapshot is stored at `storage/private/pre-consolidated-20261028-015006.json`. The migration is already applied locally, with reporting epoch 0 and no history reset timestamp; no actual history cleanup was invoked.

Other deployments:

```powershell
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/migrate.php
```

Back up first. Do not reset/import over the database. Existing single/multi posters, WordPress IDs, credentials, mappings and pending operations remain compatible. New environment examples contain no real secrets. Google Auth and its JWT dependency were installed with current Composer advisory checks enabled; no security-policy bypass was used.

## Tests and manual verification

```powershell
composer test
php tests/run.php --browser
composer validate --strict
composer audit --no-interaction
```

Tests mock both WordPress and Vision, use a reserved `.invalid` WordPress endpoint and isolated temporary databases, and explicitly neutralize production OCR environment credentials. Coverage includes existing auth/partial/multi-poster behavior, theme-plus-body image uniqueness, repeated draft saves, bracketed/custom titles, year creation/reuse/custom slugs/manual-tag preservation, accepted API timeouts, labelled dates/weekday validation, conflicting/missing OCR, manual correction, removed checkboxes, unified History, atomic reset, reload persistence, new and old-draft post-reset activity, and preserved remote synchronization.

The completed full browser run passed **239 checks**. PHP/JavaScript syntax checks, Composer validation and the dependency security audit also passed.

Manual checks still required:

1. Configure Vision, verify a real poster's labelled date and weekday, then test a mismatched/unreadable set. Do not call the provider operational until this succeeds. Confirm the service account/billing quota and costs.
2. On staging or a real approved advisory, check the actual selected WordPress single-post template. In theme mode the first poster must appear as featured and the body must begin with the second. If the template hides featured media, use content mode instead. No global theme change is needed.
3. Verify the stored post mapping that returned 404. It may reflect an unavailable/deleted/blocked endpoint or record; do not clear its IDs or create a replacement blindly. No post recreation was attempted.
4. Check year-tag creation permissions on the configured account and preservation of a manually assigned tag during an explicit date/year update.
5. On a physical phone, inspect automatic/manual date feedback, title edits, WordPress Draft and Publish controls, and the unified History/dashboard experience. Clear only intended local history as Administrator after reviewing its count; verify counters stay zero on reload while drafts remain accessible.

No automated live publication or GitHub push was performed. OCR currently remains in manual-fallback mode on this installation.

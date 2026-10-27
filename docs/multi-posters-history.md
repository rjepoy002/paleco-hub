# Scheduled multi-poster uploads and local history management

The existing MVC application, authentication, categories, WordPress API client, explicit confirmation flow and publishing locks are retained. Partial Power Interruption Update behavior is unchanged.

## Scheduled posters

The scheduled form accepts ordered JPG/JPEG, PNG and WebP selections with a default maximum of **10** images and **8 MB per image**. Actual MIME, image headers, dimensions and file size are validated on the server using the existing upload validator. Local files use random extensionless names and authenticated image delivery. Invalid batches are rejected before records/files are committed.

The interface shows responsive thumbnails, a selected count and a Remove button for each new selection. Additional selections and drag/drop append in order. Kept existing posters appear first; newly selected files follow them. Existing poster removal is explicit on save; removed files are retained privately rather than deleted while they may still be referenced by an announcement, an audit or WordPress. Changing poster selection/date resets confirmation.

There is one interruption date and an editable `Scheduled Power Interruption October 30, 2026` style title. Every save requires confirmation that all posters correspond to that date. OCR remains unconfigured, so users must read and confirm the date manually. When a RecognitionEngine supplies conflicting/multiple dates, the backend requires a separate explicit date-conflict confirmation. Existing and newly selected posters are checked. Dates are never inferred from filenames or the current date.

Buttons remain available:

- **Save Local Draft** saves the announcement and attachments locally and makes no WordPress requests.
- **Save as WordPress Draft** saves locally, uploads the posters and creates/updates one remote post with draft status. It never requests public publication. On remote failure, the edit/review link retains the saved attachment identities for retry.
- **Publish to WordPress** saves locally and opens the existing saved review screen. Public publication still requires its explicit confirmation modal.
- **Cancel** navigates back to Publishing History.

Each accepted WordPress media ID and URL is stored immediately on its individual attachment. Content contains all images in attachment order, and the first image is featured. All media are associated with the same post. Previously synchronized WordPress drafts/posts reuse their existing post IDs. Successful uploads are reused after a later upload failure; uncertain uploads reconcile by their persisted slug/marker before another creation. Poster IDs, content and ownership/destination protections remain durable across retries.

## Non-destructive migration

Run `php bin/migrate.php` on another checkout/deployment before using these screens. Migration `003_multi_posters_history`:

- Creates normalized `announcement_attachments` with announcement FK, safe storage name, MIME, order, content hash, per-image WordPress ID/URL and soft-removal timestamp.
- Backfills existing single posters and their known media identities without changing original announcement values. Original first-image fields remain as a compatibility pointer. Legacy single-image callers are also supported at runtime.
- Adds `history_hidden_at/history_hidden_by` to announcements and `deleted_at/deleted_by` to publishing events.

The migration has already been applied to the installed local database after saving a private pre-migration snapshot in `storage/private/pre-multi-posters-20261027-071856.json`. All original values in existing application tables were compared and preserved. No existing local history was hidden during implementation and no production data was removed. Schema migration metadata and new attachment rows are the only additive setup changes.

The existing migration runner is rerunnable, checks existing columns and records applied versions. Fresh `bin/setup.php` applies all migrations. Do not reset/import over the installed database as an upgrade procedure.

## Upload configuration

`MAX_POSTERS=10` is optional in `.env`; the default is 10. The application clamps this to 1–30 and PHP's `max_file_uploads`. No local `.env` credentials/settings were rewritten.

The project `.htaccess` sets `upload_max_filesize=8M` and `post_max_size=85M` under XAMPP's PHP Apache module, allowing the default ten-image batch plus form overhead. For PHP-FPM or a different web server, set equivalent per-site values in that runtime configuration. PHP `max_file_uploads` must be at least the configured poster count. If increasing MAX_POSTERS above 10, increase the total request limit accordingly; consider server request/execution time limits for larger remote batches. Safe reconciliation retains accepted media when an interrupted request must be retried.

## Delete and Clear History

Publishing History displays announcement summary entries and individual publishing events. Both have a red **Delete** action where authorized. Administrators may hide any entry; Publishers may hide only entries they own/acted on. The server enforces this independently of the UI.

Delete uses the requested confirmation text: “Are you sure you want to delete this publishing history record? This action cannot be undone.” The same modal explicitly states that WordPress posts/media are not deleted. Cancelling makes no request. Confirmed deletion is POST-only and CSRF-protected, uses a transaction, records the actor/time and appends an audit entry. Repeated deletion is a safe no-op.

**Clear History** is Administrator-only and shows the current number of local entries: announcement summaries plus publishing-event entries are counted separately, including older entries beyond the first displayed page. A signed snapshot prevents clearing a changed set using a stale confirmation. If history changes while the modal is open, refresh and confirm the new count. Clear requires explicit confirmation and POST/CSRF, then reloads the page with success feedback and no stale history rows.

Deletion is deliberately local soft deletion. It does not remove announcements, drafts, poster files, remote post/media mappings, synchronization keys, pending payloads, remembered operation identities, publishing-attempt audits or security audit logs. No WordPress deletion API exists in this feature. Hidden active drafts, unsynchronized edits and uncertain/pending work remain accessible in **Active drafts and pending work**. A new publishing result reappears in History as new activity; previously hidden event entries remain hidden.

The irreversible wording refers to the available UI: no restore action is provided. Retained tombstones/audits support integrity and duplicate protection, not a destructive data wipe. Deleting a summary does not silently delete every separate event entry; Clear History handles both entry sets explicitly.

## Tests and manual checks

```powershell
composer test
php tests/run.php --browser
```

The full browser run passed **197 checks**. All WordPress calls in tests use the fake transport and reserved `.invalid` endpoint in random isolated test databases. Coverage includes one/two/five uploads, file removal/order/counter, invalid MIME/oversize/count rejection, local and WordPress drafts, multi-image publication/featured image/association, partial failures and accepted-upload timeouts, legacy backfill, date conflicts, individual history deletion/cancellation, stale clear counts, admin clear, Publisher denial, CSRF/POST enforcement, retained files/remote IDs/audits and continued draft synchronization. Existing partial-update/authentication tests remain in the suite.

Screenshots: `storage/screenshots/multi-posters-mobile.png` and `cleared-history-mobile.png` contain fictitious test data. Runtime does not require Playwright/Node.js. Physical phone selection/removal remains a manual check.

For manual verification, select several real posters for the same date, remove one, confirm the date, and save locally. Verify ordered review thumbnails and that Save as WordPress Draft remains visible. Use staging WordPress for samples; only publish a real approved advisory to the live PALECO site through the existing explicit confirmation. No live WordPress requests or sample posts were made by implementation/automated testing, and no GitHub push was performed.

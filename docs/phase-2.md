# Phase 2 — WordPress publishing

Implemented October 27, 2026 (Asia/Manila), extending the existing app in place. The earlier `phase-2-checkpoint.md` records the paused inspection and is historical context.

## Reused components

The front controller/MVC routing, PDO model, approved Google accounts, role/session checks, CSRF, official branding, responsive layout, title detection, poster preview, private upload directory, encrypted WordPress settings, category mappings, audit log and publishing-history table were retained. `WordPress::get()` remains compatible with settings testing and now delegates to the extended client. No DocRoute files or database records were changed.

The GET-only client became `WordPressClient` plus an injectable `WordPressTransport`; production uses `CurlWordPressTransport`. `Publisher` orchestrates remote work, `Drafts` handles local creation/editing, and `AnnouncementLock` shares serialization between edits and remote actions. The controller accepts a Publisher dependency so the test-only router can inject a fake without test flags or bypasses in production routes.

## Non-destructive migration

Migration: `database/migrations/002_wordpress_publishing.php`. Runner: `php bin/migrate.php`. Fresh setup applies it automatically. Existing data is neither reset nor replaced. `schema_migrations` records application, and a database advisory lock serializes migration execution. Existing-column checks make partially completed MySQL DDL rerunnable.

Added announcement fields:

| Fields | Purpose |
|---|---|
| revision, updated_at | Local edit version and stale-form rejection |
| wordpress_post_id, wordpress_post_url, wordpress_status | Accepted remote post identity and result |
| wordpress_destination | Original REST root; prevents ID reuse on another destination |
| wordpress_media_id, wordpress_media_url, wordpress_media_hash | Verified poster reuse and replacement detection |
| remote_key | Random persistent slug/ownership marker for reconciliation |
| synced_revision, wordpress_saved_at, published_at | Last synchronized version and recorded timestamps |
| last_publish_attempt_at, publish_error | Last attempt and safe error summary |
| uncertain_stage, pending_payload | Durable post/media/attachment operation pending verification |

The new `publishing_attempts` table records announcement, actor, requested action, revision, start/finish times, outcome, safe message and remote post ID. The existing `publishing_history` table still records successful/failed results; existing history remains intact. No passwords, authorization headers, OAuth tokens or raw remote error bodies enter either table. Pending payloads contain announcement data/remote identity only.

The installed database was backed up to a private ignored snapshot before migration. All original rows/column values were compared afterward, including the encrypted connection record. Counts remained 1 user, 1 announcement and 0 publishing events/attempts immediately after implementation; automated fixture data never entered that database.

For another installation, first export the database through phpMyAdmin and back up `.env`/private uploads securely. Run `composer install`, then `php bin/migrate.php`. A database backup plus its original application key is required for credential recovery. There is no destructive down-migration. If reverting code, retain added columns/tables; do not rerun a schema reset. The protected local JSON snapshot contains CREATE statements and rows for inspection/recovery; a normal SQL export remains the preferred portable operational backup.

## API integration

Configured REST root: `https://paleco.net/paleco/wp-json/wp/v2`. The saved username and encrypted Application Password are reused server-side with HTTP Basic authentication over HTTPS.

| Request | Purpose |
|---|---|
| GET /users/me?context=edit | Verify identity and edit_posts/publish_posts/upload_files as required |
| GET /categories/{id} | Revalidate an existing verified mapping |
| GET /categories?slug=... | Resolve one exact configured slug when no ID is saved |
| POST /posts | Create a WordPress draft or published post |
| POST /posts/{id} | Update the existing WordPress draft/published post |
| GET /posts/{id}?context=edit | Verify ownership or reconcile a known-ID write |
| GET /posts?context=edit&status=any&slug=... | Reconcile uncertain creation using its random persistent key |
| POST /media | Upload a validated poster with a deterministic per-poster slug and ownership/hash marker |
| GET /media?context=edit&slug=... | Reconcile uncertain media creation |
| GET /media/{id}?context=edit | Check poster attachment |
| POST /media/{id} | Associate the uploaded media with the accepted post |

Post payloads specify title, safely escaped multiline content, exact category ID, desired status and persistent slug. Poster payloads also include featured_media and an image figure. A raw HTML comment retains the random Hub ownership marker; the media description retains its corresponding hash marker. Text is treated as text, never trusted pasted HTML. API field validation follows the [WordPress posts reference](https://developer.wordpress.org/rest-api/reference/posts/) and [media reference](https://developer.wordpress.org/rest-api/reference/media/).

Partial updates retain POWER_ADVISORY; scheduled interruptions retain INTERRUPTIONS. Existing saved category IDs are verified against their configured exact slug/name. An unresolved mapping requires one exact slug match, not a partial-name search. Settings retain verified IDs when both mapping and destination are unchanged. No categories are created; invalid/changed mappings block writing rather than falling back to Uncategorized. Category IDs in tests are fake fixtures, never production constants.

Responses must contain valid IDs, expected status/slug, retained raw title/content, required category, safe same-host HTTPS links, and featured-media identity where required. Media responses must match their ownership marker/MIME. HTTP 401/403/category failures, response errors and network timeouts produce fixed actionable summaries; arbitrary remote error text is never surfaced or logged. The cURL transport reuses HTTPS/public-DNS checks, pins the validated destination, disables redirects, verifies TLS, limits responses to 2 MB, and bounds connect/total time to 8/30 seconds per request. Every API path is restricted to the supported endpoints.

## Actions and confirmation

- **Save draft** creates/updates only the local row and private poster. **Save and review for WordPress** also stays local, then opens the saved announcement.
- **Save as WordPress Draft** sends `status=draft`. Existing post IDs are updated, and a public post cannot be demoted through this action.
- **Publish to WordPress** opens a native confirmation dialog with the saved title, mapped category and text/poster preview. The user must check the explicit authorization box and submit Confirm and publish. The server separately requires confirmation, CSRF, an active Administrator/Publisher, the expected revision and a signed review token.
- The signed review binds local content/revision to current connection and category settings. Stale edits/settings invalidate it. Editing an existing record requires its current revision and the same lock as publication.
- Once public, **Publish update to WordPress** uses the same confirmation workflow and `/posts/{id}`. Local saves leave the remote post untouched and show local changes pending.
- History/review display Local Draft, WordPress Draft, Publishing, Published or Failed, plus safe errors, attempt outcomes, remote IDs and View on WordPress links. WordPress drafts may require a WordPress-authenticated browser to open their link.

## Duplicate prevention and recovery

MySQL `GET_LOCK` prevents overlapping edits/publications for the same announcement across PHP processes. Repeated successful requests for the same synchronized revision/status reuse the existing result without another remote call. Per-record remote IDs are bound to the original REST destination, and recorded post content must retain Hub's ownership marker before updates.

Before a post or media creation/update is sent, Hub commits a durable pending stage/payload. If a timeout, crash, server error or malformed response obscures the outcome, the row remains failed/pending and editing is blocked. A retry first verifies the existing post by ID or by exact random slug plus ownership/content checks; media uses its exact random slug/hash marker. Only positive verification marks the operation complete. An empty result never proves a delayed request failed and never authorizes a second creation.

The accepted post ID is saved before media attachment. Attachment retries inspect/update that known media/post association, which cannot create another post or media object. Definite validation/auth/permission rejections of a new write permit a corrected retry; a failed read or failed authentication while reconciling does not erase earlier uncertainty.

Retries are explicit user actions, not background jobs. Recovery of an earlier operation returns that operation's actual status; it does not automatically change a recovered WordPress draft into a publication merely because a later button requested another status. Review and confirm the next action separately.

If reconciliation cannot verify exactly one result, an administrator must inspect WordPress using the remote key/post/media IDs and audit trail. Do not duplicate the local announcement or clear pending markers as a workaround. No automatic repair/destructive reset UI is included. Plugins that alter raw content/ownership markers, externally deleted posts and changed destinations can require operator intervention. Post updates with an existing ID remain safe from duplicate creation, but Hub conservatively blocks uncertain mismatched results rather than overwriting them without review.

## Automated verification

`composer test` runs baseline and publishing tests in a temporary random MySQL database. `php tests/run.php --browser` adds two Chrome workflows and currently passes 119 checks. Test databases, private test posters, mock state and seeded session files are cleaned up afterward. The fixture transport has no network implementation and rejects every destination except a reserved `.invalid` URL. Browser publishing QA blocks browser requests outside its isolated app origin. No automated test calls the live PALECO WordPress API.

Coverage includes all requested scenarios: local-only saving; WordPress drafts; direct publication; same-post draft/public updates; both exact categories; poster uploads, featured media and attachment; invalid categories/auth/permissions/network responses; repeated requests; unauthorized direct POSTs; retained IDs/URLs; and legacy draft access. Extra checks cover CSRF, missing/stale/tampered confirmation, lock contention, uncertain post creation/update, malformed JSON, lost media/attachment responses, absent-result retry blocking, changed destinations, unsafe links/file contents, migration reruns and attempt records.

Browser QA covers text/poster publication, modal confirmation with title/category/preview, editing and republishing with the same ID, login/assets, responsive navigation/forms, and review/dialog widths at 360, 390 and 768 pixels. Screenshots in `storage/screenshots/phase2-review-*.png` show fictitious data. Physical Android/iOS Safari verification remains manual.

## Manual testing

Use a staging WordPress installation for sample posts. Changing Hub's connection does not move existing remote-linked announcements; use new local drafts for that staging destination. Restore the original connection before updating/reconciling announcements already linked there.

1. Sign into Hub as Publisher or Administrator. Check WordPress settings and the verified category IDs. Connection testing uses GET only.
2. Create a text advisory with its original title, blank lines and literal HTML characters. Save locally; confirm no remote post exists. Open its review screen, edit it, and verify preview and saved text.
3. Choose Save as WordPress Draft. Check its ID/status/time and that the WordPress post is a draft in POWER_ADVISORY. Repeat the action and confirm the same ID is retained.
4. Publish through the modal and explicit checkbox. Check title/category/preview before submission. Confirm the same draft ID becomes public and View on WordPress opens its post.
5. Edit locally and verify WordPress has not changed yet. Confirm Publish update and verify the existing ID receives the changes. Try a stale review from another tab; it must be refused.
6. Prepare a real poster with a confirmed date. Verify its preview/title, save locally, then explicitly send a remote draft or publication. Check the Media Library item, post association, featured image, embedded poster, and INTERRUPTIONS category.
7. In staging, try missing categories, invalid credentials and insufficient permissions; verify clear failure summaries and no Uncategorized publication. Use mocked tests for timeout/replay/concurrency scenarios rather than disrupting the production website.
8. Review failed/uncertain attempts and retry only through the review screen. Verify actor, requested action, start/finish time, outcome and remote ID. Confirm ordinary users cannot invoke `/publish` and that CSRF is required.

No sample posts were automatically published. A real PALECO advisory must be published by an authorized user after reviewing it. This live end-to-end check remains intentionally unperformed.

## Remaining issues

- OCR engine integration is still pending; manual date entry/confirmation remains mandatory and dates are never guessed. Multiple-date OCR candidate selection belongs to that integration.
- Off-host/CDN media links and plugins that rewrite raw payloads are rejected conservatively. Add a reviewed explicit allowlist/normalization contract if the deployed WordPress site requires them.
- There is no automatic retry/repair worker, import of arbitrary external posts, remote deletion/unpublication, or automatic cleanup of orphaned/replaced remote media. Partially accepted remote writes can remain blocked pending operator investigation.
- Production operational hardening from Phase 1 (TLS, least-privilege accounts, rate limits, scheduled session cleanup, scanning/re-encoding, key rotation and monitoring) remains applicable.
- No Git commit or push was performed. Existing user changes outside the listed implementation files were preserved.

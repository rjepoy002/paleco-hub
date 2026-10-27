# Phase 2 restart checkpoint

Saved Friday, October 23, 2026 (Asia/Manila). The user requested a stop by 5:00 PM and continuation Monday, October 26. Resume only when the user returns and requests continuation. Phase 2 implementation is NOT complete.

## Request and boundaries

Original detailed request: `C:/Users/r.g.padrones.paleco/.codex/attachments/6181aa9b-fc0b-4486-9952-f64c1556cfa2/Pasted text.txt`.

Upgrade the existing application in place: local drafts with editing, WordPress drafts, explicitly confirmed publication, remote post updates, poster media uploads, reliable status/history, non-destructive migrations, concurrency control, and reconciliation after uncertain API outcomes. Preserve existing users, drafts, credentials, settings, Google authentication, CSRF, and interface. Do not modify DocRoute v2, push to GitHub, or create test posts on live WordPress. All automated WordPress tests must use mocks.

## Inspection completed

- Plain PHP 8.2 MVC structure, Composer PSR-4 autoloading, MySQL/MariaDB, Bootstrap CSS and vanilla JavaScript. No project-level AGENTS.md found; vendor instructions are irrelevant unless editing vendor (not planned).
- `app/Controllers/HubController.php` handles routing, auth, role restrictions, local draft INSERTs, history, WordPress settings/testing, and private image delivery. No announcement edit route exists yet. New drafts always redirect to history.
- `app/Services/WordPress.php` supports GET only. Reuse its AES-256-GCM encryption and HTTPS/DNS/public-address validation, DNS pinning, no-redirect policy, size/time limits, and server-side credentials. Extend behind a mockable transport/client boundary; do not expose API credentials or raw remote bodies/errors in logs/UI.
- `Auth.php` rechecks user active status and role on requests. Roles: Administrator and Publisher. Route-level authorization for new remote-write actions must be explicit, server-side, and CSRF-protected.
- `Announcement.php` detects original advisory titles and generates Manila-time fallbacks. Scheduled title currently includes square brackets; Phase 2 example omits them. Existing stored titles must not be changed silently.
- `RecognitionEngine` is modular, with an unconfigured implementation returning no dates. Posters currently require manual date and confirmation. No OCR engine is configured; avoid inventing dates.
- Poster uploads already enforce MIME/header validation, size <= 8 MB, <=40 megapixels, random extensionless private storage, and authenticated image delivery. Reuse these protections; retaining an existing poster during editing needs support.
- `announcement.php`, `history.php`, `dashboard.php`, `layout.php`, `wordpress.php`, and `public/assets/app.js` contain Phase 1 text/behavior that must be updated consistently.
- `bin/setup.php` imports initial schema with CREATE TABLE IF NOT EXISTS. It cannot add fields to existing tables. Add a real incremental, rerunnable, non-destructive migration runner.
- Existing tests use isolated random MySQL databases and temporary PHP servers; optional Playwright checks use installed local Chrome. `tests/oauth-csp.cjs` verifies the real Google redirect and must not be treated as a mocked WordPress test.

## Installed database inspected read-only

- Configured website: `https://paleco.net/paleco`.
- REST root: `https://paleco.net/paleco/wp-json/wp/v2`.
- Saved connection status: Connected (recorded test timestamp `2026-10-23 08:43:28` UTC).
- Stored mappings exist for POWER_ADVISORY and INTERRUPTIONS with resolved IDs. Revalidate remote identifiers and never hardcode the observed numeric IDs.
- Current counts: 1 user, 1 existing announcement, 0 publishing-history records. Preserve all existing records.
- `announcements` columns: id, user_id, type, title, body, image_path, image_mime, interruption_date, status, created_at. No WordPress IDs, URLs, revision or attempt fields yet.
- `publishing_history` already stores announcement_id, user_id, status, wordpress_post_id, message, created_at. It lacks action, attempt identity, uncertainty, and reliable in-progress outcome fields. Extend appropriately or add a separate attempts table while retaining old history.
- No credentials were printed, no live WordPress requests were made, and no application/database changes were made during this Phase 2 inspection. This checkpoint is the only Phase 2 file created so far.

## Proposed implementation sequence for Monday

1. Review current files and this checkpoint; verify the persisted connection metadata and schema have not changed. Do not reset or rebuild the app.
2. Add incremental migrations for WordPress post/media ID, URL, remote status, timestamps, safe error summary, attempt/reconciliation state, local revision or operation identifier, and reliable attempt records. Bind saved remote identities to their original destination so changed settings cannot accidentally update another site's same numeric post ID. Preserve Phase 1 drafts/statuses and provide a backup/migration procedure.
3. Extend the API client with JSON POST/update and validated binary/multipart media upload, typed safe error handling (401, 403, invalid category, network/timeout/malformed response), a mockable transport, and strict response validation. Keep HTTPS/SSRF protections on every request, including returned links/media URLs where rendered or fetched.
4. Resolve categories by exact configured slug or verified stored identifier. Do not select ambiguous partial names or silently create categories. Revalidate actual category membership before remote writes. Verify edit_posts/publish_posts/upload_files as required for each action.
5. Add an orchestrating publishing service with database-backed serialization (including concurrent processes), stored operation identity, safe repeated-click handling, and remote post reuse. Persist an uncertainty marker before each non-idempotent network write. On timeout/crash, reconcile positively before creating anything else. A missing search result alone is not proof a previous creation failed; block blind retries pending reconciliation/manual resolution. Handle uncertain media uploads similarly.
6. Add editing and a review screen for persisted announcements. Distinguish Save locally, Save as WordPress Draft, and Publish. Public publication must require a confirmation modal showing title, category, preview and a server-validated explicit confirmation bound to the current announcement revision. Update an existing WordPress post ID instead of creating a new one. Local saves must never trigger remote writes. Avoid hiding failed/uncertain remote state behind a local draft save.
7. For scheduled advisories upload media first, store the media identity durably, include safe poster content and featured_media, and stop before post creation if the required poster fails. Preserve uploaded poster privacy locally.
8. Update statuses and history/UI: Local Draft, WordPress Draft, Publishing, Published, Failed; safe error summaries, authorized retry/reconcile actions, remote View on WordPress links, revision-aware updates, and accurate Phase 2 banners. Preserve the existing responsive design.
9. Add mocked automated coverage for all 15 required scenarios plus concurrency, uncertain post/media results, destination changes, stale confirmations, CSRF/direct unauthorized POSTs, and repeated updates. Ensure no mock test can reach the live PALECO website. Run existing tests and desktop/mobile browser workflows with fake WordPress responses.
10. Update README/FILES and provide changes, reused components, migration instructions, API details, workflow, test results, manual testing instructions, and remaining limitations. Live end-to-end posting requires an explicit real user action; do not publish sample/test announcements.

## Authentication fix to preserve

Google Sign-In previously failed in Chrome because form-action also checked the local POST's redirect to Google. `app/bootstrap.php` now uses `form-action 'self' https://accounts.google.com`; all other CSP directives remain strict. Root/form/callback use the confirmed `http://localhost/paleco-hub` origin. State, S256 PKCE, CSRF, remembered-token rotation and replay protection are in place. This was verified before Phase 2; do not undo it.

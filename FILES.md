# PALECO Hub file inventory

The existing Phase 1 application was extended in place. DocRoute v2 was not modified.

## Scheduled multi-poster / history-management update

Created: `database/migrations/003_multi_posters_history.php`, `app/Services/Attachments.php`, `app/Services/ScheduledDrafts.php`, `app/Services/ScheduledMedia.php`, `app/Services/History.php`, `app/Views/scheduled.php`, `app/Views/history-announcements.php`, `app/Views/poster-images.php`, `public/assets/scheduled.js`, `public/assets/history.js`, `tests/multi-history.php`, `tests/multi-history-browser.cjs`, `docs/multi-posters-history.md`.

Modified: `.env.example`, `.htaccess`, `app/Controllers/HubController.php`, `app/Services/Drafts.php`, `app/Services/Publisher.php`, `app/Views/announcement.php`, `app/Views/history.php`, `app/Views/review.php`, `public/assets/app.js`, `public/assets/app.css`, `tests/FakeWordPressTransport.php`, `tests/browser.cjs`, `tests/publishing-browser.cjs`, `tests/publishing.php`, `tests/run.php`, `README.md`, `FILES.md`.

Private pre-migration snapshot: `storage/private/pre-multi-posters-20261027-071856.json` (ignored and not web-accessible). Installed migration is additive; no actual history entries were deleted during implementation. Partial-update workflow, WordPress configuration/categories and authentication are preserved. Previous task changes listed below remain intact.

## LAN asset and mobile login update

Created: `tests/lan-assets.cjs`, `docs/lan-assets.md`.

Modified for this update: `app/bootstrap.php` (same-origin path helpers), `app/Views/layout.php`, `app/Views/login.php`, `app/Views/partial.php` (asset references), `app/Services/Auth.php` (callback-origin check), `app/Controllers/HubController.php` (local auth-error redirect), `public/assets/app.css` (login-only styling), `README.md`, `FILES.md`.

The CSP, Apache configuration, `.env`, OAuth callback values, WordPress integration and database schema are unchanged. Previous uncommitted partial-form work remains intact.

## Partial form simplification update

Created: `app/Services/PartialPublication.php`, `app/Views/partial.php`, `public/assets/partial.js`, `tests/partial-form.php`, `tests/partial-browser.cjs`, `docs/partial-publishing-form.md`.

Modified: `app/Controllers/HubController.php`, `app/Services/Announcement.php`, `app/Services/Publisher.php`, `app/Views/announcement.php`, `public/assets/app.js`, `public/assets/app.css`, `tests/browser.cjs`, `tests/publishing-browser.cjs`, `tests/publishing.php`, `README.md`, `FILES.md`.

No schema, authentication, WordPress credential/configuration or scheduled-module behavior changes. Existing backend draft functionality is retained.

## Phase 2 created files

- `app/Services/AnnouncementLock.php`
- `app/Services/CurlWordPressTransport.php`
- `app/Services/Drafts.php`
- `app/Services/Migrations.php`
- `app/Services/Publisher.php`
- `app/Services/Uploads.php`
- `app/Services/WordPressClient.php`
- `app/Services/WordPressFailure.php`
- `app/Services/WordPressTransport.php`
- `app/Views/review.php`
- `bin/migrate.php`
- `database/migrations/002_wordpress_publishing.php`
- `docs/phase-2.md`
- `tests/FakeWordPressTransport.php`
- `tests/publishing.php`
- `tests/publishing-browser.cjs`

## Phase 2 modified files

- `.gitignore` - allow the existing base schema to be versioned while keeping private backups ignored.
- `app/Controllers/HubController.php`
- `app/Services/Announcement.php`
- `app/Services/WordPress.php`
- `app/Views/announcement.php`
- `app/Views/dashboard.php`
- `app/Views/history.php`
- `app/Views/layout.php`
- `app/Views/wordpress.php`
- `bin/setup.php`
- `public/assets/app.css`
- `public/assets/app.js`
- `public/sw.js`
- `tests/browser.cjs`
- `tests/router.php`
- `tests/run.php`
- `README.md`
- `docs/phase-2-checkpoint.md`
- `FILES.md`

The existing `database/schema.sql` is unchanged. It is now unignored so fresh installations and tests can reproduce the base schema.

## All source files

- `.env.example`
- `.gitignore`
- `.htaccess`
- `app/bootstrap.php`
- `app/Controllers/HubController.php`
- `app/Models/DB.php`
- `app/Services/Announcement.php`
- `app/Services/AnnouncementLock.php`
- `app/Services/Auth.php`
- `app/Services/CurlWordPressTransport.php`
- `app/Services/Drafts.php`
- `app/Services/GoogleProvider.php`
- `app/Services/Migrations.php`
- `app/Services/Publisher.php`
- `app/Services/RecognitionEngine.php`
- `app/Services/UnconfiguredRecognition.php`
- `app/Services/Uploads.php`
- `app/Services/WordPress.php`
- `app/Services/WordPressClient.php`
- `app/Services/WordPressFailure.php`
- `app/Services/WordPressTransport.php`
- `app/Views/announcement.php`
- `app/Views/dashboard.php`
- `app/Views/error.php`
- `app/Views/history.php`
- `app/Views/layout.php`
- `app/Views/login.php`
- `app/Views/review.php`
- `app/Views/security.php`
- `app/Views/users.php`
- `app/Views/wordpress.php`
- `bin/approve-user.php`
- `bin/migrate.php`
- `bin/setup.php`
- `composer.json`
- `composer.lock`
- `database/migrations/002_wordpress_publishing.php`
- `database/schema.sql`
- `docs/phase-2.md`
- `docs/phase-2-checkpoint.md`
- `index.php`
- `public/assets/app.css`
- `public/assets/app.js`
- `public/assets/bootstrap.min.css`
- `public/assets/icon-192.png`
- `public/assets/icon-512.png`
- `public/assets/paleco-logo.png`
- `public/manifest.json`
- `public/sw.js`
- `README.md`
- `tests/browser.cjs`
- `tests/FakeWordPressTransport.php`
- `tests/oauth-csp.cjs`
- `tests/publishing.php`
- `tests/publishing-browser.cjs`
- `tests/router.php`
- `tests/run.php`

## Private/generated local state

- `.env` - preserved; never included in version control or output.
- `vendor/` - existing Composer dependencies.
- `storage/uploads/` - private runtime posters.
- `storage/private/pre-phase2-20261027-060716.json` - protected pre-migration snapshot; contains private data and encrypted credentials.
- `storage/qa/` - optional local Playwright tooling.
- `storage/test-server.log` - isolated QA server log.
- `storage/screenshots/phase2-review-desktop.png` and `phase2-review-mobile.png` - fictitious test data.
- Other existing login/dashboard screenshots remain under `storage/screenshots/`.

## Database

- Applied `002_wordpress_publishing` non-destructively to the independent Hub database.
- Preserved users, drafts, mappings, application settings and encrypted credentials.
- Added `schema_migrations`, `publishing_attempts` and announcement publishing metadata.
- No live WordPress records were created or changed by implementation/QA.
- Temporary test databases, posters, fixtures and sessions are removed after tests.

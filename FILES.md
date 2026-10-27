# Created and modified files

All application files below were created for this standalone project. DocRoute files were read only; the authorized logo was copied into this project.

## Source and public assets

- `composer.lock`
- `composer.json`
- `tests/run.php`
- `tests/router.php`
- `tests/browser.cjs`
- `tests/oauth-csp.cjs` — real-browser Google OAuth CSP redirect regression check.
- `README.md`
- `bin/setup.php`
- `bin/approve-user.php`
- `public/sw.js`
- `public/manifest.json`
- `.gitignore`
- `.env.example`
- `.htaccess`
- `index.php`
- `app/bootstrap.php`
- `public/assets/paleco-logo.png`
- `public/assets/icon-512.png`
- `public/assets/icon-192.png`
- `public/assets/bootstrap.min.css`
- `public/assets/app.js`
- `public/assets/app.css`
- `database/schema.sql`
- `app/Views/announcement.php`
- `app/Views/wordpress.php`
- `app/Views/users.php`
- `app/Views/security.php`
- `app/Views/login.php`
- `app/Views/layout.php`
- `app/Views/history.php`
- `app/Views/error.php`
- `app/Views/dashboard.php`
- `app/Services/GoogleProvider.php`
- `app/Services/Auth.php`
- `app/Services/Announcement.php`
- `app/Services/RecognitionEngine.php`
- `app/Services/UnconfiguredRecognition.php`
- `app/Services/WordPress.php`
- `app/Controllers/HubController.php`
- `app/Models/DB.php`

## Generated local state

- `.env` — private local configuration with a new encryption key; excluded from version control.
- `vendor/` — Composer dependencies from the pinned lockfile; excluded from version control.
- `storage/qa/` — optional local Playwright QA dependency; excluded from version control.
- `storage/screenshots/login-desktop.png`
- `storage/screenshots/login-mobile.png`
- `storage/screenshots/dashboard-desktop.png`
- `storage/screenshots/dashboard-mobile.png`
- `storage/test-server.log` — isolated integration server log.
- `storage/uploads/` — protected runtime poster storage.

## Database changes

- Created independent `paleco_hub` database and tables in `database/schema.sql`.
- Approved the user-supplied Gmail identity as Administrator.
- Automated test databases use random names and are removed after each run.
- No DocRoute database changes or live WordPress writes.

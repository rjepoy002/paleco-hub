# PALECO Hub — Phase 1

Standalone PHP MVC application at **http://localhost/paleco-hub**. This build uses PHP 8.2+, MySQL/MariaDB, Composer, locally served Bootstrap 5.3.8, and vanilla JavaScript. No DocRoute source or credentials were changed. The official logo was copied from the existing authorized `docroute-v2/public/images/paleco-logo.png`; PWA icons resize that asset without redrawing it.

## Current installation

- Dependencies are installed and pinned in `composer.lock`.
- `.env` exists with a newly generated independent encryption key; its contents must remain private.
- The independent `paleco_hub` database and schema are installed.
- `r.g.padrones.paleco@gmail.com` is approved as the first Administrator, with display name `R. G. Padrones` (editable by another administrator).
- Google credentials are intentionally blank. The login screen works, but a real Google login cannot succeed until the following configuration is completed.
- No live WordPress calls or publishing were performed during implementation.

## Composer and database setup

For another installation, from PowerShell:

```powershell
Set-Location C:\xampp\htdocs\paleco-hub
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/setup.php --init-only
```

Edit `.env` locally to set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. Then:

```powershell
php bin/setup.php
php bin/approve-user.php approved@example.com "Full Name" Administrator
```

Setup creates only the named independent database and missing tables; it never resets existing data. `database/schema.sql` is the initial SQL migration. Subsequent schema changes should be added as versioned migrations. You can import the SQL into an already created database with phpMyAdmin instead. Use a dedicated database account in production; the local XAMPP installation currently uses the default development connection. The schema includes users, roles, permissions, role permissions, login sessions, remembered devices, application settings, encrypted WordPress connections, category mappings, announcements, publishing history, and audit logs.

## Google OAuth configuration

DocRoute's setup documentation, configuration structure, and controller were inspected read-only. Hub follows its verified-email, preapproved-account approach while using its own users, cookies, state, and sessions. Its `.env` secrets were not read or copied.

1. Open the existing organization's Google Cloud project and choose or create a **Web application** OAuth client for Hub. A dedicated client within the same project keeps credentials separate. The existing client can also authorize another callback if the project owner chooses.
2. Add the exact authorized redirect URI:

   `http://localhost/paleco-hub/auth/google/callback`

3. Enter the chosen client credentials directly in Hub's local `.env`:

   ```dotenv
   GOOGLE_CLIENT_ID=<Hub web application client ID>
   GOOGLE_CLIENT_SECRET=<Hub web application client secret>
   GOOGLE_REDIRECT_URI=http://localhost/paleco-hub/auth/google/callback
   ```

4. If the consent screen is in Testing, add the approved Gmail account to its test users. Ensure the consent audience permits Gmail accounts. Google test-user membership and Hub administrator approval are both required where applicable.
5. Open the local URL and choose **Continue with Google** using the approved account. A successful verified identity links its Google subject to the existing approved user and redirects to `/dashboard`. Unknown, inactive, or conflicting identities are denied; no public registration exists.

Only `openid email profile` scopes are requested. The server uses one-time session state (10-minute expiry) and S256 PKCE; access and refresh tokens are not saved. The first linking transaction locks the approved account and the database enforces unique email/Google IDs. See [Google's web-server OAuth documentation](https://developers.google.com/identity/protocols/oauth2/web-server).

Do not paste secrets into chat, commit `.env`, or copy DocRoute's credentials into source. Register a separate exact HTTPS production callback before deployment. `localhost` and `127.0.0.1` are distinct OAuth/cookie origins.

## Localhost testing

Start Apache and MySQL in XAMPP and visit **http://localhost/paleco-hub**. The root `.htaccess` requires Apache `mod_rewrite` and `AllowOverride All`. It forwards application routes to `index.php`, maps public assets, and blocks private project paths. All frontend assets are served locally; no frontend CDN is required at runtime.

After configuring Google:

1. Sign in using the approved Gmail account and verify the dashboard.
2. In User Management, approve a second Google account as Publisher. Verify that account can draft announcements but receives HTTP 403 for `/users` and `/wordpress`, including POST requests.
3. Paste a multiline partial advisory. Verify the detected original title, edit it, review the preview, and save the draft. History must preserve the text and line breaks, with HTML treated as text.
4. Select a JPG, PNG, or WebP poster (maximum 8 MB / 40 megapixels), enter its actual date, and confirm it matches the poster. Verify the generated title and preview, then save. No date is guessed by the app.
5. In Security Settings, revoke sessions, forget remembered devices, and test Sign out everywhere. Logout revokes the current session and current remember token. Updated user access revokes all that user's sessions and remembered devices.
6. Optionally enter WordPress credentials through the Administrator settings form. Save, then Test Connection. This performs **GET requests only** to `/users/me?context=edit` and categories. It verifies edit-post permission and resolves exact category names/slugs to real IDs. No publishing endpoint is implemented.
7. On mobile, open/close navigation and review forms/previews. On Android use the install option when offered. On iPhone use Safari → Share → Add to Home Screen. An HTTPS host is needed for service workers on a physical phone; a phone's `localhost` refers to that phone, not this PC. Native iOS/Safari and Android hardware testing remains manual.

Automated checks use an isolated random `paleco_hub_test_*` database and temporary PHP server, then remove their test data. They never use the production site's WordPress credentials. The test database account needs CREATE/DROP DATABASE permissions. Test identities cannot sign in to the installed app.

```powershell
composer test
# Optional real Chrome checks; install QA tooling only if absent:
npm.cmd install --prefix storage/qa --no-save --package-lock=false playwright
php tests/run.php --browser
composer validate --strict
composer audit
```

Screenshots from browser checks are in `storage/screenshots/`. They contain isolated fictitious users. Normal runtime does not require Node.js, npm, Playwright, or Chrome. Automated tests exercise existing authenticated sessions through native PHP session files; they do not bypass authentication in application code. A live Google consent round trip and a live WordPress credential test require external configuration and remain manual.

## Completed features

- Responsive navy/gold PALECO interface, official logo, login, dashboard statistics, quick-access cards, desktop sidebar, collapsible mobile navigation, and consistent settings screens.
- Google-only authentication implementation, preapproved accounts, Administrator/Publisher authorization, CSRF, output escaping, prepared statements, independent sessions, and audit events.
- Two-hour idle / twelve-hour maximum login sessions. Optional 30-day devices store hashed rotating bearer tokens, use HttpOnly/SameSite cookies, and support revocation. Detected replay revokes that remember token family.
- Partial text draft workflow with exact original-title preservation and editable Manila-time fallback, multiline preview and storage.
- Poster draft workflow, validated private uploads, authenticated image delivery, mandatory actual date and confirmation, generated editable title, and modular recognition interface.
- Administrator account approval/access changes; self-demotion blocked and at least one active Administrator retained.
- Administrator WordPress settings, AES-256-GCM credential encryption, read-only connection testing, category ID retrieval, URL validation, public DNS checks, DNS pinning, and redirect blocking.
- Draft/publishing history UI and database support. Phase 1 has no live publishing route or action.
- Manifest, existing-logo PWA icons, service worker, and installation support. Only an exact public asset allowlist is cached; pages, OAuth, private posters, and authenticated data are excluded.

## Pending configuration and later phases

- Register Hub's callback and configure its Google OAuth credentials; then perform a real approved-account sign-in, denied-account sign-in, cancellation, and remember-me round trip.
- Configure the production WordPress Application Password and test actual category IDs. Default website: `https://paleco.net/paleco`. Default category names: `POWER_ADVISORY` and `INTERRUPTIONS`. API paths are WordPress REST v2 endpoints. See [WordPress REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/).
- Choose and implement OCR behind `RecognitionEngine`. `UnconfiguredRecognition` currently returns no dates, so the UI clearly requires manual transcription and confirmation. An implementation returning several ISO dates must present those candidate dates for user selection before publication; current server scaffolding enforces explicit confirmation and membership when more than one candidate exists. A dynamic OCR candidate selection endpoint/UI is a later engine integration task.
- Live WordPress publishing, media posting, duplicate prevention, retries, immutable publishing result records, and post links belong to a later phase.
- Production deployment hardening: TLS, dedicated service accounts, restricted ACLs, backup/recovery, session cleanup, operational monitoring, and hardware browser QA. The current root-path hosting is configured specifically for `/paleco-hub`; update `.htaccess`, `.env`, manifest and service-worker scope together if moved.

## Security and operations

- Keep `APP_KEY` (32 random bytes, base64 encoded) outside source control and backed up separately. Losing it makes saved WordPress passwords unreadable. Changing it requires re-entering connection credentials; there is no automatic key rotation yet. Saved passwords never render in HTML or JavaScript. Application logs intentionally exclude exception messages and OAuth query values to avoid credential leakage.
- WordPress destinations require HTTPS, a public DNS hostname, no URL credentials/query/fragment/custom ports, and matching website/API hostnames. Requests pin validated DNS addresses with cURL, retain TLS verification, block redirects, and cap response size/time. An egress firewall remains appropriate for production defense in depth.
- Uploaded posters have random extensionless filenames outside public routes. MIME, dimensions, size, and native image headers are checked. No OCR command or user-supplied executable is run. Consider malware scanning and image re-encoding in a later upload pipeline.
- Sessions and remember tokens are revocable server-side; permissions and active status are rechecked on each authenticated request. User management revokes existing access on changes. Revoking only a login session leaves separately remembered devices available; use Forget device or Sign out everywhere to remove persistent access.
- XAMPP's local HTTP cookies cannot use `Secure`; switching `APP_URL` to HTTPS enables it automatically. Do not deploy on public HTTP. Secure cookie paths and a unique session name isolate Hub from DocRoute. Browser profiles on the same origin are not a security boundary against other compromised apps; production should use a dedicated origin.
- Use production PHP settings (`display_errors=Off`, `log_errors=On`). Restrict `.env`, session files, and `storage` to the service account. Protect backups and audit exports. Set server clocks correctly. Database event times use UTC; fallback advisory titles use Asia/Manila.
- There is no scheduled expired-session cleanup, audit viewer, login rate limiter, or automated key rotation yet. Add lifecycle cleanup and perimeter throttling before wider production use. PWA static caching deliberately provides no offline access to private pages.
- No email or messaging notifications are sent. No DocRoute files were modified. All created/modified project files are listed in `FILES.md`.

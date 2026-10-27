# PALECO Hub — Phase 2

Standalone PHP/MySQL advisory workspace at **http://localhost/paleco-hub/**. PHP 8.2+, Composer, Bootstrap 5.3.8, and vanilla JavaScript. The existing official PALECO logo, responsive interface, Google authentication, users, drafts, encrypted WordPress settings, and PWA remain in place.

Phase 2 adds editable local drafts, WordPress drafts, explicitly confirmed public publication, same-post updates, poster uploads/attachment, publishing history, safe retries, and reconciliation. Nothing is sent to WordPress merely by saving locally or opening a page.

See [Phase 2 implementation and manual testing guide](docs/phase-2.md) for migration details, API endpoints, error recovery, coverage, and remaining limitations. [FILES.md](FILES.md) lists source files and the Phase 2 changes.

## Installed state

- Local database migrated with `002_wordpress_publishing`; existing rows and original values were verified unchanged.
- Existing configured connection remains `https://paleco.net/paleco/wp-json/wp/v2`, with saved Connected status and preserved encrypted credentials.
- Initial Administrator: `r.g.padrones.paleco@gmail.com`.
- Google configuration remains unchanged. State, S256 PKCE, CSRF, session expiration, remembered-device rotation/revocation and the Google CSP fix are preserved.
- A private pre-migration database snapshot is under `storage/private/`. It is ignored by Git and blocked by Apache. Keep encryption-key backups separately.
- No live WordPress requests, test posts, or GitHub pushes were made during Phase 2 implementation. Publishing is now available only through explicit authorized actions.

## Installation and migration

Existing installation:

```powershell
Set-Location C:\xampp\htdocs\paleco-hub
composer install --no-dev --prefer-dist --optimize-autoloader
# Back up the database with phpMyAdmin before migrating another installation.
php bin/migrate.php
```

Migrations are incremental and rerunnable. They add columns/tables without dropping or resetting data. MySQL DDL commits independently, so interrupted migrations inspect existing columns before continuing. A database lock prevents concurrent migration runs.

New installation:

```powershell
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/setup.php --init-only
# Edit .env locally: database settings and Google OAuth client values.
php bin/setup.php
php bin/approve-user.php approved@example.com "Full Name" Administrator
```

`bin/setup.php` imports `database/schema.sql` and applies incremental migrations. For SQL imports through phpMyAdmin, import the base schema then run `php bin/migrate.php`. Use a dedicated least-privilege database user in production, with temporary schema permissions for migration.

## Google Sign-In

For LAN/mobile rendering and the separate Google callback limitation, see [mobile assets and LAN access](docs/lan-assets.md). Assets stay on the origin being viewed. The private-IP login page can render normally, but phone authentication requires a deliberately configured, registered HTTPS hostname; the existing localhost callback is not silently rewritten.

Use the existing organization's Google Cloud project and a Web application OAuth client configured for Hub. Enter credentials locally in `.env`; never paste secrets into chat or source control:

```dotenv
APP_URL=http://localhost/paleco-hub
GOOGLE_CLIENT_ID=<Hub web application client ID>
GOOGLE_CLIENT_SECRET=<Hub web application client secret>
GOOGLE_REDIRECT_URI=http://localhost/paleco-hub/auth/google/callback
```

Register that exact callback in Google Cloud. If consent is in Testing, add the permitted Google accounts as test users. Accounts also need separate approval in Hub. Google-only sign-in links a verified email to an existing active account, never publicly registers users, and requests only `openid email profile`. Tokens are not saved. Production requires HTTPS and an exact production callback.

Chrome checks CSP on the form's redirect to Google. The retained policy permits `form-action 'self' https://accounts.google.com`, with all other directives restricted. `APP_URL`, browser URL, and registered callback must use consistent origins. DocRoute v2 was not modified and its secrets were not copied.

## Daily workflow

Scheduled interruptions now support ordered multiple posters, per-image previews/removal, Local Draft, WordPress Draft and explicitly confirmed publication. Publishing History has local-only Delete and Administrator Clear History with preserved drafts/synchronization state. See [multi-poster and history guide](docs/multi-posters-history.md) for migration `003_multi_posters_history`, limits, permissions and verification.

**Partial Power Interruption Update now uses Publish and Cancel only.** Its title is detected automatically and remains editable. Publish opens a final confirmation and sends a public post only after confirmation; it creates no draft. Cancel confirms discarding changed content. Errors preserve the form for safe retry. See [partial publishing form](docs/partial-publishing-form.md). The scheduled-poster workflow and backend/history draft functions below remain available.

1. Open Partial Power Interruption Update or Scheduled Power Interruption. Paste text or choose a validated poster and confirm the actual interruption date.
2. **Save draft** keeps everything local. **Save and review for WordPress** also saves locally, then opens the saved version's review screen.
3. Choose **Save as WordPress Draft** to create/update an unpublished remote draft, or **Publish to WordPress** to open the title/category/preview confirmation modal. Check the authorization box and choose **Confirm and publish** for public publication.
4. Review status, result, remote post ID, and attempts. Published announcements provide **View on WordPress**. Existing remote drafts and published posts reuse their WordPress ID when updated.
5. Use **Edit local announcement** to change text, title, date, or poster. Local edits do not change WordPress until an explicit remote action. Editing an uncertain operation is blocked until reconciliation.

Only active Publishers and Administrators may invoke remote publishing. Administrators inherit Publisher permissions. WordPress separately checks its configured user's edit, publish, and media capabilities. The mappings remain POWER_ADVISORY for partial updates and INTERRUPTIONS for scheduled posters; numeric category IDs are resolved/verified, not hardcoded in application code.

## Testing

```powershell
composer test
php tests/run.php --browser
composer validate --strict
composer audit --no-interaction
```

The complete browser run currently passes **197 checks**, including the original auth/session/draft checks, mocked publishing tests, simplified partial-form checks, multi-poster uploads and local history management. Tests create a random isolated `paleco_hub_test_*` database and remove it afterward. The fake WordPress transport has no HTTP implementation and accepts only `https://wordpress.example.invalid`; no real WordPress credentials or live APIs are used. Test accounts are isolated from installed Hub users.

Optional browser QA uses installed Chrome and Playwright under ignored `storage/qa/`:

```powershell
npm.cmd install --prefix storage/qa --no-save --package-lock=false playwright
php tests/run.php --browser
```

Runtime requires neither Node.js nor Playwright. Screenshots are under `storage/screenshots/`; Phase 2 review captures use fictitious test users and posts. The optional `node tests/oauth-csp.cjs` regression navigates to real Google authorization but never signs into an account; it is separate from the WordPress mock tests.

## Deployment and security

- XAMPP must run Apache/MySQL with `mod_rewrite` and `AllowOverride All`. Root `.htaccess` maps `/paleco-hub`, serves assets, and blocks `.env`, source, dependencies, tests, docs and private storage. Only the public login is available without authentication. Do not expose project directories to resolve routing errors.
- Use HTTPS in production. Cookies become Secure when `APP_URL` is HTTPS; HTTP localhost is development only. Use a dedicated origin, restricted filesystem ACLs, production PHP error settings, backups and egress restrictions.
- Preserve `APP_KEY`: it is a base64 32-byte server key for AES-256-GCM credentials and review signatures. Losing/changing it requires re-entering saved WordPress passwords. Saved passwords never render in HTML/JavaScript or announcement/audit records.
- WordPress requests require validated public HTTPS destinations, DNS pinning, TLS verification, no redirects, bounded time/body sizes, and safe fixed API paths. Remote post/media links must belong to the configured HTTPS host. Off-host CDN media URLs require an explicit future allowlist change, not a wildcard bypass.
- Posters are MIME/header/size/dimension checked and stored with random extensionless private names. Files are revalidated before upload to WordPress. Serving a local poster requires authentication and Publisher/Administrator access.
- Server-side CSRF, signed revision/connection-bound reviews, explicit public confirmation, prepared SQL, HTML escaping, per-announcement database locks, persisted pending writes, ownership markers and destination-bound remote IDs protect the publishing workflow.
- Session policy remains 2-hour idle / 12-hour maximum; remembered devices last up to 30 days with rotating hashed tokens. Revoking a session does not revoke separately remembered access; use Forget device or Sign out everywhere.
- The service worker caches only exact public asset paths. It never caches authenticated pages, OAuth, private posters, API responses, or credentials. Android installation uses the browser prompt when available; iOS uses Share → Add to Home Screen. Physical-device PWA use needs an accessible HTTPS host.

## Remaining limitations

OCR remains unconfigured; read and confirm poster dates manually. No date is invented. Native iOS/Android hardware testing and a real approved advisory publication remain manual. The implementation intentionally does not create live test posts.

If WordPress times out and reconciliation cannot positively identify the result, Hub blocks another creation. An administrator must investigate the remote record before any manual repair; do not clear pending markers blindly. WordPress plugins that rewrite raw content or remove ownership markers can also require investigation. There is no automatic background retry, remote deletion/unpublishing UI, automatic cleanup of abandoned media, or general import of externally created posts.

Expired-session cleanup, rate limiting, malware scanning/image re-encoding, key rotation, operational monitoring and a dedicated audit viewer remain deployment follow-up work. See the Phase 2 guide for safe recovery and manual verification.

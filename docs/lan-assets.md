# Mobile styling and LAN assets

## Root cause confirmed

Both `http://localhost/paleco-hub/` and `http://192.168.20.94/paleco-hub/` returned the login page, but its templates used the canonical `url()` helper for browser assets. With `APP_URL=http://localhost/paleco-hub`, the LAN HTML therefore requested stylesheets, JavaScript, logo, icons and manifest from `http://localhost/...`.

On a phone, localhost refers to the phone itself. In addition, the LAN document's strict CSP treats localhost as a different origin. Chrome reproduction recorded `style-src-elem`, `script-src-elem` and `img-src` violations before the fix. The CSS files themselves were present and served correctly at the LAN address; this was an asset-origin problem, not primarily a responsive breakpoint problem.

The existing viewport was already correct (`width=device-width, initial-scale=1, viewport-fit=cover`). There was one CSP response policy, no conflicting CSP meta tag, and no need to relax CSP or change Apache rewrite rules.

## Fix

- `local_url()` takes only the deployment subdirectory from configured APP_URL. `asset()` uses it to emit paths such as `/paleco-hub/assets/app.css` without a hostname or scheme. Neither helper builds absolute URLs from Host or forwarded headers.
- Shared CSS/JS/icon/manifest references, the branded logo and the partial form script now use those same-origin paths. The login form action also stays on the current origin.
- Canonical `url()` and the configured Google callback remain unchanged. No WordPress settings, credentials, schema or publishing actions changed.
- Login-only CSS improves small-screen spacing, readable copy and touch targets: a minimum 48-pixel Google button, a 44-pixel Remember Me label, and a 20-pixel checkbox. PALECO logo, colors and existing controls remain intact.
- The CSP still limits styles/scripts to self, images to self/blob/data, and form actions to self plus the exact Google authorization origin. Fonts use system families, with no external font/CDN request.

## Google Sign-In is a separate origin requirement

The installed callback remains `http://localhost/paleco-hub/auth/google/callback`. It works for browser sessions started on that localhost origin on the XAMPP computer. A phone opened at the LAN IP cannot safely complete that callback: localhost would refer to the phone and the LAN cookie/state would not be available on the callback origin.

Hub now explains that mismatch on the login screen and refuses to start OAuth from a different callback origin, keeping the error on the same local login page. It does not rewrite the callback to the request Host, share session cookies across hosts, disable CSRF/state/PKCE, or bypass Google. Desktop sign-in continues unchanged, including Remember Me submission and revocation protections. Request headers are used only to compare origins, never to generate an absolute redirect. Proxy forwarding headers are not implicitly trusted; deployments terminating TLS at a proxy must configure their HTTPS request handling correctly.

Google requires HTTPS redirect URIs except for localhost and disallows raw IP hosts except loopback addresses. `192.168.20.94` is not loopback. Do not change GOOGLE_REDIRECT_URI to the private IP and assume it will work. See [Google's redirect URI validation rules](https://developers.google.com/identity/protocols/oauth2/web-server#uri-validation).

For real phone authentication, an administrator must provide a reachable, controlled HTTPS hostname and a certificate trusted by the phone, register its exact callback in the chosen Google Web application client, and deliberately update Hub's canonical URL/callback configuration. Start and finish sign-in on that same hostname. No production OAuth settings or local credentials were changed by this fix.

Plain HTTP LAN access can render this login page, but it does not provide a production authentication transport. Service workers/PWA installation also require a secure context on a physical phone; the visual design does not depend on service-worker availability.

## Verification

```powershell
node tests/lan-assets.cjs
# Optional explicit addresses:
node tests/lan-assets.cjs http://localhost/paleco-hub http://192.168.20.94/paleco-hub
node tests/oauth-csp.cjs
php tests/run.php --browser
```

The LAN test needs Apache and the specified local interfaces reachable. It is separate from the portable isolated mock suite, so a disconnected Wi-Fi interface does not make ordinary unit/integration tests depend on this IP.

Verified on both actual Apache origins:

| Resource | Result |
|---|---|
| Login document | 200; one unchanged CSP and proper viewport |
| bootstrap.min.css, app.css | 200; text/css |
| app.js | 200; text/javascript |
| PALECO logo and home-screen icon | 200; image/png |
| manifest.json | 200; application/json |
| Layout at 360, 390, 430 and desktop 1280 px | Styled, no horizontal overflow, usable touch controls |
| Browser requests | Same origin; no failed asset requests, CSP violations, mixed-origin/mixed-content assets or JavaScript errors |
| LAN Google action | Safe local refusal with clear configured-origin explanation |
| Desktop Google action | Reaches Google's sign-in page; state, S256 PKCE, CSRF and Remember Me submission preserved |

The complete isolated mock/browser regression suite still passes 149 checks. It includes authentication/device handling and both publishing modules; it invokes no live WordPress actions. Screenshots are under `storage/screenshots/login-localhost-390.png` and `login-lan-390.png`.

Remaining manual checks: reload on the actual Android device over Wi-Fi, then verify Google authentication only after an administrator supplies a registered HTTPS hostname. Automated Chrome emulation is not a physical-device test, and no real Google account was signed into during verification. No live WordPress publication or GitHub push was performed.

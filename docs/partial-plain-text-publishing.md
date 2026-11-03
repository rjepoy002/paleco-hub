# Partial advisory plain-text WordPress publishing

Implemented October 29, 2026 (Asia/Manila). This update affects Partial Power Interruption Updates only. No existing remote post is automatically repaired or rewritten.

## PALECO INFO title update — October 30, 2026

Automatic detection first looks for a PALECO INFO header timestamp. For example, `PALECO INFO 10/30/26 1:58 PM` generates `Power Service Advisory 10/30/26 1:58 PM`, even if another advisory heading appears below it. It accepts flexible whitespace (including a wrapped header), case-insensitive PALECO INFO/AM/PM, one- or two-digit hours, and two- or four-digit years. Date digits, leading zeroes, hour and AM/PM case come from the source; separator whitespace is normalized. Invalid or absent timestamps use the original advisory-heading/content fallback. No current/server time is used.

Manual title changes survive typing and inline pastes. Selecting the entire advisory and pasting a replacement starts automatic detection for the new announcement; clearing the body or title also retains the existing reset behavior. Request cancellation and sequence checks protect newer edits from stale detection results. The entire original body still goes to WordPress, with the existing safe plain-text escaping and line-ending normalization. Category/tag, duplicate protection and scheduled poster behavior remain unchanged.

Files changed for this follow-up: `app/Services/Announcement.php`, `public/assets/partial.js`, `tests/partial-form.php`, `tests/partial-browser.cjs`, `tests/partial-plain-text.php`, this guide and `README.md`.

Verification: `php tests/run.php --browser` passed **269 checks**, including timestamp/fallback cases, replacement-versus-inline paste, manual edits, confirmation body preservation, generated/manual WordPress title payloads and single POWER_ADVISORY tag assignment. PHP/JavaScript syntax checks passed. All remote publishing was mocked; no live posts or mobile application code were changed. The mobile/rendered-content limitations below still apply.

## Findings and behavior

`Announcement::publicationBody()` previously removed the detected heading. `Publisher` then wrapped the remaining text with `<p>`/`<br>` and appended an identity comment. The browser preview also removed the heading. These were application behaviors, separate from any mobile rendering issue.

The complete submitted body now remains intact, including the original heading exactly once, regardless of the detected or manually edited WordPress title. CRLF/CR are normalized to LF; blank lines and Unicode remain. Literal user HTML is escaped with double encoding disabled, so `<script>` becomes text and an existing `&amp;` stays `&amp;`. No paragraph, break or tracking-comment markup is generated for partial bodies. Existing user-supplied comment syntax is escaped as text, not executable HTML. Title detection still works and title-only/empty advisories remain invalid.

Both WordPress Draft and Publish use this same payload. Existing authentication, permission checks, CSRF, public confirmation, local drafts and publishing history remain. Partial forms retain their current two-button interface; existing WordPress Draft controls on saved-record review remain supported.

The category remains the configured, verified `POWER_ADVISORY` category. The exact named `POWER_ADVISORY` tag is reused or created with slug `power_advisory`. Existing manual tags are preserved. The resolver uses destination-scoped locking, persistent creation uncertainty and exact lookup, including recovery from an accepted response timeout. The existing year-tag resolver now delegates to the same implementation with its original namespace and matching rules; scheduled poster content/media/featured-image/identity logic is unchanged.

## Identity and retry safety

No new partial tracking comment is written to `post_content`. The server retains destination, post ID, random remote slug, pending payload and revision in the existing local database. Known IDs are fetched with authenticated `context=edit`; a missing creation response is reconciled by the previously persisted random slug. The full expected raw content/title/status/slug/category/tags must match. Ambiguous, absent or mutated results keep the uncertain state and block blind recreation. Do not delete these local mappings to work around an error.

Old uncertain operations reconcile their original persisted payload first, rather than silently rewriting the old post under new rules. A later explicit revision can use the new format. Missing old content cannot be reconstructed from WordPress alone; the repair uses the original local advisory body.

## WordPress configuration and saved-content filters

The Application Password account needs `edit_posts`, permission to edit the mapped posts, `publish_posts` for public publication and permission to assign the required terms. If the tag does not already exist, it also needs the WordPress taxonomy permission to create tags (normally `manage_categories`). Precreating `POWER_ADVISORY` as a WordPress Administrator avoids granting tag-creation rights to the publishing account.

Core normally applies `wpautop` while rendering; that is different from changing stored `post_content`. A save-time plugin can still mutate the raw content. Hub compares the authenticated REST response's `content.raw` exactly and refuses to report success when it changes; retries then reconcile rather than create another post.

For sites with save-time formatting filters, deploy the supplied **[PALECO Hub Plain Advisory Storage plugin](wordpress/paleco-hub-plain-text.php)** to `wp-content/mu-plugins/paleco-hub-plain-text.php` on the target WordPress installation. This file has been prepared and hook-tested locally; it has **not** been installed on the live site.

Hub sends `paleco_hub_plain_text: true` on partial REST writes. The plugin requires an authenticated editor, a valid random Hub slug and the POWER_ADVISORY category. It safely escapes the text again without double encoding, restores only that request's escaped content at the final insertion filter, and stores the identifier in private `_paleco_hub_key` post meta through the authenticated REST operation. It does not change global `the_content` filters, scheduled posters or other posts. Without this plugin, Hub relies on its local mapping; ordinary WordPress ignores the extra request parameter. The plugin does not expose secrets or require public raw-content access.

Verify the deployed plugin against the actual site's plugins using an explicitly approved advisory. Another plugin that rewrites the database after insertion must be resolved on WordPress; local tests cannot guarantee compatibility with arbitrary remote code.

References: [WordPress insertion-data filter](https://developer.wordpress.org/reference/hooks/wp_insert_post_data/), [WordPress `wpautop`](https://developer.wordpress.org/reference/functions/wpautop/).

## Optional repair of one affected post

No bulk repair or automatic migration runs. From the Hub directory, first preview using an active Hub Administrator/Publisher ID:

```powershell
php bin/repair-partial.php --id=123 --user=2
```

This preview reads local data only, prints the proposed raw body/status/title and makes no HTTP request or database change. Review the content and existing WordPress post before applying. It requires a mapped, fully synchronized partial record with no unresolved operation.

For a WordPress draft, explicitly use `--apply`. For an already public post, both switches are required:

```powershell
php bin/repair-partial.php --id=123 --user=2 --apply --confirm-public-update
```

Application uses the existing revision/permission/locking/publishing/history flow and updates the same mapped ID. It preserves the public/draft status and existing title, restores the local full advisory body and applies the category/tag. If a network or local commit error occurs, reconcile the same record from its existing review page before attempting further edits. Preview output may contain operational advisory text; keep it private as appropriate. No repair command with a real apply switch was run during implementation.

## Mobile inspection

This Hub repository contains the responsive browser application, not an Android/iOS WordPress consumer. No `content.rendered` consumer or native/mobile project was found here. A limited read-only search of the nearby `mportalv2` application sources also found no matching consumer; that does not establish that the real mobile client is available.

Public REST `content.rendered` can still contain HTML paragraphs, links, entities or other presentation markup from WordPress display filters. A mobile client displaying it as plain text should use an HTML parser to convert paragraphs/breaks to newlines, remove comments and decode entities once, then render through a native text widget. Do not decode untrusted entities and insert the result as executable HTML. Do not ship WordPress Application Passwords to a mobile client to obtain `content.raw`. These server changes alone do not establish that mobile rendering is fixed.

## Changed files and checks

- Application: `app/Services/Announcement.php`, `Publisher.php`, `WordPressClient.php`, `YearTags.php`; new `AdvisoryTags.php` and shared `TagResolver.php`; `public/assets/partial.js`.
- Optional tools: `bin/repair-partial.php`, `docs/wordpress/paleco-hub-plain-text.php`.
- Tests: `tests/FakeWordPressTransport.php`, `publishing.php`, `partial-form.php`, `browser.cjs`, `partial-browser.cjs`; new `partial-plain-text.php` and isolated `wordpress-plain-plugin.php`.
- Documentation: this guide and `README.md`.

Checks use mock WordPress requests, reserved `.invalid` destinations, isolated temporary databases and a standalone WordPress hook harness. They cover original/manual titles, exact newline/blank-line preservation, safe/idempotent escaping, absence of generated paragraph tags/comments, category/tag creation/reuse/preservation/failures, filtered-response failure, accepted response timeout and retry safety, legacy mapped repair, preview-only behavior, explicit public-repair confirmation, and scheduled regression coverage. No database schema change, live publication, live repair, credential change or GitHub push was performed.

Results: `php tests/run.php` passed 252 checks; `php tests/run.php --browser` passed 257 checks. The browser run includes the final plugin create/update escaping assertions and preview/modal heading preservation. Composer configuration validation passed. Real WordPress plugin deployment, save-filter compatibility and the actual mobile client remain manual checks.

# Partial update publishing form

The Partial Power Interruption Update main form now has exactly **Publish** and **Cancel** actions. The Scheduled Power Interruption form is unchanged. Existing backend draft endpoints and history/review actions are retained for compatibility.

## Behavior

- Typing is debounced 250 ms; pasted text is processed promptly. The authenticated `/partial/title` endpoint calls the shared PHP `Announcement` rules, removing the duplicate JavaScript regex.
- Recognizable advisory titles retain their emoji, Unicode, case and whitespace. Otherwise a bounded editable fallback is generated from the first meaningful advisory line. Empty input generates no title. Limits remain 255 title characters and 100,000 body characters.
- Manual title edits survive body changes. Clearing the title resumes detection; clearing the advisory resets manual state for a new advisory. Existing saved titles initially count as intentional overrides.
- Abort/sequence guards reject stale responses. Changes during confirmation preparation invalidate the result.
- The modal shows the final title, POWER_ADVISORY category, body preview and public-visibility warning. Opening/cancelling it creates no local record or WordPress draft.
- Cancel goes to Publishing History and asks for confirmation only when body/title differ from their initial values.
- The recognized heading is omitted from WordPress body content because it already appears as the post title. Original text remains stored for history; public content retains escaping and line breaks.

## Confirmed publishing and retry safety

`POST /partial/prepare` validates and signs confirmation without saving a draft. `POST /partial/publish` requires CSRF, an active Publisher/Administrator, explicit confirmation, and a signature bound to user/content/operation/version/current WordPress configuration. It then calls the existing `Publisher::send` with `publish` status.

Only confirmed submissions create a publishing record. Failed attempts are marked failed and retain content for retry/history. The operation-to-announcement mapping uses existing `application_settings`, keyed by a user-bound random operation ID. No schema migration or credential/configuration change is needed. Do not delete operation entries for outstanding requests; they retain retry identity when a browser response is lost.

Repeated submissions reuse the recorded announcement and remote post ID. A definite rejection permits a corrected, freshly confirmed retry on the same record. Pending uncertain writes cannot be edited around reconciliation. The existing destination binding, exact category resolution, locks, history, and reconciliation remain authoritative even when client buttons are disabled.

WordPress and expired-CSRF failures render the form with its body/title and operation preserved. Success opens the existing result page with feedback and View on WordPress. After an uncertain result, retry that same preserved form rather than starting a separate advisory.

## Verification

Run `composer test` and `php tests/run.php --browser`. The full browser run passed **149 checks**. All publishing tests use fake WordPress responses and isolated temporary databases. Coverage includes titles/fallbacks, pasted/typed Unicode and emoji, long/empty/invalid input, manual/reset behavior, stale responses, clean/dirty Cancel, confirmation cancellation, CSRF/permissions, preserved errors, POWER_ADVISORY, repeated requests, and reconciliation after an accepted-post timeout. Existing scheduled poster and authentication tests remain applicable.

Manual checks: inspect the two-button row on desktop and a physical phone; paste an actual advisory, override/reset its title, open confirmation and choose Go back, then test Cancel's discard prompt. A live publication is intentionally left to an authorized user with a real approved advisory. No live test publication or GitHub push was performed.

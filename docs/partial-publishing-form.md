# Partial update publishing form

The main form has Publish and Cancel. Publish validates and signs content through the existing /partial/prepare endpoint, then submits asynchronously to /partial/publish using existing authentication and CSRF. There is no additional publication dialog. Scheduled publishing is unchanged.

The existing PartialPublication service stores a user-bound operation mapping, creates or updates the local announcement, and calls Publisher::send. Publisher tracks the pending remote payload before writing, verifies WordPress status and content, and commits announcement state, attempt results and history together. Uncertain results reconcile by the existing mapped post ID or random remote slug, without another creation.

The JSON success contract requires published local status, WordPress publish status, a known post ID, a synced revision, no uncertain stage and saved published history. Normal HTML requests retain their existing response behavior.

Only this confirmed success clears content, title, local IDs, versions, confirmation token, validation and preview. A fresh server-generated operation replaces the prior identity. Title detection timers and requests are cancelled and sequence guards reject late results; this form has no draft autosave. The empty content field receives focus without scrolling, a non-blocking success message appears, and the module remains open. Failure, timeout, draft or ambiguous results retain content and operation identity for safe retry. Published records and history are never deleted by reset.

Title detection retains the existing source timestamp behavior (for example Power Service Advisory 11/03/26 11:07 AM), manual overrides and fallback rules. The complete original title line stays in the body. POWER_ADVISORY category and tag, plain text cleanup and credential configuration are unchanged.

No configuration or migration is required. No production test publication is needed. Run php tests/run.php and php tests/run.php --browser; tests use fake WordPress and temporary databases.

For localhost manual verification, open /paleco-hub/partial with an authorized account, paste an approved advisory and review its title and preview. Publish only when the advisory is approved for the configured destination. Verify success, empty fields and focus; paste a second advisory and verify distinct posts and retained history. With a test WordPress destination, simulate failure or timeout and verify fields remain and retry reconciles the same post. Check desktop and Android Chrome, including narrow screens.

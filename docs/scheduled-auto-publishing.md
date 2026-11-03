# Scheduled interruption: publish, reset and manual Facebook sharing

## Hub flow

The main upload form has Publish and Cancel. Posters are scanned using the existing labelled interruption-date parser; filenames and upload timestamps never determine the date. Conflicting dates must be resolved by removing conflicting posters. Existing manual correction for unreadable OCR remains available. The generated title is Scheduled Power Interruption [October 25, 2026], with the interruption year applied as its tag and INTERRUPTIONS as its category.

Publish validates the selection and opens one dialog showing title, interruption date and poster count. Cancel preserves the selection. Confirm & Publish saves through ScheduledDrafts and calls Publisher::send with publish status. It does not visit a review screen or require another Publish. Legacy local/WordPress draft endpoints and saved-record review actions remain compatible, but draft buttons are removed from this primary form.

POST /scheduled/publish keeps existing session authentication, Publisher/Administrator authorization and CSRF. A user-bound random operation in application_settings binds the original record/version, title, date/source, removals and ordered file hashes. A named lock serializes this operation. Retry reuses the saved announcement/attachments and existing media/post reconciliation. Changed retry data cannot bypass reconciliation; restore the submitted data or open Publishing History to reconcile/edit the saved record.

Reset requires published local status, WordPress publish status, known post ID, synced revision, no uncertain stage, and saved published history. The browser checks this JSON contract too. Reset cancels OCR and advances its sequence guard, revokes object URLs, and clears files, previews, OCR, title, date, validation and active record identifiers. A fresh operation permits an independent second publication, including selection of the same filename. Stored posts, attachment records, history, post IDs/permalinks, duplicate tracking and authentication are retained. The success message includes View Published Post using the verified remote permalink and opens it in a new tab.

Failures/ambiguous responses preserve the form. When WordPress publish is known but local finalization fails, the error explicitly distinguishes remote success from incomplete local finalization. The saved WordPress ID, permalink and pending payload survive; retry reconciles that post without another creation.

## Poster ordering and duplication

The reported first-poster duplication came from rendering featured_media and also putting the first image in the body. The existing ScheduledMedia fix remains authoritative: SCHEDULED_FEATURED_DISPLAY=theme (default) keeps the first poster featured and includes only posters 2 onward in body order. A theme that displays featured media plus the body therefore shows every poster once. SCHEDULED_FEATURED_DISPLAY=content is for templates that hide featured media; it includes every poster in the body. Select the mode against the actual WordPress single-post template. Do not switch to content mode when that template already displays the featured poster. New three-poster tests cover order and first-image non-duplication. Hub does not append image markup to existing generated content.

## Facebook findings: read-only public inspection, November 3, 2026

Inspected https://paleco.net/paleco/scheduled-power-interruption-october-25-2026/ and its anonymous REST record /wp-json/wp/v2/posts/47442. Both returned HTTP 200; REST status was publish, featured_media was 47444 and the canonical permalink matched. The public HTML contains one HT Mega Elementor widget (htmega-social-shere-addons / htmega-social-share, data-social="facebook") inside the post's own Elementor wp-post layout, not evidence of a universal single-post sharing hook. HT Mega loads goodshare.min.js, whose Facebook handler opens a manual sharer using the current page URL/title. No Facebook submission was performed.

The page identifies Yoast SEO and exposes og:title, og:url, og:site_name (Palawan Electric Cooperative), og:type=article, and og:image referencing 10222026_3.jpg. That poster returned HTTP 200 image/jpeg. This verifies metadata on an existing public sample, not the preview of a new Hub-created post. Facebook's cached rendering still requires manual verification.

New Hub REST posts do not automatically inherit per-post Elementor layouts. Keeping featured_media and the title/permalink supports the installed SEO plugin, but does not alone install the sharing widget. No sharing button, shortcode, Facebook SDK or Facebook publishing API has been added to the Hub post payload. No global WordPress hooks, theme options or plugin settings have been changed from localhost.

## Optional WordPress deployment to reuse the existing widget

The companion docs/wordpress/paleco-hub-scheduled-sharing.php uses the installed Elementor renderer to display a sharing-only saved template. It does not create a replacement sharing implementation, alter stored post content, or make Facebook API calls. It is disabled until configured. If the selected WordPress single-post template already provides sharing, keep this helper disabled and retain that template instead.

For the inspected per-post-layout setup:

1. In staging WordPress, save the existing HT Mega Share to Facebook widget as a dedicated published Elementor template containing just that widget and its layout containers. Retain the widget's existing Facebook settings. Do not use a complete announcement as the template, or copy its gallery/recent-post widgets. The helper rejects templates containing other widgets or more than one sharing widget.
2. Install and activate paleco-hub-scheduled-sharing.php in wp-content/plugins/. Set define('PALECO_HUB_SHARE_TEMPLATE_ID', TEMPLATE_ID); in wp-config.php using the dedicated template ID. Template selection may alternatively use the paleco_hub_scheduled_share_template_id filter.
3. Ensure that template does not contain a hard-coded old post URL/title. The inspected widget uses the current document URL/title. Retain existing Elementor, HT Mega and Yoast installations.
4. The helper runs only on published, public, main-loop Hub posts in INTERRUPTIONS with the existing ownership marker. It skips existing sharing markup and per-post Elementor builder layouts. It neither changes POWER_ADVISORY nor renders a second widget when passed content already containing this widget. Templates supplying sharing outside the content hook must leave this helper disabled to prevent duplication.
5. On a staging Hub-created post, verify exactly one widget, correct styling/scripts and the correct current URL/title in its Facebook dialog. Verify Yoast's rendered og:image matches the intended featured poster, og:title matches the advisory, og:url matches the public permalink and og:site_name is present. Confirm all posters appear exactly once. Do not deploy a second helper if the theme/plugin already handles this globally.

The local hook harness verifies scoping, existing-renderer reuse, duplicate avoidance and template restrictions using stubs. It does not run a real Elementor/HT Mega/Yoast installation. WordPress installation/template configuration and live Facebook preview behavior remain operator verification steps. No application schema migration or WordPress credential change is required.

## Verification and manual checks

Run php tests/run.php --browser for isolated database, fake WordPress/Vision, PHP integration and desktop/mobile browser regression tests. Added coverage includes one/two/three posters, title/category/year, featured image, ordered bodies without a repeated first image, verified permalink/history, operation replay, second publication, timeout reconciliation, injected local-history failure, changed-retry rejection, one confirmation/cancel, double clicks, complete reset and same-filename selection. Legacy draft API behavior remains covered without restoring draft UI buttons. Partial publishing/auth/history regression tests remain in the suite. The sharing helper harness runs through this suite or directly with php tests/wordpress-scheduled-sharing.php.

On localhost, open /paleco-hub/scheduled as a publisher and upload approved posters. Review OCR/title/date/order. Click Publish then Cancel; verify preservation. Confirm an approved staging publication; verify one confirmation, success/permalink and empty upload controls. Immediately publish another and check separate posts and retained history. Simulate a staging failure/timeout and retry unchanged data. Finally check a physical Android Chrome device and the actual public WordPress page/Facebook Create Post preview manually. Do not claim Facebook sharing was tested from mocked API results alone.

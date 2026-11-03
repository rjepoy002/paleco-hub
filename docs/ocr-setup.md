# Scheduled interruption OCR: XAMPP setup and verification

## Diagnosis on November 3, 2026

This checkout has OCR_PROVIDER=none and an empty GOOGLE_APPLICATION_CREDENTIALS setting. Google Auth, Guzzle, PHP cURL and OpenSSL are installed. The GoogleVisionTransport implementation exists but is intentionally not initialized without an approved service-account key. Google Sign-In client ID/secret are separate and cannot authenticate this OCR service. No service-account key is available yet, so live automatic recognition remains unverified and disabled. No Google Cloud account, billing or API configuration was changed.

## Existing provider

Google Cloud Vision DOCUMENT_TEXT_DETECTION remains the sole production provider. No new dependency or paid service was introduced. Google Vision may incur charges; use an approved project with the API, billing, permissions and quotas configured by its administrator. See Google's [Vision setup](https://docs.cloud.google.com/vision/docs/setup) and [authentication documentation](https://docs.cloud.google.com/vision/docs/authentication). This app sends validated image bytes directly to the API; it does not require uploading posters to Cloud Storage.

## Local XAMPP configuration

1. Have the project administrator enable Cloud Vision API in the approved Google Cloud project, verify billing/quota and authorize a dedicated service account. Ensure required service-use permissions are available. Do not grant project Owner just to run OCR.
2. Store its service-account JSON outside every served web root, for example C:/xampp/private/paleco-vision-service-account.json, readable by the Apache service account. Do not store it under C:/xampp/htdocs or paste it into chat, code or a browser. Google Sign-In OAuth client JSON is not a service-account key.
3. In C:/xampp/htdocs/paleco-hub/.env set:

   OCR_PROVIDER=google_vision
   GOOGLE_APPLICATION_CREDENTIALS=C:/xampp/private/paleco-vision-service-account.json

   Forward slashes avoid Windows backslash escaping. Quote paths containing spaces. Keep .env and key files out of version control. Existing system environment variables take precedence over .env, so correct any stale Apache/system overrides too.
4. In the project directory run composer install if dependencies are missing. In XAMPP's PHP settings ensure cURL, OpenSSL and fileinfo are enabled. Configure a valid CA bundle if TLS reports trust errors; do not disable TLS verification. Restart Apache after changing PHP settings, file permissions or service environment variables. The CLI and Apache may use different php.ini files/service accounts.
5. Run C:/xampp/php/php.exe bin/check-ocr.php. This performs local validation only and prints no key, token, private path or project identity. Exit 1 reports a configuration problem. A successful preflight is not proof of Google API access, billing or OCR accuracy.
6. With an approved real poster, explicitly run:

   C:/xampp/php/php.exe bin/check-ocr.php "C:/path/to/interruption-poster.jpg"

   Supplying a poster argument sends it to the existing Google Vision API and may incur provider charges. It validates the image first, prints only the recognized interruption date/title or a controlled error, and does not publish to WordPress. Exit 0 means a labelled date was recognized; exit 2 means no unambiguous valid labelled date; exit 1 means configuration/provider/image failure.
7. Open /paleco-hub/scheduled and select that poster. Before publishing, verify the date field, title and per-poster detection results. Try matching/conflicting multiple posters and an unreadable image. Check the actual printed date against recognition. Only then rely on automatic dates for publication.

For the example Interruption Date: October 25, 2026 | Sunday, expected results are 2026-10-25 and Scheduled Power Interruption [October 25, 2026].

## Recognition and fallback

Images are validated by MIME, dimensions and size before OCR. Each poster is scanned separately in selection order; filenames/upload times are never parsed for dates. Labelled dates support capitalization, spacing, punctuation and line breaks, including line breaks inside the label/date. Other field dates are excluded. Different dates and weekday mismatches are reported instead of selecting the first poster's date. Unlabelled possible dates still require manual review.

The UI shows Detecting interruption date... and then Interruption date detected: October 25, 2026 when all posters agree. Per-poster results retain their own detected dates. Manual date corrections and custom titles are retained. Missing/unreadable/service-failed scans allow manual entry; conflicting posters must be resolved before publication. Request timeout releases the UI for manual entry; ongoing server requests may still finish in the background. Stale results cannot overwrite a newer selection/reset.

Configuration is checked before provider requests/cache access and returns exact safe reasons: disabled/unsupported provider, missing/unreadable/out-of-web-root credentials, malformed JSON, wrong credential type or missing dependencies. Controlled provider errors distinguish authentication, denied access/API or billing setup, quota, rejected images and unavailable/timeouts without echoing raw Google response bodies. Unknown provider exceptions remain sanitized. Unavailable/runtime/quota failures are not cached, allowing immediate recovery at month rollover; successful image/provider/parser results cache normally. The parser cache version changed so old parsing does not mask the updated rules. No database migration is required.

## Verification limits

Tests use fake Google Vision text/responses and isolated temporary databases. They cover parser variations, matching/conflicting/missing dates, configuration diagnostics, provider failures, manual corrections, per-poster feedback and scheduled publishing/reset/history/sharing-helper/partial regression flows. Run php tests/run.php --browser. These tests do not establish that this installation can call Google Vision. The real-poster CLI and browser checks above remain required after a credential file is supplied. No live WordPress publication is needed to verify recognition.

Monthly application allowance: see [ocr-monthly-limit.md](ocr-monthly-limit.md). The default is 900 conservative units per Asia/Manila month, shared by web scans and the CLI. Preflight prints a usage snapshot; real-poster CLI checks must reserve units before sending. This is not a Google Cloud billing cap.

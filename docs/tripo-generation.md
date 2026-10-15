# Photo to shoe model

Manual GLB upload, calibration, poster capture and product editing remain available. The alternative workflow is **Admin → Generate from photos**. Existing product rows keep their current publication state when these migrations run. Generation never overwrites an existing product or automatically publishes one. Replacing a model through product editing now resets its calibration and poster, unpublishes it and requires renewed review.

## Setup

1. Set the backend-only `TRIPO_API_KEY` in `.env` (API keys and API billing are separate from the Tripo Studio account). Never use a `VITE_` variable for this key.
2. Use `QUEUE_CONNECTION=database` or `redis`, with a shared lock-capable cache such as the existing database cache. Set `DB_QUEUE_RETRY_AFTER=180` (or `REDIS_QUEUE_RETRY_AFTER=180`); this must exceed the job timeout of 120 seconds. Deploy the same configuration to every worker.
3. Run `php artisan migrate`, `php artisan storage:link`, and `npm run build`. Clear/rebuild cached configuration after changing credentials: `php artisan config:clear` or `php artisan config:cache`.
4. Run `php artisan queue:work --queue=tripo,default --tries=1 --timeout=120`. If `TRIPO_QUEUE` changes, update the worker queue list. Supervise and restart workers after deployments.
5. Run `php artisan schedule:work` locally, or run `php artisan schedule:run` every minute through the production scheduler. `tripo:maintain` recovers abandoned polls and removes expired private drafts/assets. Manual invocation: `php artisan tripo:maintain`.
6. Enable PHP cURL, fileinfo, GD, and the application's normal extensions. cURL pins a verified public IPv4 address for asset downloads. Node is needed only for the frontend build, not by generation workers.

`config/tripo.php` and `.env.example` list model versions, queue, timeouts, poll delay, deadline, failure budget, image/model limits and retention. HTTP timeouts are clamped to 45 seconds (connection timeout: 10 seconds) to keep a task read plus download within the 120-second job timeout. Each uploading job handles just one photo. Database/Redis/Beanstalk queue reservation defaults are now 180 seconds; explicit settings of 120 seconds or less block generation until corrected. For SQS, configure a visibility timeout exceeding 120 seconds in AWS.

The default exact download host is `cdn.tripo3d.ai`, as shown by official task responses. Signed output URLs are never persisted or returned to browsers. If a live task uses another storage host, generation fails safely: verify the **exact** hostname with Tripo before adding it to `TRIPO_ASSET_HOSTS`. Do not add broad wildcards or arbitrary user-provided hosts. HTTPS is mandatory; credentials, nonstandard ports, private/reserved addresses and redirects are rejected. Network proxies are disabled for downloads to preserve IP pinning.

## Admin workflow

1. Create a draft with product details and one JPEG/PNG photo in the front slot. A three-quarter photo can be used for single-image generation. Photos need good lighting, a clean background and only one shoe. Size: 256–8192 pixels per side, default maximum 10 MB each.
2. Optionally add clearly labeled left/back/right views of the same shoe. Front is required; two or more photos use multi-view generation. Sources are stored on the private local disk and sent to Tripo by the backend.
3. Review the selected model and face limit. Check the paid-credit confirmation and click **Generate model**. Saving a draft itself never creates a provider task. No fixed credit price is claimed; inspect Tripo pricing for your account and settings.
4. Leave/reopen the job from Model generations. Progress is only shown when supplied by the provider. Completion downloads and validates the GLB into private application storage. Review it in the existing model-viewer and use the authenticated GLB download if needed.
5. Confirm that the asset contains one accurate shoe without unwanted geometry, then create an **unpublished** product and continue into the existing calibrator. Browser preflight uses the same r131 loader and fitting code as live try-on. Confirm orientation, side and geometry, adjust the fit, check the visual-review box, and save.
6. Return to product editing, capture a poster using the existing capture controls, test live try-on on a real device, and publish through the existing listing checkbox. Reference fitting is a visual alignment tool, not actual foot measurement.

Geometry semantics require human review. Orientation detection is a suggestion, especially for low-confidence results; its heuristic scores are not guarantees. Server checks cover GLB header/chunks, embedded buffers/textures, triangle positions, finite coordinates, size, complexity and unsupported extensions. Browser checks cover rendering, visible static meshes, transformed dimensions and mirroring preparation. Hidden geometry does not determine fitting bounds. Root transforms participate consistently in orientation and fitting, and slider edits reuse cached static bounds.

## State and failure handling

`draft → queued → uploading → submitting → generating → validating → ready`

Any processing stage can fail. A timeout, server error, malformed response or worker interruption while submitting a paid request becomes `ambiguous`. There is no automatic paid POST retry and no assumed provider idempotency guarantee. An admin must locate the matching task in the Tripo console and use **Resume existing task** with its verified ID. Do not create another paid task while the outcome is unknown. Ambiguous drafts are retained for reconciliation.

Transient uploads, reads and downloads are retried by delayed jobs, subject to a consecutive-failure budget and overall deadline. Polling never sleeps inside a worker. Each generation uses a shared cache lock; persisted states and task IDs make duplicate jobs harmless. A due-time check suppresses premature duplicate polls. The scheduler recovers a missed dispatch or stopped worker after three minutes. If it recovers `submitting`, it marks the submission ambiguous instead of sending it again.

Known tasks can be resumed after deadlines without creating a paid task. A failed known task offers **Create a new draft for a paid retry**; the new draft still requires a separate paid Generate action. Explicit retries retain the previous record and provider ID. A 429 during a paid submission fails the draft for explicit retry; safe reads respect `Retry-After`/reset timing.

Private draft photos and completed downloads are pruned after `TRIPO_RETENTION_DAYS` (default 30). Linked products own separate public GLBs, and cleanup preserves those referenced copies. Source files shared by retry drafts remain until every referencing draft expires. Failed `.part` downloads are removed immediately; interrupted ones disappear when their expired draft is pruned. Unreferenced generated public copies and source files are pruned after retention. Ambiguous jobs are excluded from automatic deletion. Source photos and private model previews are accessible only through this admin workflow; no provider signed URLs are exposed.

## Provider contract verified October 6, 2026

This client uses the documented **v3** API, not mixed v2/v3 request fields:

| Operation | Contract |
| --- | --- |
| Authentication | Bearer API key, backend only |
| Base | `https://openapi.tripo3d.ai/v3` |
| Upload | `POST /files`, multipart `file`, `data.file_token` |
| Single image | `POST /generation/image-to-model`, `input`, `model` |
| Multi-view | `POST /generation/multiview-to-model`, labeled `inputs` array with front and at least one other view |
| Create response | `code: 0`, `data.task_id` |
| Status | `GET /tasks/{task_id}`, `data.status`, optional progress and `output.model_url` |
| Selected options | H-series v3.1/v3.0/v2.5, face limit, texture and PBR; optional compression is omitted |

Generation returns GLB directly: no export task or additional paid conversion is needed for this path. The current loader has no configured Draco, Meshopt or KTX2 decoder, so compressed/unsupported output is rejected. No fictional `compress=none` or GLB export API parameter is sent. If the provider returns an incompatible file, explicitly export/decompress to a static uncompressed GLB in a model editor and use the existing manual upload workflow, or create a new photo draft after changing the source. The documented conversion endpoint exports other formats and does not provide a documented universal uncompressed GLB switch; this integration does not automatically spend conversion credits.

The v3 quick start states that output URLs expire after five minutes; every download retry re-queries the task for a fresh link. Rates vary by endpoint/key. Official documentation describes HTTP 429, `Retry-After`, `X-RateLimit-Reset`, and account concurrency by task category. Webhooks are documented, but this integration deliberately uses polling. Cancellation is a documented task status; the reviewed task-management reference does not specify a cancellation endpoint used here, so there is no invented cancel action. Consult Tripo support for cancelling a running paid task.

Official references:

- [Authentication and introduction](https://developers.tripo3d.ai/en/docs/introduction)
- [File upload](https://developers.tripo3d.ai/en/docs/files)
- [Single-image H series](https://developers.tripo3d.ai/en/docs/generation-image-to-model/standard)
- [Multi-view H series](https://developers.tripo3d.ai/en/docs/generation-multiview-to-model/standard)
- [Task query](https://developers.tripo3d.ai/en/docs/task-query)
- [Lifecycle](https://developers.tripo3d.ai/en/docs/task-lifecycle)
- [Output expiration](https://developers.tripo3d.ai/en/docs/quick-start)
- [Rates and concurrency](https://developers.tripo3d.ai/en/docs/rate-limits)
- [Conversion](https://developers.tripo3d.ai/en/docs/models-convert)
- [Webhooks](https://developers.tripo3d.ai/en/docs/webhooks)

## Verification and manual acceptance

Automated tests fake provider HTTP calls and never spend credits:

```sh
php artisan test
npm test
npm run build
```

Before enabling this for production:

- Verify the worker and scheduler share a lock-capable cache and run with the same queue/environment configuration.
- Save/reopen both single-image and multi-view drafts. Remove a photo before saving; verify invalid files are rejected. Verify non-admin access is forbidden.
- With authorized API credentials and credits, explicitly generate one single-image model and one multi-view model. Verify the exact live download hostname, response contract, uncompressed output and billing in the provider console. Mocked tests cannot prove actual provider output compatibility or generation quality.
- Double-click Generate, refresh and close/reopen the page; confirm one provider task. Stop/restart a worker during submission and reconcile its task ID without spending credits again.
- Review/download the GLB, inspect it from all angles, create a draft product, and verify that publishing is refused until browser preflight and visual fit review are saved. Simulate a failed load; Save must remain blocked.
- Capture a poster; confirm a product remains unpublished until the existing Publish checkbox is saved. Test on real mobile devices with both feet, lighting changes and model mirroring. Confirm GPU performance and visual fit.
- Replace a model on an existing product; verify the old poster and calibration are cleared, the product becomes unpublished, and a stale calibrator cannot approve the replacement.
- Exercise provider failures, deadline/resume and explicit paid retry in a test account. Run cleanup with expired drafts; verify referenced product assets and photos referenced by a newer retry draft are retained.

No live Tripo generation or real-device acceptance is claimed by the implementation or mocked tests.

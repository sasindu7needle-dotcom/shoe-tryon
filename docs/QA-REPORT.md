# QA report — 13 October 2026

**Assessment: not ready for release sign-off.** The existing suites pass, but additional checks reproduced eight application defects, including loss of an existing model after a failed replacement upload. Dependency audits also report advisories. Live tracking on physical phones remains unverified.

This was an assessment. Application code, dependency lockfiles, and `.env` were not changed. Development dependencies were installed from `composer.lock`. Browser work used a separate SQLite database in `storage/app/qa/qa.sqlite`, with temporary QA account data.

## Checks performed

| Check | Result |
| --- | --- |
| Existing PHP suite: `php artisan test` | 33 passed, 281 assertions |
| Existing JavaScript suite: `npm test` | 37 passed |
| PHP formatting: Pint `--test` | Passed |
| Composer manifest and installed platform requirements | Passed |
| Production assets: `npm run build` | Passed; model-viewer chunk is about 1 MB before gzip |
| Bundled models: `npm run models:audit` | All four loaded; model sizes 0.39–0.98 MB, textures at most 1024×1024 |
| Supplemental backend checks | 29 cases: 22 passed, 7 failed, exposing three defect groups below |
| Admin access matrix | All 20 cases passed: ten endpoints each checked as a guest and ordinary user |
| HTML and embedded JSON escaping | Both malicious product-name checks passed |
| Chrome responsive smoke checks | Home, product, try-on intro, and login at 390×844 and 1280×844; no uncaught JavaScript exceptions or horizontal overflow detected |
| Product viewer | Model rendered; selecting Beach updated the viewer and try-on URL |
| Camera permission denial | Correct error phase and actionable message |
| Admin browser flows | Sign-in, product pages, calibration rendering/save, and logout succeeded |
| Poster capture | API saved a WebP; fetching its public URL failed with 403 in this local setup |
| Production-only npm audit | No advisories reported |
| Full npm / Composer audits | Findings described below |

The five JavaScript defect reproductions execute the actual application source with controlled DOM, camera, tracker, and asynchronous boundaries. They establish the logic errors below; they are not physical-device camera tests. Browser checks used the installed Chrome without adding a browser package.

## Reproduced application defects

### QA-01 — High: a failed replacement upload destroys the old model

**Location:** `app/Http/Controllers/Admin/ProductController.php:53–54`; related poster handling at lines 59–60 and `ProductPosterController.php:22–27`.

**Reproduction:** Edit a product with an uploaded model and replace the file while the storage adapter's `putFileAs()` returns `false`, as can happen on a failed write. The supplemental request test controls that failure.

**Observed:** The old model is deleted first. The controller saves `model_path = "storage/"` and redirects as though the update succeeded. The configured public disk has `throw = false`.

**Expected:** Preserve the existing file and database path, report the failed upload, and only delete the previous file after the replacement has been stored and the database update succeeds. Poster replacement follows the same ordering and needs equivalent failure coverage; that branch was reviewed but not separately fault-injected.

### QA-02 — Medium: a quick tab switch can leave the camera permanently stopped

**Location:** `resources/js/tryon/main.js:456–479`.

**Reproduction:** Hide a running try-on page; return before asynchronous `setPaused(true)` completes.

**Observed:** The visible event returns because `state.busy` is true. The pending hide operation then stops the stream. The page stays in the running phase with `state.stream === null`; no resume is scheduled.

**Expected:** Reconcile the final visibility state after the pending operation and reopen the camera when visible. Validate rapid hide/show and camera-flip transitions on phones after correction.

### QA-03 — Medium: playback failure leaks an acquired camera stream

**Location:** `resources/js/tryon/camera.js:41–66`, with caller cleanup in `main.js:114–117`.

**Reproduction:** `getUserMedia()` succeeds, then `video.play()` rejects.

**Observed:** The actual camera module rejects without stopping any track. The caller never receives the stream, so its cleanup cannot release it.

**Expected:** Stop acquired tracks if playback or frame initialization fails, then propagate the error.

### QA-04 — Medium: selecting the original shoe does not cancel a pending switch

**Location:** `resources/js/tryon/main.js:417–421`.

**Reproduction:** Shoe A is displayed. Select B while its download is pending, then select A again before B finishes.

**Observed:** The A selection is ignored because A is still `state.product`. B subsequently mounts despite A being the latest choice.

**Expected:** Every new selection supersedes pending selections, including a selection of the currently displayed shoe.

### QA-05 — Medium: a late colourway request can override the current material

**Location:** `resources/js/tryon/main.js:303–318` and `resources/js/tryon/variants.js:43–55`.

**Reproduction:** Select a colourway whose material resolution is delayed; immediately select another colourway that resolves first; allow the older request to finish.

**Observed:** The newer choice mounts, but the old request later mutates the same cached glTF scene. The reproduction ends with UI state `Fast` and actual mesh material `Slow`. The mount-token check occurs after that mutation.

**Expected:** Only the current request may modify the displayed scene; cancellation or serialized material application must cover the material mutation itself.

### QA-06 — Medium: changing shoes during continuous tracking undercounts the funnel

**Location:** `resources/js/tryon/main.js:165–178` and `mountProduct()`.

**Reproduction:** Detect a foot while wearing virtual shoe A; switch to B without losing valid tracking.

**Observed:** B receives `session_started`, but its valid frames never emit `foot_detected` because `setTracking(true)` returns when tracking is already true.

**Expected:** Record detection once per product whenever valid tracking is present. This affects the dashboard's detection conversion rate.

### QA-07 — Medium: malformed admin fields return 500 instead of validation errors

**Location:** `app/Http/Requests/Admin/ProductRequest.php:20–21` and `CalibrationRequest.php:41`.

**Reproduction:** As an admin, submit an array for a generated product name, slug, currency, forward axis, or up axis. For the name case, leave the slug absent so it is generated from the name.

**Observed:** All five request cases return 500 with `Array to string conversion`. Product normalization and calibration cross-field checks cast values before their types are safely validated.

**Expected:** Return 422 with field validation errors and preserve existing product data.

### QA-08 — Medium: a corrupt GLB header is accepted as a model

**Location:** `app/Rules/GlbFile.php:21–54`.

**Reproduction:** Upload `broken.glb` consisting of only `glTF` plus little-endian integers `2` and `12`: a twelve-byte header with no JSON or geometry chunks.

**Observed:** The upload endpoint creates a product and returns a success redirect. The rule checks only the header and declared length. Such a file cannot supply a renderable shoe.

**Expected:** Reject structurally invalid files before storing/publishing them. Validate chunk structure and renderable model content; check unsupported compression extensions as part of compatibility validation.

## Dependency audit findings

These are advisory matches from the audit services, not demonstrated exploits against this application. Review call paths and update the lockfiles in a separate fix.

**npm:** Six affected package entries: one critical and five high. They are in development/build tooling; `npm audit --omit=dev --json` reports zero findings.

| Installed package | Reported concern |
| --- | --- |
| `shell-quote` 1.8.4, through `concurrently` 9.2.3 | [Command injection advisory](https://github.com/advisories/GHSA-pqg4-j6r4-53mv); also parsing denial of service |
| `nanoid` 3.3.15 | [Generator denial of service](https://github.com/advisories/GHSA-2v37-7h3g-55p8) |
| `postcss` 8.5.16 | [Source-map path traversal](https://github.com/advisories/GHSA-r28c-9q8g-f849) |
| `sharp` 0.35.4 | [librsvg dependency vulnerability](https://github.com/advisories/GHSA-wq5f-xc86-pv6w) |
| `source-map-js` 1.2.1 | [Indexed-source-map denial of service](https://github.com/advisories/GHSA-68fv-2mgg-jv7q) |

`concurrently` is the sixth affected package entry because it depends on `shell-quote`.

**Composer:** Four advisories across three locked packages.

| Installed package | Reported concern |
| --- | --- |
| `laravel/framework` 13.18.1 | [Debug-page XSS](https://github.com/advisories/GHSA-jh5r-qr3c-85q8), low severity |
| `league/commonmark` 2.10.1 | [Raw HTML filtering bypass](https://github.com/advisories/GHSA-97jj-33gv-5xf9), medium; [table parsing denial of service](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8), high |
| `league/flysystem` 3.35.1 | [Malformed UTF-8 path normalization bypass](https://github.com/advisories/GHSA-cxf4-7mrp-vvpr), low |

Production deployment configuration disables debug mode. No path accepting shopper-supplied Markdown was identified during this review. These observations limit the demonstrated exposure; they do not replace dependency updates and verification.

## Local setup and deployment observations

1. **Local MySQL is unavailable.** With the current `.env`, `/up` returns 200 but application pages and the catalog API return 500. The database connection is refused. A separate QA SQLite database allows the app checks to run; `.env` was preserved.
2. **Uploaded assets are inaccessible locally.** `public/storage` is a regular directory rather than the expected link to `storage/app/public`. Poster capture writes a real WebP and returns its URL, but that URL returns 403. The local README also omits `php artisan storage:link`. Correct the local storage mapping and test retrieval of both uploaded GLBs and posters. Do not overwrite the existing directory without inspecting its contents.
3. **Deployment key-generation instructions are circular (static review).** The documented `docker compose run --rm app php artisan key:generate --show` runs when `APP_KEY` is empty, but Compose requires a nonempty key and the entrypoint also exits when it is missing. Document a bootstrap command that bypasses those guards. Docker is unavailable here, so the container flow was not executed.
4. **README requirements are stale.** It says PHP 8.3+, while the Composer manifest requires PHP 8.4 and the locked Symfony packages require 8.4.1+.

## Evidence and reruns

Local evidence is retained under the ignored `storage/app/qa/` directory:

- `ExtendedQaTest.php`, `backend-results.xml`, and `backend-output.txt`: supplemental backend cases and failures.
- `runtime-probes.mjs`: the five controlled JavaScript reproductions.
- `browser-smoke.mjs`, `browser-results.json`, and PNG screenshots: Chrome checks and observed results.
- `http-smoke.mjs`: redirect and captured-poster retrieval checks.

Run from the repository root:

```text
php artisan test
npm test
npm run build
npm run models:audit
php vendor/laravel/pint/builds/pint --test
php vendor/phpunit/phpunit/phpunit storage/app/qa/ExtendedQaTest.php
node storage/app/qa/runtime-probes.mjs
composer audit --locked --format=json
npm audit --json
npm audit --omit=dev --json
```

The supplemental backend checks intentionally fail against the defects above and are outside the normal suite. The JavaScript probe script asserts and reports the observed defects; a successful exit means reproduction succeeded, not that the application is correct. Browser scripts require the isolated QA server and Chrome debugging endpoint used during this run.

## Remaining verification before release

Fix QA-01 first, then the camera and selection races, validation/model compatibility, and funnel accounting. Review the advisory matches and update dependencies. Repeat the relevant reproduction cases after each fix.

Physical Android Chrome and iPhone Safari still need real foot tracking, portrait/landscape orientation, sustained performance, lock/background/resume, camera flip, snapshot/share/save, and native AR checks. Cross-origin camera embedding and the actual Docker/HTTPS deployment were not exercised. Responsive Chrome emulation does not establish those device behaviors.

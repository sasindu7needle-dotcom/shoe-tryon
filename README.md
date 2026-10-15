# Shoe Try-On

A Laravel storefront where shoppers see shoes **on their own feet** through their
phone camera, plus a 3D product viewer and an admin to load and fit new models.

Everything about the camera runs in the browser: frames are never uploaded, and
the server only sends static files. That is what keeps it cheap — a 1 vCPU / 1 GB
VPS is enough.

| | |
|---|---|
| Foot detection & 3D pose | [WebAR.rocks.hand](https://github.com/WebAR-rocks/WebAR.rocks.hand) (MIT), `NN_FOOT_31` network |
| Rendering | three.js r131 (MIT) |
| Product viewer & native AR | [`<model-viewer>`](https://modelviewer.dev) (Apache-2.0) |
| Backend | Laravel 13, SQLite by default |
| Serving | FrankenPHP / Caddy with automatic HTTPS |

Every dependency and sample model allows commercial use. See `/credits` in the
running app, and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for why these were
chosen over MediaPipe and the other options.

## What it does

- **Live try-on** (`/try-on/{slug}`): both feet tracked at once, shoes switchable
  from a carousel, colourways (`KHR_materials_variants`), photo capture with
  share/save, camera flip, and the camera released when the tab is hidden.
- **Storefront**: catalogue, product page with a 3D viewer, "View in your space"
  (Scene Viewer on Android, Quick Look on iOS), sizes and colourways.
- **Admin** (`/admin`): upload a `.glb`, auto-detect its orientation, align it to
  the tracked foot in a live preview, capture a catalogue poster, publish, and
  read an anonymous try-on funnel (sessions → feet detected → photos).
- **Embeddable**: point an existing store at `/try-on/{slug}`, or iframe it by
  listing the origin in `TRYON_EMBED_ORIGINS`.

## Requirements

PHP 8.4.1+, Composer, Node 22.12+, and a phone with a rear camera for testing.
The camera needs **HTTPS** (or `localhost`).

## Run it locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
php artisan app:create-admin you@example.com
npm run build   # or: npm run dev
php artisan serve
```

Then open `http://localhost:8000`. To try the camera from a phone, expose the dev
server over HTTPS (for example `npx localtunnel --port 8000`, a Cloudflare tunnel,
or deploy) — browsers block cameras on plain HTTP.

No camera at hand? Append `?demo=/path/to/clip.mp4` to a try-on URL to run the
tracker against a video file from `public/` instead.

Testing on an Android phone over USB, which avoids needing HTTPS at all:

```bash
adb reverse tcp:8000 tcp:8000   # then open http://localhost:8000 on the phone
```

If tracking looks poor, open a try-on page with `?debug=1` as an admin: the
overlay shows detection scores and lets you tune live. See
[docs/TUNING.md](docs/TUNING.md) — and note that a **laptop webcam is the worst
case** for these networks, so judge quality on a phone.

## Add a shoe

1. **Model**: one **right** shoe, glTF-binary (`.glb`), under ~3 MB, textures
   ≤ 1024 px, no Draco/meshopt compression (the left foot is mirrored at runtime,
   which needs plain float attributes). Left shoes work too — tick *Mirror*.
2. Upload it in **Admin → Products → Add product**.
3. On **Adjust fit**, press *Detect orientation*, then check the preview: your
   shoe should sit on the ghost reference shoe with the leg through its opening.
   Nudge with the sliders if needed and save.
4. *Capture poster* on the product form renders a catalogue image from the 3D view.
5. Tick **Published**.

The bundled samples are rebuilt from `resources/models/source` with
`npm run models:samples`.

## Tests

```bash
php artisan test   # HTTP, admin, validation, analytics
npm test           # fitting maths, orientation detection, colourways
```

## Deploy

See [DEPLOYMENT.md](DEPLOYMENT.md): one `docker compose up -d` on a VPS, with
automatic HTTPS and pre-compressed assets.

## Limitations

- Foot tracking needs decent light and both feet reasonably in frame, about
  1 m (3 ft) away. It is a **visual fit preview, not a measurement** — it does not
  size feet.
- The first visit downloads ~10 MB of tracking model (cached for a year afterwards).
- Bundled shoes use Blender-authored fit anchors and ankle masks; older uploads
  use the generic mask. These are not per-shopper silhouettes, so very thin or
  wide ankles still need visual checks. See [fit validation](docs/SHOE-FIT-RESULTS.md).
- Tracking quality on real phones still needs a pass on your own devices; see the
  device checklist in DEPLOYMENT.md.

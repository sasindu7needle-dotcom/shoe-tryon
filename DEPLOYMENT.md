# Deploying on a low-budget VPS

One container serves PHP and the static assets, gets its own Let's Encrypt
certificate and keeps everything in a single volume. All tracking happens in the
shopper's browser, so the server only ever sends files: **1 vCPU / 1 GB RAM and
~2 GB disk** is enough for a small shop.

HTTPS is not optional — browsers refuse camera access without it.

## 1. Point a domain at the server

Create an `A` record (for example `tryon.example.com`) pointing at the VPS, and
open ports `80` and `443` (TCP, plus UDP 443 for HTTP/3).

## 2. Install Docker

```bash
curl -fsSL https://get.docker.com | sh
```

## 3. Configure

```bash
git clone <your repository> shoetryon && cd shoetryon
cp .env.production.example .env
docker compose run --rm app php artisan key:generate --show   # paste into APP_KEY
nano .env                                                     # domain, email, APP_URL
```

## 4. Start

```bash
docker compose up -d --build
```

The first build takes a few minutes (it pre-compresses the 19 MB tracking model
with brotli so the server spends no CPU per request). Migrations run on every
start.

## 5. Create your admin account

```bash
docker compose exec app php artisan app:create-admin you@example.com
```

Then sign in at `https://your-domain/admin`, add products, and publish.

The catalogue starts empty. To load the four sample shoes (useful for a first
look, and safe to delete afterwards):

```bash
docker compose exec app php artisan db:seed --force
```

## Updating

```bash
git pull && docker compose up -d --build
```

Bump `TRYON_ASSET_VERSION` in `.env` whenever you change anything under
`public/vendor` or `public/models`: those URLs are cached for a year.

## Backups

Everything that matters (SQLite database, uploaded models and posters) lives in
the `storage` volume:

```bash
docker compose exec app tar czf - storage/app > backup-$(date +%F).tar.gz
```

## Behind Cloudflare or another proxy

Set `SERVER_NAME=:80` and `TRUSTED_PROXIES=*` in `.env`, and let the proxy
terminate TLS. Keep `SESSION_SECURE_COOKIE=true`.

## Verify after deploying

```bash
curl -I https://your-domain/up
curl -sI https://your-domain/vendor/webar-rocks/neural-nets/NN_FOOT_31.json -H 'Accept-Encoding: br' | grep -i 'content-encoding\|cache-control'
```

Expect `200`, `content-encoding: br` and a one-year `cache-control`. The brotli
copy is 9.7 MB against 19.6 MB uncompressed, and the server does no compression
work per request.

## Check on real phones

The tracking maths is covered by tests and the fit is verifiable in the admin
preview, but **camera behaviour has to be checked on devices**. On at least one
Android phone and one iPhone:

1. Open the try-on page, allow the camera, and point it at your feet from about
   1 m (3 ft). The status should reach "Tracking your feet" within a second or two.
2. Check the shoes sit on your feet as you turn them, in portrait and landscape.
3. Switch shoes and colourways; take a photo and share it.
4. Lock the phone and return: the camera indicator should stop and tracking resume.
5. Deny camera permission once and confirm the error screen explains how to fix it.

If shoes look tilted or float, adjust `TRYON_CAMERA_FOV` (the assumed vertical
field of view, default 33) — the 3D pose is solved against it. Per-shoe offsets
belong in the admin fit editor instead.

Rather than guessing, open the page with `?debug=1` while signed in as an admin:
the overlay shows the live detection score per foot and lets you tune the field
of view, threshold and stabiliser on the device itself, then copy the resulting
`.env` lines. See [docs/TUNING.md](docs/TUNING.md).

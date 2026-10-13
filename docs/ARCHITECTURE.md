# How it works, and why

## The constraint that drives everything

Shoppers must see a shoe on their own feet, from a phone browser, on a cheap VPS,
with software that can be used commercially. Two consequences:

1. **All computer vision runs on the device.** The server never sees a frame, so
   it needs no GPU and no ML runtime — it serves static files.
2. **Every dependency must permit commercial use**, models and networks included.

## Choosing the foot tracker

| Option | License | Verdict |
|---|---|---|
| **WebAR.rocks.hand, `NN_FOOT_*`** | MIT (code *and* networks) | **Chosen.** Purpose-built for a phone pointed down at feet; returns a full 6-DoF pose per foot, both feet at once, and ships a leg occluder. |
| MediaPipe Pose Landmarker | Apache-2.0 | Rejected. Its person detector keys on the face/torso, so the "look down at my feet" framing often detects nothing; and heel/foot-index landmarks give only a flat image-plane angle, no 3D roll or pitch. |
| MediaPipe Objectron (shoe) | Apache-2.0 | Rejected: legacy/unmaintained, coarse 3D box, no bare-foot support. |
| Ultralytics YOLO pose | AGPL-3.0 | Rejected: AGPL is unusable for a closed commercial store without a paid licence. |
| Niantic/8th Wall XR engine | Proprietary EULA | Rejected and removed. Its licence forbids products "offered for a fee" whose value derives substantially from the engine, and it has no foot tracking. |
| Snap Camera Kit, Perfect Corp, Wanna | Commercial | Rejected: per-seat/usage fees. |

The repository previously contained the MediaPipe approach and unused Niantic
engine files; both were replaced (see the cleanup note in the project root).

### How a pose becomes a shoe on a foot

1. `NN_BAREFOOT_3` (default) or `NN_FOOT_31` (socks/shoes) detects each foot and returns nine landmarks (ankle ×4, heel ×2,
   toe bases ×3).
2. `HandTrackerThreeHelper` stabilises the landmarks (One-Euro filter) and solves
   a perspective-n-point pose against a reference foot model, producing a 3D
   transform per foot. `foot-pose.js` validates and refines perspective
   reprojection against these landmarks before the pose filter exposes a shoe.
3. The helper mirrors any object added to it for the left foot, so **one right
   shoe model covers both feet**.
4. A leg/foot occluder writes depth only, hiding the parts of the shoe that the
   real leg should cover — that is what makes the shoe look worn rather than
   pasted on.

The pose solve uses an assumed camera field of view (`TRYON_CAMERA_FOV`, default
33°, the upstream default). It matters: too far from the real camera and shoes
tilt or float.

## The foot frame, and fitting any model into it

The tracker works in the frame of the WebAR.rocks reference shoe: **X right,
Y toward the toes, Z up**, in arbitrary units where that shoe is `1.29656` long.
`resources/js/tryon/shoe-fitter.js` holds those constants and does the mapping:

1. Rotate the model so its forward axis becomes +Y and its up axis +Z.
2. Mirror across X if it is a left shoe.
3. Scale so the shoe is exactly the reference length, then align heel, sole and
   centre line. **Every shoe is normalised to the tracked foot**, which is what
   you want: the same foot wears a size 7 and a size 12 the same way on screen.
4. Apply the product's calibration — centimetres and degrees, pivoting around the
   middle of the sole so rotations feel local.

Because step 3 is deterministic, the admin's fit preview can show an uploaded
model against the reference shoe and the occluder *without a camera*, which is
how fit is verified in development.

`resources/js/tryon/orientation.js` guesses the export convention from the
geometry alone: the longest horizontal axis is the length; the taller end is the
heel (a collar, heel counter or boot shaft is always taller than a toe box); and
a toe tip sits toward the big-toe side, which reveals left vs right. It is
verified against all four bundled samples in `tests/js/orientation.test.mjs`.

## Assets

Sample models are built by `scripts/prepare-sample-models.mjs` into one
convention (toes +X, up +Y, metres, sole on y=0) so they share a calibration and
look right in `<model-viewer>`. Textures are resized to 1024 px and converted to
WebP; 32 MB of original uploads became 2.1 MB.

Geometry is **welded but not quantized, Draco- or meshopt-compressed**: the
left-foot mirror in three.js r131 transforms vertex buffers directly, which needs
plain float attributes.

three.js is pinned to **r131**, the version the WebAR.rocks helper is written
against. The storefront's `<model-viewer>` bundles its own modern three.js, so
the two never meet.

## Performance and cost

- The default `NN_BAREFOOT_3` is about 1.3 MB; shoppers wearing socks or shoes
  can select `NN_FOOT_31`, which is 19.6 MB of JSON → 9.7 MB brotli, pre-compressed at image build
  and served with a one-year immutable cache. It is fetched with a real progress
  bar (the uncompressed size comes from the server, since compressed streams
  report the wrong total).
- GLB models also receive lossless HTTP Brotli/gzip compression at image build;
  the four samples are 18–31% smaller with Brotli, preserving runtime buffers.
- Render resolution is capped (`TRYON_MAX_DPR`, default 1.5).
- The camera stream is released when the tab is hidden.
- Nothing per-request is expensive on the server: no ML, no image processing.

## Privacy

Camera frames stay in the page. Analytics are anonymous: a random per-visit
session id, an event name, an optional product and `mobile`/`desktop` — no IP, no
user agent, no cookies. That is also why the funnel counts *distinct sessions*
rather than users.

## What is verified, and what is not

Verified here: the fitting maths and orientation detection (unit tests against the
real sample models), the full HTTP surface including admin and validation
(31 PHPUnit tests), the fit of all four samples against the reference (admin
preview), colourway switching, shoe switching, snapshots, and the camera-denied
path. The tracking pipeline was also run end to end against a video file, and
produced **the same result as the unmodified upstream demo on identical input**.

Not verified here: behaviour with a real camera on real phones — the environment
used to build this has no camera. Follow the device checklist in DEPLOYMENT.md
before launching.

## Worth doing next

- Per-shopper leg occlusion via a segmentation mask, instead of the generic
  occluder, for a cleaner shoe opening.
- A size recommendation, which needs a reference object (a coin or a card) in
  frame or a depth sensor — today's tracker deliberately does not measure feet.
- Queue the analytics endpoint behind a write-buffer if traffic ever outgrows
  SQLite.

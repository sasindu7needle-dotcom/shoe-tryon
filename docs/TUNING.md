# Tuning foot tracking

Tracking quality depends far more on the camera and framing than on the code.
This is how to find the right settings for the devices your shoppers actually
use, instead of guessing.

## First, rule out the framing

The `NN_FOOT_*` networks are trained on **a phone held above the feet, looking
down, from about 1 m (3 ft)**, with both feet in frame and decent light.

A **laptop webcam is the worst case**: it sees feet side-on, from too close, at
a steep angle, through a different lens. Poor results there say little about
how the product behaves on a phone. Test on a phone before changing anything —
`DEPLOYMENT.md` has the device checklist, and `adb reverse tcp:8000 tcp:8000`
lets an Android phone reach a local dev server over USB (`localhost` on the
phone counts as a secure origin, so the camera is allowed).

## The overlay

Open any try-on page with `?debug=1`, signed in as an admin (or anywhere with
`APP_DEBUG=true`):

```
https://your-site/try-on/street-runner?debug=1
```

It shows, every frame:

| Reading | Meaning |
|---|---|
| `fps` | Tracker loop rate. Below ~10 on a phone means the device is struggling; lower `TRYON_MAX_DPR`. |
| `foot N: score` | The **raw network score**, even when it is below the threshold. This is the number to watch. |
| `DETECTED` / `no` | Whether the score passed the detection threshold. |
| `pose ok` / `POSE FAILED` | Whether the 3D pose solved. Detected but failing here points at the field of view. |
| `video` / `canvas` | Capture resolution versus render resolution. |
| `acquiring` / `tracking` / `lost` | Independent pose-filter state for each foot. Acquiring requires consecutive valid observations; missing or rejected poses are hidden. |
| `delta` | Translation in tracker units and rotation in degrees before pose filtering. This is a diagnostic, not measured foot registration error. |

`Draw landmarks` overlays the nine tracked points, which is the quickest way to
see whether the network is finding feet at all or locking onto something else.

## What to change, in order

**1. Camera field of view** — the biggest lever, and the usual cause of shoes
that tilt, float, or sit at the wrong scale. The 3D pose is solved against this
number, so it must roughly match the real lens. The library's default is 33°;
phone rear cameras are nearer 45–55°. Use the presets, then keep what looks
right on the device:

```
TRYON_CAMERA_FOV=50
```

**2. Detection threshold** — if the overlay shows scores hovering just under the
threshold (say 0.72 against 0.80), the network *is* seeing the foot and the
threshold is too strict. Lower it until it locks on reliably, then add a little
margin. Too low and it will latch onto hands, bags or patterned floors:

```
TRYON_DETECTION_THRESHOLD=0.7
```

**3. Stabiliser** — `beta` trades smoothness for responsiveness (lower is
smoother but lags), `min cut-off` sets the floor. Only worth touching if the fit
is right but jittery.

An additional time-based pose filter rejects isolated jumps and smooths position
and orientation. The overlay distinguishes a successful solve from acquisition
of a visible lock. A gap of 150 ms resets filter history. Source FOV now maps
through the video's cover crop using focal lengths; retain the source FOV setting
when changing orientation rather than manually compensating for portrait mode.

**4. A different network** — upstream ships several. Fetch them and switch in
the overlay's dropdown:

```bash
npm run models:foot-nets
```

They are ~19 MB each and gitignored. If one is clearly better on your devices,
ship it instead:

```
TRYON_NEURAL_NETWORK=vendor/webar-rocks/neural-nets/NN_FOOT_29.json
```

`NN_BAREFOOT_3` is only 1.3 MB and is worth a look if your shoppers try shoes on
bare feet — a much faster first load, at the cost of socks and shoes.

**Copy these settings** puts the current values on the clipboard as `.env`
lines. Put them in `.env` and restart to make them the default for everyone.

## What the overlay cannot fix

- **Per-shoe** misalignment — one shoe sitting wrong while others are fine is a
  model problem. Use the admin fit editor (see the `shoe-model-onboarding` skill).
- **Nothing detected at any threshold** — framing, light, or the feet being out
  of frame. Check the landmarks overlay before blaming settings.

## Check the new shoe profiles on the same phone

Use the updated models with asset version `3`. Keep the same camera, lighting,
framing and FOV for the first comparison. Capture each shoe with both feet still,
toes turned inward/outward, and the phone looking straight down and obliquely.
Include a bare-foot reference from the same position and a short motion clip.

For the ballet flat, check that skin is visible through the opening up to the
vamp without losing the rim. For the runner, check heel seating, toe coverage
and both sides of the collar. For the boot, check the cuff around the leg and
that the shaft stays intact; ankle bending remains limited by foot-only tracking.
Compare the live preview and downloaded snapshot, then check loss/reacquisition,
switching products, and FPS in debug mode.

If every shoe shares the same tilt or offset, adjust the FOV in the overlay
before changing individual shoe calibration. If only one shoe is wrong, use its
admin fit controls or edit its Blender fit markers. Record the device/browser,
selected FOV and view with fresh captures so further tuning has a repeatable
reference. These checks are pending; automated geometry/render checks do not
establish real-world foot registration accuracy.

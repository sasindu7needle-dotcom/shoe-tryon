---
name: shoe-model-onboarding
description: Add a new shoe model to the virtual try-on catalogue — license check, GLB preparation with glTF-Transform, orientation/fit calibration, poster, and verification. Use when adding, replacing, optimising or debugging a shoe model, or when a shoe looks wrong on the foot.
---

# Adding a shoe to the try-on catalogue

## 1. Check the licence first

The model ships to every visitor, so it must allow commercial use and
redistribution. Record the exact notice in the product's **Model credit** field —
CC BY *requires* attribution and a note that the file was modified. The `/credits`
page lists these automatically.

Known-good sources: your own models, CC0 (Poly Haven), CC BY with attribution
(Khronos glTF sample assets, many Sketchfab models), MIT (WebAR.rocks demos).
Watch for trademarks: a real brand's logo on a model is a trademark problem even
when the mesh is CC-licensed.

## 2. Prepare the file

Requirements, all enforced by how the runtime works:

- **One right shoe.** The left foot is mirrored at runtime. A left shoe is fine —
  tick *Mirror* in the fit editor.
- **`.glb`, under ~3 MB**, textures ≤ 1024 px.
- **No Draco, meshopt or quantization.** The left-foot mirror transforms vertex
  buffers directly in three.js r131, which needs float attributes.
- Indexed geometry (`weld()`); the runtime adds an index if missing.

For a bundled sample, add it to `scripts/prepare-sample-models.mjs` and run
`npm run models:samples`; the script normalises orientation (toes +X, up +Y,
metres, sole on y=0), resizes textures and asserts sane proportions. For a
one-off, the same tooling works ad hoc:

```bash
npx @gltf-transform/cli optimize in.glb out.glb --texture-size 1024 --compress false
```

## 3. Upload and fit

1. **Admin → Products → Add product**, upload the `.glb`, save (it starts as a draft).
2. On **Adjust fit**: press *Detect orientation*. It reads the geometry — longest
   horizontal axis is the length, the taller end is the heel, and the toe tip
   leans toward the big-toe side, which reveals left vs right.
3. Check the preview: the shoe should sit on the ghost reference shoe with the
   leg through its opening and the sole on the grid. If it is rotated 90°, the
   forward/up axes are wrong — fix those before touching the sliders.
4. Fine-tune with the sliders (centimetres and degrees) only for small
   adjustments: a boot worn over thick socks might want `scale` 1.03.
5. *Capture poster* on the product form, then tick **Published**.

## 4. Verify

- Fit preview: shoe aligned with the reference, no clipping through the ankle.
- `/try-on/{slug}?demo=/some-clip.mp4` renders it without a camera.
- A real phone, pointed at your feet from ~1 m — the only way to judge the fit.

## When a shoe looks wrong

| Symptom | Cause |
|---|---|
| Rotated 90° or lying on its side | Wrong forward/up axis — re-run *Detect orientation* |
| Toes point at the shopper | Forward axis sign flipped (`+z` vs `-z`) |
| Shoe on the wrong foot / laces on the inside | It is a left shoe — tick *Mirror* |
| Floats above or sinks into the foot | Offset "Down ↔ up" in the fit editor |
| Right size but wrong angle on **every** shoe | Not the model: tune `TRYON_CAMERA_FOV` |
| Black or unlit | Baked lighting in emissive textures — see `useBakedLightingOnly()` in the prepare script |

## Key files

- `resources/js/tryon/shoe-fitter.js` — the foot frame and fitting maths
- `resources/js/tryon/orientation.js` — orientation/chirality detection
- `scripts/prepare-sample-models.mjs` — sample asset pipeline
- `tests/js/*.test.mjs` — run `npm test` after touching either of the first two

# Shoe optimization and tracking audit — 15 October 2026

The four shipped shoes meet the project's current limits of 30,000 triangles,
3 MiB per GLB and 1024 × 1024 textures. No mesh reduction is justified by those
limits alone. Tracking accuracy and sustained mobile performance still need
physical-device verification.

## Measured assets

The running Blender 5.2.2 LTS add-on at `localhost:9876` imported the actual
`public/models/samples/*.glb` files into temporary scenes. Its original scene,
three objects and two materials were restored after the audit. Source assets
and authoring files were not edited.

| Shoe | GLB MB (decimal) | Triangles per shoe | Shoe draw calls per pass | All-variant texture estimate (MiB) |
| --- | ---: | ---: | ---: | ---: |
| Street runner | 0.981 | 22,700 | 1 | 26.7 |
| Canvas sneaker | 0.410 | 8,720 | 4 | 10.7 |
| Ballet flat | 0.387 | 12,800 | 1 | 16.0 |
| Rain boot | 0.467 | 14,032 | 1 | 32.0 |

Every mesh has UVs, finite coordinates, zero loose vertices and zero zero-area
faces at the audit tolerance. Dimensions and sole placement agree with the
existing Blender report. Geometry and texture hashes match the previous
`resources/models/optimized/result.json` report.

Blender reports boundary edges on the imported meshes. These counts include
split vertices in exported render geometry; they do not establish visible holes
or justify welding across UV/normal seams.

Draw calls count scene mesh primitives, excluding depth masks and additional
passes. Two visible feet double the shoe primitive count. The texture estimate
assumes every embedded variant texture is resident as RGBA8 with mipmaps; it
excludes geometry, environment maps, render targets and tracker allocations.
It is a planning estimate, not a GPU measurement. All four models together
contain approximately 85.3 MiB by this estimate, despite just 2.24 MB of GLBs.

## Tracking changes made

- Reconcile the latest tab visibility after asynchronous camera pause/resume.
  Returning during a pending pause now reacquires a stream.
- Apply the same reconciliation after camera flips and startup, including
  releasing a newly acquired stream if the page became hidden.
- Avoid reacquiring a healthy camera on duplicate visible events.
- Release acquired camera tracks and detach the stream if video playback fails.

These changes improve camera lifecycle reliability. They do not change the
network, pose smoothing thresholds, camera FOV or authored shoe calibration.

## Remaining optimization priorities

1. Measure debug FPS and capture fit on the target phones with both feet still,
   turning, moving and leaving/re-entering frame. Include tab background/resume,
   camera flip, portrait/landscape and at least a sustained session. Compare
   identical camera positions before adjusting FOV or individual fit markers.
2. Profile decoded texture residency when switching through all products and
   colourways. If memory pressure appears, evaluate bounded model caching and
   smaller texture variants with side-by-side visual checks. The boot and runner
   have the largest estimated texture footprints.
3. If rendering is the bottleneck, evaluate a lower-detail runner and combining
   compatible canvas primitives. Preserve material variants, normals, UVs and
   the shoe openings; re-run the production r131 fit/mirroring tests.
4. Measure network startup separately. The installed `NN_FOOT_31.json` is
   19,597,238 bytes uncompressed; `NN_BAREFOOT_3.json` is 1,287,757 bytes.
   Compare the smaller network against the intended barefoot/sock/shoe inputs
   before changing defaults. These sizes are not measured compressed transfers.

The prior `docs/QA-REPORT.md` also records product/colourway selection races and
tracking funnel accounting issues. Those remain outside these camera fixes.
The boot's authored cuff mask remains a rigid approximation driven by foot pose;
Blender geometry inspection cannot validate its fit when a real ankle bends.

## Verification and reruns

- `npm test`: 36 tests passed, including five camera lifecycle regressions and
  the production Three.js r131 loading, variants, fit, mask, mirroring and pose
  tests. Camera regressions use controlled asynchronous boundaries; they do not
  simulate hardware camera behavior.
- `npm run build`: passed; existing model-viewer chunk size warning remains.
- `git diff --check`: passed.
- `npm run models:audit:blender`: repeat the live Blender geometry audit with
  the add-on running on port 9876.
- `node scripts/audit-models.mjs storage/app/model-build/gltf-live-audit.json`:
  repeat the GLB, texture, draw-call and memory-estimate audit.

Detailed live evidence is under `storage/app/model-build/blender-live-audit.json`
and `storage/app/model-build/gltf-live-audit.json`. No physical-phone footage or
GPU profiling was available for this audit, so no FPS or registration-accuracy
improvement is claimed.

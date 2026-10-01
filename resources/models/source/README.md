# Sample model sources

Originals for the shoes in `public/models/samples`, kept so the catalogue can be
rebuilt with `npm run models:samples`. They are excluded from the Docker image.

All four allow commercial use. Keep the credit lines below in sync with each
product's **Model credit** field in the admin, which is what `/credits` shows.

| File | Source | License | Credit required |
|---|---|---|---|
| `street-runner.glb` | [Khronos glTF-Sample-Assets — Materials Variants Shoe](https://github.com/KhronosGroup/glTF-Sample-Assets/tree/main/Models/MaterialsVariantsShoe) | CC BY 4.0, © 2021 Shopify | **Yes** — attribution and a note that it was modified |
| `canvas-sneaker.glb` | [WebAR.rocks.hand](https://github.com/WebAR-rocks/WebAR.rocks.hand) foot VTO demo (`converseShoe2.glb`) | MIT, © 2020 WebAR.rocks | Keep the MIT notice |
| `ballet-flat.glb` | WebAR.rocks.hand foot VTO demo (`ballerinaShoe.glb`) | MIT, © 2020 WebAR.rocks | Keep the MIT notice |
| `rain-boot/` | [Poly Haven — Rubber Boots](https://polyhaven.com/a/rubber_boots) | CC0 | None (appreciated) |

`public/models/occluder.glb` — the foot and leg mask used to hide the parts of a
shoe the real leg covers — also comes from WebAR.rocks.hand (MIT).

## What the build does to them

`scripts/prepare-sample-models.mjs` rotates each model to toes +X / up +Y in
metres with the sole on y=0, resizes textures to 1024 px WebP, welds geometry,
turns the boot's clean and dirty pairs into `KHR_materials_variants` colourways,
and makes the canvas sneaker's baked emissive lighting render correctly.

These are samples, not a catalogue: replace them with your own product scans or
models before launching.

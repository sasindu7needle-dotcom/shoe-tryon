# Blender shoe fit assets

The four `.blend` files contain isolated, editable shoe scenes with packed images
and `Fit_*` empty objects. `.fit.json` contains their exported anchors and mask
profiles. The runner, flat and boot use version 2 masks; the canvas sneaker keeps
its version 1 profile. Original meshes/materials are preserved in `../source`.

All four models were already below 30,000 triangles and 3 MB. Their geometry and
texture bytes are unchanged in the final GLBs; optimization here is calibrated
fit and per-shoe masking, without destructive mesh reduction.

## Reproduce from originals

1. Run `node scripts/prepare-sample-models.mjs --stage` in the repository.
2. In Blender (including through MCP on port 9876), run:

   ```python
   PROJECT_ROOT = r"F:\Sab Cis\3 year sem 1\7Needle Intern\2026.06\Thilakawardhana\shoetryonbit"
   exec(compile(open(PROJECT_ROOT + '/scripts/prepare-shoes-blender.py', encoding='utf-8').read(), 'prepare-shoes-blender.py', 'exec'))
   ```

   Alternatively run `blender --background --factory-startup --python
   scripts/prepare-shoes-blender.py -- "<absolute repository path>"`.
3. Run `npm run models:samples`, then `npm test` and `npm run build`.
4. Run `node scripts/audit-models.mjs resources/models/optimized/result.json`.

The Blender script audits only the imported shoe meshes and preserves the user's
active scene. It refuses silent geometry changes: changed geometry needs review
and a geometry export before metadata is packaged with the original source.

## Edit the fit

Open the relevant `.blend`, move its `Fit_heel`, `Fit_toe`, `Fit_sole`, and
`Fit_ankleOpening` empties. Blender is Z-up; the exported metadata is glTF Y-up.
For version 2, move `FitSection_<volume>_<index>` empties to adjust the mask's
cross-sections. Each empty is a flattened ellipse: its X/Y scales are the
length/width radii in Blender, and its position is the section centre. Keep them
ordered from lowest to highest. The `FitMask-*` wire meshes preview their shape;
they are rebuilt on export and excluded from shoe geometry. Version 1 mask
`radius` (length/width), `bottom` and `top` remain in the scene's `tryOnFit.occlusion`
custom property. To export edits, set `EXPORT_CURRENT = True`
alongside `PROJECT_ROOT` before executing the script above. Use `False` to rebuild
from the authored defaults in the script. Set `ONLY_SLUGS` to a list of slugs to
rebuild just those assets without replacing other authoring files or audit rows.
Then run `npm run models:samples`.

Version 2 JSON uses `occlusion.volumes`, each with a name and 2–24 ordered
`sections` of `{center: [x,y,z], radius: [length,width]}` in glTF Y-up metres.
The runtime builds capped, indexed lofts and applies the exact shoe transform.
The flat mask covers the exposed instep; the boot mask starts inside the cuff
rather than extending through the shaft. These are authored approximations and
need live phone validation, especially when the ankle bends.

New authoring files record a shoe geometry hash. Moving fit markers is supported;
editing shoe geometry causes metadata export to fail rather than silently
discarding the edit when original geometry is packaged. This update required
no geometry edits. A later mesh edit needs an explicit geometry export and
integration with the material-preserving packaging path.

Blender 5.2's material-variant exporter failed and subsequently crashed on these
assets. Final GLB packaging therefore uses glTF Transform to retain the original
texture channels and colourways exactly; it does not round-trip their materials
through Blender. Saved `.blend` files and metadata were completed using Blender's
background mode after the MCP connection was lost.

`baseline.json`, `blender-audit.json` and `result.json` document before/after
assets. The baseline report is a fixed comparison artifact; do not regenerate it
from the new GLBs. Licences and attribution remain in `../source/README.md`.

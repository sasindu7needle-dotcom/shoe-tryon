"""Run in Blender after `node scripts/prepare-sample-models.mjs --stage`.

Set PROJECT_ROOT before exec() when using Blender MCP. This preserves the active
scene and selection; only new ShoeFit scenes and their imported data are edited.
"""
import bpy
import bmesh
import json
import struct
import sys
from pathlib import Path
from mathutils import Vector

ROOT = Path(globals().get('PROJECT_ROOT') or sys.argv[sys.argv.index('--') + 1]).resolve()
INPUT = ROOT / 'storage/app/model-build/input'
OUTPUT = ROOT / 'resources/models/optimized'
OUTPUT.mkdir(parents=True, exist_ok=True)
SLUGS = ['street-runner', 'canvas-sneaker', 'ballet-flat', 'rain-boot']
# Opening centres and mask radii authored against top and side views, in glTF
# metres. Masks stay inside the opening; no shopper-specific leg shape is assumed.
PROFILES = {
    'street-runner': {'opening': [-0.079, 0.086, -0.004], 'radius': [0.032, 0.025], 'bottom': 0.06, 'top': 0.30},
    'canvas-sneaker': {'opening': [-0.075, 0.075, 0.0], 'radius': [0.029, 0.024], 'bottom': 0.045, 'top': 0.29},
    'ballet-flat': {'opening': [-0.078, 0.038, 0.0], 'radius': [0.030, 0.023], 'bottom': 0.028, 'top': 0.27},
    'rain-boot': {'opening': [-0.069, 0.355, 0.0], 'radius': [0.039, 0.029], 'bottom': 0.055, 'top': 0.58},
}


def write_profile(scene, slug):
    profile = scene['tryOnFit'].to_dict()
    for obj in scene.objects:
        name = obj.get('fitAnchor')
        if name in profile['anchors']:
            p = obj.matrix_world.translation
            profile['anchors'][name] = [p.x, p.z, -p.y]
    (OUTPUT / (slug + '.fit.json')).write_text(json.dumps(profile, indent=2) + '\n')
    scene['tryOnFit'] = profile
    bpy.data.libraries.write(str(OUTPUT / (slug + '.blend')), {scene},
                             path_remap='RELATIVE', fake_user=True, compress=True)


def export_scene(scene, slug, bounds):
    bpy.context.window.scene = scene
    profile = PROFILES[slug]
    anchors = {'heel': [bounds[0][0], 0, profile['opening'][2]],
               'toe': [bounds[1][0], 0, profile['opening'][2]],
               'sole': [0, 0, 0], 'ankleOpening': profile['opening']}
    scene['tryOnFit'] = {'version': 1, 'anchors': anchors,
                         'occlusion': {k: v for k, v in profile.items() if k != 'opening'}}
    scene['shoeSlug'] = slug
    # A standalone .blend must carry this asset's variant names on its scene.
    raw = (INPUT / (slug + '.glb')).read_bytes()
    size = struct.unpack_from('<I', raw, 12)[0]
    gltf = json.loads(raw[20:20 + size])
    registry = scene.gltf2_KHR_materials_variants_variants
    registry.clear()
    for index, variant in enumerate(gltf.get('extensions', {}).get('KHR_materials_variants', {}).get('variants', [])):
        item = registry.add()
        item.name, item.variant_idx = variant['name'], index
    markers = scene.collection.children.get('Fit anchors - ' + slug)
    if markers is None:
        markers = bpy.data.collections.new('Fit anchors - ' + slug)
        scene.collection.children.link(markers)
    for name, coordinate in anchors.items():
        obj = next((obj for obj in markers.objects if obj.get('fitAnchor') == name), None)
        if obj is None:
            obj = bpy.data.objects.new('Fit_' + name, None)
            markers.objects.link(obj)
        obj['fitAnchor'] = name
        obj.empty_display_size = 0.008
        obj.location = (coordinate[0], -coordinate[2], coordinate[1])
    bpy.context.view_layer.update()
    # Package with glTF Transform, preserving the original material variants
    # and texture channels. Blender 5.2's multi-scene variants exporter reads
    # scene[0] and can crash on these assets. No material round-trip is needed.
    write_profile(scene, slug)


def prepare():
    old_scene = bpy.context.window.scene
    registry_owner = bpy.data.scenes[0]
    registry = getattr(registry_owner, 'gltf2_KHR_materials_variants_variants', [])
    old_variants = [(item.name, item.variant_idx) for item in registry]
    scenes = []
    report = []
    try:
        for slug in SLUGS:
            scene = bpy.data.scenes.new('ShoeFit-' + slug)
            scenes.append(scene)
            bpy.context.window.scene = scene
            bpy.ops.import_scene.gltf(filepath=str(INPUT / (slug + '.glb')),
                                      import_webp_texture=True, import_pack_images=True)
            meshes = [obj for obj in scene.objects if obj.type == 'MESH']
            before = after = 0
            points = []
            for obj in meshes:
                mesh = obj.data
                before += sum(len(poly.vertices) - 2 for poly in mesh.polygons)
                bm = bmesh.new()
                bm.from_mesh(mesh)
                # Only loose vertices and zero-area faces: do not collapse UV
                # seams, hard normals, laces, or the interior of the opening.
                loose = [v for v in bm.verts if not v.link_faces]
                bmesh.ops.delete(bm, geom=loose, context='VERTS')
                degenerate = [f for f in bm.faces if f.calc_area() < 1e-14]
                bmesh.ops.delete(bm, geom=degenerate, context='FACES')
                bm.to_mesh(mesh)
                bm.free()
                mesh.update()
                after += sum(len(poly.vertices) - 2 for poly in mesh.polygons)
                points.extend(obj.matrix_world @ v.co for v in mesh.vertices)

            # glTF X/Y/Z -> Blender X/-Z/Y. Authoring markers are visible and
            # editable in the .blend; their exported positions use glTF axes.
            gltf_points = [Vector((p.x, p.z, -p.y)) for p in points]
            bounds = [[min(p[i] for p in gltf_points) for i in range(3)],
                      [max(p[i] for p in gltf_points) for i in range(3)]]
            report.append({'slug': slug, 'trianglesBefore': before,
                           'trianglesAfter': after, 'bounds': bounds})
            if before != after:
                raise RuntimeError(slug + ': geometry changed; review and export geometry before packaging metadata')
            export_scene(scene, slug, bounds)

        (OUTPUT / 'blender-audit.json').write_text(json.dumps(report, indent=2) + '\n')
    finally:
        bpy.context.window.scene = old_scene
        # The importer itself stores variant names on scene[0]. Restore them
        # after writing our isolated authoring files, including on an error.
        registry = getattr(registry_owner, 'gltf2_KHR_materials_variants_variants', None)
        if registry is not None:
            registry.clear()
            for name, index in old_variants:
                item = registry.add()
                item.name, item.variant_idx = name, index
    print(json.dumps(report))


if globals().get('EXPORT_CURRENT', False) or '--export-current' in sys.argv:
    scene = bpy.context.scene
    slug = scene.get('shoeSlug')
    if slug not in SLUGS:
        raise RuntimeError('Select a prepared ShoeFit scene before exporting its anchors')
    write_profile(scene, slug)
else:
    prepare()

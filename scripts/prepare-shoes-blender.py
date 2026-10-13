"""Run in Blender after `node scripts/prepare-sample-models.mjs --stage`.

Set PROJECT_ROOT before exec() when using Blender MCP. This preserves the active
scene and selection; only new ShoeFit scenes and their imported data are edited.
"""
import bpy
import bmesh
import json
import re
import struct
import sys
from hashlib import sha256
from math import isfinite
from math import cos, sin, tau
from pathlib import Path
from mathutils import Vector

ROOT = Path(globals().get('PROJECT_ROOT') or sys.argv[sys.argv.index('--') + 1]).resolve()
INPUT = ROOT / 'storage/app/model-build/input'
OUTPUT = ROOT / 'resources/models/optimized'
OUTPUT.mkdir(parents=True, exist_ok=True)
SLUGS = ['street-runner', 'canvas-sneaker', 'ballet-flat', 'rain-boot']
# glTF metres, authored against the mesh in Blender. Heel/toe follow the foot
# portion, excluding the runner's outsole overhang and the boot's tall shaft.
# Sections describe the exposed instep/leg, not the full shoe or a measured leg.
PROFILES = {
    'street-runner': {
        'heel': -0.139, 'toe': 0.141, 'opening': [-0.082, 0.086, -0.004],
        'volumes': [{'name': 'ankle', 'sections': [
            {'center': [-0.081, 0.055, -0.004], 'radius': [0.043, 0.025]},
            {'center': [-0.081, 0.080, -0.004], 'radius': [0.043, 0.027]},
            {'center': [-0.082, 0.110, -0.004], 'radius': [0.034, 0.027]},
            {'center': [-0.090, 0.310, -0.004], 'radius': [0.030, 0.029]},
        ]}],
    },
    'canvas-sneaker': {
        'opening': [-0.075, 0.075, 0.0],
        'volumes': [{'name': 'ankle', 'sections': [
            {'center': [-0.080, 0.045, 0.0], 'radius': [0.040, 0.026]},
            {'center': [-0.075, 0.068, 0.0], 'radius': [0.044, 0.029]},
            {'center': [-0.076, 0.105, 0.0], 'radius': [0.030, 0.026]},
            {'center': [-0.086, 0.290, 0.0], 'radius': [0.032, 0.030]},
        ]}],
    },
    'ballet-flat': {
        'heel': -0.119, 'toe': 0.120, 'opening': [-0.078, 0.038, 0.005],
        'volumes': [{'name': 'instep-and-ankle', 'sections': [
            {'center': [-0.020, 0.022, 0.004], 'radius': [0.090, 0.028]},
            {'center': [-0.018, 0.038, 0.004], 'radius': [0.092, 0.033]},
            {'center': [-0.062, 0.075, 0.005], 'radius': [0.035, 0.024]},
            {'center': [-0.075, 0.110, 0.005], 'radius': [0.028, 0.025]},
            {'center': [-0.080, 0.280, 0.005], 'radius': [0.029, 0.027]},
        ]}],
    },
    'rain-boot': {
        'heel': -0.115, 'toe': 0.129, 'opening': [-0.064, 0.365, 0.0],
        'volumes': [{'name': 'cuff-and-calf', 'sections': [
            # Start inside the cuff: a mask through the whole shaft cuts it away.
            {'center': [-0.064, 0.346, 0.0], 'radius': [0.056, 0.033]},
            {'center': [-0.064, 0.375, 0.0], 'radius': [0.057, 0.035]},
            {'center': [-0.073, 0.420, 0.0], 'radius': [0.043, 0.030]},
            {'center': [-0.083, 0.640, 0.0], 'radius': [0.042, 0.032]},
        ]}],
    },
}


def geometry_digest(scene):
    digest = sha256()
    bpy.context.view_layer.update()
    for obj in sorted(scene.objects, key=lambda obj: obj.name):
        if obj.type != 'MESH' or obj.get('fitMaskPreview'):
            continue
        for vertex in obj.data.vertices:
            digest.update(struct.pack('<3f', *(obj.matrix_world @ vertex.co)))
        for polygon in obj.data.polygons:
            digest.update(struct.pack('<I', len(polygon.vertices)))
            for index in polygon.vertices:
                digest.update(struct.pack('<I', index))
    return digest.hexdigest()


def validate_profile(profile):
    for anchor in profile['anchors'].values():
        if len(anchor) != 3 or not all(isfinite(value) for value in anchor):
            raise ValueError('Fit anchors must contain three finite coordinates')
    for volume in profile['occlusion'].get('volumes', []):
        sections = volume['sections']
        if not 2 <= len(sections) <= 24:
            raise ValueError('Each mask needs 2-24 sections')
        for index, section in enumerate(sections):
            center, radius = section['center'], section['radius']
            if len(center) != 3 or not all(isfinite(v) and abs(v) < 2 for v in center):
                raise ValueError('Mask centers must contain three finite coordinates in metres')
            if len(radius) != 2 or not all(isfinite(v) and 0 < v < 0.2 for v in radius):
                raise ValueError('Mask radii must be between 0 and 0.2 metres')
            if index and center[1] - sections[index-1]['center'][1] <= 1e-5:
                raise ValueError('Mask sections must increase in height; do not cross them')
        if sections[-1]['center'][1] - sections[0]['center'][1] > 1:
            raise ValueError('Mask height cannot exceed one metre')


def write_profile(scene, slug):
    if scene.get('fitGeometryHash') and geometry_digest(scene) != scene['fitGeometryHash']:
        raise RuntimeError('Shoe geometry changed: this pipeline packages fit metadata with original geometry. '
                           'Export and integrate the edited mesh explicitly before packaging it.')
    profile = scene['tryOnFit'].to_dict()
    for obj in scene.objects:
        name = obj.get('fitAnchor')
        if name in profile['anchors']:
            p = obj.matrix_world.translation
            profile['anchors'][name] = [p.x, p.z, -p.y]
        volume_index = obj.get('fitVolume')
        section_index = obj.get('fitSection')
        if volume_index is not None and section_index is not None:
            p = obj.matrix_world.translation
            section = profile['occlusion']['volumes'][volume_index]['sections'][section_index]
            section['center'] = [p.x, p.z, -p.y]
            section['radius'] = [abs(obj.scale.x), abs(obj.scale.y)]
    validate_profile(profile)
    update_mask_preview(scene, profile)
    update_tracker_reference(scene, profile)
    (OUTPUT / (slug + '.fit.json')).write_text(json.dumps(profile, indent=2) + '\n')
    scene['tryOnFit'] = profile
    bpy.data.libraries.write(str(OUTPUT / (slug + '.blend')), {scene},
                             path_remap='RELATIVE', fake_user=True, compress=True)


def update_tracker_reference(scene, profile):
    """Show the actual NN's nine landmarks in the web fitter's coordinates.

    These are reference guides, not a scan of the shopper. Rebuild on export so
    changes to the heel/toe/sole anchors stay aligned with runtime fitting.
    """
    source = (ROOT / 'resources/js/tryon/shoe-fitter.js').read_text(encoding='utf-8')
    frame_source = source.split('export const FOOT_FRAME = Object.freeze({', 1)[1].split('});', 1)[0]
    frame = {key: float(value) for key, value in re.findall(r'(\w+):\s*(-?[\d.]+)', frame_source)}
    network = json.loads((ROOT / 'public/vendor/webar-rocks/neural-nets/NN_BAREFOOT_3.json').read_text())
    heel, toe = profile['anchors']['heel'], profile['anchors']['toe']
    scale = frame['length'] / (toe[0] - heel[0])
    center = profile['anchors']['ankleOpening'][2]
    sole = profile['anchors']['sole'][1]
    collection_name = 'Tracker reference - ' + scene['shoeSlug']
    collection = scene.collection.children.get(collection_name)
    if collection is None:
        collection = bpy.data.collections.new(collection_name)
        scene.collection.children.link(collection)
    for obj in list(collection.objects):
        bpy.data.objects.remove(obj, do_unlink=True)
    for keypoint in network['exportData']['keypoints']:
        x, y, z = keypoint['position']
        obj = bpy.data.objects.new('Tracker_' + keypoint['label'], None)
        obj.location = (heel[0] + (y - frame['heelY']) / scale,
                        -(center + (x - frame['centerX']) / scale),
                        sole + (z - frame['soleZ']) / scale)
        obj.empty_display_type = 'SPHERE'
        obj.empty_display_size = 0.003
        obj.show_in_front = True
        obj['trackerLandmark'] = keypoint['label']
        collection.objects.link(obj)
    scene['trackerReferenceNetwork'] = 'NN_BAREFOOT_3'
    scene['trackerReferenceFrame'] = frame
    bpy.context.view_layer.update()


def update_mask_preview(scene, profile):
    """Visible wire masks in authoring scenes, excluded from shoe geometry."""
    for obj in list(scene.objects):
        if obj.get('fitMaskPreview'):
            mesh = obj.data
            bpy.data.objects.remove(obj, do_unlink=True)
            if mesh.users == 0:
                bpy.data.meshes.remove(mesh)
    for volume in profile['occlusion'].get('volumes', []):
        vertices, faces = [], []
        segments = 32
        for section in volume['sections']:
            x, y, z = section['center']
            rx, rz = section['radius']
            vertices.extend((x + rx*cos(i*tau/segments), -(z + rz*sin(i*tau/segments)), y)
                            for i in range(segments))
        for ring in range(len(volume['sections']) - 1):
            for i in range(segments):
                a, b = ring*segments+i, ring*segments+(i+1)%segments
                faces.append((a, b, b+segments, a+segments))
        faces.extend([tuple(reversed(range(segments))),
                      tuple(range(len(vertices)-segments, len(vertices)))])
        mesh = bpy.data.meshes.new('FitMask-' + volume['name'])
        mesh.from_pydata(vertices, [], faces)
        mesh.update()
        obj = bpy.data.objects.new(mesh.name, mesh)
        obj['fitMaskPreview'] = True
        obj.display_type = 'WIRE'
        obj.hide_render = True
        scene.collection.objects.link(obj)


def export_scene(scene, slug, bounds):
    bpy.context.window.scene = scene
    profile = PROFILES[slug]
    anchors = {'heel': [profile.get('heel', bounds[0][0]), 0, profile['opening'][2]],
               'toe': [profile.get('toe', bounds[1][0]), 0, profile['opening'][2]],
               'sole': [0, 0, 0], 'ankleOpening': profile['opening']}
    occlusion = {'volumes': profile['volumes']} if 'volumes' in profile else {
        key: profile[key] for key in ['radius', 'bottom', 'top']}
    scene['tryOnFit'] = {'version': 2 if 'volumes' in profile else 1,
                        'anchors': anchors, 'occlusion': occlusion}
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
    for volume_index, volume in enumerate(occlusion.get('volumes', [])):
        for section_index, section in enumerate(volume['sections']):
            obj = bpy.data.objects.new(f"FitSection_{volume['name']}_{section_index}", None)
            markers.objects.link(obj)
            obj['fitVolume'], obj['fitSection'] = volume_index, section_index
            obj.empty_display_type = 'SPHERE'
            obj.empty_display_size = 1
            x, y, z = section['center']
            obj.location = (x, -z, y)
            obj.scale = (section['radius'][0], section['radius'][1], 0.001)
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
            if globals().get('ONLY_SLUGS') and slug not in ONLY_SLUGS:
                continue
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
            scene['fitGeometryHash'] = geometry_digest(scene)
            export_scene(scene, slug, bounds)

        audit_path = OUTPUT / 'blender-audit.json'
        previous = json.loads(audit_path.read_text()) if audit_path.exists() else []
        audits = {item['slug']: item for item in previous + report}
        audit_path.write_text(json.dumps([audits[slug] for slug in SLUGS if slug in audits], indent=2) + '\n')
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

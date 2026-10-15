"""Audit shipped shoes through the running Blender add-on (localhost:9876).

Run: python scripts/audit-shoes-blender.py
Imports into temporary scenes, restores the active scene and removes imported data.
Writes storage/app/model-build/blender-live-audit.json; never exports shoe assets.
"""
import json
from pathlib import Path


def audit(root):
    import bpy
    import bmesh
    from datetime import datetime, timezone
    from hashlib import sha256
    from math import isfinite

    original_scene = bpy.context.window.scene
    collections = ['scenes', 'objects', 'collections', 'meshes', 'materials', 'images', 'textures', 'node_groups', 'actions']
    existing = {name: set(getattr(bpy.data, name)) for name in collections}
    owner = bpy.data.scenes[0]
    registry = getattr(owner, 'gltf2_KHR_materials_variants_variants', [])
    variants = [(item.name, item.variant_idx) for item in registry]
    report = {'blender': bpy.app.version_string, 'utc': datetime.now(timezone.utc).isoformat(), 'models': []}
    try:
        for slug in ['street-runner', 'canvas-sneaker', 'ballet-flat', 'rain-boot']:
            path = root / 'public/models/samples' / (slug + '.glb')
            scene = bpy.data.scenes.new('Audit-' + slug)
            bpy.context.window.scene = scene
            bpy.ops.import_scene.gltf(filepath=str(path), import_pack_images=True)
            bpy.context.view_layer.update()
            meshes, points = [], []
            for obj in scene.objects:
                if obj.type != 'MESH':
                    continue
                mesh = obj.data
                mesh.calc_loop_triangles()
                bm = bmesh.new()
                bm.from_mesh(mesh)
                meshes.append({
                    'name': obj.name, 'vertices': len(mesh.vertices), 'triangles': len(mesh.loop_triangles),
                    'materialSlots': len(mesh.materials), 'uvLayers': len(mesh.uv_layers),
                    'looseVertices': sum(not v.link_faces for v in bm.verts),
                    'degenerateFaces': sum(f.calc_area() < 1e-14 for f in bm.faces),
                    'boundaryEdges': sum(e.is_boundary for e in bm.edges),
                    'nonManifoldEdges': sum(not e.is_manifold for e in bm.edges),
                    'finiteCoordinates': all(isfinite(c) for v in mesh.vertices for c in v.co),
                })
                bm.free()
                points.extend(obj.matrix_world @ v.co for v in mesh.vertices)
            # Convert Blender Z-up back to glTF Y-up metres.
            coords = [(p.x, p.z, -p.y) for p in points]
            report['models'].append({
                'slug': slug, 'bytes': path.stat().st_size, 'sha256': sha256(path.read_bytes()).hexdigest(),
                'triangles': sum(mesh['triangles'] for mesh in meshes), 'meshes': meshes,
                'bounds': [[min(p[i] for p in coords) for i in range(3)],
                           [max(p[i] for p in coords) for i in range(3)]],
            })
    finally:
        bpy.context.window.scene = original_scene
        registry = getattr(owner, 'gltf2_KHR_materials_variants_variants', None)
        if registry is not None:
            registry.clear()
            for name, index in variants:
                item = registry.add()
                item.name, item.variant_idx = name, index
        imported = [item for name in collections for item in getattr(bpy.data, name) if item not in existing[name]]
        bpy.data.batch_remove(ids=imported)
    report['restoredScene'] = bpy.context.scene.name
    output = root / 'storage/app/model-build/blender-live-audit.json'
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report))


def connect(root):
    import socket
    script = str(Path(__file__).resolve())
    code = f"import runpy; runpy.run_path({script!r}, init_globals={{'PROJECT_ROOT': {str(root)!r}}})"
    with socket.create_connection(('localhost', 9876), timeout=10) as connection:
        connection.settimeout(60)
        connection.sendall(json.dumps({'type': 'execute_code', 'params': {'code': code}}).encode())
        response = bytearray()
        while True:
            chunk = connection.recv(65536)
            if not chunk:
                raise RuntimeError('Blender closed the connection before returning a complete response')
            response.extend(chunk)
            try:
                result = json.loads(response)
                break
            except (json.JSONDecodeError, UnicodeDecodeError):
                continue
    if result.get('status') != 'success':
        raise RuntimeError(result)
    print(result['result']['result'])


if 'PROJECT_ROOT' in globals():
    audit(Path(PROJECT_ROOT))
elif __name__ == '__main__':
    connect(Path(__file__).resolve().parents[1])

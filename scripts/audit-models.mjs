import { NodeIO } from '@gltf-transform/core';
import { ALL_EXTENSIONS } from '@gltf-transform/extensions';
import { getBounds } from '@gltf-transform/functions';
import { createHash } from 'node:crypto';
import fs from 'node:fs/promises';
import sharp from 'sharp';

const io = new NodeIO().registerExtensions(ALL_EXTENSIONS);
const report = [];
for (const slug of ['street-runner', 'canvas-sneaker', 'ballet-flat', 'rain-boot']) {
    const file = `public/models/samples/${slug}.glb`;
    const document = await io.read(file);
    const root = document.getRoot();
    const geometry = createHash('sha256');
    let triangles = 0;
    for (const mesh of root.listMeshes()) {
        for (const primitive of mesh.listPrimitives()) {
            triangles += (primitive.getIndices()?.getCount() ?? primitive.getAttribute('POSITION').getCount()) / 3;
            for (const accessor of [...primitive.listAttributes(), primitive.getIndices()].filter(Boolean)) {
                const array = accessor.getArray();
                geometry.update(Buffer.from(array.buffer, array.byteOffset, array.byteLength));
            }
        }
    }
    const textures = await Promise.all(root.listTextures().map(async texture => {
        const data = texture.getImage();
        const { width, height } = await sharp(data).metadata();
        return { width, height, sha256: createHash('sha256').update(data).digest('hex') };
    }));
    report.push({
        slug, bytes: (await fs.stat(file)).size, triangles,
        materials: root.listMaterials().length, textures,
        bounds: getBounds(root.getDefaultScene()),
        geometryHash: geometry.digest('hex'),
        variants: root.listExtensionsUsed().find(e => e.extensionName === 'KHR_materials_variants')?.listVariants().map(v => v.getName()) ?? [],
        fitProfile: root.getDefaultScene().getExtras().tryOnFit ?? null,
    });
}
const output = JSON.stringify(report, null, 2) + '\n';
if (process.argv[2]) await fs.writeFile(process.argv[2], output);
else process.stdout.write(output);

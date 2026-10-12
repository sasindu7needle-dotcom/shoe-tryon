/**
 * Builds the web-ready sample shoes in public/models/samples from the
 * originals in resources/models/source.
 *
 * Every output uses one convention so the samples share a calibration and
 * look right in <model-viewer>: toes toward +X, up +Y, right-hand side +Z,
 * sole at Y = 0, real-world metres. Geometry is welded (indexed) but not
 * quantized or Draco/meshopt compressed, because the try-on mirrors meshes
 * for the left foot with three.js r131, which needs float attributes.
 *
 * Usage: npm run models:samples
 */
import { NodeIO } from '@gltf-transform/core';
import { ALL_EXTENSIONS, KHRMaterialsVariants } from '@gltf-transform/extensions';
import { dedup, getBounds, prune, textureCompress, weld } from '@gltf-transform/functions';
import fs from 'node:fs/promises';
import path from 'node:path';
import sharp from 'sharp';

const SOURCE_DIR = 'resources/models/source';
const staging = process.argv.includes('--stage');
const OUTPUT_DIR = staging ? 'storage/app/model-build/input' : 'public/models/samples';
const MAX_TEXTURE_SIZE = 1024;

// Rotation from the WebAR.rocks foot frame (x right, y toes, z up):
// y -> X, z -> Y, x -> Z (-120 degrees about (1, 1, 1)).
const FOOT_FRAME_TO_GLTF = [-0.5, -0.5, -0.5, 0.5];
// Toes +Z -> +X (90 degrees about Y).
const TOES_Z_TO_X = [0, Math.SQRT1_2, 0, Math.SQRT1_2];

const io = new NodeIO().registerExtensions(ALL_EXTENSIONS).registerDependencies({ sharp });

const samples = [
    {
        slug: 'street-runner',
        source: 'street-runner.glb',
        // Already toes +X, up +Y, in metres.
    },
    {
        slug: 'canvas-sneaker',
        source: 'canvas-sneaker.glb',
        rotation: FOOT_FRAME_TO_GLTF,
        lengthMetres: 0.28,
        prepare: useBakedLightingOnly,
    },
    {
        slug: 'ballet-flat',
        source: 'ballet-flat.glb',
        rotation: FOOT_FRAME_TO_GLTF,
        lengthMetres: 0.25,
    },
    {
        slug: 'rain-boot',
        source: 'rain-boot/rubber_boots_1k.gltf',
        rotation: TOES_Z_TO_X,
        prepare: keepRightBootWithMudVariant,
    },
];

/**
 * The canvas sneaker stores its baked appearance in emissive textures. A white,
 * fully metallic base would add environment reflections on top, so make the
 * base black and rough and let the emissive texture carry the look.
 */
function useBakedLightingOnly(document) {
    for (const material of document.getRoot().listMaterials()) {
        material.setBaseColorFactor([0, 0, 0, 1]).setMetallicFactor(0).setRoughnessFactor(1);
    }
}

/**
 * The Poly Haven asset contains clean and dirty pairs side by side. Keep the
 * clean right boot and expose the dirty material as a KHR_materials_variants
 * option, so shoppers can switch between them.
 */
function keepRightBootWithMudVariant(document) {
    const root = document.getRoot();
    const nodes = Object.fromEntries(root.listNodes().map((node) => [node.getName(), node]));
    const clean = nodes.rubber_boots_r;
    const dirty = nodes.rubber_boots_dirt_r;
    if (!clean || !dirty) {
        throw new Error('Unexpected rain boot source: rubber_boots_r / rubber_boots_dirt_r not found.');
    }

    const variants = document.createExtension(KHRMaterialsVariants);
    const cleanVariant = variants.createVariant('Clean');
    const muddyVariant = variants.createVariant('Muddy');
    const cleanPrimitive = clean.getMesh().listPrimitives()[0];
    const muddyMaterial = dirty.getMesh().listPrimitives()[0].getMaterial();

    cleanPrimitive.setExtension(
        'KHR_materials_variants',
        variants
            .createMappingList()
            .addMapping(variants.createMapping().setMaterial(cleanPrimitive.getMaterial()).addVariant(cleanVariant))
            .addMapping(variants.createMapping().setMaterial(muddyMaterial).addVariant(muddyVariant)),
    );

    clean.setTranslation([0, 0, 0]);
    for (const node of root.listNodes()) {
        if (node !== clean) {
            node.dispose();
        }
    }
}

/** Rotate, scale and centre the scene: sole on Y = 0, centred on X and Z. */
function normalizeTransform(document, { rotation, lengthMetres }) {
    const scene = document.getRoot().getDefaultScene() ?? document.getRoot().listScenes()[0];
    const pivot = document.createNode('ShoeRoot');
    for (const child of scene.listChildren()) {
        scene.removeChild(child);
        pivot.addChild(child);
    }
    scene.addChild(pivot);

    if (rotation) {
        pivot.setRotation(rotation);
    }

    if (lengthMetres) {
        const bounds = getBounds(scene);
        pivot.setScale(new Array(3).fill(lengthMetres / (bounds.max[0] - bounds.min[0])));
    }

    const bounds = getBounds(scene);
    pivot.setTranslation([
        -(bounds.min[0] + bounds.max[0]) / 2,
        -bounds.min[1],
        -(bounds.min[2] + bounds.max[2]) / 2,
    ]);

    return getBounds(scene);
}

function assertShoeProportions(slug, { min, max }) {
    const [length, height, width] = [0, 1, 2].map((axis) => max[axis] - min[axis]);
    // Toes along X means X is the longest horizontal extent; real shoes are 20-35 cm.
    if (length < width || length < 0.2 || length > 0.35) {
        throw new Error(`${slug}: unexpected size ${[length, height, width].map((v) => v.toFixed(3))} (expected toes along +X, metres).`);
    }

    return { length, height, width };
}

await fs.mkdir(OUTPUT_DIR, { recursive: true });

for (const sample of samples) {
    const document = await io.read(path.join(SOURCE_DIR, sample.source));
    sample.prepare?.(document);

    const bounds = normalizeTransform(document, sample);
    if (!staging) {
        const profile = JSON.parse(await fs.readFile(`resources/models/optimized/${sample.slug}.fit.json`, 'utf8'));
        document.getRoot().getDefaultScene().setExtras({ tryOnFit: profile });
    }
    const { length, height, width } = assertShoeProportions(sample.slug, bounds);

    await document.transform(
        prune(),
        dedup(),
        weld(),
        textureCompress({ encoder: sharp, targetFormat: 'webp', resize: [MAX_TEXTURE_SIZE, MAX_TEXTURE_SIZE] }),
    );

    const output = path.join(OUTPUT_DIR, `${sample.slug}.glb`);
    await io.write(output, document);
    const { size } = await fs.stat(output);
    console.log(
        `${sample.slug}: ${(size / 1024 / 1024).toFixed(2)} MB, ` +
            `L ${(length * 100).toFixed(1)} cm x H ${(height * 100).toFixed(1)} cm x W ${(width * 100).toFixed(1)} cm`,
    );
}

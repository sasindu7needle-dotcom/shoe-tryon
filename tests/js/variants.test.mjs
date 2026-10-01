import test from 'node:test';
import assert from 'node:assert/strict';

import { applyVariant, listVariants, resolveVariant } from '../../resources/js/tryon/variants.js';

/** Minimal stand-in for a parsed glTF with two colourways on one mesh. */
function fakeGltf() {
    const materials = { 0: { name: 'clean' }, 1: { name: 'muddy' } };
    const mesh = {
        isMesh: true,
        material: materials[0],
        userData: {
            gltfExtensions: {
                KHR_materials_variants: {
                    mappings: [
                        { material: 0, variants: [0] },
                        { material: 1, variants: [1] },
                    ],
                },
            },
        },
    };
    const root = { traverse: (fn) => fn(mesh) };

    return {
        gltf: {
            parser: {
                json: { extensions: { KHR_materials_variants: { variants: [{ name: 'Clean' }, { name: 'Muddy' }] } } },
                getDependency: (_type, index) => Promise.resolve(materials[index]),
                assignFinalMaterial: () => {},
            },
        },
        root,
        mesh,
    };
}

test('variant names are listed, and missing extensions give an empty list', () => {
    assert.deepEqual(listVariants(fakeGltf().gltf), ['Clean', 'Muddy']);
    assert.deepEqual(listVariants({ parser: { json: {} } }), []);
    assert.deepEqual(listVariants({}), []);
});

test('names are matched regardless of case, as exporters lower-case them', () => {
    assert.equal(resolveVariant(['midnight', 'beach'], 'Midnight'), 'midnight');
    assert.equal(resolveVariant(['Clean', 'Muddy'], 'muddy'), 'Muddy');
    assert.equal(resolveVariant(['Clean'], 'Sparkly'), null);
    assert.equal(resolveVariant(['Clean'], null), null);
});

test('applying a variant swaps the material and reports the file\'s own name', async () => {
    const { gltf, root, mesh } = fakeGltf();

    assert.equal(await applyVariant(gltf, root, 'muddy'), 'Muddy');
    assert.equal(mesh.material.name, 'muddy');

    assert.equal(await applyVariant(gltf, root, 'Clean'), 'Clean');
    assert.equal(mesh.material.name, 'clean');
});

test('an unknown variant restores the default material', async () => {
    const { gltf, root, mesh } = fakeGltf();
    await applyVariant(gltf, root, 'Muddy');

    assert.equal(await applyVariant(gltf, root, 'Sparkly'), null);
    assert.equal(mesh.material.name, 'clean', 'falls back to the material the mesh shipped with');
});

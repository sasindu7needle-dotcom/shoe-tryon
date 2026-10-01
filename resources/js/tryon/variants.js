/**
 * KHR_materials_variants (colourways) for three.js r131, which parses the
 * extension into userData but has no API to switch variants.
 */

// Original material per mesh. A WeakMap, not userData: Object3D.clone()
// JSON-serialises userData, which would serialise whole materials.
const defaultMaterials = new WeakMap();

export function listVariants(gltf) {
    const extension = gltf.parser?.json?.extensions?.KHR_materials_variants;

    return extension ? extension.variants.map((variant) => variant.name) : [];
}

/**
 * Match a variant name from the catalogue against the names inside the file,
 * which are often lower-cased by exporters.
 */
export function resolveVariant(variants, name) {
    const wanted = String(name ?? '').toLowerCase();

    return variants.find((variant) => variant.toLowerCase() === wanted) ?? null;
}

/**
 * Switch `root` (a glTF scene) to the named variant. Unknown names restore the
 * default materials. Resolves with the applied variant name or null.
 */
export async function applyVariant(gltf, root, variantName) {
    const variants = listVariants(gltf);
    const index = variants.indexOf(resolveVariant(variants, variantName));
    const pending = [];

    root.traverse((object) => {
        const mappings = object.isMesh ? object.userData.gltfExtensions?.KHR_materials_variants?.mappings : null;
        if (!mappings) {
            return;
        }
        if (!defaultMaterials.has(object)) {
            defaultMaterials.set(object, object.material);
        }

        const mapping = index >= 0 ? mappings.find((candidate) => candidate.variants.includes(index)) : null;
        const material = mapping
            ? gltf.parser.getDependency('material', mapping.material)
            : Promise.resolve(defaultMaterials.get(object));

        pending.push(
            material.then((resolved) => {
                object.material = resolved;
                gltf.parser.assignFinalMaterial(object);
            }),
        );
    });

    await Promise.all(pending);

    return index >= 0 ? variants[index] : null;
}

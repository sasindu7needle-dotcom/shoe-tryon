/** Lighting shared by the live try-on and the admin fit preview (three.js r131). */
export function setupLighting(THREE, renderer, scene) {
    renderer.outputEncoding = THREE.sRGBEncoding;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1;

    // Image-based lighting gives PBR materials believable reflections.
    if (THREE.RoomEnvironment) {
        const generator = new THREE.PMREMGenerator(renderer);
        scene.environment = generator.fromScene(new THREE.RoomEnvironment(), 0.04).texture;
        generator.dispose();
    }

    const key = new THREE.DirectionalLight(0xffffff, 0.8);
    key.position.set(0.5, 1, 1);
    scene.add(new THREE.HemisphereLight(0xffffff, 0x5c5c66, 0.4), key);
}

export function loadGltf(THREE, url) {
    return new THREE.GLTFLoader().loadAsync(url);
}

export function disposeObject(object) {
    object.traverse((child) => {
        if (child.isMesh) {
            child.geometry.dispose();
        }
    });
}

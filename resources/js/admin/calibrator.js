/**
 * Fit preview for the admin.
 *
 * Shows the uploaded shoe in the tracker's foot frame next to the reference
 * shoe and the foot/leg occluder, so a model can be aligned without a camera.
 */
import { loadGltf, setupLighting } from '../tryon/scene.js';
import { detectOrientation } from '../tryon/orientation.js';
import {
    createShoeOccluders,
    collectImportedPoints,
    preflightModel,
    DEFAULT_CALIBRATION,
    ensureIndexedGeometry,
    fitShoe,
    FOOT_FRAME,
} from '../tryon/shoe-fitter.js';

const THREE = window.THREE;
const config = JSON.parse(document.getElementById('calibrator-config').textContent);
const root = document.getElementById('calibrator');
const form = root.querySelector('[data-controls]');
const canvas = root.querySelector('[data-preview]');
const loadStatus = root.querySelector('[data-load-status]');
const saveStatus = root.querySelector('[data-save-status]');
const detectResult = root.querySelector('[data-detect-result]');

let calibration = { ...DEFAULT_CALIBRATION, ...config.product.calibration };
let model = null;
let shoe = null;
let genericOccluder = null;
let masks = [];
let runtimePreflight = false;
let orientationPoints = null;

/* ── Scene ───────────────────────────────────────────────────────── */

const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
const scene = new THREE.Scene();
const camera = new THREE.PerspectiveCamera(35, 1, 0.1, 100);
camera.up.set(0, 0, 1); // the foot frame is Z-up
camera.position.set(2.4, -2.6, 1.6);

const controls = new THREE.OrbitControls(camera, canvas);
controls.target.set(FOOT_FRAME.centerX, 0, FOOT_FRAME.soleZ + 0.3);
controls.enableDamping = true;
controls.enablePan = false;

setupLighting(THREE, renderer, scene);

const grid = new THREE.GridHelper(4, 16, 0x78716c, 0x44403c);
grid.rotation.x = Math.PI / 2;
grid.position.z = FOOT_FRAME.soleZ;
scene.add(grid, new THREE.AxesHelper(0.9));

function ghostMaterial(color) {
    return new THREE.MeshStandardMaterial({
        color,
        transparent: true,
        opacity: 0.28,
        depthWrite: false,
        roughness: 0.6,
    });
}

const maskMaterial = ghostMaterial(0xfb7185);

function applyMaterial(object, material) {
    object.traverse((child) => {
        if (child.isMesh) {
            child.material = material;
        }
    });

    return object;
}

function resize() {
    const { clientWidth, clientHeight } = canvas;
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(clientWidth, clientHeight, false);
    camera.aspect = clientWidth / Math.max(clientHeight, 1);
    camera.updateProjectionMatrix();
}

new ResizeObserver(resize).observe(canvas);

renderer.setAnimationLoop(() => {
    controls.update();
    renderer.render(scene, camera);
});

/* ── Shoe ────────────────────────────────────────────────────────── */

function refreshShoe() {
    if (!model) {
        return;
    }
    if (shoe) {
        scene.remove(shoe);
    }
    masks.forEach(mask => { scene.remove(mask); mask.geometry.dispose(); });
    masks = [];
    try {
        shoe = fitShoe(THREE, model, calibration);
        scene.add(shoe);
        masks = createShoeOccluders(THREE, shoe, genericOccluder);
        masks.forEach(mask => scene.add(applyMaterial(mask, maskMaterial)));
        loadStatus.textContent = '';
        runtimePreflight = true;
    } catch (error) {
        runtimePreflight = false;
        loadStatus.textContent = error.message;
    }
}

function detect() {
    try {
        const result = detectOrientation(orientationPoints, calibration.upAxis);
        calibration = { ...calibration, forwardAxis: result.forwardAxis, mirror: result.mirror };
        writeForm();
        refreshShoe();
        detectResult.textContent =
            `Detected: toes along ${result.forwardAxis.toUpperCase()}, ` +
            `${result.mirror ? 'left shoe (mirrored)' : 'right shoe'}. ` +
            `Confidence ${Math.round(result.confidence.toe * 100)}% / ${Math.round(result.confidence.side * 100)}%. ` +
            'This is a suggestion. Confirm toes, top and side visually before saving.';
    } catch (error) {
        detectResult.textContent = `Could not detect the orientation: ${error.message}`;
    }
}

/* ── Form ────────────────────────────────────────────────────────── */

function readValue(name) {
    const [key, index] = name.split('.');

    return index === undefined ? calibration[key] : calibration[key][Number(index)];
}

function writeValue(name, value) {
    const [key, index] = name.split('.');
    if (index === undefined) {
        calibration[key] = value;
    } else {
        calibration[key] = calibration[key].map((current, i) => (i === Number(index) ? value : current));
    }
}

function writeForm() {
    for (const field of form.elements) {
        if (!field.name) {
            continue;
        }
        const value = readValue(field.name);
        if (field.type === 'checkbox') {
            field.checked = Boolean(value);
        } else {
            field.value = value;
        }
        updateOutput(field);
    }
}

function updateOutput(field) {
    const output = form.querySelector(`[data-output-for="${field.name}"]`);
    if (output) {
        output.textContent = `${Number(field.value).toFixed(field.step < 0.1 ? 2 : 1)} ${field.dataset.unit ?? ''}`.trim();
    }
}

form.addEventListener('input', (event) => {
    const field = event.target;
    if (!field.name) return;
    writeValue(field.name, field.type === 'checkbox' ? field.checked : field.type === 'range' ? Number(field.value) : field.value);
    updateOutput(field);
    refreshShoe();
});

form.addEventListener('click', (event) => {
    const action = event.target.dataset.action;
    if (action === 'detect') {
        detect();
    } else if (action === 'reset') {
        calibration = { ...calibration, scale: 1, offsetCm: [0, 0, 0], rotationDeg: [0, 0, 0] };
        writeForm();
        refreshShoe();
    }
});

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!runtimePreflight || (config.requiresReview && !form.querySelector('[data-visual-review]')?.checked)) {
        saveStatus.textContent = 'Load a compatible model and confirm the visual review before saving.';
        return;
    }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    saveStatus.textContent = 'Saving…';

    try {
        const response = await fetch(config.saveUrl, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ ...calibration, model_path: config.modelPath, runtime_preflight: runtimePreflight, visual_review: form.querySelector('[data-visual-review]')?.checked ?? false }),
        });
        const payload = await response.json();
        if (!response.ok) {
            throw new Error(Object.values(payload.errors ?? {}).flat().join(' ') || payload.message);
        }
        calibration = payload.calibration;
        writeForm();
        saveStatus.textContent = 'Fit saved.';
    } catch (error) {
        saveStatus.textContent = `Could not save: ${error.message}`;
    } finally {
        button.disabled = false;
    }
});

/* ── Load ────────────────────────────────────────────────────────── */

(async function load() {
    try {
        const [productGltf, referenceGltf, occluderGltf] = await Promise.all([
            loadGltf(THREE, config.product.modelUrl),
            loadGltf(THREE, config.reference.modelUrl),
            loadGltf(THREE, config.tracker.occluderUrl),
        ]);

        scene.add(applyMaterial(fitShoe(THREE, referenceGltf.scene, config.reference.calibration), ghostMaterial(0x38bdf8)));
        genericOccluder = occluderGltf.scene.children[0];

        model = productGltf.scene;
        if (config.requiresReview) preflightModel(THREE, model);
        let vertices = 0;
        model.traverseVisible((child) => { vertices += child.isMesh ? child.geometry.attributes.position.count : 0; });
        orientationPoints = collectImportedPoints(THREE, model, Math.max(1, Math.ceil(vertices / 40000)));
        ensureIndexedGeometry(THREE, model);
        writeForm();
        refreshShoe();
        resize();
    } catch (error) {
        console.error(error);
        loadStatus.textContent = 'The 3D model could not be loaded.';
    }
})();

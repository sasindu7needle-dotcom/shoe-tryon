import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import { NodeIO } from '@gltf-transform/core';
import { ALL_EXTENSIONS } from '@gltf-transform/extensions';
import sharp from 'sharp';
import { fitShoe, createShoeOccluders, computeBounds, ensureIndexedGeometry, FOOT_FRAME } from '../../resources/js/tryon/shoe-fitter.js';
import { createFootPoseFilter } from '../../resources/js/tryon/pose-filter.js';
import { listVariants, applyVariant } from '../../resources/js/tryon/variants.js';

async function runtime() {
    const context = vm.createContext({ console, TextDecoder, TextEncoder, setTimeout, clearTimeout, performance });
    context.window = context;
    context.self = context;
    vm.runInContext(await fs.readFile('public/vendor/three-r131/three.min.js', 'utf8'), context);
    vm.runInContext(await fs.readFile('public/vendor/three-r131/GLTFLoader.js', 'utf8'), context);
    return context;
}

test('all shipped GLBs load, fit, mirror and switch variants with the production r131 loader', async () => {
    const context = await runtime();
    const { THREE } = context;
    assert.equal(THREE.REVISION, '131');
    const io = new NodeIO().registerExtensions(ALL_EXTENSIONS);
    const baseline = JSON.parse(await fs.readFile('resources/models/optimized/baseline.json', 'utf8'));
    for (const original of baseline) {
        const buffer = await fs.readFile(`public/models/samples/${original.slug}.glb`);
        const document = await io.readBinary(buffer);
        const root = document.getRoot();
        assert.ok(buffer.byteLength <= 3 * 1024 * 1024);
        let triangles = 0;
        for (const mesh of root.listMeshes()) for (const primitive of mesh.listPrimitives()) {
            assert.ok(primitive.getIndices(), 'left-foot mirroring needs indices');
            assert.equal(primitive.getAttribute('POSITION').getComponentType(), 5126);
            assert.equal(primitive.getAttribute('NORMAL').getComponentType(), 5126);
            triangles += primitive.getIndices().getCount() / 3;
        }
        assert.ok(triangles <= 30000);
        for (const texture of root.listTextures()) {
            const { width, height } = await sharp(texture.getImage()).metadata();
            assert.ok(width <= 1024 && height <= 1024);
        }
        // Use the real parser and materials, stubbing only browser image decode.
        // Image dimensions/format are verified above; GPU rendering needs a browser.
        const loader = new THREE.GLTFLoader();
        loader.register(parser => {
            parser.loadTexture = async () => new THREE.Texture();
            return { name: 'EXT_texture_webp', loadTexture: async () => new THREE.Texture() };
        });
        const data = buffer.buffer.slice(buffer.byteOffset, buffer.byteOffset + buffer.byteLength);
        const gltf = await new Promise((resolve, reject) => loader.parse(data, '', resolve, reject));
        assert.equal(gltf.scene.userData.tryOnFit.version, 1);
        assert.deepEqual([...listVariants(gltf)], original.variants);
        for (const variant of listVariants(gltf)) assert.equal(await applyVariant(gltf, gltf.scene, variant), variant);
        ensureIndexedGeometry(THREE, gltf.scene);
        const shoe = fitShoe(THREE, gltf.scene, { forwardAxis: '+x' });
        const heel = new THREE.Vector3().fromArray(gltf.scene.userData.tryOnFit.anchors.heel).applyMatrix4(gltf.scene.matrixWorld);
        assert.ok(Math.abs(heel.y - FOOT_FRAME.heelY) < 1e-6);
        const [mask] = createShoeOccluders(THREE, shoe);
        assert.ok(mask.geometry.attributes.position.array.every(Number.isFinite));
        // Reflect geometry just as the production helper does for left feet.
        shoe.traverse(child => {
            if (!child.isMesh) return;
            const reflected = child.geometry.clone().applyMatrix4(new THREE.Matrix4().makeScale(-1, 1, 1));
            assert.ok(reflected.attributes.position.array.every(Number.isFinite));
        });
    }
});

test('the actual tracker helper hides failed poses, clears lost feet, and resets on chirality changes', async () => {
    const context = await runtime();
    const { THREE } = context;
    let time = 0;
    let draws = 0;
    let solved = { rotation: [[1, 0, 0], [0, 1, 0], [0, 0, 1]], translation: [0, 0, 5] };
    context.WEBARROCKSHAND = { compute_pose: () => solved };
    context.window.devicePixelRatio = 3;
    const parents = [new THREE.Group(), new THREE.Group()];
    parents.forEach(parent => { parent.matrixAutoUpdate = false; parent.visible = false; });
    let reported;
    let dimensions;
    const state = {
        spec: { maxHandsDetected: 2, cameraZoom: 1, enableFlipObject: true, hideTrackerIfDetectionLost: true,
            cameraMinVideoDimFov: 50, cameraFovRange: [20, 75],
            VTOCanvas: { width: 600, height: 900, clientWidth: 400, clientHeight: 600 },
            callbackTrack: (_detections, valid) => { reported = [...valid]; } },
        three: { trackersParent: parents, trackersLeft: parents.map(() => new THREE.Group()), trackersRight: parents.map(() => new THREE.Group()),
            renderer: { render: () => { draws++; }, setSize() {}, setViewport() {} }, camera: new THREE.PerspectiveCamera() },
        video: { videoWidth: 720, videoHeight: 1280 },
        stabilizers: parents.map(() => ({ update: (points, w, h) => { dimensions = [w, h]; return points; }, reset() {} })),
        filters: parents.map(() => createFootPoseFilter(THREE, { now: () => time })),
        pose: { poseLandmarksIndices: [0], imgPointsPx: [[0, 0]], focals: [500, 500], matMov: new THREE.Matrix4(),
            objPointsRight: { points: [[0, 0, 0]], mean: new THREE.Vector3() }, objPointsLeft: { points: [[0, 0, 0]], mean: new THREE.Vector3() } },
    };
    context.testState = state;
    let source = await fs.readFile('public/vendor/webar-rocks/helpers/HandTrackerThreeHelper.js', 'utf8');
    // Inject dependency state into the vendored closure; execute its real pose
    // and visibility logic while replacing only WebGL/video drawing.
    source = source.replace('return that;', `
        _spec = testState.spec; Object.assign(_three, testState.three);
        _landmarksStabilizers = testState.stabilizers; _poseFilters = testState.filters;
        Object.assign(_poseEstimation, testState.pose);
        _videoElement = testState.video;
        _gl = { viewport() {}, flush() {} }; draw_video = function() {};
        that.testFocals = () => { update_focals(); return _poseEstimation.focals; };
        that.testFrame = callbackTrack; return that;`);
    vm.runInContext(source + '\nthis.helper = HandTrackerThreeHelper;', context);
    const detection = (right = true) => ({ isDetected: true, isRightHand: right, neuralNetworkInd: 0, landmarks: [[0, 0]] });
    const frame = (a, b = { isDetected: false }) => context.helper.testFrame([a, b]);
    frame(detection());
    assert.equal(parents[0].visible, false);
    assert.equal(parents[0].userData.poseSolved, true, 'acquiring is distinct from a failed solve');
    time = 40; frame(detection());
    assert.equal(parents[0].visible, true);
    assert.deepEqual(reported, [true, false]);
    assert.deepEqual(dimensions, [400, 600], 'uses actual CSS size despite the DPR cap');
    solved = null;
    time = 60; frame(detection());
    assert.equal(parents[0].visible, false);
    assert.equal(parents[0].userData.poseSolved, false);
    assert.deepEqual(reported, [false, false]);
    solved = { rotation: [[1, 0, 0], [0, 1, 0], [0, 0, 1]], translation: [0, Infinity, 5] };
    time = 80; frame(detection());
    assert.equal(parents[0].visible, false);
    solved.translation = [0, 0, 5];
    time = 100; frame(detection());
    assert.equal(parents[0].visible, true);
    time = 120; frame(detection(false));
    assert.equal(parents[0].visible, false, 'new side must acquire independently');
    time = 160; frame(detection(false));
    assert.equal(parents[0].visible, true);
    const before = draws;
    time = 180; frame({ isDetected: false });
    assert.equal(parents[0].visible, false);
    assert.equal(draws, before + 1, 'a fully lost frame clears the canvas');
    context.helper.reset_tracking();
    assert.equal(parents[0].userData.footLock.status, 'lost');
    const model = new THREE.Mesh(new THREE.BoxGeometry(0.28, 0.1, 0.09));
    model.geometry.translate(0.01, 0.05, 0.02);
    const shoe = fitShoe(THREE, model, { forwardAxis: '+x', offsetCm: [2, 0, 0] });
    context.helper.add_threeObject(shoe);
    const right = computeBounds(THREE, state.three.trackersRight[0]);
    const left = computeBounds(THREE, state.three.trackersLeft[0]);
    assert.ok(Math.abs(left.min.x + right.max.x) < 1e-6);
    assert.ok(Math.abs(left.max.x + right.min.x) < 1e-6);
    const mask = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.1, 1));
    context.helper.add_threeOccluder(mask);
    assert.equal(state.three.trackersLeft[0].children.length, 2);
    context.helper.clear_threeObjects(true);
    assert.equal(state.three.trackersLeft[0].children.length, 0);
    assert.equal(state.three.trackersRight[0].children.length, 0, 'product switch also detaches its mask');
    for (const [vw, vh, cw, ch] of [[720, 1280, 600, 900], [1280, 720, 600, 900], [720, 1280, 900, 600]]) {
        Object.assign(state.video, { videoWidth: vw, videoHeight: vh });
        Object.assign(state.spec.VTOCanvas, { width: cw, height: ch });
        context.helper.update_threeCamera();
        const expected = Math.min(vw, vh) / (2 * Math.tan(25 * Math.PI / 180)) * Math.max(cw / vw, ch / vh);
        const focal = context.helper.testFocals();
        assert.ok(Math.abs(focal[0] - expected) < 1e-6, 'PnP focal length matches the video cover crop');
        assert.ok(Math.abs(ch / (2 * Math.tan(state.three.camera.fov * Math.PI / 360)) - expected) < 1e-6, 'render camera uses the same projection');
    }
});

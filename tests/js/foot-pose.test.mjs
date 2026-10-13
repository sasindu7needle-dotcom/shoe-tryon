import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import * as THREE from 'three';
import { projectFootPoint, solveFootPose } from '../../resources/js/tryon/foot-pose.js';

const network = JSON.parse(await fs.readFile('public/vendor/webar-rocks/neural-nets/NN_BAREFOOT_3.json', 'utf8'));
const landmarks = network.exportData.keypoints.map(point => point.position);
const mean = [0, 1, 2].map(axis => landmarks.reduce((sum, point) => sum + point[axis], 0) / landmarks.length);
const points = landmarks.map(point => point.map((v, axis) => v - mean[axis]));
const identity = () => ({ ok: true, rotation: [[1, 0, 0], [0, 1, 0], [0, 0, 1]], translation: [0, 0, 5] });
const pixels = (pose = identity(), focal = 500, model = points) => model.map(point => projectFootPoint(point, pose, focal, focal));

async function nativeSolver() {
    const context = vm.createContext({ console, performance, URL, navigator: { userAgent: 'node' } });
    context.window = context;
    context.self = context;
    // Initialise the actual embedded PnP solver, stopping before camera/WebGL setup.
    const source = (await fs.readFile('public/vendor/webar-rocks/WebARRocksHand.js', 'utf8'))
        .replace('Sb=window.zyp;', 'Sb=window.zyp;return;');
    vm.runInContext(source + '\nthis.api=WEBARROCKSHAND;', context);
    context.api.init({});
    return (...args) => context.api.compute_pose(...args);
}

test('real native solver registers angled, mirrored and differently sized feet', async () => {
    const solve = await nativeSolver();
    const sockNetwork = JSON.parse(await fs.readFile('public/vendor/webar-rocks/neural-nets/NN_FOOT_31.json', 'utf8'));
    const sockLandmarks = network.exportData.keypoints.map(point =>
        sockNetwork.exportData.keypoints.find(candidate => candidate.label === point.label).position);
    const sockMean = [0, 1, 2].map(axis => sockLandmarks.reduce((sum, point) => sum + point[axis], 0) / sockLandmarks.length);
    const sockPoints = sockLandmarks.map(point => point.map((value, axis) => value - sockMean[axis]));
    for (const reference of [points, sockPoints]) {
        for (const mirror of [1, -1]) {
            const model = reference.map(([x, y, z]) => [mirror * x, y, z]);
            for (const [rx, ry, rz] of [[0, 0, 0], [0.5, 0.2, 0.8], [-0.35, 0.3, -1.2], [0.2, -0.4, 2.5]]) {
                const matrix = new THREE.Matrix4().makeRotationFromEuler(new THREE.Euler(rx, ry, rz)).elements;
                const pose = { ok: true, rotation: [0, 1, 2].map(i => [matrix[i], matrix[i + 4], matrix[i + 8]]), translation: [0.3, -0.2, 4] };
                for (const focal of [350, 700, 1050]) {
                    const result = solveFootPose(model, pixels(pose, focal, model), focal, focal, {}, solve);
                    assert.equal(result.ok, true, JSON.stringify({ mirror, rx, ry, rz, focal, quality: result.quality }));
                    assert.ok(result.quality.rmsRatio < 0.025, JSON.stringify({ mirror, rx, ry, rz, focal, result }));
                    // A visually plausible but wrong pose must not pass just because it is finite.
                    assert.ok(Math.abs(result.translation[2] - 4) < 0.1);
                }
            }
        }
    }
});

test('failed solver flags reject finite stale transforms', () => {
    const result = solveFootPose(points, pixels(), 500, 500, {}, () => ({ ...identity(), ok: false }));
    assert.equal(result.ok, false);
    assert.equal(result.quality.reason, 'pose');
});

test('nonfinite observations and tiny feet never enter the native solver', () => {
    let calls = 0;
    const solve = () => { calls++; return identity(); };
    const bad = pixels();
    bad[2][0] = NaN;
    assert.equal(solveFootPose(points, bad, 500, 500, {}, solve).quality.reason, 'landmarks');
    assert.equal(solveFootPose(points, pixels().map(p => p.map(v => v / 100)), 500, 500, {}, solve).quality.reason, 'too-small');
    assert.equal(calls, 0);
});

test('large landmark disagreement hides the shoe instead of accepting a finite wrong pose', () => {
    const wrong = { ...identity(), translation: [3, 0, 5] };
    let calls = 0;
    const result = solveFootPose(points, pixels(), 500, 500, {}, () => { calls++; return wrong; });
    assert.equal(result.ok, false);
    assert.equal(result.quality.reason, 'reprojection');
    assert.equal(calls, 1);
});

test('one drifting landmark can be excluded with bounded work; solver buffers are copied', () => {
    const observations = pixels();
    observations[0][0] += 22;
    const reused = identity();
    let calls = 0;
    const result = solveFootPose(points, observations, 500, 500, {}, () => { calls++; return reused; });
    assert.equal(result.ok, true);
    assert.equal(result.quality.reason, 'one-outlier');
    assert.equal(result.quality.excludedLandmark, 0);
    assert.equal(calls, 3);
    reused.translation[2] = 100;
    reused.rotation[0][0] = NaN;
    assert.equal(result.translation[2], 5);
    assert.equal(result.rotation[0][0], 1);
});

test('two inconsistent landmarks cannot be removed to invent a good fit', () => {
    const observations = pixels();
    observations[0][0] += 25;
    observations[4][0] -= 25;
    let calls = 0;
    const result = solveFootPose(points, observations, 500, 500, {}, () => { calls++; return identity(); });
    assert.equal(result.ok, false);
    assert.ok(calls <= 3);
});

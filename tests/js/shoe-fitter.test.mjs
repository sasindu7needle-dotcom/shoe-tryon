/**
 * The fitting maths runs against npm three here and the vendored r131 build in
 * the browser; both expose the same API for what these functions use.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';

import {
    computeBounds,
    createCalfOccluder,
    ensureIndexedGeometry,
    fitShoe,
    FOOT_FRAME,
    modelToFootRotation,
    normalizeCalibration,
    placeOccluder,
} from '../../resources/js/tryon/shoe-fitter.js';

/** A shoe-shaped box: toes along +X, up +Y, 28 cm long. */
function boxModel({ length = 0.28, height = 0.1, width = 0.09 } = {}) {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(length, height, width));
    mesh.geometry.translate(length / 2, height / 2, 0); // heel at origin, sole on y = 0
    const scene = new THREE.Group();
    scene.add(mesh);

    return scene;
}

test('calibration defaults are filled in and axes validated', () => {
    const calibration = normalizeCalibration({ forwardAxis: '+x' });
    assert.equal(calibration.upAxis, '+y');
    assert.equal(calibration.scale, 1);
    assert.deepEqual(calibration.offsetCm, [0, 0, 0]);

    assert.throws(() => normalizeCalibration({ forwardAxis: '+y', upAxis: '-y' }), /perpendicular/);
    assert.throws(() => normalizeCalibration({ forwardAxis: 'up' }), /Unknown axis/);
});

test('the forward axis becomes +Y and the up axis +Z in the foot frame', () => {
    for (const [forwardAxis, upAxis] of [['+x', '+y'], ['-z', '+y'], ['+y', '+z'], ['-x', '-z']]) {
        const rotation = modelToFootRotation(THREE, forwardAxis, upAxis);
        const forward = new THREE.Vector3(...{ '+x': [1, 0, 0], '-x': [-1, 0, 0], '+y': [0, 1, 0], '-y': [0, -1, 0], '+z': [0, 0, 1], '-z': [0, 0, -1] }[forwardAxis]);
        const up = new THREE.Vector3(...{ '+x': [1, 0, 0], '-x': [-1, 0, 0], '+y': [0, 1, 0], '-y': [0, -1, 0], '+z': [0, 0, 1], '-z': [0, 0, -1] }[upAxis]);

        const toes = forward.clone().applyMatrix4(rotation);
        const top = up.clone().applyMatrix4(rotation);

        assert.ok(toes.distanceTo(new THREE.Vector3(0, 1, 0)) < 1e-6, `${forwardAxis} should map to the toe direction`);
        assert.ok(top.distanceTo(new THREE.Vector3(0, 0, 1)) < 1e-6, `${upAxis} should map to up`);
        assert.ok(Math.abs(new THREE.Matrix4().copy(rotation).determinant() - 1) < 1e-6, 'must stay a rotation');
    }
});

test('a fitted shoe matches the reference heel, sole, centre line and length', () => {
    const fitted = fitShoe(THREE, boxModel(), { forwardAxis: '+x', upAxis: '+y' });
    const bounds = computeBounds(THREE, fitted);

    assert.ok(Math.abs(bounds.min.y - FOOT_FRAME.heelY) < 1e-6, 'heel aligned');
    assert.ok(Math.abs(bounds.min.z - FOOT_FRAME.soleZ) < 1e-6, 'sole aligned');
    assert.ok(Math.abs((bounds.min.x + bounds.max.x) / 2 - FOOT_FRAME.centerX) < 1e-6, 'centred across');
    assert.ok(Math.abs(bounds.max.y - bounds.min.y - FOOT_FRAME.length) < 1e-6, 'scaled to the reference length');
});

test('models are fitted the same way whichever axes they were exported with', () => {
    const reference = computeBounds(THREE, fitShoe(THREE, boxModel(), { forwardAxis: '+x', upAxis: '+y' }));

    // Same box, exported toes -Z (a common Blender export).
    const rotated = boxModel();
    rotated.rotation.y = -Math.PI / 2; // +X -> -Z
    const other = computeBounds(THREE, fitShoe(THREE, rotated, { forwardAxis: '-z', upAxis: '+y' }));

    for (const axis of ['x', 'y', 'z']) {
        assert.ok(Math.abs(reference.min[axis] - other.min[axis]) < 1e-6, `min ${axis}`);
        assert.ok(Math.abs(reference.max[axis] - other.max[axis]) < 1e-6, `max ${axis}`);
    }
});

test('scale, centimetre offsets and degrees are applied', () => {
    const plain = computeBounds(THREE, fitShoe(THREE, boxModel(), { forwardAxis: '+x' }));

    const bigger = computeBounds(THREE, fitShoe(THREE, boxModel(), { forwardAxis: '+x', scale: 1.1 }));
    const grown = (bigger.max.y - bigger.min.y) / (plain.max.y - plain.min.y);
    assert.ok(Math.abs(grown - 1.1) < 1e-6, 'scale grows the shoe');

    const moved = computeBounds(THREE, fitShoe(THREE, boxModel(), { forwardAxis: '+x', offsetCm: [0, 0, 1] }));
    const oneCm = FOOT_FRAME.length / FOOT_FRAME.lengthCm;
    assert.ok(Math.abs(moved.min.z - (plain.min.z + oneCm)) < 1e-6, '1 cm up is 1 cm up');

    const turned = fitShoe(THREE, boxModel(), { forwardAxis: '+x', rotationDeg: [0, 0, 90] });
    assert.ok(Math.abs(turned.rotation.z - Math.PI / 2) < 1e-6, 'degrees become radians');
});

test('mirroring turns a left shoe into a right one without changing the footprint', () => {
    const model = boxModel();
    model.children[0].geometry.translate(0, 0, 0.02); // make it asymmetric across the foot

    const plain = computeBounds(THREE, fitShoe(THREE, boxModel(), { forwardAxis: '+x' }));
    const mirrored = fitShoe(THREE, model, { forwardAxis: '+x', mirror: true });
    const bounds = computeBounds(THREE, mirrored);

    assert.equal(mirrored.getObjectByProperty('type', 'Group').children.length > 0, true);
    assert.ok(Math.abs(bounds.max.y - bounds.min.y - (plain.max.y - plain.min.y)) < 1e-6, 'length unchanged');
    assert.ok(Math.abs((bounds.min.x + bounds.max.x) / 2 - FOOT_FRAME.centerX) < 1e-6, 'still centred');
});

test('geometry without an index gets one, because left feet are mirrored by reversing it', () => {
    const model = boxModel();
    model.children[0].geometry.deleteAttribute('normal');
    model.children[0].geometry = model.children[0].geometry.toNonIndexed();
    assert.equal(model.children[0].geometry.index, null);

    ensureIndexedGeometry(THREE, model);

    const { index, attributes } = model.children[0].geometry;
    assert.equal(index.count, attributes.position.count);
});

test('the occluder keeps the transform used by the WebAR.rocks demo', () => {
    const occluder = placeOccluder(new THREE.Mesh(new THREE.BoxGeometry(1, 1, 1)));
    assert.equal(occluder.scale.x, 0.95);
    assert.equal(occluder.position.y, -0.02);
});

test('the calf occluder rises from the ankle along +Z', () => {
    const bounds = computeBounds(THREE, createCalfOccluder(THREE));
    assert.ok(bounds.min.z < FOOT_FRAME.soleZ + 1, 'starts around the ankle');
    assert.ok(bounds.max.z > 2, 'reaches well above the shoe');
});

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
    createShoeOccluders,
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

function profiledModel() {
    const model = boxModel();
    model.userData.tryOnFit = {
        version: 1,
        anchors: { heel: [0.01, 0, 0.01], toe: [0.27, 0, 0.01], sole: [0, 0.005, 0], ankleOpening: [0.06, 0.08, 0.01] },
        occlusion: { radius: [0.025, 0.02], bottom: 0.05, top: 0.3 },
    };
    return model;
}

test('authored anchors control the fit even when geometry extends beyond them', () => {
    const model = profiledModel();
    const fitted = fitShoe(THREE, model, { forwardAxis: '+x' });
    const point = (name) => new THREE.Vector3().fromArray(model.userData.tryOnFit.anchors[name]).applyMatrix4(model.matrixWorld);
    assert.ok(Math.abs(point('heel').y - FOOT_FRAME.heelY) < 1e-6);
    assert.ok(Math.abs(point('toe').y - point('heel').y - FOOT_FRAME.length) < 1e-6);
    assert.ok(Math.abs(point('sole').z - FOOT_FRAME.soleZ) < 1e-6);
    assert.ok(Math.abs(point('ankleOpening').x - FOOT_FRAME.centerX) < 1e-6);
    assert.equal(fitted.userData.tryOnFit.version, 1);
});

test('authored masks follow the same mirrored and calibrated model transform', () => {
    const model = profiledModel();
    const fitted = fitShoe(THREE, model, { forwardAxis: '+x', mirror: true, scale: 1.1, offsetCm: [2, 1, 3], rotationDeg: [5, 0, 15] });
    const [mask] = createShoeOccluders(THREE, fitted);
    const expected = new THREE.Vector3(0.06, 0.175, 0.01).applyMatrix4(model.matrixWorld);
    const center = computeBounds(THREE, mask).getCenter(new THREE.Vector3());
    assert.ok(center.distanceTo(expected) < 1e-6);
    assert.ok(mask.geometry.index);
});

function volumeModel() {
    const model = profiledModel();
    model.userData.tryOnFit.version = 2;
    model.userData.tryOnFit.occlusion = { volumes: [{ name: 'instep', sections: [
        { center: [0.10, 0.03, 0.01], radius: [0.07, 0.025] },
        { center: [0.08, 0.07, 0.01], radius: [0.05, 0.03] },
        { center: [0.06, 0.30, 0.01], radius: [0.025, 0.03] },
    ] }] };
    return model;
}

test('version 2 masks are closed, indexed volumes with caps visible from above', () => {
    const model = volumeModel();
    const shoe = fitShoe(THREE, model, { forwardAxis: '+x' });
    const [mask] = createShoeOccluders(THREE, shoe);
    assert.equal(mask.name, 'shoeOccluder_instep');
    const inverse = new THREE.Matrix4().copy(model.matrixWorld).invert();
    const geometry = mask.geometry.clone().applyMatrix4(inverse);
    const local = new THREE.Mesh(geometry, new THREE.MeshBasicMaterial({ side: THREE.DoubleSide }));
    const ray = new THREE.Raycaster(new THREE.Vector3(0.06, 1, 0.01), new THREE.Vector3(0, -1, 0));
    const hits = ray.intersectObject(local);
    assert.ok(hits.length >= 2, 'ray hits both end caps');
    assert.ok(Math.abs(hits[0].point.y - 0.3) < 1e-6);
    assert.ok(hits.some(hit => Math.abs(hit.point.y - 0.03) < 1e-6));
    const edges = new Map();
    const index = geometry.index.array;
    for (let i = 0; i < index.length; i += 3) {
        for (const [a, b] of [[index[i], index[i+1]], [index[i+1], index[i+2]], [index[i+2], index[i]]]) {
            const key = [Math.min(a, b), Math.max(a, b)].join(':');
            edges.set(key, (edges.get(key) ?? 0) + 1);
        }
    }
    assert.ok([...edges.values()].every(count => count === 2), 'no holes or loose boundary edges');
});

test('every section receives the shoe transform, including calibration and mirroring', () => {
    const model = volumeModel();
    const shoe = fitShoe(THREE, model, { forwardAxis: '+x', mirror: true, scale: 1.08,
        offsetCm: [1, -2, 0.5], rotationDeg: [4, 7, -12] });
    const [mask] = createShoeOccluders(THREE, shoe);
    const position = mask.geometry.attributes.position;
    for (const [i, section] of model.userData.tryOnFit.occlusion.volumes[0].sections.entries()) {
        const actual = new THREE.Vector3().fromBufferAttribute(position, i * 32);
        const expected = new THREE.Vector3(section.center[0] + section.radius[0], section.center[1], section.center[2])
            .applyMatrix4(model.matrixWorld);
        assert.ok(actual.distanceTo(expected) < 1e-6);
    }
});

test('invalid version 2 volumes use the generic mask without leaving partially built masks', () => {
    const generic = new THREE.Mesh(new THREE.BoxGeometry());
    const invalidVolumes = [
        [],
        [{ name: 'bad', sections: [{ center: [0, 0, 0], radius: [0.02, 0.02] }] }],
        [{ name: 'bad', sections: [{ center: [0, 0.1, 0], radius: [0.02, 0.02] },
            { center: [0, 0.1, 0], radius: [0.02, 0.02] }] }],
        [{ name: 'bad', sections: [{ center: [0, 0, 0], radius: [NaN, 0.02] },
            { center: [0, 0.2, 0], radius: [0.02, 0.02] }] }],
    ];
    for (const volumes of invalidVolumes) {
        const model = volumeModel();
        model.userData.tryOnFit.occlusion.volumes = volumes;
        const fitted = fitShoe(THREE, model, { forwardAxis: '+x' });
        const [mask] = createShoeOccluders(THREE, fitted, generic);
        assert.equal(mask.scale.x, 0.95);
        assert.notEqual(mask.geometry, generic.geometry);
        assert.deepEqual(createShoeOccluders(THREE, fitted), [], 'no generic template means no unsafe mask');
    }
    assert.equal(generic.scale.x, 1);
});

test('malformed profiles use the legacy fit and generic mask without mutating the template', () => {
    const model = boxModel();
    model.userData.tryOnFit = { version: 1, anchors: { heel: [NaN, 0, 0] } };
    const fitted = fitShoe(THREE, model, { forwardAxis: '+x' });
    assert.equal(fitted.userData.tryOnFit, null);
    const generic = new THREE.Mesh(new THREE.BoxGeometry());
    const [mask] = createShoeOccluders(THREE, fitted, generic);
    assert.notEqual(mask.geometry, generic.geometry);
    assert.equal(generic.scale.x, 1);
    assert.equal(mask.scale.x, 0.95);
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

import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { createFootPoseFilter } from '../../resources/js/tryon/pose-filter.js';

function harness() {
    let time = 0;
    const parent = new THREE.Group();
    const filter = createFootPoseFilter(THREE, { now: () => time });
    return {
        parent, filter,
        at(ms, x = 0, angle = 0) {
            time = ms;
            const matrix = new THREE.Matrix4().compose(
                new THREE.Vector3(x, 0, -5),
                new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 1, 0), angle),
                new THREE.Vector3(1, 1, 1),
            );
            return filter.update([], parent, null, 720, matrix)?.clone() ?? null;
        },
        miss(ms) { time = ms; return filter.miss(); },
    };
}

test('requires consecutive valid observations before exposing a pose', () => {
    const h = harness();
    assert.equal(h.at(1000), null);
    assert.equal(h.parent.userData.footLock.status, 'acquiring');
    h.miss(1010);
    assert.equal(h.at(1020), null);
    assert.equal(h.at(1030), null, 'reacquisition time starts after the interruption');
    assert.ok(h.at(1060));
    assert.equal(h.parent.userData.footLock.status, 'tracking');
});

test('rejects invalid translation and rotation, and isolated jumps', () => {
    const h = harness();
    h.at(0); h.at(40);
    assert.equal(h.at(60, NaN), null);
    assert.equal(h.at(80, 0, NaN), null);
    assert.equal(h.at(100, 4), null);
    assert.ok(h.at(120, 0.01));
    assert.equal(h.at(140, 0, Math.PI), null);
});

test('persistent relocation and confirmed loss acquire a fresh pose without a slide', () => {
    const h = harness();
    h.at(0); h.at(40);
    assert.equal(h.at(80, 3), null);
    assert.equal(h.at(200, 3), null);
    assert.equal(h.at(240, 3).elements[12], 3);
    h.miss(400);
    assert.equal(h.parent.userData.footLock.status, 'lost');
    assert.equal(h.at(440, -2), null);
    assert.equal(h.at(480, -2).elements[12], -2);
});

test('cloned feet have independent acquisition and reset state', () => {
    let time = 0;
    const first = createFootPoseFilter(THREE, { now: () => time });
    const second = first.clone();
    const a = new THREE.Group(), b = new THREE.Group();
    const matrix = new THREE.Matrix4().makeTranslation(0, 0, -5);
    first.update([], a, null, 720, matrix);
    time = 40;
    assert.ok(first.update([], a, null, 720, matrix));
    assert.equal(second.update([], b, null, 720, matrix), null);
    first.reset();
    time = 80;
    assert.ok(second.update([], b, null, 720, matrix));
    assert.equal(a.userData.footLock.status, 'lost');
});

test('smoothing reduces stationary noise without large frame-rate-dependent lag', () => {
    for (const fps of [30, 60]) {
        const h = harness();
        let rawEnergy = 0, filteredEnergy = 0;
        for (let i = 0; i < fps * 3; i++) {
            const x = Math.sin(i * 2.1) * 0.01;
            const result = h.at(i * 1000 / fps, x);
            if (i > fps && result) {
                rawEnergy += x * x;
                filteredEnergy += result.elements[12] ** 2;
            }
        }
        assert.ok(filteredEnergy < rawEnergy * 0.5, `noise reduced at ${fps} fps`);
    }
    const follow = (fps) => {
        const h = harness();
        let result;
        for (let i = 0; i <= fps; i++) result = h.at(i * 1000 / fps, i / fps * 0.4);
        return result.elements[12];
    };
    assert.ok(Math.abs(follow(30) - follow(60)) < 0.01);
    assert.ok(0.4 - follow(30) < 0.02);
});

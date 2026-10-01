import test from 'node:test';
import assert from 'node:assert/strict';
import { NodeIO } from '@gltf-transform/core';
import { ALL_EXTENSIONS } from '@gltf-transform/extensions';

import { detectOrientation } from '../../resources/js/tryon/orientation.js';

/**
 * A crude right shoe: a wedge that is tall at the heel and low at the toes,
 * with the toe tip offset toward the big-toe (medial) side.
 * Built toes +X, up +Y; `transform` re-expresses it in other conventions.
 */
function shoePoints(transform = ([x, y, z]) => [x, y, z], { mirror = false } = {}) {
    const points = [];
    for (let i = 0; i <= 40; i++) {
        const along = i / 40; // 0 heel .. 1 toe
        const height = 0.11 * (1 - along) ** 1.5 + 0.01;
        const centre = mirror ? 0.012 * along ** 2 : -0.012 * along ** 2;
        for (const side of [-1, 1]) {
            for (const level of [0, 0.5, 1]) {
                points.push(transform([along * 0.28, height * level, centre + side * 0.045 * (1 - 0.35 * along ** 2)]));
            }
        }
    }

    return points;
}

test('a right shoe is recognised however it was exported', () => {
    const conventions = {
        '+x': ([x, y, z]) => [x, y, z],
        '-x': ([x, y, z]) => [-x, y, -z],
        '+z': ([x, y, z]) => [-z, y, x],
        '-z': ([x, y, z]) => [z, y, -x],
    };

    for (const [forwardAxis, transform] of Object.entries(conventions)) {
        const result = detectOrientation(shoePoints(transform));
        assert.equal(result.forwardAxis, forwardAxis, `toes along ${forwardAxis}`);
        assert.equal(result.mirror, false, `${forwardAxis} is a right shoe`);
        assert.ok(result.confidence.toe > 0.2, 'the heel is clearly taller than the toe');
    }
});

test('a left shoe is flagged for mirroring', () => {
    const result = detectOrientation(shoePoints(undefined, { mirror: true }));
    assert.equal(result.forwardAxis, '+x');
    assert.equal(result.mirror, true);
});

test('the up axis is respected', () => {
    // Same shoe exported Z-up: up +Z, toes +X.
    const result = detectOrientation(shoePoints(([x, y, z]) => [x, -z, y]), '+z');
    assert.equal(result.forwardAxis, '+x');
    assert.equal(result.upAxis, '+z');
    assert.equal(result.mirror, false);
});

test('tiny inputs are rejected rather than guessed', () => {
    assert.throws(() => detectOrientation([[0, 0, 0], [1, 1, 1]]), /Not enough geometry/);
});

test('every bundled sample is detected as a right shoe with toes along +X', async () => {
    const io = new NodeIO().registerExtensions(ALL_EXTENSIONS);

    for (const slug of ['street-runner', 'canvas-sneaker', 'ballet-flat', 'rain-boot']) {
        const document = await io.read(`public/models/samples/${slug}.glb`);
        const points = [];
        for (const node of document.getRoot().listNodes()) {
            const mesh = node.getMesh();
            if (!mesh) continue;
            const m = node.getWorldMatrix();
            for (const primitive of mesh.listPrimitives()) {
                const position = primitive.getAttribute('POSITION');
                for (let i = 0; i < position.getCount(); i += 3) {
                    const [x, y, z] = position.getElement(i, [0, 0, 0]);
                    points.push([
                        m[0] * x + m[4] * y + m[8] * z + m[12],
                        m[1] * x + m[5] * y + m[9] * z + m[13],
                        m[2] * x + m[6] * y + m[10] * z + m[14],
                    ]);
                }
            }
        }

        const result = detectOrientation(points);
        assert.equal(result.forwardAxis, '+x', `${slug} points its toes along +X`);
        assert.equal(result.mirror, false, `${slug} is a right shoe`);
    }
});

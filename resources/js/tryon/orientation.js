/**
 * Guesses how an uploaded shoe model is oriented, from its vertices alone.
 *
 * - Length axis: the longest horizontal extent.
 * - Toe direction: the lower end of the shoe. The heel end carries the collar,
 *   heel counter or boot shaft and is always taller.
 * - Left or right: a toe box is asymmetric, with its tip on the big-toe
 *   (medial) side. On a right shoe the tip sits left of the forefoot centre.
 *
 * Validated on the bundled samples (sneaker, high-top, ballet flat, boot) in
 * both glTF and WebAR.rocks conventions. The up axis cannot be guessed as
 * reliably, so it is an input (glTF files are +Y up by specification).
 */

const AXIS_INDEX = { x: 0, y: 1, z: 2 };

function axisVector(axis) {
    const vector = [0, 0, 0];
    vector[AXIS_INDEX[axis[1]]] = axis[0] === '-' ? -1 : 1;

    return vector;
}

function cross(a, b) {
    return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
}

function dot(a, b) {
    return a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
}

function range(values) {
    let min = Infinity;
    let max = -Infinity;
    for (const value of values) {
        if (value < min) min = value;
        if (value > max) max = value;
    }

    return { min, max, size: max - min };
}

/**
 * @param {Array<[number, number, number]>} points model-space vertices
 * @param {string} upAxis e.g. '+y'
 * @returns {{forwardAxis: string, upAxis: string, mirror: boolean, confidence: {toe: number, side: number}}}
 */
export function detectOrientation(points, upAxis = '+y') {
    if (!(upAxis?.[1] in AXIS_INDEX) || !['+', '-'].includes(upAxis[0]) || points.some((point) => point.length !== 3 || !point.every(Number.isFinite))) {
        throw new Error('Orientation requires finite points and a valid up axis.');
    }
    if (points.length < 16) {
        throw new Error('Not enough geometry to detect the orientation.');
    }

    const upIndex = AXIS_INDEX[upAxis[1]];
    const up = axisVector(upAxis);
    const heights = points.map((point) => dot(point, up));
    const height = range(heights);

    const [first, second] = [0, 1, 2].filter((index) => index !== upIndex);
    const extents = [first, second].map((index) => range(points.map((point) => point[index])));
    const lengthIndex = extents[0].size >= extents[1].size ? first : second;
    const length = extents[0].size >= extents[1].size ? extents[0] : extents[1];
    if (length.size <= 1e-8 || height.size <= 1e-8) {
        throw new Error('The model has degenerate orientation dimensions.');
    }

    // Tallest point within 20% of each end of the length axis.
    const band = 0.2 * length.size;
    let nearMin = -Infinity;
    let nearMax = -Infinity;
    points.forEach((point, i) => {
        const along = point[lengthIndex];
        if (along <= length.min + band) nearMin = Math.max(nearMin, heights[i]);
        if (along >= length.max - band) nearMax = Math.max(nearMax, heights[i]);
    });

    const toesAtMax = nearMax < nearMin;
    const forwardAxis = `${toesAtMax ? '+' : '-'}${'xyz'[lengthIndex]}`;

    // Side of the toe tip relative to the forefoot centre line (lower half only,
    // so a tongue or collar cannot shift the centre).
    const forward = axisVector(forwardAxis);
    const right = cross(forward, up);
    const along = points.map((point) => dot(point, forward));
    const across = points.map((point) => dot(point, right));
    const alongRange = range(along);

    const forefoot = [];
    const tip = [];
    points.forEach((_, i) => {
        if (along[i] > alongRange.max - 0.35 * alongRange.size && heights[i] < height.min + 0.5 * height.size) {
            forefoot.push(across[i]);
        }
        if (along[i] > alongRange.max - 0.03 * alongRange.size) {
            tip.push(across[i]);
        }
    });

    const forefootRange = range(forefoot.length ? forefoot : across);
    const tipCentre = tip.reduce((sum, value) => sum + value, 0) / Math.max(tip.length, 1);
    const sideOffset = forefootRange.size > 0
        ? (tipCentre - (forefootRange.min + forefootRange.max) / 2) / forefootRange.size
        : 0;

    return {
        forwardAxis,
        upAxis,
        // A tip on the right-hand side means a left shoe, which must be mirrored.
        mirror: sideOffset > 0,
        confidence: {
            toe: height.size > 0 ? Math.abs(nearMin - nearMax) / height.size : 0,
            side: Math.abs(sideOffset),
        },
    };
}

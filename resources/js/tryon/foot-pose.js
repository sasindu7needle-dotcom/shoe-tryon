/**
 * Validate foot registration in image space. The native solver's `ok` flag
 * matters: on failure it can return the preceding rotation/translation.
 * Error ratios are relative to the detected landmark span, not canvas pixels.
 */
function validPose(pose) {
    return pose && pose.ok !== false && pose.translation?.length === 3 &&
        Array.from(pose.translation).every(Number.isFinite) && pose.translation[2] > 0 &&
        pose.rotation?.length === 3 && Array.from(pose.rotation).every(row =>
            row?.length === 3 && Array.from(row).every(Number.isFinite));
}

/** Projection in the native solver's convention, before the Three.js conversion. */
export function projectFootPoint(point, pose, fx, fy) {
    const [x, y, z] = point;
    const r = pose.rotation, t = pose.translation;
    const depth = r[2][0] * x + r[2][1] * y - r[2][2] * z + t[2];
    if (depth <= 1e-6) return null;
    return [
        fx * (r[0][0] * x + r[0][1] * y - r[0][2] * z + t[0]) / depth,
        fy * (r[1][0] * x + r[1][1] * y - r[1][2] * z + t[1]) / depth,
    ];
}

function measure(pose, objectPoints, imagePoints, fx, fy, span, excluded = -1) {
    const errors = objectPoints.map((point, index) => {
        const projected = projectFootPoint(point, pose, fx, fy);
        return projected ? Math.hypot(projected[0] - imagePoints[index][0], projected[1] - imagePoints[index][1]) / span : Infinity;
    });
    const inliers = errors.filter((_error, index) => index !== excluded);
    return {
        errors,
        rmsRatio: Math.sqrt(inliers.reduce((sum, error) => sum + error * error, 0) / inliers.length),
        maxRatio: Math.max(...inliers),
        spanPx: span,
        excludedLandmark: excluded,
    };
}

function copyPose(pose) {
    // The native solver reuses its output arrays across calls and feet.
    return { ...pose, rotation: Array.from(pose.rotation, row => Array.from(row)), translation: Array.from(pose.translation) };
}

function solveLinear(matrix, rhs) {
    const rows = matrix.map((row, i) => [...row, rhs[i]]);
    for (let col = 0; col < 6; col++) {
        let pivot = col;
        for (let i = col + 1; i < 6; i++) if (Math.abs(rows[i][col]) > Math.abs(rows[pivot][col])) pivot = i;
        if (Math.abs(rows[pivot][col]) < 1e-12) return null;
        [rows[col], rows[pivot]] = [rows[pivot], rows[col]];
        const divisor = rows[col][col];
        for (let j = col; j <= 6; j++) rows[col][j] /= divisor;
        for (let i = 0; i < 6; i++) {
            if (i === col) continue;
            const factor = rows[i][col];
            for (let j = col; j <= 6; j++) rows[i][j] -= factor * rows[col][j];
        }
    }
    return rows.map(row => row[6]);
}

function applyStep(pose, step) {
    const angle = Math.hypot(...step.slice(0, 3));
    const s = angle < 1e-8 ? 1 : Math.sin(angle) / angle;
    const c = angle < 1e-8 ? 0.5 : (1 - Math.cos(angle)) / (angle * angle);
    const [x, y, z] = step;
    const cross = [[0, -z, y], [z, 0, -x], [-y, x, 0]];
    const delta = cross.map((row, i) => row.map((value, j) => (i === j ? 1 : 0) + s * value +
        c * cross[i].reduce((sum, v, k) => sum + v * cross[k][j], 0)));
    return { ...pose,
        rotation: delta.map(row => [0, 1, 2].map(j => row.reduce((sum, v, k) => sum + v * pose.rotation[k][j], 0))),
        translation: pose.translation.map((v, i) => v + step[i + 3]),
    };
}

/** Refine the native estimate by minimising the actual perspective pixel error. */
function refinePose(initial, points, pixels, fx, fy) {
    let pose = copyPose(initial);
    const cost = candidate => points.reduce((sum, p, i) => {
        const q = projectFootPoint(p, candidate, fx, fy);
        return q ? sum + (q[0] - pixels[i][0]) ** 2 + (q[1] - pixels[i][1]) ** 2 : Infinity;
    }, 0);
    let error = cost(pose), damping = 0.001;
    if (!Number.isFinite(error)) return pose;
    // Six unknowns and nine points: bounded work independent of the video size.
    for (let iteration = 0; iteration < 8 && error > 1e-8; iteration++) {
        const normal = Array.from({ length: 6 }, () => Array(6).fill(0));
        const rhs = Array(6).fill(0);
        for (let i = 0; i < points.length; i++) {
            const p = [points[i][0], points[i][1], -points[i][2]];
            const q = pose.rotation.map(row => row.reduce((sum, v, k) => sum + v * p[k], 0));
            const [a, b, depth] = q.map((v, k) => v + pose.translation[k]);
            const derivatives = [[0, -q[2], q[1]], [q[2], 0, -q[0]], [-q[1], q[0], 0], [1, 0, 0], [0, 1, 0], [0, 0, 1]];
            for (let axis = 0; axis < 2; axis++) {
                const focal = axis === 0 ? fx : fy, value = axis === 0 ? a : b;
                const jacobian = derivatives.map(d => focal / depth * (d[axis] - value / depth * d[2]));
                const residual = pixels[i][axis] - focal * value / depth;
                for (let j = 0; j < 6; j++) {
                    rhs[j] += jacobian[j] * residual;
                    for (let k = 0; k < 6; k++) normal[j][k] += jacobian[j] * jacobian[k];
                }
            }
        }
        for (let i = 0; i < 6; i++) normal[i][i] += damping * Math.max(normal[i][i], 1);
        const step = solveLinear(normal, rhs);
        if (!step || !step.every(Number.isFinite)) break;
        // Stay local to the native estimate; reject steps through the camera.
        if (Math.hypot(...step.slice(0, 3)) > 0.5 || Math.hypot(...step.slice(3)) > pose.translation[2] * 0.25) {
            damping *= 10;
            continue;
        }
        const candidate = applyStep(pose, step);
        const nextError = candidate.translation[2] > 0 ? cost(candidate) : Infinity;
        if (nextError < error) {
            pose = candidate;
            const improvement = error - nextError;
            error = nextError;
            damping = Math.max(damping / 3, 1e-7);
            if (improvement < 1e-7) break;
        } else {
            damping *= 10;
        }
    }
    return pose;
}

/** Up to one outlier can be excluded, with at most two additional solves. */
export function solveFootPose(objectPoints, imagePoints, fx, fy, options, solve) {
    const reject = (reason, quality = {}) => ({ ok: false, quality: { ...quality, accepted: false, reason } });
    if (objectPoints.length !== 9 || imagePoints.length !== 9 ||
        !objectPoints.every(point => point?.length === 3 && point.every(Number.isFinite)) ||
        !imagePoints.every(point => point?.length === 2 && point.every(Number.isFinite)) ||
        !Number.isFinite(fx) || !Number.isFinite(fy) || fx <= 0 || fy <= 0) return reject('landmarks');
    const span = Math.hypot(
        Math.max(...imagePoints.map(p => p[0])) - Math.min(...imagePoints.map(p => p[0])),
        Math.max(...imagePoints.map(p => p[1])) - Math.min(...imagePoints.map(p => p[1])),
    );
    if (span < 12) return reject('too-small', { spanPx: span });

    const solved = solve(objectPoints, imagePoints, fx, fy, options);
    if (!validPose(solved)) return reject('pose');
    const initialQuality = measure(solved, objectPoints, imagePoints, fx, fy, span);
    if (initialQuality.maxRatio > 0.45) return reject('reprojection', initialQuality);
    const full = refinePose(solved, objectPoints, imagePoints, fx, fy);
    const quality = measure(full, objectPoints, imagePoints, fx, fy, span);
    if (quality.rmsRatio <= 0.08 && quality.maxRatio <= 0.18) {
        return { ...full, quality: { ...quality, accepted: true, reason: null } };
    }

    // A hidden heel/ankle landmark may drift. Retry the two worst candidates,
    // keeping eight observations and requiring a stricter inlier fit. Do not
    // salvage wildly inconsistent detections or let two feet become one pose.
    if (quality.maxRatio > 0.45) return reject('reprojection', quality);
    const worst = quality.errors.map((error, index) => ({ error, index }))
        .sort((a, b) => b.error - a.error).slice(0, 2);
    let best = null;
    for (const { index } of worst) {
        const inlierPoints = objectPoints.filter((_p, i) => i !== index);
        const inlierPixels = imagePoints.filter((_p, i) => i !== index);
        const candidate = solve(inlierPoints, inlierPixels, fx, fy, options);
        if (!validPose(candidate)) continue;
        const pose = refinePose(candidate, inlierPoints, inlierPixels, fx, fy);
        const measured = measure(pose, objectPoints, imagePoints, fx, fy, span, index);
        if (measured.rmsRatio <= 0.06 && measured.maxRatio <= 0.12 && measured.errors[index] <= 0.35 &&
            (!best || measured.rmsRatio < best.quality.rmsRatio)) {
            best = { ...pose, quality: { ...measured, accepted: true, reason: 'one-outlier' } };
        }
    }
    return best ?? reject('reprojection', quality);
}

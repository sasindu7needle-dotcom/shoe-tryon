/**
 * Maps any shoe model onto the WebAR.rocks foot tracker.
 *
 * The foot frame is the one used by the WebAR.rocks foot demo: X to the right,
 * Y toward the toes, Z up, in tracker units where the demo's reference shoe is
 * FOOT_FRAME.length long. A model is rotated into that frame, scaled to the
 * reference length and aligned on the heel, sole and centre line. The
 * per-product calibration then nudges it (centimetres and degrees).
 *
 * Functions take the THREE namespace as an argument so they run against the
 * vendored r131 build in the browser and against npm three in tests.
 */

export const FOOT_FRAME = Object.freeze({
    // Reference shoe bounds after the demo transform (scale 0.95, Y -0.02).
    length: 1.29656,
    heelY: -0.60473,
    soleZ: -0.28852,
    centerX: -0.02537,
    // Real-world length the reference shoe stands for, to express offsets in cm.
    lengthCm: 28,
});

// The demo occluder is modelled in the same frame and uses the same transform.
export const OCCLUDER_TRANSFORM = Object.freeze({ scale: 0.95, position: [0, -0.02, 0] });

export const AXES = Object.freeze({
    '+x': [1, 0, 0],
    '-x': [-1, 0, 0],
    '+y': [0, 1, 0],
    '-y': [0, -1, 0],
    '+z': [0, 0, 1],
    '-z': [0, 0, -1],
});

export const DEFAULT_CALIBRATION = Object.freeze({
    forwardAxis: '+z',
    upAxis: '+y',
    mirror: false,
    scale: 1,
    offsetCm: [0, 0, 0],
    rotationDeg: [0, 0, 0],
});

function triple(values) {
    return [0, 1, 2].map((i) => Number(values?.[i]) || 0);
}

export function normalizeCalibration(calibration = {}) {
    const merged = { ...DEFAULT_CALIBRATION, ...calibration };
    if (!(merged.forwardAxis in AXES) || !(merged.upAxis in AXES)) {
        throw new Error(`Unknown axis in calibration: ${merged.forwardAxis} / ${merged.upAxis}`);
    }
    if (merged.forwardAxis[1] === merged.upAxis[1]) {
        throw new Error('The forward and up axes must be perpendicular.');
    }

    return {
        forwardAxis: merged.forwardAxis,
        upAxis: merged.upAxis,
        mirror: Boolean(merged.mirror),
        scale: Number(merged.scale) > 0 ? Number(merged.scale) : 1,
        offsetCm: triple(merged.offsetCm),
        rotationDeg: triple(merged.rotationDeg),
    };
}

/** Rotation matrix taking model coordinates into the foot frame. */
export function modelToFootRotation(THREE, forwardAxis, upAxis) {
    const forward = new THREE.Vector3().fromArray(AXES[forwardAxis]);
    const up = new THREE.Vector3().fromArray(AXES[upAxis]);
    const right = new THREE.Vector3().crossVectors(forward, up);

    // makeBasis maps the foot axes onto the model axes; for a rotation the
    // transpose is the inverse.
    return new THREE.Matrix4().makeBasis(right, forward, up).transpose();
}

/**
 * Call `callback(vector)` for every vertex in world space. Every `step`-th vertex
 * is visited, which keeps large uploads fast when only a sample is needed.
 */
export function forEachWorldVertex(THREE, object, callback, step = 1) {
    const vertex = new THREE.Vector3();
    object.updateMatrixWorld(true);
    object.traverse((child) => {
        const position = child.isMesh ? child.geometry.attributes.position : null;
        if (!position) {
            return;
        }
        for (let i = 0; i < position.count; i += step) {
            callback(vertex.fromBufferAttribute(position, i).applyMatrix4(child.matrixWorld));
        }
    });
}

/**
 * Bounds from the vertices themselves. Box3.setFromObject() in r131 transforms
 * each geometry's bounding box, which over-estimates rotated nodes.
 */
export function computeBounds(THREE, object) {
    const bounds = new THREE.Box3();
    forEachWorldVertex(THREE, object, (vertex) => bounds.expandByPoint(vertex));

    return bounds;
}

/**
 * The WebAR.rocks helper mirrors meshes for the left foot by reversing index
 * order, so every geometry needs an index buffer.
 */
export function ensureIndexedGeometry(THREE, object) {
    object.traverse((child) => {
        const geometry = child.isMesh ? child.geometry : null;
        if (!geometry || geometry.index) {
            return;
        }
        const count = geometry.attributes.position.count;
        const indices = count > 65535 ? new Uint32Array(count) : new Uint16Array(count);
        for (let i = 0; i < count; i++) {
            indices[i] = i;
        }
        geometry.setIndex(new THREE.BufferAttribute(indices, 1));
    });
}

/** Versioned scene extras, in the model scene's coordinates (metres). */
export function readFitProfile(model) {
    const profile = model.userData.tryOnFit;
    if (profile?.version !== 1) return null;
    const point = (value) => Array.isArray(value) && value.length === 3 && value.every(Number.isFinite);
    if (!['heel', 'toe', 'sole', 'ankleOpening'].every(key => point(profile.anchors?.[key]))) return null;
    return profile;
}

/**
 * Wrap `model` in groups that place it on the tracked foot.
 * Returns the root group to hand to HandTrackerThreeHelper.add_threeObject().
 */
export function fitShoe(THREE, model, calibration) {
    const cal = normalizeCalibration(calibration);

    const oriented = new THREE.Group();
    oriented.quaternion.setFromRotationMatrix(modelToFootRotation(THREE, cal.forwardAxis, cal.upAxis));
    oriented.add(model);

    // Mirroring across the foot's X axis turns a left shoe into a right one.
    const mirrored = new THREE.Group();
    mirrored.scale.x = cal.mirror ? -1 : 1;
    mirrored.add(oriented);

    const bounds = computeBounds(THREE, mirrored);
    if (bounds.isEmpty()) {
        throw new Error('The model has no visible geometry.');
    }
    const size = bounds.getSize(new THREE.Vector3());
    let length = size.y;
    let heel = bounds.min.y;
    let sole = bounds.min.z;
    let center = (bounds.min.x + bounds.max.x) / 2;
    const profile = readFitProfile(model);
    if (profile) {
        const toFoot = new THREE.Matrix4().multiplyMatrices(mirrored.matrix, oriented.matrix);
        model.updateMatrix();
        toFoot.multiply(model.matrix);
        const anchor = (name) => new THREE.Vector3().fromArray(profile.anchors[name]).applyMatrix4(toFoot);
        const heelPoint = anchor('heel');
        const toePoint = anchor('toe');
        // An admin can still rotate a legacy upload. If authored axes no longer
        // describe a forward length, fall back to the established bounds fit.
        if (toePoint.y - heelPoint.y > 1e-6) {
            length = toePoint.y - heelPoint.y;
            heel = heelPoint.y;
            sole = anchor('sole').z;
            center = anchor('ankleOpening').x;
        }
    }
    if (!Number.isFinite(length) || length <= 1e-6) throw new Error('The model has no usable shoe length.');
    const scale = (FOOT_FRAME.length / length) * cal.scale;

    // Rotations pivot around the middle of the sole rather than the tracker origin.
    const pivot = new THREE.Vector3(FOOT_FRAME.centerX, FOOT_FRAME.heelY + FOOT_FRAME.length / 2, FOOT_FRAME.soleZ);

    const fitted = new THREE.Group();
    fitted.scale.setScalar(scale);
    fitted.position
        .set(
            FOOT_FRAME.centerX - scale * center,
            FOOT_FRAME.heelY - scale * heel,
            FOOT_FRAME.soleZ - scale * sole,
        )
        .sub(pivot);
    fitted.add(mirrored);

    const unitsPerCm = FOOT_FRAME.length / FOOT_FRAME.lengthCm;
    const root = new THREE.Group();
    root.name = 'fittedShoe';
    root.position.copy(pivot).add(new THREE.Vector3().fromArray(cal.offsetCm).multiplyScalar(unitsPerCm));
    root.rotation.set(...cal.rotationDeg.map((degrees) => THREE.MathUtils.degToRad(degrees)));
    root.add(fitted);
    root.updateMatrixWorld(true);

    root.userData.footprint = { length: size.y * scale, width: size.x * scale };
    // Store the complete transform so the mask receives the same calibration,
    // including mirror, scale, offset and rotation, as the visible shoe.
    root.userData.modelToFoot = model.matrixWorld.toArray();
    root.userData.tryOnFit = profile;

    return root;
}

/** A calibrated depth mask, or the demo mask for uploads without a profile. */
export function createShoeOccluders(THREE, fitted, generic) {
    const profile = fitted.userData.tryOnFit;
    const mask = profile?.occlusion;
    if (!mask || !Array.isArray(mask.radius) || mask.radius.length !== 2 ||
        !mask.radius.every(value => Number.isFinite(value) && value > 0 && value < 0.2) ||
        !Number.isFinite(mask.bottom) || !Number.isFinite(mask.top) ||
        mask.top <= mask.bottom || mask.top - mask.bottom > 1) {
        const fallback = generic.clone();
        fallback.geometry = generic.geometry.clone();
        return [placeOccluder(fallback)];
    }
    const opening = profile.anchors.ankleOpening;
    const geometry = new THREE.CylinderGeometry(1, 1, mask.top - mask.bottom, 24, 1, true);
    geometry.scale(mask.radius[0], 1, mask.radius[1]);
    geometry.translate(opening[0], (mask.top + mask.bottom) / 2, opening[2]);
    geometry.applyMatrix4(new THREE.Matrix4().fromArray(fitted.userData.modelToFoot));
    const occluder = new THREE.Mesh(geometry);
    occluder.name = 'shoeAnkleOccluder';
    return [occluder];
}

/** Apply the demo transform to the occluder mesh loaded from occluder.glb. */
export function placeOccluder(occluder) {
    occluder.scale.multiplyScalar(OCCLUDER_TRANSFORM.scale);
    occluder.position.x += OCCLUDER_TRANSFORM.position[0];
    occluder.position.y += OCCLUDER_TRANSFORM.position[1];
    occluder.position.z += OCCLUDER_TRANSFORM.position[2];

    return occluder;
}

/**
 * The demo occluder stops just above the ankle. Tall boots need the calf
 * hidden too, so extend it with a slightly tapered, tilted cylinder whose base
 * matches the occluder's top cross-section.
 */
export function createCalfOccluder(THREE) {
    const height = 2.2;
    const geometry = new THREE.CylinderGeometry(0.2, 0.14, height, 24, 1, true);
    geometry.rotateX(Math.PI / 2); // cylinder axis from Y to Z
    geometry.scale(1, 1.15, 1); // legs are deeper front-to-back than side-to-side
    geometry.translate(0, 0, height / 2);

    const calf = new THREE.Mesh(geometry);
    calf.name = 'calfOccluder';
    calf.position.set(0.05, -0.33, 0.42);
    calf.rotation.x = -0.12;

    return calf;
}

let shadowTexture = null;

/** Soft blob shadow under the sole, which grounds the shoe on the floor. */
export function createContactShadow(THREE, footprint, opacity = 0.45) {
    if (!shadowTexture) {
        const size = 128;
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = size;
        const context = canvas.getContext('2d');
        const gradient = context.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
        gradient.addColorStop(0, 'rgba(0,0,0,1)');
        gradient.addColorStop(0.55, 'rgba(0,0,0,0.55)');
        gradient.addColorStop(1, 'rgba(0,0,0,0)');
        context.fillStyle = gradient;
        context.fillRect(0, 0, size, size);
        shadowTexture = new THREE.CanvasTexture(canvas);
    }

    const shadow = new THREE.Mesh(
        new THREE.PlaneGeometry(footprint.width * 1.5, footprint.length * 1.15),
        new THREE.MeshBasicMaterial({ map: shadowTexture, transparent: true, opacity, depthWrite: false, toneMapped: false }),
    );
    shadow.name = 'contactShadow';
    shadow.renderOrder = -1;
    shadow.position.set(FOOT_FRAME.centerX, FOOT_FRAME.heelY + FOOT_FRAME.length / 2, FOOT_FRAME.soleZ - 0.01);

    return shadow;
}

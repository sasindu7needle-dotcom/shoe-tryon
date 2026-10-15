/** Independent, time-based pose filtering for each tracker slot (Three r131). */
export function createFootPoseFilter(THREE, { now = () => performance.now() } = {}) {
    const position = new THREE.Vector3();
    const rotation = new THREE.Quaternion();
    const targetPosition = new THREE.Vector3();
    const targetRotation = new THREE.Quaternion();
    const scale = new THREE.Vector3();
    const output = new THREE.Matrix4();
    let lastGood = null;
    let acquiredAt = null;
    let samples = 0;
    let parent = null;
    let status = 'lost';

    function report(reason = null, translationError = 0, rotationError = 0) {
        if (parent) parent.userData.footLock = { status, reason, translationError, rotationError };
    }

    const filter = {
        clone: () => createFootPoseFilter(THREE, { now }),
        reset() {
            lastGood = acquiredAt = null;
            samples = 0;
            status = 'lost';
            report('reset');
        },
        miss(reason = 'missing') {
            // Never display an old pose during a miss. Retain only filter history
            // for a short gap; a longer gap must acquire a fresh lock.
            if (lastGood === null || now() - lastGood >= 150) filter.reset();
            if (status !== 'tracking') {
                filter.reset();
            }
            report(reason);
            return null;
        },
        update(_points, tracker, _camera, _height, matrix) {
            parent = tracker;
            const time = now();
            if (!matrix.elements.every(Number.isFinite)) return filter.miss('invalid');
            matrix.decompose(targetPosition, targetRotation, scale);
            if (targetPosition.z >= 0 || !targetRotation.toArray().every(Number.isFinite)) {
                return filter.miss('invalid');
            }
            if (lastGood !== null && time - lastGood >= 150) filter.reset();
            const dt = lastGood === null ? 0 : Math.max(0, (time - lastGood) / 1000);
            const distance = lastGood === null ? 0 : position.distanceTo(targetPosition);
            const angle = lastGood === null ? 0 : rotation.angleTo(targetRotation);
            // Reject isolated jumps, including slot reassignment to a distant
            // foot. Persistent movement is reacquired after the 150 ms timeout.
            if (lastGood !== null && (distance > Math.max(0.45, Math.abs(position.z) * 0.12) + dt * 3.5 || angle > Math.PI / 3)) {
                return filter.miss('jump');
            }
            if (lastGood === null) {
                position.copy(targetPosition);
                rotation.copy(targetRotation);
                acquiredAt = time;
            } else {
                const speed = distance / Math.max(dt, 0.001);
                const cutoff = Math.min(24, 5 + speed * 2);
                const alpha = 1 - Math.exp(-2 * Math.PI * cutoff * dt);
                position.lerp(targetPosition, alpha);
                rotation.slerp(targetRotation, alpha);
            }
            lastGood = time;
            samples++;
            status = samples >= 2 && time - acquiredAt >= 32 ? 'tracking' : 'acquiring';
            report(null, distance, angle * 180 / Math.PI);
            return status === 'tracking' ? output.compose(position, rotation, scale.set(1, 1, 1)) : null;
        },
    };
    return filter;
}

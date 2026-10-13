/**
 * Thin wrapper over WebAR.rocks.hand's foot tracking (MIT).
 * Relies on the classic scripts loaded by the page: THREE (r131),
 * WEBARROCKSHAND, WebARRocksLMStabilizer and HandTrackerThreeHelper.
 */
import { TryOnError } from './camera.js';
import { createFootPoseFilter } from './pose-filter.js';
import { solveFootPose } from './foot-pose.js';

// Landmarks of the NN_FOOT_* networks used to solve the 3D pose.
const FOOT_LANDMARKS = [
    'ankleBack', 'ankleOut', 'ankleIn', 'ankleFront',
    'heelBackOut', 'heelBackIn',
    'pinkyToeBaseTop', 'middleToeBaseTop', 'bigToeBaseTop',
];

const TRACKER_ERRORS = {
    GL_INCOMPATIBLE: 'Your device’s graphics are not supported by live try-on.',
    WEBCAM_UNAVAILABLE: 'The camera stopped responding. Reload the page and try again.',
    ALREADY_INITIALIZED: 'Live try-on is already running in this tab. Reload the page.',
};

/**
 * Download the neural network with progress. The server compresses the JSON,
 * so progress is measured against the known uncompressed size.
 */
export async function downloadNeuralNetwork(url, expectedBytes, onProgress) {
    const response = await fetch(url);
    if (!response.ok) {
        throw new TryOnError('download', `The foot tracking model could not be downloaded (HTTP ${response.status}).`);
    }

    let text;
    if (response.body && expectedBytes) {
        const reader = response.body.getReader();
        const chunks = [];
        let received = 0;
        for (;;) {
            const { done, value } = await reader.read();
            if (done) break;
            chunks.push(value);
            received += value.length;
            onProgress(Math.min(received / expectedBytes, 1));
        }
        text = await new Blob(chunks).text();
    } else {
        text = await response.text();
    }
    onProgress(1);

    const network = JSON.parse(text);
    // Mirrors what the library does when it fetches networks itself.
    network.ef = url.split('?')[0].split('/').pop();

    return network;
}

export function startFootTracker({ video, trackerCanvas, sceneCanvas, neuralNetwork, threshold, maxFeet, cameraFov, debugLandmarks = false, onFrame }) {
    return HandTrackerThreeHelper.init({
        poseLandmarksLabels: FOOT_LANDMARKS,
        enableFlipObject: true,
        cameraZoom: 1,
        freeZRot: true,
        threshold,
        // Drives both the 3D pose solve and the render camera.
        cameraMinVideoDimFov: cameraFov,
        cameraFovRange: [20, 75],
        // Let the mirrored network candidates determine foot side. Forcing the
        // pair's screen-space order swaps left/right when feet cross or turn.
        scanSettings: {
            multiDetectionSearchSlotsRate: 0.5,
            multiDetectionMaxOverlap: 0.5,
            multiDetectionOverlapScaleXY: [0.5, 1],
            multiDetectionEqualizeSearchSlotScale: true,
            multiDetectionForceSearchOnOtherSide: false,
            multiDetectionForceChirality: 0,
            disableIsRightHandNNEval: true,
            overlapFactors: [1.0, 1.0, 1.0],
            translationScalingFactors: [0.3, 0.3, 1],
            nScaleLevels: 2,
            scale0Factor: 0.5,
        },
        VTOCanvas: sceneCanvas,
        handTrackerCanvas: trackerCanvas,
        debugDisplayLandmarks: debugLandmarks,
        NNs: [neuralNetwork],
        maxHandsDetected: maxFeet,
        videoSettings: { videoElement: video },
        stabilizationSettings: {
            NNSwitchMask: { isRightHand: true, isFlipped: false },
        },
        landmarksStabilizerSpec: { minCutOff: 0.001, beta: 5 },
        poseFilter: createFootPoseFilter(window.THREE),
        poseSolver: (points, pixels, fx, fy, options) => solveFootPose(points, pixels, fx, fy, options,
            (...args) => WEBARROCKSHAND.compute_pose(...args)),
        callbackTrack: onFrame,
    }).catch((error) => {
        throw new TryOnError('tracker', TRACKER_ERRORS[error] ?? `Live try-on could not start (${error}).`);
    });
}

/** Point the running tracker at a (re)started camera stream in the same element. */
export function replaceVideo(video) {
    return new Promise((resolve) => WEBARROCKSHAND.update_videoElement(video, resolve));
}

export function setPaused(paused) {
    return WEBARROCKSHAND.toggle_pause(paused, false).catch(() => {});
}

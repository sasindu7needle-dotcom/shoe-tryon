export class TryOnError extends Error {
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}

const CAMERA_ERRORS = {
    NotAllowedError: ['permission', 'Camera access was blocked. Allow the camera for this site in your browser settings, then try again.'],
    SecurityError: ['permission', 'Camera access was blocked by the browser.'],
    NotFoundError: ['no-camera', 'No camera was found on this device.'],
    OverconstrainedError: ['no-camera', 'No suitable camera was found on this device.'],
    NotReadableError: ['camera-busy', 'The camera is being used by another app. Close it and try again.'],
    AbortError: ['camera-busy', 'The camera could not be started. Try again.'],
};

export function assertBrowserSupport() {
    if (!window.isSecureContext) {
        throw new TryOnError('insecure', 'The camera needs a secure (HTTPS) connection.');
    }
    if (!navigator.mediaDevices?.getUserMedia) {
        throw new TryOnError('unsupported', 'This browser cannot access the camera. Try the latest Chrome or Safari.');
    }
    const canvas = document.createElement('canvas');
    if (!(canvas.getContext('webgl2') || canvas.getContext('webgl'))) {
        throw new TryOnError('unsupported', 'This browser does not support WebGL, which live try-on needs.');
    }
}

function waitForFrames(video) {
    return new Promise((resolve) => {
        if (video.videoWidth > 0) {
            resolve();
            return;
        }
        video.addEventListener('loadeddata', () => resolve(), { once: true });
    });
}

/** Start the camera in `video`. Resolves with the MediaStream once frames arrive. */
export async function openCamera(video, facingMode) {
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: facingMode },
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
        });
    } catch (error) {
        const [code, message] = CAMERA_ERRORS[error.name] ?? ['camera', `The camera could not be started (${error.name}).`];
        throw new TryOnError(code, message);
    }

    try {
        video.muted = true;
        video.playsInline = true;
        video.srcObject = stream;
        await video.play();
        await waitForFrames(video);
    } catch (error) {
        // The caller cannot release a stream it has not received yet.
        stopCamera(stream);
        if (video.srcObject === stream) video.srcObject = null;
        throw error;
    }

    return stream;
}

/**
 * Play a video file instead of the camera. Used for demos on devices without a
 * usable rear camera, and for testing tracking without a person in front of it.
 * Only same-origin paths are accepted.
 */
export async function openDemoVideo(video, path) {
    if (!path.startsWith('/') || path.startsWith('//')) {
        throw new TryOnError('demo', 'The demo video must be a path on this site.');
    }

    video.muted = true;
    video.playsInline = true;
    video.loop = true;
    video.srcObject = null;
    video.src = path;

    // Calling play() before the file has data rejects with AbortError.
    await new Promise((resolve, reject) => {
        video.addEventListener('loadeddata', resolve, { once: true });
        video.addEventListener('error', () => reject(new TryOnError('demo', 'The demo video could not be loaded.')), { once: true });
    });

    try {
        await video.play();
    } catch (error) {
        throw new TryOnError('demo', `The demo video could not be played (${error.name}).`);
    }
}

export function stopCamera(stream) {
    stream?.getTracks().forEach((track) => track.stop());
}

export async function countCameras() {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();

        return devices.filter((device) => device.kind === 'videoinput').length;
    } catch {
        return 1;
    }
}

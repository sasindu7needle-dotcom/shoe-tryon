/**
 * Live foot try-on page.
 *
 * Flow: intro -> (tap) camera preview + downloads -> tracker running.
 * The camera stream is owned here rather than by WebAR.rocks so the preview
 * appears immediately and the camera can be flipped or released when the tab
 * is hidden.
 */
import { createAnalytics } from './analytics.js';
import { assertBrowserSupport, countCameras, openCamera, openDemoVideo, stopCamera, TryOnError } from './camera.js';
import { downloadNeuralNetwork, replaceVideo, setPaused, startFootTracker } from './foot-tracker.js';
import { disposeObject, loadGltf, setupLighting } from './scene.js';
import { createCalfOccluder, createContactShadow, ensureIndexedGeometry, fitShoe, placeOccluder } from './shoe-fitter.js';
import { composeSnapshot } from './snapshot.js';
import { applyVariant, listVariants, resolveVariant } from './variants.js';

const THREE = window.THREE;
const config = JSON.parse(document.getElementById('tryon-config').textContent);
const root = document.getElementById('tryon');
const $ = (selector) => root.querySelector(selector);

const ui = {
    video: $('#camera-video'),
    trackerCanvas: $('#tracker-canvas'),
    sceneCanvas: $('#scene-canvas'),
    flipButton: $('[data-action="flip"]'),
    progress: $('#loading-progress'),
    progressLabel: $('#loading-label'),
    errorMessage: $('#error-message'),
    statusDot: $('[data-status-dot]'),
    statusText: $('[data-status-text]'),
    hint: $('#tracking-hint'),
    productName: $('#product-name'),
    productPrice: $('#product-price'),
    productLink: $('#product-link'),
    productList: $('#product-list'),
    variantList: $('#variant-list'),
    snapshotDialog: $('#snapshot-dialog'),
    snapshotImage: $('#snapshot-image'),
    shareButton: $('[data-action="share"]'),
};

const state = {
    phase: 'intro',
    facingMode: 'environment',
    stream: null,
    three: null,
    product: config.products.find((product) => product.id === config.productId) ?? config.products[0],
    variant: null,
    models: new Map(),
    mounted: null,
    mountToken: 0,
    tracking: false,
    lostTimer: 0,
    hintTimer: 0,
    snapshotRequested: false,
    snapshot: null,
    busy: false,
};

const query = new URLSearchParams(window.location.search);

// ?demo=/path/to/video.mp4 replaces the camera with a looping clip, for
// demonstrations on machines without a usable camera. Nothing is tracked.
const demoVideo = query.get('demo');
const analytics = demoVideo ? { track() {} } : createAnalytics(config.eventsUrl);

// ?debug=1 opens the tuning overlay, for admins or APP_DEBUG environments.
const debugEnabled = config.debugAvailable && query.get('debug') === '1';

// Live-tunable tracking settings; the defaults come from the server config.
const tuning = {
    cameraFov: config.tracker.cameraFov,
    threshold: config.tracker.threshold,
    beta: 5,
    minCutOff: 0.001,
    landmarks: debugEnabled,
    networkUrl: config.tracker.neuralNetworkUrl,
};

let debugPanel = null;
let frameTimes = [];
let pendingTuning = null;
let applyingTuning = false;

/* ── Phases & layout ─────────────────────────────────────────────── */

function setPhase(phase) {
    state.phase = phase;
    root.dataset.phase = phase;
    root.querySelectorAll('[data-phases]').forEach((element) => {
        element.hidden = !element.dataset.phases.split(' ').includes(phase);
    });
}

function setProgress(fraction, label) {
    ui.progress.style.width = `${Math.round(fraction * 100)}%`;
    ui.progress.parentElement.setAttribute('aria-valuenow', String(Math.round(fraction * 100)));
    if (label) {
        ui.progressLabel.textContent = label;
    }
}

function fail(error) {
    console.error('Try-on error:', error);
    stopCamera(state.stream);
    state.stream = null;
    ui.errorMessage.textContent = error instanceof TryOnError
        ? error.message
        : 'Something went wrong while starting live try-on. Please try again.';
    setPhase('error');
}

let layoutFrame = 0;

function layout() {
    const cssWidth = window.innerWidth;
    const cssHeight = window.innerHeight;
    const pixelRatio = Math.min(window.devicePixelRatio || 1, config.tracker.maxDevicePixelRatio);
    const width = Math.round(cssWidth * pixelRatio);
    const height = Math.round(cssHeight * pixelRatio);

    for (const element of [ui.video, ui.trackerCanvas, ui.sceneCanvas]) {
        element.style.width = `${cssWidth}px`;
        element.style.height = `${cssHeight}px`;
    }

    if (state.three) {
        HandTrackerThreeHelper.resize(width, height);
    } else {
        ui.trackerCanvas.width = ui.sceneCanvas.width = width;
        ui.trackerCanvas.height = ui.sceneCanvas.height = height;
    }
}

function scheduleLayout() {
    cancelAnimationFrame(layoutFrame);
    layoutFrame = requestAnimationFrame(layout);
}

function applyMirroring() {
    const transform = state.facingMode === 'user' ? 'scaleX(-1)' : '';
    for (const element of [ui.video, ui.trackerCanvas, ui.sceneCanvas]) {
        element.style.transform = transform;
    }
}

/* ── Tracking status ─────────────────────────────────────────────── */

function renderTracking(tracking) {
    ui.statusDot.className = `size-2 rounded-full ${tracking ? 'bg-emerald-400' : 'bg-amber-400 animate-pulse'}`;
    ui.statusText.textContent = tracking ? 'Tracking your feet' : 'Looking for your feet…';

    clearTimeout(state.hintTimer);
    ui.hint.hidden = true;
    if (!tracking) {
        state.hintTimer = setTimeout(() => (ui.hint.hidden = false), 4000);
    }
}

function setTracking(tracking) {
    if (tracking === state.tracking) {
        return;
    }
    state.tracking = tracking;
    clearTimeout(state.lostTimer);

    if (tracking) {
        renderTracking(true);
        analytics.track('foot_detected', state.product.id, { once: true });
        navigator.vibrate?.(20);
    } else {
        // Brief losses happen while feet move; don't flash the status.
        state.lostTimer = setTimeout(() => renderTracking(false), 700);
    }
}

function onTrackerFrame(detectStates, validity) {
    const detections = Array.isArray(detectStates) ? detectStates : [detectStates];
    const valid = Array.isArray(validity) ? validity : [validity];
    setTracking(detections.some((detection, i) => detection.isDetected && valid[i]));

    if (debugPanel) {
        reportDebugFrame(detections, valid);
    }

    if (state.snapshotRequested) {
        state.snapshotRequested = false;
        // Render now so both canvases hold the same frame, then copy them.
        state.three.renderer.render(state.three.scene, state.three.camera);
        composeSnapshot(ui.trackerCanvas, ui.sceneCanvas, {
            mirror: state.facingMode === 'user',
            caption: `${state.product.name} · ${config.appName}`,
        }).then(showSnapshot);
    }
}

/* ── Debug overlay ───────────────────────────────────────────────── */

function reportDebugFrame(detections, valid) {
    const now = performance.now();
    frameTimes.push(now);
    if (frameTimes.length > 30) {
        frameTimes.shift();
    }
    const span = frameTimes.at(-1) - frameTimes[0];

    debugPanel.update({
        fps: span > 0 ? ((frameTimes.length - 1) * 1000) / span : 0,
        feet: detections.map((detection, i) => ({
            // `detected` is the raw network score; `isDetected` is it passing
            // the threshold, so a near-miss is visible while tuning.
            score: detection.detected,
            detected: Boolean(detection.isDetected),
            solved: Boolean(valid[i]),
        })),
        video: { width: ui.video.videoWidth, height: ui.video.videoHeight },
        canvas: { width: ui.trackerCanvas.width, height: ui.trackerCanvas.height },
        pixelRatio: Math.min(window.devicePixelRatio || 1, config.tracker.maxDevicePixelRatio),
        network: tuning.networkUrl.split('/').pop().split('?')[0],
    });
}

/**
 * Push tuning changes into the running tracker. Calls are coalesced because
 * each one re-initialises pose estimation.
 */
async function applyTuning(patch) {
    Object.assign(tuning, patch);
    pendingTuning = { ...pendingTuning, ...patch };

    if (applyingTuning) {
        return;
    }
    applyingTuning = true;

    try {
        while (pendingTuning) {
            const changes = pendingTuning;
            pendingTuning = null;

            await HandTrackerThreeHelper.update({
                cameraMinVideoDimFov: tuning.cameraFov,
                threshold: tuning.threshold,
                debugDisplayLandmarks: tuning.landmarks,
                landmarksStabilizerSpec: { minCutOff: tuning.minCutOff, beta: tuning.beta },
                // Only touch the network when it actually changed: switching
                // makes the library fetch and rebuild it.
                ...(changes.networkUrl ? { NNs: null, NNsPaths: [changes.networkUrl] } : {}),
            });
            // Re-applies the field of view to both the render camera and the
            // focal lengths the pose solve uses.
            layout();
        }
    } catch (error) {
        console.error('Could not apply tracking settings:', error);
    } finally {
        applyingTuning = false;
    }
}

async function openDebugPanel() {
    const { createDebugPanel } = await import('./debug-panel.js');
    debugPanel = createDebugPanel({
        settings: tuning,
        networks: config.tracker.networks ?? [],
        onChange: applyTuning,
    });
    root.append(debugPanel.node);
}

/* ── Products & variants ─────────────────────────────────────────── */

function loadModel(product) {
    if (!state.models.has(product.id)) {
        const promise = loadGltf(THREE, product.modelUrl).then((gltf) => {
            ensureIndexedGeometry(THREE, gltf.scene);

            return gltf;
        });
        promise.catch(() => state.models.delete(product.id));
        state.models.set(product.id, promise);
    }

    return state.models.get(product.id);
}

async function mountProduct(product, requestedVariant) {
    const token = ++state.mountToken;
    setProductLoading(product, true);

    try {
        const gltf = await loadModel(product);
        const variants = listVariants(gltf);
        const variant = [requestedVariant, product.defaultVariant]
            .map((name) => resolveVariant(variants, name))
            .find(Boolean) ?? variants[0] ?? null;
        if (variant) {
            await applyVariant(gltf, gltf.scene, variant);
        }
        if (token !== state.mountToken) {
            return; // the shopper picked something else meanwhile
        }

        const shoe = fitShoe(THREE, gltf.scene, product.calibration);
        const group = new THREE.Group();
        group.add(shoe, createContactShadow(THREE, shoe.userData.footprint));

        releaseMountedShoe();
        HandTrackerThreeHelper.add_threeObject(group);
        state.mounted = group;
        state.product = product;
        state.variant = variant;

        renderProduct(variants);
        analytics.track('session_started', product.id, { once: true });
    } catch (error) {
        console.error(`Could not load ${product.name}:`, error);
        if (!state.mounted) {
            throw new TryOnError('model', `The 3D model for ${product.name} could not be loaded.`);
        }
    } finally {
        setProductLoading(product, false);
    }
}

function releaseMountedShoe() {
    if (!state.mounted) {
        return;
    }
    // Left-foot copies own cloned geometry; the helper only detaches them.
    for (const tracker of state.three.trackersLeft) {
        tracker.children.filter((child) => !child.userData.isOccluder).forEach(disposeObject);
    }
    HandTrackerThreeHelper.clear_threeObjects(false);
    state.mounted.getObjectByName('contactShadow')?.geometry.dispose();
    state.mounted = null;
}

function renderProduct(variants) {
    const { product } = state;
    ui.productName.textContent = product.name;
    ui.productPrice.textContent = product.price;
    ui.productLink.href = product.productUrl;
    document.title = `Try on ${product.name} · ${config.appName}`;
    if (window.location.pathname !== new URL(product.tryOnUrl).pathname) {
        history.replaceState(null, '', product.tryOnUrl);
    }

    ui.productList.querySelectorAll('button').forEach((button) => {
        button.setAttribute('aria-pressed', String(Number(button.dataset.productId) === product.id));
    });

    ui.variantList.replaceChildren(
        ...variants.map((name) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = name;
            button.setAttribute('aria-pressed', String(name === state.variant));
            button.className = 'rounded-full border border-white/30 bg-black/40 px-3 py-1.5 text-xs font-semibold capitalize backdrop-blur aria-pressed:border-orange-400 aria-pressed:bg-orange-500';
            button.addEventListener('click', () => selectVariant(name));

            return button;
        }),
    );
    ui.variantList.hidden = variants.length < 2;
}

function renderProductList() {
    ui.productList.replaceChildren(
        ...config.products.map((product) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.productId = product.id;
            button.title = product.name;
            button.className = 'relative grid size-16 shrink-0 snap-center place-items-center overflow-hidden rounded-2xl border-2 border-transparent bg-white/90 text-[10px] font-semibold leading-tight text-stone-900 transition aria-pressed:border-orange-500 data-[loading]:animate-pulse';
            button.setAttribute('aria-label', product.name);
            if (product.posterUrl) {
                const image = document.createElement('img');
                image.src = product.posterUrl;
                image.alt = '';
                image.className = 'h-full w-full object-contain p-1';
                button.append(image);
            } else {
                button.textContent = product.name;
            }
            button.addEventListener('click', () => selectProduct(product));

            return button;
        }),
    );
}

function setProductLoading(product, loading) {
    ui.productList.querySelector(`[data-product-id="${product.id}"]`)?.toggleAttribute('data-loading', loading);
}

function selectProduct(product) {
    if (product.id !== state.product.id) {
        mountProduct(product, product.defaultVariant);
    }
}

function selectVariant(name) {
    if (name !== state.variant) {
        mountProduct(state.product, name);
        analytics.track('variant_selected', state.product.id, { variant: name });
    }
}

/* ── Camera ──────────────────────────────────────────────────────── */

async function flipCamera() {
    if (state.busy || demoVideo) {
        return;
    }
    state.busy = true;
    const facingMode = state.facingMode === 'environment' ? 'user' : 'environment';

    try {
        await setPaused(true);
        stopCamera(state.stream);
        state.stream = await openCamera(ui.video, facingMode);
        state.facingMode = facingMode;
        await replaceVideo(ui.video);
        applyMirroring();
        layout();
        await setPaused(false);
    } catch (error) {
        fail(error);
    } finally {
        state.busy = false;
    }
}

async function onVisibilityChange() {
    if (state.phase !== 'running' || state.busy || demoVideo) {
        return;
    }
    state.busy = true;

    try {
        if (document.hidden) {
            // Release the camera (and its privacy indicator) in the background.
            await setPaused(true);
            stopCamera(state.stream);
            state.stream = null;
        } else {
            state.stream = await openCamera(ui.video, state.facingMode);
            await replaceVideo(ui.video);
            await setPaused(false);
        }
    } catch (error) {
        fail(error);
    } finally {
        state.busy = false;
    }
}

/* ── Snapshot ────────────────────────────────────────────────────── */

function showSnapshot(blob) {
    if (!blob) {
        return;
    }
    if (state.snapshot) {
        URL.revokeObjectURL(state.snapshot.url);
    }
    const file = new File([blob], `${state.product.slug}-try-on.jpg`, { type: 'image/jpeg' });
    state.snapshot = { file, url: URL.createObjectURL(blob) };
    ui.snapshotImage.src = state.snapshot.url;
    ui.shareButton.hidden = !navigator.canShare?.({ files: [file] });
    ui.snapshotDialog.showModal();
    analytics.track('snapshot_taken', state.product.id, { variant: state.variant });
}

async function shareSnapshot() {
    try {
        await navigator.share({
            files: [state.snapshot.file],
            title: state.product.name,
            text: `Trying on the ${state.product.name}`,
            url: state.product.productUrl,
        });
    } catch (error) {
        if (error.name !== 'AbortError') {
            console.warn('Share failed:', error);
        }
    }
}

function downloadSnapshot() {
    const link = document.createElement('a');
    link.href = state.snapshot.url;
    link.download = state.snapshot.file.name;
    link.click();
}

/* ── Start ───────────────────────────────────────────────────────── */

async function start() {
    setPhase('loading');
    setProgress(0, 'Starting camera…');

    try {
        assertBrowserSupport();
        layout();
        if (demoVideo) {
            await openDemoVideo(ui.video, demoVideo);
        } else {
            state.stream = await openCamera(ui.video, state.facingMode);
            countCameras().then((count) => (ui.flipButton.hidden = count < 2));
        }

        const label = 'Downloading foot tracking…';
        setProgress(0, label);
        const [network, occluder] = await Promise.all([
            downloadNeuralNetwork(config.tracker.neuralNetworkUrl, config.tracker.neuralNetworkBytes, (fraction) => setProgress(fraction * 0.9, label)),
            loadGltf(THREE, config.tracker.occluderUrl),
            loadModel(state.product),
        ]);

        setProgress(0.95, 'Starting tracker…');
        state.three = await startFootTracker({
            video: ui.video,
            trackerCanvas: ui.trackerCanvas,
            sceneCanvas: ui.sceneCanvas,
            neuralNetwork: network,
            threshold: tuning.threshold,
            maxFeet: config.tracker.maxFeet,
            cameraFov: tuning.cameraFov,
            // Landmark drawing has to be set up at init to be toggleable later.
            debugLandmarks: debugEnabled,
            onFrame: onTrackerFrame,
        });
        // Stacking workaround from the WebAR.rocks demos for iOS Safari.
        ui.trackerCanvas.style.zIndex = 3;
        ui.sceneCanvas.style.zIndex = 4;

        setupLighting(THREE, state.three.renderer, state.three.scene);
        HandTrackerThreeHelper.add_threeOccluder(placeOccluder(occluder.scene.children[0]));
        HandTrackerThreeHelper.add_threeOccluder(createCalfOccluder(THREE));

        renderProductList();
        // The product page passes the colourway the shopper was looking at.
        await mountProduct(state.product, new URLSearchParams(window.location.search).get('variant'));
        layout();
        setProgress(1);
        setPhase('running');
        renderTracking(false);

        if (debugEnabled) {
            await openDebugPanel();
        }
    } catch (error) {
        fail(error);
    }
}

/* ── Wire up ─────────────────────────────────────────────────────── */

root.addEventListener('click', (event) => {
    const action = event.target.closest('[data-action]')?.dataset.action;
    const actions = {
        start,
        retry: () => window.location.reload(),
        flip: flipCamera,
        snapshot: () => state.phase === 'running' && (state.snapshotRequested = true),
        share: shareSnapshot,
        download: downloadSnapshot,
        'close-snapshot': () => ui.snapshotDialog.close(),
    };
    actions[action]?.();
});

// The networks are trained on phone cameras looking down at feet; say so
// rather than letting laptop users conclude the tracking is broken.
if (!window.matchMedia('(pointer: coarse)').matches) {
    root.querySelector('[data-desktop-only]')?.removeAttribute('hidden');
}

window.addEventListener('resize', scheduleLayout, { passive: true });
window.visualViewport?.addEventListener('resize', scheduleLayout, { passive: true });
document.addEventListener('visibilitychange', onVisibilityChange);
window.addEventListener('pagehide', () => stopCamera(state.stream));
window.addEventListener('pageshow', (event) => event.persisted && window.location.reload());

setPhase('intro');

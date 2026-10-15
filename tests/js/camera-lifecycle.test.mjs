import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { openCamera, stopCamera, TryOnError } from '../../resources/js/tryon/camera.js';

function deferred() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { promise, resolve };
}

function stream() {
    const track = { stopped: false, stop() { this.stopped = true; } };
    return { track, getTracks: () => [track] };
}

function element() {
    return { style: {}, dataset: {}, addEventListener() {}, querySelector: element,
        querySelectorAll: () => [], parentElement: { setAttribute() {} } };
}

function harness(overrides = {}) {
    const root = element();
    const config = { products: [{ id: 1 }], productId: 1, tracker: { maxDevicePixelRatio: 1 } };
    const document = { hidden: false, addEventListener() {},
        getElementById: id => id === 'tryon-config' ? { textContent: JSON.stringify(config) } : root };
    const calls = [];
    const context = vm.createContext({
        document, URLSearchParams, console, navigator: {}, TryOnError,
        window: { location: { search: '' }, matchMedia: () => ({ matches: true }),
            addEventListener() {}, innerWidth: 400, innerHeight: 800 },
        setTimeout: () => 1, clearTimeout() {}, createAnalytics: () => ({ track() {} }),
        stopCamera, openCamera: async () => { const camera = stream(); calls.push(camera); return camera; },
        setPaused: async () => {}, replaceVideo: async () => {},
        HandTrackerThreeHelper: { reset_tracking() {}, resize() {} }, ...overrides,
    });
    const source = readFileSync(new URL('../../resources/js/tryon/main.js', import.meta.url), 'utf8')
        .replace(/^import .*;\r?\n/gm, '');
    vm.runInContext(source + '\nglobalThis.app = {state, onVisibilityChange, flipCamera};', context);
    context.app.state.phase = 'running';
    context.app.state.stream = stream();
    return { ...context.app, document, calls };
}

test('camera playback failure releases every acquired track and detaches the stream', async () => {
    const camera = stream();
    const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {
        mediaDevices: { getUserMedia: async () => camera },
    } });
    const video = { play: async () => { throw new Error('playback failed'); } };
    try {
        await assert.rejects(openCamera(video, 'environment'), /playback failed/);
        assert.equal(camera.track.stopped, true);
        assert.equal(video.srcObject, null);
    } finally {
        if (descriptor) Object.defineProperty(globalThis, 'navigator', descriptor);
        else delete globalThis.navigator;
    }
});

test('returning during a pending pause resumes tracking with a fresh stream', async () => {
    const pause = deferred();
    const paused = [];
    const h = harness({ setPaused: value => { paused.push(value); return value ? pause.promise : Promise.resolve(); } });
    const old = h.state.stream;
    h.document.hidden = true;
    const hiding = h.onVisibilityChange();
    h.document.hidden = false;
    await h.onVisibilityChange();
    pause.resolve();
    await hiding;
    assert.equal(old.track.stopped, true);
    assert.equal(h.state.stream, h.calls[0]);
    assert.equal(h.state.stream.track.stopped, false);
    assert.deepEqual(paused, [true, false]);
    assert.equal(h.state.busy, false);
});

test('hiding during camera acquisition releases the newly acquired stream', async () => {
    const capture = deferred();
    const camera = stream();
    const h = harness({ openCamera: () => capture.promise });
    h.state.stream = null;
    const showing = h.onVisibilityChange();
    h.document.hidden = true;
    await h.onVisibilityChange();
    capture.resolve(camera);
    await showing;
    assert.equal(camera.track.stopped, true);
    assert.equal(h.state.stream, null);
    assert.equal(h.state.busy, false);
});

test('camera flip reconciles a visibility change received while busy', async () => {
    const capture = deferred();
    const camera = stream();
    const h = harness({ openCamera: () => capture.promise });
    const old = h.state.stream;
    const flipping = h.flipCamera();
    h.document.hidden = true;
    await h.onVisibilityChange();
    capture.resolve(camera);
    await flipping;
    assert.equal(old.track.stopped, true);
    assert.equal(camera.track.stopped, true);
    assert.equal(h.state.stream, null);
    assert.equal(h.state.facingMode, 'user');
});

test('duplicate visibility events do not reacquire a running camera', async () => {
    const h = harness();
    const old = h.state.stream;
    await h.onVisibilityChange();
    await h.onVisibilityChange();
    assert.equal(h.state.stream, old);
    assert.equal(h.calls.length, 0);
    h.document.hidden = true;
    await h.onVisibilityChange();
    await h.onVisibilityChange();
    assert.equal(h.state.stream, null);
    assert.equal(old.track.stopped, true);
});

/**
 * Tracking debug overlay.
 *
 * Opened with ?debug=1 by an admin (or in any environment with APP_DEBUG on).
 * It shows what the tracker actually sees — the raw detection score per foot,
 * whether the 3D pose solved, and the video/canvas geometry — and lets the
 * numbers that govern tracking be tuned live.
 *
 * The camera field of view matters most: the pose is solved against it, so a
 * value far from the real lens tilts, offsets and mis-scales the shoe. Phones
 * are nearer 45-55 degrees than the library's 33 degree default.
 */

const FOV_PRESETS = [
    ['Default', 33],
    ['Phone', 50],
    ['Laptop', 45],
    ['Wide', 65],
];

function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

function formatNumber(value, digits = 2) {
    return Number.isFinite(value) ? value.toFixed(digits) : '–';
}

/**
 * @param {object} options
 * @param {{cameraFov: number, threshold: number, beta: number, minCutOff: number, landmarks: boolean, networkUrl: string}} options.settings
 * @param {Array<{name: string, url: string, bytes: number}>} options.networks
 * @param {(patch: object) => void} options.onChange applied live to the tracker
 */
export function createDebugPanel({ settings, networks, onChange }) {
    const panel = element('section', 'pointer-events-auto absolute left-2 top-2 z-30 w-64 rounded-2xl bg-black/80 p-3 text-[11px] leading-tight text-white shadow-xl backdrop-blur');
    panel.setAttribute('aria-label', 'Tracking debug');

    const header = element('div', 'mb-2 flex items-center justify-between');
    header.append(element('strong', 'text-xs', 'Tracking debug'));
    const collapse = element('button', 'rounded bg-white/10 px-2 py-0.5', 'hide');
    collapse.type = 'button';
    header.append(collapse);
    panel.append(header);

    const body = element('div', 'space-y-2');
    panel.append(body);
    collapse.addEventListener('click', () => {
        body.hidden = !body.hidden;
        collapse.textContent = body.hidden ? 'show' : 'hide';
    });

    const stats = element('pre', 'whitespace-pre-wrap font-mono text-[10px] text-emerald-300');
    body.append(stats);

    const controls = element('div', 'space-y-2 border-t border-white/15 pt-2');
    body.append(controls);

    const sliders = [
        { key: 'cameraFov', label: 'Camera FOV', min: 20, max: 75, step: 1, digits: 0, unit: '°' },
        { key: 'threshold', label: 'Detection threshold', min: 0.4, max: 0.99, step: 0.01, digits: 2, unit: '' },
        { key: 'beta', label: 'Stabiliser beta (lower = smoother)', min: 0.5, max: 20, step: 0.5, digits: 1, unit: '' },
        { key: 'minCutOff', label: 'Stabiliser min cut-off', min: 0.0005, max: 0.02, step: 0.0005, digits: 4, unit: '' },
    ];

    const inputs = {};
    for (const slider of sliders) {
        const label = element('label', 'block');
        const row = element('span', 'flex justify-between');
        row.append(element('span', '', slider.label));
        const output = element('span', 'font-mono text-orange-300', formatNumber(settings[slider.key], slider.digits) + slider.unit);
        row.append(output);
        label.append(row);

        const input = element('input', 'w-full accent-orange-500');
        Object.assign(input, { type: 'range', min: slider.min, max: slider.max, step: slider.step, value: settings[slider.key] });
        input.addEventListener('input', () => {
            const value = Number(input.value);
            output.textContent = formatNumber(value, slider.digits) + slider.unit;
            onChange({ [slider.key]: value });
        });
        label.append(input);
        controls.append(label);
        inputs[slider.key] = { input, output, slider };
    }

    const presets = element('div', 'flex flex-wrap gap-1');
    presets.append(element('span', 'w-full text-white/60', 'Camera FOV presets'));
    for (const [name, fov] of FOV_PRESETS) {
        const button = element('button', 'rounded bg-white/10 px-2 py-1 hover:bg-white/20', `${name} ${fov}°`);
        button.type = 'button';
        button.addEventListener('click', () => {
            inputs.cameraFov.input.value = fov;
            inputs.cameraFov.output.textContent = `${fov}°`;
            onChange({ cameraFov: fov });
        });
        presets.append(button);
    }
    controls.append(presets);

    if (networks.length > 1) {
        const label = element('label', 'block');
        label.append(element('span', 'block text-white/60', 'Neural network'));
        const select = element('select', 'w-full rounded bg-white/10 px-1 py-1');
        for (const network of networks) {
            const option = element('option', '', `${network.name} (${(network.bytes / 1024 / 1024).toFixed(1)} MB)`);
            option.value = network.url;
            option.selected = network.url === settings.networkUrl;
            select.append(option);
        }
        select.addEventListener('change', () => onChange({ networkUrl: select.value }));
        label.append(select);
        controls.append(label);
    }

    const landmarks = element('label', 'flex items-center gap-2');
    const landmarksInput = element('input', 'accent-orange-500');
    landmarksInput.type = 'checkbox';
    landmarksInput.checked = settings.landmarks;
    landmarksInput.addEventListener('change', () => onChange({ landmarks: landmarksInput.checked }));
    landmarks.append(landmarksInput, element('span', '', 'Draw landmarks'));
    controls.append(landmarks);

    const copy = element('button', 'w-full rounded bg-orange-500 px-2 py-1 font-semibold', 'Copy these settings');
    copy.type = 'button';
    copy.addEventListener('click', async () => {
        const text = [
            `TRYON_CAMERA_FOV=${Math.round(settings.cameraFov)}`,
            `TRYON_DETECTION_THRESHOLD=${formatNumber(settings.threshold, 2)}`,
            `# stabiliser: beta ${formatNumber(settings.beta, 1)}, minCutOff ${formatNumber(settings.minCutOff, 4)}`,
            `# network: ${settings.networkUrl}`,
        ].join('\n');
        try {
            await navigator.clipboard.writeText(text);
            copy.textContent = 'Copied';
        } catch {
            copy.textContent = text.split('\n').slice(0, 2).join('  ');
        }
        setTimeout(() => (copy.textContent = 'Copy these settings'), 2500);
    });
    controls.append(copy);

    return {
        node: panel,

        /** Called every tracker frame with what the library reported. */
        update({ fps, feet, video, canvas, pixelRatio, network }) {
            const lines = [
                `fps ${formatNumber(fps, 0)}   net ${network}`,
                `video ${video.width}x${video.height}  canvas ${canvas.width}x${canvas.height} @${formatNumber(pixelRatio, 2)}`,
            ];
            feet.forEach((foot, index) => {
                lines.push(
                    `foot ${index}: score ${formatNumber(foot.score)} ${foot.detected ? 'DETECTED' : 'no'}` +
                        `${foot.detected ? (foot.solved ? ' pose ok' : ' POSE FAILED') : ''}`,
                );
                if (foot.lock) {
                    lines.push(`  ${foot.lock.status}${foot.lock.reason ? ' / ' + foot.lock.reason : ''}` +
                        ` delta ${formatNumber(foot.lock.translationError, 3)} / ${formatNumber(foot.lock.rotationError, 1)} deg`);
                }
            });
            if (!feet.length) {
                lines.push('no feet reported');
            }
            stats.textContent = lines.join('\n');
        },

        destroy() {
            panel.remove();
        },
    };
}

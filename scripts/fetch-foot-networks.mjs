/**
 * Downloads the rest of the WebAR.rocks foot networks (MIT) so they can be
 * compared in the try-on debug overlay.
 *
 * Only NN_FOOT_31 (the demo default) and NN_BAREFOOT_3 ship with the app; the
 * others are ~19 MB each and are gitignored. If a different one tracks better
 * on your devices, point TRYON_NEURAL_NETWORK at it and ship that instead.
 *
 * Usage: npm run models:foot-nets
 */
import fs from 'node:fs/promises';
import path from 'node:path';

const BASE = 'https://raw.githubusercontent.com/WebAR-rocks/WebAR.rocks.hand/master/neuralNets';
const TARGET = 'public/vendor/webar-rocks/neural-nets';

const NETWORKS = [
    // Feet with shoes, socks or bare. Higher numbers are newer.
    'NN_FOOT_23', 'NN_FOOT_25', 'NN_FOOT_26', 'NN_FOOT_27',
    'NN_FOOT_28', 'NN_FOOT_29', 'NN_FOOT_30', 'NN_FOOT_31',
    // Bare feet only, but far smaller.
    'NN_BAREFOOT_1', 'NN_BAREFOOT_2', 'NN_BAREFOOT_3',
];

await fs.mkdir(TARGET, { recursive: true });

for (const name of NETWORKS) {
    const file = path.join(TARGET, `${name}.json`);
    const existing = await fs.stat(file).catch(() => null);
    if (existing) {
        console.log(`${name}: already present (${(existing.size / 1024 / 1024).toFixed(1)} MB)`);
        continue;
    }

    process.stdout.write(`${name}: downloading… `);
    const response = await fetch(`${BASE}/${name}.json`);
    if (!response.ok) {
        console.log(`failed (HTTP ${response.status})`);
        continue;
    }

    const bytes = Buffer.from(await response.arrayBuffer());
    // Guard against saving an HTML error page under a .json name.
    JSON.parse(bytes.toString('utf8'));
    await fs.writeFile(file, bytes);
    console.log(`${(bytes.length / 1024 / 1024).toFixed(1)} MB`);
}

console.log('\nOpen a try-on page with ?debug=1 as an admin to switch between them.');

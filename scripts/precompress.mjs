/**
 * Writes .br and .gz next to large static files so the web server can serve them
 * without compressing anything per request. The 19 MB tracking model is the
 * reason this exists: on the fly it would cost seconds of CPU per visitor.
 *
 * Usage: node scripts/precompress.mjs public/vendor public/models public/build
 */
import { brotliCompressSync, constants, gzipSync } from 'node:zlib';
import fs from 'node:fs';
import path from 'node:path';

// GLB embeds uncompressed vertex/index buffers alongside compressed textures.
// HTTP compression preserves float attributes needed by left-foot mirroring.
const COMPRESSIBLE = new Set(['.json', '.js', '.mjs', '.css', '.svg', '.wasm', '.map', '.glb']);
const MIN_BYTES = 1024;

function* walk(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const full = path.join(directory, entry.name);
        if (entry.isDirectory()) {
            yield* walk(full);
        } else if (entry.isFile()) {
            yield full;
        }
    }
}

let files = 0;
let saved = 0;

for (const directory of process.argv.slice(2)) {
    if (!fs.existsSync(directory)) {
        continue;
    }

    for (const file of walk(directory)) {
        if (!COMPRESSIBLE.has(path.extname(file)) || file.endsWith('.br') || file.endsWith('.gz')) {
            continue;
        }
        const source = fs.readFileSync(file);
        if (source.length < MIN_BYTES) {
            continue;
        }

        const brotli = brotliCompressSync(source, {
            params: {
                [constants.BROTLI_PARAM_QUALITY]: 11,
                [constants.BROTLI_PARAM_SIZE_HINT]: source.length,
            },
        });
        fs.writeFileSync(`${file}.br`, brotli);
        fs.writeFileSync(`${file}.gz`, gzipSync(source, { level: 9 }));

        files++;
        saved += source.length - brotli.length;
    }
}

console.log(`Pre-compressed ${files} files, saving ${(saved / 1024 / 1024).toFixed(1)} MB per cold visit.`);

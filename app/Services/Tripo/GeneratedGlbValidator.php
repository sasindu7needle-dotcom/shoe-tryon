<?php

namespace App\Services\Tripo;

use JsonException;

/** Structural checks; shoe semantics and transformed bounds require browser review. */
class GeneratedGlbValidator
{
    public function validate(string $path): void
    {
        $size = filesize($path);
        if ($size < 28 || $size > config('tripo.max_model_bytes')) {
            throw new TripoException('Generated model is empty or exceeds the download size limit.');
        }
        $file = fopen($path, 'rb');
        try {
            $header = unpack('a4magic/Vversion/Vlength', fread($file, 12));
            if ($header['magic'] !== 'glTF' || $header['version'] !== 2 || $header['length'] !== $size) {
                throw new TripoException('Generated model is not a complete binary glTF 2.0 asset.');
            }
            $json = null;
            $binaryLength = 0;
            $binaryOffset = 0;
            while (ftell($file) < $size) {
                if ($size - ftell($file) < 8) {
                    throw new TripoException('Generated GLB has an incomplete chunk.');
                }
                $chunk = unpack('Vlength/a4type', fread($file, 8));
                if ($chunk['length'] % 4 !== 0 || $chunk['length'] > $size - ftell($file)) {
                    throw new TripoException('Generated GLB has invalid chunk lengths.');
                }
                if ($json === null) {
                    if ($chunk['type'] !== 'JSON' || $chunk['length'] > 8 * 1024 * 1024) {
                        throw new TripoException('Generated GLB must start with a bounded JSON chunk.');
                    }
                    try {
                        $json = json_decode(fread($file, $chunk['length']), true, 128, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        throw new TripoException('Generated GLB metadata is invalid.');
                    }
                } else {
                    if ($chunk['type'] !== "BIN\0" || $binaryLength) {
                        throw new TripoException('Generated GLB contains unsupported chunks.');
                    }
                    $binaryLength = $chunk['length'];
                    $binaryOffset = ftell($file);
                    fseek($file, $binaryLength, SEEK_CUR);
                }
            }
        } finally {
            fclose($file);
        }
        if (($json['asset']['version'] ?? null) !== '2.0' || ! $binaryLength) {
            throw new TripoException('Generated model has no embedded glTF 2.0 geometry.');
        }
        $allowed = ['KHR_materials_unlit', 'KHR_texture_transform', 'KHR_materials_clearcoat', 'KHR_materials_transmission', 'KHR_materials_ior', 'KHR_materials_specular', 'KHR_materials_volume', 'KHR_materials_pbrSpecularGlossiness', 'KHR_lights_punctual', 'KHR_mesh_quantization', 'EXT_texture_webp'];
        foreach (array_unique(array_merge($json['extensionsRequired'] ?? [], $json['extensionsUsed'] ?? [])) as $extension) {
            if (! in_array($extension, $allowed, true)) {
                throw new TripoException('Generated model uses an unsupported extension ('.$extension.'). Export an uncompressed GLB and use manual upload.');
            }
        }
        foreach ($json['buffers'] ?? [] as $index => $buffer) {
            if ($index !== 0 || isset($buffer['uri']) || ($buffer['byteLength'] ?? PHP_INT_MAX) > $binaryLength) {
                throw new TripoException('Generated GLB must contain one embedded buffer.');
            }
        }
        foreach ($json['images'] ?? [] as $image) {
            if (isset($image['uri']) || ! in_array($image['mimeType'] ?? null, ['image/png', 'image/jpeg', 'image/webp'], true) || ! isset($json['bufferViews'][$image['bufferView'] ?? -1])) {
                throw new TripoException('Generated GLB textures must be embedded PNG, JPEG or WebP images.');
            }
        }
        foreach ($json['bufferViews'] ?? [] as $view) {
            if (($view['buffer'] ?? -1) !== 0 || ($view['byteOffset'] ?? 0) < 0 || ($view['byteLength'] ?? 0) <= 0 || ($view['byteOffset'] ?? 0) + $view['byteLength'] > $binaryLength) {
                throw new TripoException('Generated GLB has invalid buffer references.');
            }
        }
        $positions = 0;
        foreach ($json['meshes'] ?? [] as $mesh) {
            foreach ($mesh['primitives'] ?? [] as $primitive) {
                $accessor = $json['accessors'][$primitive['attributes']['POSITION'] ?? -1] ?? [];
                if (($primitive['mode'] ?? 4) !== 4 || ($accessor['type'] ?? null) !== 'VEC3' || ($accessor['componentType'] ?? null) !== 5126 || ($accessor['count'] ?? 0) < 3 || ! isset($accessor['bufferView']) || isset($accessor['sparse'])) {
                    throw new TripoException('Generated model needs usable triangle geometry for foot mirroring.');
                }
                $positions += $accessor['count'];
                if ($positions > 2000000) {
                    throw new TripoException('Generated model is too complex for live try-on.');
                }
                $this->positions($path, $accessor, $json['bufferViews'][$accessor['bufferView']] ?? [], $binaryOffset);
            }
        }
        if (! $positions || $positions > 2000000 || empty($json['scenes'][$json['scene'] ?? 0]['nodes'])) {
            throw new TripoException('Generated model has no usable scene or is too complex for live try-on.');
        }
    }

    private function positions(string $path, array $accessor, array $view, int $binaryOffset): void
    {
        $stride = $view['byteStride'] ?? 12;
        $offset = $accessor['byteOffset'] ?? 0;
        if ($stride < 12 || $stride > 252 || $stride % 4 !== 0 || $offset < 0 || $offset + ($accessor['count'] - 1) * $stride + 12 > ($view['byteLength'] ?? 0)) {
            throw new TripoException('Generated model has invalid position buffer ranges.');
        }
        $stream = fopen($path, 'rb');
        try {
            fseek($stream, $binaryOffset + ($view['byteOffset'] ?? 0) + $offset);
            $min = [INF, INF, INF];
            $max = [-INF, -INF, -INF];
            for ($i = 0; $i < $accessor['count']; $i++) {
                $bytes = fread($stream, 12);
                if (strlen($bytes) !== 12) {
                    throw new TripoException('Generated geometry is truncated.');
                }
                foreach (array_values(unpack('g3', $bytes)) as $axis => $value) {
                    if (! is_finite($value)) {
                        throw new TripoException('Generated model contains nonfinite geometry.');
                    }
                    $min[$axis] = min($min[$axis], $value);
                    $max[$axis] = max($max[$axis], $value);
                }
                if ($stride > 12) {
                    fseek($stream, $stride - 12, SEEK_CUR);
                }
            }
            if (max(array_map(fn ($axis) => $max[$axis] - $min[$axis], [0, 1, 2])) <= 1e-8) {
                throw new TripoException('Generated model contains degenerate geometry.');
            }
        } finally {
            fclose($stream);
        }
    }
}

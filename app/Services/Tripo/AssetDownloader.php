<?php

namespace App\Services\Tripo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AssetDownloader
{
    public function download(string $url, int $generationId): string
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443) || ! in_array($parts['host'] ?? '', config('tripo.asset_hosts'), true)) {
            throw new TripoException('Tripo returned an unapproved asset host. Ask the server administrator to verify the host allowlist.');
        }
        // Resolve once and pin the public IP to prevent DNS rebinding. Never follow redirects.
        $addresses = $this->resolve($parts['host']);
        if (! $addresses) {
            throw new TripoException('Asset host could not be resolved.', true);
        }
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new TripoException('Asset host resolves to an unsafe destination.');
            }
        }
        $disk = Storage::disk('local');
        $directory = 'generations/'.$generationId;
        $disk->makeDirectory($directory);
        $temporary = $directory.'/download.part';
        $path = $disk->path($temporary);
        try {
            $options = ['proxy' => '', 'sink' => $path, 'on_headers' => function ($response) {
                if ((int) $response->getHeaderLine('Content-Length') > config('tripo.max_model_bytes')) {
                    throw new TripoException('Generated model exceeds the download limit.');
                }
            }, 'progress' => function ($total, $downloaded) {
                if ($downloaded > config('tripo.max_model_bytes')) {
                    throw new TripoException('Generated model exceeds the download limit.');
                }
            }];
            if (extension_loaded('curl')) {
                $options['curl'] = [CURLOPT_RESOLVE => [$parts['host'].':443:'.$addresses[0]]];
            } elseif (! app()->runningUnitTests()) {
                throw new TripoException('Enable PHP cURL for securely pinned asset downloads.');
            }
            $response = Http::connectTimeout(config('tripo.connect_timeout'))->timeout(config('tripo.timeout'))
                ->withoutRedirecting()->withOptions($options)->get($url);
            if (! $response->successful()) {
                throw new TripoException('Asset download failed. A fresh provider link will be requested.', $response->status() === 403 || $response->status() === 429 || $response->serverError());
            }
            app(GeneratedGlbValidator::class)->validate($path);
            $final = $directory.'/model.glb';
            if (! rename($path, $disk->path($final))) {
                throw new TripoException('Could not promote the validated model into storage.');
            }

            return $final;
        } catch (TripoException $e) {
            throw $e;
        } catch (Throwable) {
            throw new TripoException('Asset transfer failed or exceeded its limits. Retrying safely.', true);
        } finally {
            $disk->delete($temporary);
        }
    }

    protected function resolve(string $host): array|false
    {
        return gethostbynamel($host);
    }
}

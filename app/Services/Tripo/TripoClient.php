<?php

namespace App\Services\Tripo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TripoClient
{
    private function request(): PendingRequest
    {
        if (! config('tripo.key')) {
            throw new TripoException('Configure TRIPO_API_KEY on the server before generating.');
        }

        return Http::baseUrl(config('tripo.base_url'))->withToken(config('tripo.key'))
            ->acceptJson()->connectTimeout(config('tripo.connect_timeout'))
            ->timeout(config('tripo.timeout'))->withoutRedirecting();
    }

    private function data(Response $response, bool $paid = false): array
    {
        $code = $response->json('code');
        $retry = max(15, min(300, (int) ($response->header('Retry-After') ?: ((int) $response->header('X-RateLimit-Reset') - time()))));
        if ($response->status() === 429 || in_array($code, [1007, 2000], true)) {
            throw new TripoException('Tripo is rate limited or busy. Wait before trying again.', true, false, $retry);
        }
        if ($response->serverError()) {
            throw new TripoException('Tripo is temporarily unavailable.', true, $paid);
        }
        if ($paid && ($response->status() === 408 || ($response->successful() && ($code === null || ($code === 0 && ! is_array($response->json('data'))))))) {
            throw new TripoException('Submission response was incomplete. Reconcile in the Tripo console before continuing.', false, true);
        }
        if (! $response->successful() || $code !== 0 || ! is_array($response->json('data'))) {
            $message = match ($code) {
                2010 => 'Tripo credits are exhausted. Top up the API account before an explicit paid retry.',
                2008 => 'Tripo rejected the photo. Review its content before an explicit paid retry.',
                default => 'Tripo rejected the request. Check the API credentials and selected options.',
            };
            throw new TripoException($message);
        }

        return $response->json('data');
    }

    public function upload(string $path): string
    {
        $stream = Storage::disk('local')->readStream($path);
        try {
            $data = $this->data($this->request()->attach('file', $stream, basename($path))->post('/files'));
        } catch (TripoException $e) {
            throw $e;
        } catch (Throwable) {
            throw new TripoException('Photo upload could not complete. Retry later.', true);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if (! is_string($data['file_token'] ?? null) || ! $data['file_token']) {
            throw new TripoException('Tripo did not return an image token.');
        }

        return $data['file_token'];
    }

    public function generate(array $tokens, array $options): string
    {
        $request = $this->request(); // Configuration errors occur before a paid submission.
        $multi = count($tokens) > 1;
        $payload = $options + ($multi
            ? ['inputs' => collect($tokens)->map(fn ($token, $view) => [$view => $token])->values()->all()]
            : ['input' => reset($tokens)]);
        try {
            $data = $this->data($request->post($multi ? '/generation/multiview-to-model' : '/generation/image-to-model', $payload), true);
        } catch (TripoException $e) {
            throw $e;
        } catch (Throwable) {
            throw new TripoException('Submission outcome is unknown. Reconcile in the Tripo console; do not submit again.', false, true);
        }
        if (! is_string($data['task_id'] ?? null) || ! preg_match('/^[a-zA-Z0-9_-]{1,120}$/', $data['task_id'])) {
            throw new TripoException('Submission returned no usable task ID. Reconcile in the Tripo console.', false, true);
        }

        return $data['task_id'];
    }

    public function task(string $id): array
    {
        try {
            return $this->data($this->request()->get('/tasks/'.rawurlencode($id)));
        } catch (TripoException $e) {
            throw $e;
        } catch (Throwable) {
            throw new TripoException('Could not read Tripo progress. Retrying safely.', true);
        }
    }
}

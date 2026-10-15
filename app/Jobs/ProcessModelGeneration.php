<?php

namespace App\Jobs;

use App\Models\ModelGeneration;
use App\Services\Tripo\AssetDownloader;
use App\Services\Tripo\TripoClient;
use App\Services\Tripo\TripoException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessModelGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $generationId)
    {
        $this->onQueue(config('tripo.queue'));
    }

    public function handle(TripoClient $client, AssetDownloader $downloader): void
    {
        $lock = Cache::lock('tripo-generation:'.$this->generationId, 150);
        if (! $lock->get()) {
            return;
        }
        try {
            $generation = ModelGeneration::find($this->generationId);
            if (! $generation || ! in_array($generation->status, ModelGeneration::ACTIVE, true)) {
                return;
            }
            if ($generation->next_poll_at?->isFuture()) {
                return;
            }
            // A worker may have died after sending the paid request. Never resend it.
            if ($generation->status === 'submitting') {
                $generation->update(['status' => 'ambiguous', 'error' => 'Submission was interrupted. Reconcile the task ID in the Tripo console before continuing.']);

                return;
            }
            if ($generation->deadline_at?->isPast()) {
                $generation->update(['status' => 'failed', 'error' => 'Polling deadline reached. Resume a known task to check again without spending credits.']);

                return;
            }
            try {
                if (! $generation->provider_task_id) {
                    $generation->update(['status' => 'uploading']);
                    $tokens = $generation->image_tokens ?? [];
                    foreach ($generation->source_paths as $view => $path) {
                        if (! isset($tokens[$view])) {
                            $tokens[$view] = $client->upload($path);
                            $generation->update(['image_tokens' => $tokens, 'read_failures' => 0]);
                            // One upload per job bounds execution even for four photos.
                            $this->schedule($generation, 5);

                            return;
                        }
                    }
                    $generation->update(['status' => 'submitting']);
                    $id = $client->generate($tokens, $generation->options);
                    $generation->update(['provider_task_id' => $id, 'status' => 'generating', 'read_failures' => 0]);
                    $this->schedule($generation);

                    return;
                }
                $task = $client->task($generation->provider_task_id);
                $progress = $task['progress'] ?? null;
                $generation->update(['provider_progress' => is_numeric($progress) && $progress >= 0 && $progress <= 100 ? (int) $progress : null]);
                if (in_array($task['status'] ?? '', ['failed', 'cancelled', 'banned', 'expired'], true)) {
                    throw new TripoException('Tripo task '.$task['status'].'. Review the source photo and API account. A new generation requires an explicit paid retry.');
                }
                if (($task['status'] ?? '') === 'success') {
                    $url = $task['output']['model_url'] ?? null;
                    if (! is_string($url) || ! $url) {
                        throw new TripoException('Tripo completed without a GLB URL. Review the provider task before trying again.');
                    }
                    $generation->update(['status' => 'validating']);
                    $path = $downloader->download($url, $generation->id);
                    $generation->update(['status' => 'ready', 'model_path' => $path, 'completed_at' => now(), 'error' => null, 'next_poll_at' => null]);

                    return;
                }
                if (! in_array($task['status'] ?? '', ['queued', 'running'], true)) {
                    throw new TripoException('Tripo returned an unrecognized task status. Check the existing task in the console.');
                }
                $generation->update(['status' => 'generating', 'read_failures' => 0, 'error' => null]);
                $this->schedule($generation);
            } catch (TripoException $e) {
                if ($e->ambiguous) {
                    $generation->update(['status' => 'ambiguous', 'error' => $e->getMessage()]);
                } elseif ($e->transient && $generation->status !== 'submitting' && $generation->read_failures < config('tripo.max_read_failures')) {
                    $generation->increment('read_failures');
                    $generation->update(['error' => $e->getMessage()]);
                    $this->schedule($generation, $e->retryAfter);
                } else {
                    $generation->update(['status' => 'failed', 'error' => $e->getMessage()]);
                }
            } catch (Throwable) {
                $generation->update(['status' => $generation->status === 'submitting' ? 'ambiguous' : 'failed', 'error' => 'Processing was interrupted. Check the existing task and server configuration before continuing.']);
            }
        } finally {
            $lock->release();
        }
    }

    private function schedule(ModelGeneration $generation, ?int $seconds = null): void
    {
        $next = now()->addSeconds(max(5, $seconds ?? config('tripo.poll_interval')));
        $generation->update(['next_poll_at' => $next]);
        self::dispatch($generation->id)->delay($next);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = ModelGeneration::find($this->generationId);
        if ($generation && in_array($generation->status, ModelGeneration::ACTIVE, true)) {
            $generation->update(['status' => $generation->status === 'submitting' ? 'ambiguous' : 'failed', 'error' => 'Worker stopped. Resume a known task, or reconcile an interrupted submission in the Tripo console.']);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ProcessModelGeneration;
use App\Models\ModelGeneration;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class MaintainModelGenerations extends Command
{
    protected $signature = 'tripo:maintain';

    protected $description = 'Recover interrupted polling and prune expired private drafts and orphan files';

    public function handle(): int
    {
        ModelGeneration::whereIn('status', ModelGeneration::ACTIVE)
            ->where('updated_at', '<', now()->subMinutes(3))
            ->chunkById(100, function ($generations) {
                foreach ($generations as $generation) {
                    ProcessModelGeneration::dispatch($generation->id);
                }
            });
        $cutoff = now()->subDays(config('tripo.retention_days'));
        ModelGeneration::whereIn('status', ['draft', 'failed', 'ready'])
            ->where('updated_at', '<', $cutoff)->chunkById(100, function ($generations) {
                foreach ($generations as $generation) {
                    $lock = Cache::lock('tripo-generation:'.$generation->id, 150);
                    if (! $lock->get()) {
                        continue;
                    }
                    try {
                        // Prepared products own a distinct public copy, which is retained.
                        Storage::disk('local')->deleteDirectory('generations/'.$generation->id);
                        $generation->delete();
                    } finally {
                        $lock->release();
                    }
                }
            });
        $referenced = ModelGeneration::all(['source_paths'])->flatMap(fn ($generation) => array_values($generation->source_paths))->all();
        foreach (Storage::disk('local')->files('generations/sources') as $path) {
            if (! in_array($path, $referenced, true) && Storage::disk('local')->lastModified($path) < $cutoff->timestamp) {
                Storage::disk('local')->delete($path);
            }
        }
        foreach (Storage::disk('public')->files('products/models') as $path) {
            if (str_starts_with(basename($path), 'generated-') && ! Product::where('model_path', Product::UPLOAD_PREFIX.$path)->exists()
                && Storage::disk('public')->lastModified($path) < $cutoff->timestamp) {
                Storage::disk('public')->delete($path);
            }
        }
        $this->info('Generation recovery and cleanup complete.');

        return self::SUCCESS;
    }
}

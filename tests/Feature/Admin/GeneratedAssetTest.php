<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessModelGeneration;
use App\Models\ModelGeneration;
use App\Models\Product;
use App\Services\Tripo\AssetDownloader;
use App\Services\Tripo\TripoClient;
use App\Services\Tripo\TripoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeneratedAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['tripo.key' => 'test-key']);
    }

    private function glb(array $overrides = [], ?string $binary = null): string
    {
        $binary ??= pack('g*', 0, 0, 0, 1, 0, 0, 0, 1, 1);
        $json = json_encode(array_replace([
            'asset' => ['version' => '2.0'], 'scene' => 0, 'scenes' => [['nodes' => [0]]],
            'nodes' => [['mesh' => 0]], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
            'buffers' => [['byteLength' => 36]], 'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 36]],
            'accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3']],
        ], $overrides), JSON_THROW_ON_ERROR);
        $json = str_pad($json, (int) (ceil(strlen($json) / 4) * 4), ' ');

        return 'glTF'.pack('VV', 2, 28 + strlen($json) + strlen($binary)).pack('V', strlen($json)).'JSON'.$json.pack('V', strlen($binary))."BIN\0".$binary;
    }

    private function downloader(): AssetDownloader
    {
        return new class extends AssetDownloader
        {
            protected function resolve(string $host): array|false
            {
                return ['93.184.216.34'];
            }
        };
    }

    public function test_download_is_validated_and_atomically_stored_without_provider_authorization(): void
    {
        Storage::fake('local');
        Http::fake(['https://cdn.tripo3d.ai/model.glb' => Http::response($this->glb())]);
        $path = $this->downloader()->download('https://cdn.tripo3d.ai/model.glb', 1);
        $this->assertSame('generations/1/model.glb', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertMissing('generations/1/download.part');
        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }

    public function test_unsafe_hosts_redirects_and_oversized_downloads_are_rejected(): void
    {
        Storage::fake('local');
        foreach (['http://cdn.tripo3d.ai/model.glb', 'https://cdn.tripo3d.ai.evil.test/model.glb', 'https://127.0.0.1/model.glb', 'https://user:pass@cdn.tripo3d.ai/model.glb', 'https://cdn.tripo3d.ai:8443/model.glb'] as $url) {
            try {
                $this->downloader()->download($url, 1);
                $this->fail('Unsafe URL accepted.');
            } catch (TripoException $e) {
                $this->assertStringContainsString('unapproved', $e->getMessage());
            }
        }
        Http::assertNothingSent();
        Http::fake(['https://cdn.tripo3d.ai/model.glb' => Http::sequence()->push('', 302, ['Location' => 'http://127.0.0.1/secret'])->push($this->glb())]);
        try {
            $this->downloader()->download('https://cdn.tripo3d.ai/model.glb', 1);
            $this->fail('Redirect accepted.');
        } catch (TripoException $e) {
            $this->assertFalse($e->transient);
        }
        config(['tripo.max_model_bytes' => 32]);
        try {
            $this->downloader()->download('https://cdn.tripo3d.ai/model.glb', 1);
            $this->fail('Oversized file accepted.');
        } catch (TripoException $e) {
            $this->assertStringContainsString('size limit', $e->getMessage());
        }
        Storage::disk('local')->assertMissing('generations/1/model.glb');
        Storage::disk('local')->assertMissing('generations/1/download.part');
        Http::assertSentCount(2);
    }

    public function test_invalid_compressed_external_and_nonfinite_assets_fail_before_product_creation(): void
    {
        Storage::fake('local');
        Queue::fake();
        $assets = [
            'truncated' => substr($this->glb(), 0, -1),
            'compressed' => $this->glb(['extensionsRequired' => ['EXT_meshopt_compression']]),
            'external' => $this->glb(['buffers' => [['byteLength' => 36, 'uri' => 'https://evil.test/model.bin']]]),
            'empty' => $this->glb(['meshes' => []]),
            'invalid range' => $this->glb(['accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 100, 'type' => 'VEC3']]]),
            'nonfinite' => $this->glb(binary: pack('g*', NAN, 0, 0, 1, 0, 0, 0, 1, 1)),
            'degenerate' => $this->glb(binary: str_repeat("\0", 36)),
        ];
        foreach ($assets as $name => $asset) {
            $draft = ModelGeneration::create(['draft' => ['name' => $name], 'source_paths' => [], 'options' => [], 'status' => 'generating', 'provider_task_id' => 'task_'.str_replace(' ', '_', $name), 'deadline_at' => now()->addMinutes(5)]);
            Http::fake([
                '*/tasks/*' => Http::response(['code' => 0, 'data' => ['status' => 'success', 'output' => ['model_url' => 'https://cdn.tripo3d.ai/model.glb']]]),
                'https://cdn.tripo3d.ai/model.glb' => Http::response($asset),
            ]);
            (new ProcessModelGeneration($draft->id))->handle(app(TripoClient::class), $this->downloader());
            $this->assertSame('failed', $draft->refresh()->status, $name);
            Storage::disk('local')->assertMissing('generations/'.$draft->id.'/model.glb');
        }
        $this->assertSame(0, Product::count());
        Queue::assertNothingPushed();
    }

    public function test_expired_asset_links_are_refetched_without_new_paid_tasks(): void
    {
        Storage::fake('local');
        Queue::fake();
        Http::fake([
            '*/tasks/task_shoe' => Http::response(['code' => 0, 'data' => ['status' => 'success', 'output' => ['model_url' => 'https://cdn.tripo3d.ai/model.glb']]]),
            'https://cdn.tripo3d.ai/model.glb' => Http::sequence()->push('', 403)->push($this->glb()),
        ]);
        $draft = ModelGeneration::create(['draft' => [], 'source_paths' => [], 'options' => [], 'status' => 'generating', 'provider_task_id' => 'task_shoe', 'deadline_at' => now()->addMinutes(5)]);
        $job = new ProcessModelGeneration($draft->id);
        $job->handle(app(TripoClient::class), $this->downloader());
        $this->assertSame('validating', $draft->refresh()->status);
        $draft->update(['next_poll_at' => null]);
        $job->handle(app(TripoClient::class), $this->downloader());
        $this->assertSame('ready', $draft->refresh()->status);
        Http::assertSentCount(4);
        Queue::assertPushed(ProcessModelGeneration::class, 1);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessModelGeneration;
use App\Models\ModelGeneration;
use App\Models\Product;
use App\Models\User;
use App\Services\Tripo\AssetDownloader;
use App\Services\Tripo\TripoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModelGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['tripo.key' => 'test-key', 'queue.default' => 'database']);
    }

    private function draft(array $overrides = []): ModelGeneration
    {
        return ModelGeneration::create(array_replace([
            'user_id' => $this->admin()->id,
            'draft' => ['name' => 'Generated Runner', 'price_cents' => 9900, 'currency' => 'USD', 'sort_order' => 0],
            'source_paths' => ['front' => 'generations/sources/front.png'],
            'options' => ['model' => 'v3.1-20260211', 'face_limit' => 20000, 'texture' => true, 'pbr' => true],
            'status' => 'draft',
            'deadline_at' => now()->addMinutes(30),
        ], $overrides));
    }

    private function work(ModelGeneration $draft): void
    {
        $draft->update(['next_poll_at' => null]);
        (new ProcessModelGeneration($draft->id))->handle(app(TripoClient::class), app(AssetDownloader::class));
    }

    public function test_generation_endpoints_require_an_admin(): void
    {
        $draft = $this->draft();
        $this->get(route('admin.generations.index'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        foreach (['show', 'status', 'asset'] as $action) {
            $this->get(route('admin.generations.'.$action, $draft))->assertForbidden();
        }
        foreach (['start', 'resume', 'retry', 'prepare'] as $action) {
            $this->post(route('admin.generations.'.$action, $draft))->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_saving_private_photos_does_not_create_a_paid_task_and_the_draft_reopens(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAs($this->admin())->post(route('admin.generations.store'), [
            'name' => 'Photo Runner', 'price' => '89.95', 'currency' => 'usd', 'sizes' => '8, 9',
            'photos' => ['front' => UploadedFile::fake()->image('front.png', 400, 400)],
            'model' => 'v3.1-20260211', 'face_limit' => 20000,
        ])->assertRedirect();
        $draft = ModelGeneration::sole();
        $this->assertSame('draft', $draft->status);
        $this->get(route('admin.generations.create'))->assertOk()->assertSee('Private source photos');
        $this->get(route('admin.generations.index'))->assertOk()->assertSee('Photo Runner');
        $this->assertSame(8995, $draft->draft['price_cents']);
        Storage::disk('local')->assertExists($draft->source_paths['front']);
        $this->get(route('admin.generations.show', $draft))->assertOk()->assertSee('Generate model')->assertDontSee('test-key');
        $this->get(route('admin.generations.status', $draft))->assertOk()->assertJsonPath('status', 'draft')->assertJsonMissingPath('source_paths');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_invalid_image_content_and_unknown_views_are_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('admin.generations.store'), [
            'name' => 'Runner', 'price' => 99, 'currency' => 'USD', 'model' => 'v3.1-20260211', 'face_limit' => 20000,
            'photos' => ['front' => UploadedFile::fake()->createWithContent('front.png', 'not an image'), 'top' => UploadedFile::fake()->image('top.png')],
        ])->assertSessionHasErrors(['photos', 'photos.front']);
        $this->assertSame(0, ModelGeneration::count());
    }

    public function test_paid_confirmation_and_duplicate_start_are_enforced(): void
    {
        Queue::fake();
        $draft = $this->draft();
        $this->actingAs($this->admin());
        $this->post(route('admin.generations.start', $draft))->assertSessionHasErrors('paid_confirmation');
        $this->post(route('admin.generations.start', $draft), ['paid_confirmation' => 1])->assertRedirect();
        $this->post(route('admin.generations.start', $draft), ['paid_confirmation' => 1])->assertRedirect();
        $this->assertSame('queued', $draft->refresh()->status);
        Queue::assertPushed(ProcessModelGeneration::class, 1);
        Http::assertNothingSent();
    }

    public function test_upload_submission_polling_and_completion_are_idempotent(): void
    {
        Storage::fake('local');
        Queue::fake();
        Storage::disk('local')->put('generations/sources/front.png', 'image');
        Http::fake([
            '*/files' => Http::response(['code' => 0, 'data' => ['file_token' => 'file_front']]),
            '*/generation/image-to-model' => Http::response(['code' => 0, 'data' => ['task_id' => 'task_shoe']]),
            '*/tasks/task_shoe' => Http::sequence()->push(['code' => 0, 'data' => ['status' => 'running', 'progress' => 45]])->push(['code' => 0, 'data' => ['status' => 'success', 'progress' => 100, 'output' => ['model_url' => 'https://cdn.tripo3d.ai/model.glb']]]),
        ]);
        $this->mock(AssetDownloader::class)->shouldReceive('download')->once()->with('https://cdn.tripo3d.ai/model.glb', \Mockery::type('int'))->andReturn('generations/1/model.glb');
        $draft = $this->draft(['status' => 'queued']);
        $this->work($draft);
        $this->assertSame('uploading', $draft->refresh()->status);
        $this->work($draft);
        $this->assertSame('task_shoe', $draft->refresh()->provider_task_id);
        $this->work($draft);
        $this->assertSame(45, $draft->refresh()->provider_progress);
        $this->work($draft);
        $this->assertSame('ready', $draft->refresh()->status);
        $this->work($draft);
        $this->assertSame(0, Product::count());
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'image-to-model') && $request['input'] === 'file_front' && ! isset($request['compress']) && $request->hasHeader('Authorization', 'Bearer test-key'));
        Queue::assertPushed(ProcessModelGeneration::class, 3);
    }

    public function test_multiview_uses_the_documented_labeled_inputs(): void
    {
        Queue::fake();
        Http::fake(['*/generation/multiview-to-model' => Http::response(['code' => 0, 'data' => ['task_id' => 'task_multi']])]);
        $draft = $this->draft(['status' => 'uploading', 'source_paths' => ['front' => 'front.png', 'back' => 'back.png'], 'image_tokens' => ['front' => 'file_front', 'back' => 'file_back']]);
        $this->work($draft);
        Http::assertSent(fn ($request) => $request['inputs'] === [['front' => 'file_front'], ['back' => 'file_back']]);
        $this->assertSame('generating', $draft->refresh()->status);
    }

    public function test_paid_timeout_is_ambiguous_and_is_never_resubmitted(): void
    {
        Queue::fake();
        Http::fake(['*/generation/image-to-model' => Http::failedConnection()]);
        $draft = $this->draft(['status' => 'uploading', 'image_tokens' => ['front' => 'file_front']]);
        $this->work($draft);
        $this->work($draft);
        $this->assertSame('ambiguous', $draft->refresh()->status);
        $this->actingAs($this->admin())->post(route('admin.generations.start', $draft), ['paid_confirmation' => 1])->assertRedirect();
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
        $this->post(route('admin.generations.resume', $draft), ['provider_task_id' => 'task_reconciled'])->assertRedirect();
        $this->assertSame('task_reconciled', $draft->refresh()->provider_task_id);
        Queue::assertPushed(ProcessModelGeneration::class, 1);
    }

    public function test_rate_limited_reads_are_delayed_and_provider_failures_are_safe(): void
    {
        Queue::fake();
        Http::fake(['*/tasks/task_shoe' => Http::sequence()->push(['code' => 1007], 429, ['Retry-After' => 80])->push(['code' => 0, 'data' => ['status' => 'failed', 'error_message' => 'SECRET signed URL']])]);
        $draft = $this->draft(['status' => 'generating', 'provider_task_id' => 'task_shoe']);
        $this->work($draft);
        $this->assertSame('generating', $draft->refresh()->status);
        $this->assertSame(1, $draft->read_failures);
        $this->assertTrue($draft->next_poll_at->greaterThan(now()->addSeconds(70)));
        $this->work($draft);
        $this->assertSame('failed', $draft->refresh()->status);
        $this->assertStringNotContainsString('SECRET', $draft->error);
        Queue::assertPushed(ProcessModelGeneration::class, 1);
    }

    public function test_expired_deadline_makes_no_provider_call_and_interrupted_submission_stays_ambiguous(): void
    {
        $draft = $this->draft(['status' => 'generating', 'provider_task_id' => 'task_shoe', 'deadline_at' => now()->subMinute()]);
        $this->work($draft);
        $this->assertSame('failed', $draft->refresh()->status);
        $interrupted = $this->draft(['status' => 'submitting', 'deadline_at' => now()->subMinute()]);
        $this->work($interrupted);
        $this->assertSame('ambiguous', $interrupted->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_review_creates_one_unpublished_product_then_requires_runtime_fit_review(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $draft = $this->draft(['status' => 'ready', 'model_path' => 'generations/1/model.glb']);
        Storage::disk('local')->put($draft->model_path, file_get_contents(public_path('models/samples/canvas-sneaker.glb')));
        $this->actingAs($this->admin());
        $this->post(route('admin.generations.prepare', $draft))->assertSessionHasErrors('visual_review');
        $this->post(route('admin.generations.prepare', $draft), ['visual_review' => 1])->assertRedirect();
        $this->post(route('admin.generations.prepare', $draft), ['visual_review' => 1])->assertRedirect();
        $product = Product::sole();
        $this->assertFalse($product->is_active);
        $this->assertTrue($product->requires_fit_review);
        $data = ['name' => $product->name, 'price' => 99, 'currency' => 'USD', 'is_active' => 1];
        $this->put(route('admin.products.update', $product), $data)->assertSessionHasErrors('is_active');
        $this->putJson(route('admin.products.calibration.update', $product), Product::DEFAULT_CALIBRATION)->assertJsonValidationErrors(['visual_review', 'model_path']);
        $this->putJson(route('admin.products.calibration.update', $product), Product::DEFAULT_CALIBRATION + ['visual_review' => true, 'runtime_preflight' => true, 'model_path' => $product->model_path])->assertOk();
        $this->put(route('admin.products.update', $product), $data)->assertRedirect();
        $this->assertTrue($product->refresh()->is_active);
        $this->assertNotNull($product->fit_reviewed_at);
        $this->get(route('admin.generations.asset', $draft))->assertOk()->assertHeader('Content-Type', 'model/gltf-binary');
    }

    public function test_overlapping_jobs_do_not_call_the_provider_and_read_retries_are_bounded(): void
    {
        Queue::fake();
        $draft = $this->draft(['status' => 'generating', 'provider_task_id' => 'task_shoe']);
        $lock = Cache::lock('tripo-generation:'.$draft->id, 150);
        $lock->get();
        try {
            $this->work($draft);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        Http::fake(['*/tasks/task_shoe' => Http::failedConnection()]);
        config(['tripo.max_read_failures' => 1]);
        $this->work($draft);
        $this->assertSame('generating', $draft->refresh()->status);
        $this->work($draft);
        $this->assertSame('failed', $draft->refresh()->status);
        Http::assertSentCount(2);
        Queue::assertPushed(ProcessModelGeneration::class, 1);
    }

    public function test_server_errors_and_incomplete_paid_responses_require_reconciliation(): void
    {
        Queue::fake();
        Http::fake(['*/generation/image-to-model' => Http::sequence()->push(['message' => 'secret signed URL'], 503)->push(['unexpected' => true])]);
        foreach ([1, 2] as $case) {
            $draft = $this->draft(['status' => 'uploading', 'image_tokens' => ['front' => 'file_front']]);
            $this->work($draft);
            $this->assertSame('ambiguous', $draft->refresh()->status);
            $this->assertStringNotContainsString('secret', $draft->error);
        }
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    public function test_a_rejected_paid_submission_requires_an_explicit_retry(): void
    {
        Queue::fake();
        Http::fake(['*/generation/image-to-model' => Http::response(['code' => 1007], 429)]);
        $draft = $this->draft(['status' => 'uploading', 'image_tokens' => ['front' => 'file_front']]);
        $this->work($draft);
        $this->assertSame('failed', $draft->refresh()->status);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public function test_a_paid_retry_is_a_new_free_draft_and_does_not_change_the_published_product(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['is_active' => true]);
        $draft = $this->draft(['status' => 'failed', 'provider_task_id' => 'task_old', 'product_id' => $product->id]);
        $this->actingAs($this->admin())->post(route('admin.generations.retry', $draft))->assertRedirect();
        $copy = ModelGeneration::latest('id')->first();
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->provider_task_id);
        $this->assertNull($copy->product_id);
        $this->assertSame($draft->source_paths, $copy->source_paths);
        $this->assertTrue($product->refresh()->is_active);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_cleanup_preserves_referenced_product_files_newer_shared_photos_and_ambiguous_drafts(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
        $product = Product::factory()->create(['model_path' => 'storage/products/models/generated-100.glb']);
        Storage::disk('public')->put('products/models/generated-100.glb', 'referenced');
        $old = $this->draft(['status' => 'ready', 'product_id' => $product->id, 'model_path' => 'generations/1/model.glb']);
        $new = $this->draft();
        $ambiguous = $this->draft(['status' => 'ambiguous']);
        Storage::disk('local')->put($old->source_paths['front'], 'shared');
        Storage::disk('local')->put($old->model_path, 'private-model');
        $old->timestamps = false;
        $old->forceFill(['updated_at' => now()->subDays(40)])->save();
        $ambiguous->timestamps = false;
        $ambiguous->forceFill(['updated_at' => now()->subDays(40)])->save();
        $this->artisan('tripo:maintain')->assertSuccessful();
        $this->assertModelMissing($old);
        $this->assertModelExists($ambiguous);
        Storage::disk('local')->assertMissing($old->model_path);
        Storage::disk('local')->assertExists($new->source_paths['front']);
        Storage::disk('public')->assertExists('products/models/generated-100.glb');
        Queue::assertNothingPushed();
    }

    public function test_stale_calibration_cannot_approve_a_replacement(): void
    {
        $product = Product::factory()->create(['requires_fit_review' => true]);
        $this->actingAs($this->admin())->putJson(route('admin.products.calibration.update', $product), Product::DEFAULT_CALIBRATION + [
            'visual_review' => true, 'runtime_preflight' => true, 'model_path' => 'storage/products/models/old.glb',
        ])->assertJsonValidationErrors('model_path');
        $this->assertTrue($product->refresh()->requires_fit_review);
    }

    public function test_generation_requires_credentials_a_persistent_queue_and_a_safe_reservation(): void
    {
        Queue::fake();
        $draft = $this->draft();
        $this->actingAs($this->admin());
        config(['tripo.key' => null]);
        $this->post(route('admin.generations.start', $draft), ['paid_confirmation' => 1])->assertSessionHasErrors('generation');
        config(['tripo.key' => 'test-key', 'queue.default' => 'sync']);
        $this->post(route('admin.generations.start', $draft), ['paid_confirmation' => 1])->assertSessionHasErrors('generation');
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
        $this->post(route('admin.generations.start', $draft), ['paid_confirmation' => 1])->assertSessionHasErrors('generation');
        $this->assertSame('draft', $draft->refresh()->status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_insufficient_credits_is_a_rejected_submission_with_an_actionable_error(): void
    {
        Queue::fake();
        Http::fake(['*/generation/image-to-model' => Http::response(['code' => 2010, 'message' => 'secret'])]);
        $draft = $this->draft(['status' => 'uploading', 'image_tokens' => ['front' => 'file_front']]);
        $this->work($draft);
        $this->assertSame('failed', $draft->refresh()->status);
        $this->assertStringContainsString('Top up', $draft->error);
        $this->assertStringNotContainsString('secret', $draft->error);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }
}

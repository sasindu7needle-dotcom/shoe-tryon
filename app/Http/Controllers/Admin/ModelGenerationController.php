<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModelGenerationRequest;
use App\Jobs\ProcessModelGeneration;
use App\Models\ModelGeneration;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ModelGenerationController extends Controller
{
    public function index(): View
    {
        return view('admin.generations.index', ['generations' => ModelGeneration::with('product')->latest()->paginate(25)]);
    }

    public function create(): View
    {
        return view('admin.generations.create');
    }

    public function store(ModelGenerationRequest $request): RedirectResponse
    {
        $paths = [];
        foreach ($request->file('photos') as $view => $photo) {
            if ($photo) {
                $paths[$view] = $photo->store('generations/sources', 'local');
            }
        }
        $data = $request->safe()->except(['photos', 'model', 'face_limit']);
        $data['price_cents'] = (int) round($data['price'] * 100);
        unset($data['price']);
        $data['sizes'] = array_values(array_filter(array_map('trim', explode(',', $data['sizes'] ?? ''))));
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        try {
            $generation = ModelGeneration::create([
                'user_id' => $request->user()->id, 'draft' => $data, 'source_paths' => $paths, 'status' => 'draft',
                'options' => ['model' => $request->validated('model'), 'face_limit' => (int) $request->validated('face_limit'), 'texture' => true, 'pbr' => true],
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete(array_values($paths));
            throw $e;
        }

        return redirect()->route('admin.generations.show', $generation);
    }

    public function show(ModelGeneration $generation): View
    {
        return view('admin.generations.show', ['generation' => $generation->load('product')]);
    }

    public function status(ModelGeneration $generation): JsonResponse
    {
        return response()->json($generation->only(['status', 'provider_progress', 'error', 'updated_at']));
    }

    public function start(Request $request, ModelGeneration $generation): RedirectResponse
    {
        $request->validate(['paid_confirmation' => ['required', 'accepted']]);
        if (! config('tripo.key')) {
            throw ValidationException::withMessages(['generation' => 'Configure TRIPO_API_KEY on the server first.']);
        }
        if (in_array(config('queue.default'), ['sync', 'deferred', 'background', 'null'], true)) {
            throw ValidationException::withMessages(['generation' => 'Configure a persistent queue (database or Redis) before generation.']);
        }
        $reservation = config('queue.connections.'.config('queue.default').'.retry_after');
        if ($reservation !== null && $reservation <= 120) {
            throw ValidationException::withMessages(['generation' => 'Set the queue retry_after reservation above 120 seconds (180 recommended), then restart workers.']);
        }
        $started = DB::transaction(function () use ($generation) {
            $locked = ModelGeneration::lockForUpdate()->findOrFail($generation->id);
            if (! in_array($locked->status, ['draft', 'failed'], true)) {
                return false;
            }
            // A known provider task is resumed through the free resume action.
            if ($locked->provider_task_id) {
                throw ValidationException::withMessages(['generation' => 'Resume the existing task or create a new draft for a paid retry.']);
            }
            $locked->update(['status' => 'queued', 'started_at' => now(), 'deadline_at' => now()->addMinutes(config('tripo.deadline_minutes')), 'next_poll_at' => null, 'error' => null, 'read_failures' => 0]);

            return true;
        });
        if ($started) {
            ProcessModelGeneration::dispatch($generation->id);
        }

        return back();
    }

    public function resume(Request $request, ModelGeneration $generation): RedirectResponse
    {
        $request->validate(['provider_task_id' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_-]{1,120}$/']]);
        DB::transaction(function () use ($request, $generation) {
            $locked = ModelGeneration::lockForUpdate()->findOrFail($generation->id);
            abort_unless(in_array($locked->status, ['ambiguous', 'failed'], true), 409);
            $id = $locked->provider_task_id ?: $request->input('provider_task_id');
            if (! $id) {
                throw ValidationException::withMessages(['provider_task_id' => 'Supply the task ID verified in your Tripo console.']);
            }
            $locked->update(['provider_task_id' => $id, 'status' => 'generating', 'deadline_at' => now()->addMinutes(config('tripo.deadline_minutes')), 'next_poll_at' => null, 'read_failures' => 0, 'error' => null]);
            ProcessModelGeneration::dispatch($locked->id)->afterCommit();
        });

        return back();
    }

    public function retry(Request $request, ModelGeneration $generation): RedirectResponse
    {
        abort_unless($generation->status === 'failed', 409);
        $copy = ModelGeneration::create(['user_id' => $request->user()->id, 'draft' => $generation->draft, 'source_paths' => $generation->source_paths, 'options' => $generation->options, 'status' => 'draft']);

        return redirect()->route('admin.generations.show', $copy)->with('status', 'New draft created. Starting it spends Tripo credits.');
    }

    public function asset(ModelGeneration $generation): BinaryFileResponse
    {
        abort_unless($generation->status === 'ready' && $generation->model_path, 404);

        return response()->file(Storage::disk('local')->path($generation->model_path), ['Content-Type' => 'model/gltf-binary', 'Cache-Control' => 'private, no-store', 'Content-Disposition' => 'attachment; filename="shoe.glb"']);
    }

    public function prepare(Request $request, ModelGeneration $generation): RedirectResponse
    {
        $request->validate(['visual_review' => ['required', 'accepted']]);
        $product = DB::transaction(function () use ($generation) {
            $locked = ModelGeneration::lockForUpdate()->findOrFail($generation->id);
            abort_unless($locked->status === 'ready' && $locked->model_path, 409);
            if ($locked->product_id) {
                return $locked->product;
            }
            $path = 'products/models/generated-'.$locked->id.'.glb';
            $stream = Storage::disk('local')->readStream($locked->model_path);
            try {
                if (! Storage::disk('public')->put($path, $stream)) {
                    throw ValidationException::withMessages(['generation' => 'Could not store the product model.']);
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $product = Product::create($locked->draft + [
                'slug' => Str::slug($locked->draft['name']).'-'.Str::lower(Str::random(8)),
                'model_path' => Product::UPLOAD_PREFIX.$path, 'calibration' => Product::DEFAULT_CALIBRATION,
                'is_active' => false, 'requires_fit_review' => true,
            ]);
            $locked->update(['product_id' => $product->id]);

            return $product;
        });

        return redirect()->route('admin.products.calibration.edit', $product);
    }
}

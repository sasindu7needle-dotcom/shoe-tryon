<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TryOnController;
use App\Http\Requests\Admin\CalibrationRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductCalibrationController extends Controller
{
    public function edit(Product $product): View
    {
        return view('admin.products.calibration', [
            'product' => $product,
            'config' => [
                'product' => $product->toTryOnPayload(),
                'tracker' => TryOnController::trackerConfig(),
                // The canvas sneaker is the tracker's own reference shoe, so it
                // is a reliable ghost to align an uploaded model against.
                'reference' => [
                    'modelUrl' => asset('models/samples/canvas-sneaker.glb').'?v='.config('tryon.asset_version'),
                    'calibration' => ['forwardAxis' => '+x'] + Product::DEFAULT_CALIBRATION,
                ],
                'saveUrl' => route('admin.products.calibration.update', $product),
                'modelPath' => $product->model_path,
                'requiresReview' => $product->requires_fit_review,
            ],
        ]);
    }

    public function update(CalibrationRequest $request, Product $product): JsonResponse
    {
        $product = DB::transaction(function () use ($request, $product) {
            $locked = Product::lockForUpdate()->findOrFail($product->id);
            if (($request->has('model_path') && $request->input('model_path') !== $locked->model_path)
                || ($locked->requires_fit_review && ($request->input('model_path') !== $locked->model_path || ! $request->boolean('visual_review') || ! $request->boolean('runtime_preflight')))) {
                throw ValidationException::withMessages(['model_path' => 'Reload the current model and complete its visual fit review before saving.']);
            }
            $locked->update(['calibration' => $request->calibration(), 'requires_fit_review' => false, 'fit_reviewed_at' => now()]);

            return $locked;
        });

        return response()->json(['calibration' => $product->resolvedCalibration()]);
    }
}

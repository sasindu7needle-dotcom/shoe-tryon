<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TryOnController;
use App\Http\Requests\Admin\CalibrationRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
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
            ],
        ]);
    }

    public function update(CalibrationRequest $request, Product $product): JsonResponse
    {
        $product->update(['calibration' => $request->calibration()]);

        return response()->json(['calibration' => $product->resolvedCalibration()]);
    }
}

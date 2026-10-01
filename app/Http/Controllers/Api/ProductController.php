<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Public catalogue for headless integrations (e.g. linking an existing
     * store's product pages to /try-on/{slug}).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Product::listed()->get()->map->toTryOnPayload()->values(),
        ]);
    }
}

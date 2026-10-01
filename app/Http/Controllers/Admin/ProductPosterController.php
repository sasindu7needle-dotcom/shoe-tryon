<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductPosterController extends Controller
{
    /**
     * Store a poster captured from the 3D viewer in the admin product form.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'poster' => ['required', 'image', 'mimes:webp,png,jpg,jpeg', 'max:'.config('tryon.max_poster_upload_kb')],
        ]);

        if ($previous = Product::uploadedDiskPath($product->poster_path)) {
            Storage::disk('public')->delete($previous);
        }

        $product->update([
            'poster_path' => Product::UPLOAD_PREFIX.$request->file('poster')->store('products/posters', 'public'),
        ]);

        return response()->json(['posterUrl' => $product->posterUrl()]);
    }
}

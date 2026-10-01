<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function index(): View
    {
        return view('store.index', [
            'products' => Product::listed()->get(),
        ]);
    }

    public function show(Request $request, Product $product): View
    {
        // Admins can preview products before publishing them.
        abort_unless($product->is_active || $request->user()?->is_admin, 404);

        return view('store.show', [
            'product' => $product,
            'related' => Product::listed()->whereKeyNot($product->id)->limit(3)->get(),
        ]);
    }

    public function credits(): View
    {
        return view('store.credits', [
            'products' => Product::listed()->whereNotNull('attribution')->get(),
        ]);
    }
}

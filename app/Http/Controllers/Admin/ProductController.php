<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        return view('admin.products.index', [
            'products' => Product::query()->orderBy('sort_order')->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.form', [
            'product' => new Product(['currency' => 'USD', 'is_active' => false]),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = new Product($request->productAttributes());
        $product->model_path = $this->storeModel($request->file('model'));
        $product->poster_path = $request->hasFile('poster') ? $this->storePoster($request->file('poster')) : null;
        $product->calibration = Product::DEFAULT_CALIBRATION;
        $product->save();

        return redirect()->route('admin.products.calibration.edit', $product)
            ->with('status', 'Product saved. Use “Detect orientation”, check the fit, then publish it.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.form', ['product' => $product]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $product->fill($request->productAttributes());
        $status = 'Product updated.';

        if ($request->hasFile('model')) {
            $this->deleteUpload($product->model_path);
            $product->model_path = $this->storeModel($request->file('model'));
            $status = 'Model replaced. Re-check the fit in the calibrator.';
        }

        if ($request->hasFile('poster')) {
            $this->deleteUpload($product->poster_path);
            $product->poster_path = $this->storePoster($request->file('poster'));
        }

        $product->save();

        return redirect()->route('admin.products.edit', $product)->with('status', $status);
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->deleteUploadedFiles();
        $product->delete();

        return redirect()->route('admin.products.index')->with('status', "Deleted {$product->name}.");
    }

    private function storeModel(UploadedFile $file): string
    {
        // GLB uploads usually arrive as application/octet-stream, so the
        // extension cannot be guessed from the MIME type.
        return Product::UPLOAD_PREFIX.$file->storeAs('products/models', Str::random(40).'.glb', 'public');
    }

    private function storePoster(UploadedFile $file): string
    {
        return Product::UPLOAD_PREFIX.$file->store('products/posters', 'public');
    }

    private function deleteUpload(?string $path): void
    {
        if ($diskPath = Product::uploadedDiskPath($path)) {
            Storage::disk('public')->delete($diskPath);
        }
    }
}

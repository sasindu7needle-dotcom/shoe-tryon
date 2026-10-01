@extends('layouts.admin')

@section('title', $product->exists ? "Edit {$product->name}" : 'Add product')

@if ($product->exists)
    @push('head')
        @vite(['resources/js/admin/poster-capture.js'])
    @endpush
@endif

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">{{ $product->exists ? "Edit {$product->name}" : 'Add product' }}</h1>
        @if ($product->exists)
            <div class="flex gap-2 text-sm font-semibold">
                <a href="{{ route('admin.products.calibration.edit', $product) }}" class="rounded-full bg-orange-500 px-4 py-2 text-white hover:bg-orange-600">Adjust fit</a>
                <a href="{{ route('products.show', $product) }}" target="_blank" class="rounded-full border border-stone-300 bg-white px-4 py-2 hover:border-stone-900">Preview ↗</a>
            </div>
        @endif
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_22rem]">
        <form method="POST" enctype="multipart/form-data"
              action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}"
              class="space-y-6 rounded-2xl border border-stone-200 bg-white p-6">
            @csrf
            @if ($product->exists)
                @method('PUT')
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                    <p class="font-semibold">Please fix the following:</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-medium">Name</span>
                    <input name="name" value="{{ old('name', $product->name) }}" required maxlength="120" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">URL slug</span>
                    <input name="slug" value="{{ old('slug', $product->slug) }}" maxlength="140" placeholder="Generated from the name" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Brand</span>
                    <input name="brand" value="{{ old('brand', $product->brand) }}" maxlength="80" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2">
                </label>
                <div class="grid grid-cols-[1fr_6rem] gap-2">
                    <label class="block">
                        <span class="text-sm font-medium">Price</span>
                        <input name="price" type="number" step="0.01" min="0" value="{{ old('price', $product->exists ? $product->price_cents / 100 : '') }}" required class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Currency</span>
                        <input name="currency" value="{{ old('currency', $product->currency) }}" required maxlength="3" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 uppercase">
                    </label>
                </div>
            </div>

            <label class="block">
                <span class="text-sm font-medium">Description</span>
                <textarea name="description" rows="4" maxlength="5000" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2">{{ old('description', $product->description) }}</textarea>
            </label>

            <label class="block">
                <span class="text-sm font-medium">Sizes</span>
                <input name="sizes" value="{{ old('sizes', implode(', ', $product->sizes ?? [])) }}" placeholder="US 7, US 8, US 9" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2">
                <span class="mt-1 block text-xs text-stone-500">Comma separated.</span>
            </label>

            <fieldset class="rounded-xl border border-stone-200 p-4">
                <legend class="px-1 text-sm font-semibold">3D model</legend>
                @if ($product->exists)
                    <p class="text-sm text-stone-600">Current: <a href="{{ $product->modelUrl() }}" class="underline">{{ basename($product->model_path) }}</a></p>
                @endif
                <label class="mt-2 block">
                    <span class="text-sm font-medium">{{ $product->exists ? 'Replace model' : 'Upload model' }} (.glb)</span>
                    <input name="model" type="file" accept=".glb,model/gltf-binary" @required(! $product->exists) class="mt-1 block w-full text-sm file:mr-3 file:rounded-full file:border-0 file:bg-stone-900 file:px-4 file:py-2 file:text-white">
                </label>
                <ul class="mt-3 list-inside list-disc space-y-1 text-xs text-stone-500">
                    <li>A single <strong>right</strong> shoe works best (left shoes are mirrored automatically).</li>
                    <li>Aim for under 3 MB: textures ≤ 1024 px, under ~50k triangles. Avoid Draco/meshopt compression.</li>
                    <li>Colourways can be embedded with KHR_materials_variants.</li>
                </ul>
                <label class="mt-3 block">
                    <span class="text-sm font-medium">Default colourway</span>
                    <input name="default_variant" value="{{ old('default_variant', $product->default_variant) }}" maxlength="64" placeholder="Variant name, if the model has variants" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2">
                </label>
            </fieldset>

            <label class="block">
                <span class="text-sm font-medium">Poster image</span>
                <input name="poster" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-sm file:mr-3 file:rounded-full file:border-0 file:bg-stone-200 file:px-4 file:py-2">
                <span class="mt-1 block text-xs text-stone-500">Or capture one from the 3D view after saving.</span>
            </label>

            <label class="block">
                <span class="text-sm font-medium">Model credit / license</span>
                <input name="attribution" value="{{ old('attribution', $product->attribution) }}" maxlength="500" placeholder="Required for CC BY models, e.g. “Shoe” by Jane Doe, CC BY 4.0" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2">
            </label>

            <div class="flex flex-wrap items-center gap-6">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active)) class="rounded border-stone-300">
                    Published
                </label>
                <label class="flex items-center gap-2 text-sm font-medium">
                    Sort order
                    <input name="sort_order" type="number" min="0" value="{{ old('sort_order', $product->sort_order ?? 0) }}" class="w-24 rounded-xl border border-stone-300 px-3 py-1.5">
                </label>
            </div>

            <div class="flex justify-end">
                <button class="rounded-full bg-stone-900 px-6 py-2.5 font-semibold text-white hover:bg-stone-700">{{ $product->exists ? 'Save changes' : 'Create product' }}</button>
            </div>
        </form>

        @if ($product->exists)
            <aside class="space-y-6">
                <div class="rounded-2xl border border-stone-200 bg-white p-4" data-poster-capture data-url="{{ route('admin.products.poster.store', $product) }}">
                    <h2 class="text-sm font-semibold">Poster from 3D view</h2>
                    <p class="mt-1 text-xs text-stone-500">Rotate the shoe, then capture a transparent poster for the catalogue.</p>
                    <model-viewer src="{{ $product->modelUrl() }}" camera-controls camera-orbit="0deg 80deg auto" field-of-view="25deg"
                                  environment-image="neutral" shadow-intensity="0" interaction-prompt="none"
                                  @if ($product->default_variant) variant-name="{{ $product->default_variant }}" @endif
                                  class="mt-3 aspect-square w-full rounded-xl bg-[conic-gradient(at_50%_50%,#f5f5f4_25%,#fff_0_50%,#f5f5f4_0_75%,#fff_0)] bg-[length:24px_24px]"></model-viewer>
                    <button type="button" class="mt-3 w-full rounded-full bg-stone-900 py-2 text-sm font-semibold text-white disabled:opacity-50">Capture poster</button>
                    <p class="mt-2 text-xs text-stone-500" data-poster-status role="status"></p>
                    @if ($product->posterUrl())
                        <img src="{{ $product->posterUrl() }}" alt="Current poster" data-poster-preview class="mt-2 aspect-square w-full rounded-xl bg-stone-100 object-contain">
                    @else
                        <img alt="Current poster" data-poster-preview class="mt-2 aspect-square w-full rounded-xl bg-stone-100 object-contain" hidden>
                    @endif
                </div>

                <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="rounded-2xl border border-red-200 bg-white p-4"
                      onsubmit="return confirm('Delete this product? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <h2 class="text-sm font-semibold text-red-700">Delete product</h2>
                    <p class="mt-1 text-xs text-stone-500">Removes the product and its uploaded files. Analytics are kept.</p>
                    <button class="mt-3 rounded-full border border-red-300 px-4 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-50">Delete</button>
                </form>
            </aside>
        @endif
    </div>
@endsection

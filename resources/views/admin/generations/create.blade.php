@extends('layouts.admin')
@section('title', 'Generate from photos')
@push('head')
    @vite('resources/js/admin/generations.js')
@endpush
@section('content')
    <h1 class="text-2xl font-semibold">Generate from photos</h1>
    <p class="mt-2 max-w-2xl text-sm text-stone-600">Photograph one shoe with good lighting and a clean background. Saving this draft is free; generation starts only after your explicit confirmation.</p>
    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif
    <form data-generation-form method="POST" action="{{ route('admin.generations.store') }}" enctype="multipart/form-data" class="mt-6 max-w-3xl space-y-5 rounded-2xl border border-stone-200 bg-white p-6">
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (['name' => 'Product name', 'brand' => 'Brand', 'price' => 'Price', 'currency' => 'Currency', 'sizes' => 'Sizes (comma separated)', 'sort_order' => 'Display order'] as $field => $label)
                <label class="text-sm font-medium">{{ $label }}
                    <input name="{{ $field }}" value="{{ old($field, match($field) { 'currency' => 'USD', 'sort_order' => 0, default => '' }) }}" @required(in_array($field, ['name', 'price', 'currency'])) type="{{ in_array($field, ['price', 'sort_order']) ? 'number' : 'text' }}" @if ($field === 'price') step="0.01" min="0" @endif class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2">
                </label>
            @endforeach
        </div>
        <label class="block text-sm font-medium">Description<textarea name="description" rows="3" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2">{{ old('description') }}</textarea></label>
        <fieldset>
            <legend class="text-sm font-semibold">Private source photos</legend>
            <p class="mt-1 text-xs text-stone-500">Use the front slot for a single photo (a three-quarter view works well). For multi-view, provide front and at least one of left, back or right, showing the same shoe. JPEG or PNG, 256–8192 pixels per side, up to {{ config('tripo.max_image_kb') / 1024 }} MB each. Photos are sent to Tripo when generation starts.</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                @foreach (['front', 'left', 'back', 'right'] as $view)
                    <div data-photo-slot class="rounded-xl border border-stone-200 p-3">
                        <label class="text-sm font-medium">{{ ucfirst($view) }} {{ $view === 'front' ? '(required)' : '(optional)' }}<input data-photo type="file" name="photos[{{ $view }}]" accept="image/jpeg,image/png" @required($view === 'front') class="mt-2 block w-full text-xs"></label>
                        <img data-photo-preview hidden alt="Selected {{ $view }} photo" class="mt-3 h-36 w-full rounded-lg object-contain">
                        <button data-remove-photo hidden type="button" class="mt-2 text-xs font-semibold text-red-700">Remove photo</button>
                    </div>
                @endforeach
            </div>
        </fieldset>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm font-medium">Tripo model<select name="model" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2">@foreach (config('tripo.models') as $model)<option value="{{ $model }}" @selected(old('model', config('tripo.model')) === $model)>{{ $model }}</option>@endforeach</select></label>
            <label class="text-sm font-medium">Maximum faces<input type="number" name="face_limit" value="{{ old('face_limit', 20000) }}" min="1000" max="50000" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"></label>
        </div>
        <p class="text-xs text-stone-500">Textured PBR GLB; optional compression omitted. Geometry must be checked visually for product accuracy.</p>
        <button type="submit" class="rounded-full bg-stone-900 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">Save generation draft</button>
    </form>
@endsection

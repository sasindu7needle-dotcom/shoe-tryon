@extends('layouts.admin')

@section('title', "Fit {$product->name}")

@php
    $version = config('tryon.asset_version');
    $sliders = [
        ['scale', 'Size', 0.5, 2, 0.01, '×'],
        ['offsetCm.0', 'Left ↔ right', -10, 10, 0.1, 'cm'],
        ['offsetCm.1', 'Heel ↔ toe', -10, 10, 0.1, 'cm'],
        ['offsetCm.2', 'Down ↔ up', -10, 10, 0.1, 'cm'],
        ['rotationDeg.0', 'Tilt toe up/down', -45, 45, 0.5, '°'],
        ['rotationDeg.1', 'Roll inward/outward', -45, 45, 0.5, '°'],
        ['rotationDeg.2', 'Turn left/right', -45, 45, 0.5, '°'],
    ];
    $axes = ['+x' => '+X', '-x' => '−X', '+y' => '+Y', '-y' => '−Y', '+z' => '+Z', '-z' => '−Z'];
@endphp

@push('head')
    <script src="{{ asset('vendor/three-r131/three.min.js') }}?v={{ $version }}" defer></script>
    <script src="{{ asset('vendor/three-r131/GLTFLoader.js') }}?v={{ $version }}" defer></script>
    <script src="{{ asset('vendor/three-r131/RoomEnvironment.js') }}?v={{ $version }}" defer></script>
    <script src="{{ asset('vendor/three-r131/OrbitControls.js') }}?v={{ $version }}" defer></script>
    @vite(['resources/js/admin/calibrator.js'])
@endpush

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm text-stone-500"><a href="{{ route('admin.products.edit', $product) }}" class="hover:underline">← {{ $product->name }}</a></p>
            <h1 class="text-2xl font-semibold">Fit on the foot</h1>
        </div>
        <a href="{{ route('try-on.show', $product) }}" target="_blank" class="rounded-full border border-stone-300 bg-white px-4 py-2 text-sm font-semibold hover:border-stone-900">Test live try-on ↗</a>
    </div>

    <div id="calibrator" class="mt-6 grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="relative min-h-[28rem] overflow-hidden rounded-2xl border border-stone-200 bg-gradient-to-b from-stone-800 to-stone-950">
            <canvas data-preview class="absolute inset-0 h-full w-full"></canvas>
            <div class="pointer-events-none absolute left-4 top-4 space-y-1 rounded-xl bg-black/50 p-3 text-xs text-white">
                <p><span class="mr-1 inline-block size-2 rounded-full bg-sky-400"></span>Reference shoe (ideal fit)</p>
                <p><span class="mr-1 inline-block size-2 rounded-full bg-rose-400"></span>Foot &amp; leg (hidden in try-on)</p>
                <p class="text-white/60">Toes point along the green axis.</p>
            </div>
            <p data-load-status class="absolute inset-x-0 bottom-4 text-center text-sm text-white/80" role="status">Loading model…</p>
        </div>

        <form data-controls class="space-y-5 rounded-2xl border border-stone-200 bg-white p-5">
            <p class="text-xs text-stone-500">This preview fits against a reference shoe; it does not measure a real foot.</p>
            @if ($product->requires_fit_review)
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" data-visual-review required class="mt-1">I reviewed one shoe, with no pair or background geometry, and confirmed its orientation and fit.</label>
            @endif
            <div>
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Orientation</h2>
                    <button type="button" data-action="detect" class="rounded-full bg-orange-500 px-3 py-1 text-xs font-semibold text-white hover:bg-orange-600">Detect orientation</button>
                </div>
                <p data-detect-result class="mt-1 text-xs text-stone-500">How the toes and top of the shoe point in the uploaded file.</p>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    @foreach (['forwardAxis' => 'Toes point to', 'upAxis' => 'Top points to'] as $field => $label)
                        <label class="block text-xs font-medium">
                            {{ $label }}
                            <select name="{{ $field }}" class="mt-1 w-full rounded-lg border border-stone-300 px-2 py-1.5 text-sm">
                                @foreach ($axes as $value => $text)
                                    <option value="{{ $value }}">{{ $text }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm">
                    <input type="checkbox" name="mirror" class="rounded border-stone-300">
                    Mirror (the file contains a left shoe)
                </label>
            </div>

            <div class="space-y-3 border-t border-stone-100 pt-4">
                <h2 class="text-sm font-semibold">Fine-tune</h2>
                @foreach ($sliders as [$field, $label, $min, $max, $step, $unit])
                    <label class="block">
                        <span class="flex justify-between text-xs font-medium">
                            {{ $label }}
                            <output class="tabular-nums text-stone-500" data-output-for="{{ $field }}"></output>
                        </span>
                        <input type="range" name="{{ $field }}" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" data-unit="{{ $unit }}" class="mt-1 w-full accent-orange-500">
                    </label>
                @endforeach
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-stone-100 pt-4">
                <button type="button" data-action="reset" class="text-sm font-medium text-stone-500 hover:text-stone-900">Reset fine-tuning</button>
                <button type="submit" class="rounded-full bg-stone-900 px-5 py-2 text-sm font-semibold text-white hover:bg-stone-700 disabled:opacity-50">Save fit</button>
            </div>
            <p data-save-status class="text-right text-xs text-stone-500" role="status"></p>
        </form>
    </div>

    <script type="application/json" id="calibrator-config">@json($config)</script>
@endsection

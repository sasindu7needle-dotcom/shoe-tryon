@extends('layouts.store')

@section('title', $product->name.' · '.config('app.name'))
@section('description', Str::limit((string) $product->description, 155))

@push('head')
    <meta property="og:title" content="{{ $product->name }}">
    <meta property="og:type" content="product">
    @if ($product->posterUrl())
        <meta property="og:image" content="{{ $product->posterUrl() }}">
    @endif
    @vite(['resources/js/product-viewer.js'])
@endpush

@section('content')
    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-8 sm:px-6 md:grid-cols-[1.2fr_1fr] md:py-14">
        <div class="relative aspect-square overflow-hidden rounded-3xl border border-stone-200 bg-gradient-to-b from-white to-stone-100">
            <model-viewer
                id="product-viewer"
                src="{{ $product->modelUrl() }}"
                @if ($product->posterUrl()) poster="{{ $product->posterUrl() }}" @endif
                alt="3D model of {{ $product->name }}"
                camera-controls
                touch-action="pan-y"
                auto-rotate
                auto-rotate-delay="1500"
                rotation-per-second="18deg"
                camera-orbit="35deg 75deg auto"
                shadow-intensity="1"
                shadow-softness="0.8"
                environment-image="neutral"
                exposure="1"
                ar
                ar-modes="webxr scene-viewer quick-look"
                ar-scale="fixed"
                @if ($product->default_variant) variant-name="{{ $product->default_variant }}" @endif
                class="h-full w-full"
            >
                <button slot="ar-button" class="absolute bottom-4 left-4 rounded-full bg-white px-4 py-2 text-sm font-semibold shadow-md ring-1 ring-stone-200">
                    View in your space
                </button>
            </model-viewer>
            <p class="pointer-events-none absolute right-4 top-4 rounded-full bg-white/80 px-3 py-1 text-xs font-medium text-stone-600 backdrop-blur">Drag to rotate</p>
        </div>

        <div class="flex flex-col">
            <p class="text-sm font-medium uppercase tracking-wide text-stone-500">{{ $product->brand }}</p>
            <h1 class="mt-1 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $product->name }}</h1>
            <p class="mt-2 text-2xl text-stone-800">{{ $product->formattedPrice() }}</p>
            @unless ($product->is_active)
                <p class="mt-3 rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-800">Draft: only admins can see this page.</p>
            @endunless

            <div id="variant-picker" class="mt-6" hidden>
                <p class="text-sm font-medium">Colourway: <span data-variant-label class="capitalize text-stone-600"></span></p>
                <div data-variant-options class="mt-2 flex flex-wrap gap-2"></div>
            </div>

            @if ($product->sizes)
                <fieldset class="mt-6">
                    <legend class="text-sm font-medium">Size</legend>
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        @foreach ($product->sizes as $size)
                            <label class="cursor-pointer">
                                <input type="radio" name="size" value="{{ $size }}" class="peer sr-only">
                                <span class="block rounded-xl border border-stone-300 py-2 text-center text-sm transition peer-checked:border-stone-900 peer-checked:bg-stone-900 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-orange-500">{{ $size }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            <a href="{{ route('try-on.show', $product) }}" class="mt-8 inline-flex items-center justify-center gap-2 rounded-full bg-orange-500 px-6 py-4 text-base font-semibold text-white transition hover:bg-orange-600">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                Try on with your camera
            </a>
            <p class="mt-2 text-center text-xs text-stone-500">Best on a phone. Your camera feed stays on your device.</p>

            @if ($product->description)
                <div class="mt-8 border-t border-stone-200 pt-6 text-stone-700">
                    <h2 class="text-sm font-semibold text-stone-900">About this shoe</h2>
                    <p class="mt-2 leading-relaxed">{{ $product->description }}</p>
                </div>
            @endif

            @if ($product->attribution)
                <p class="mt-6 text-xs text-stone-500">3D model: {{ $product->attribution }}</p>
            @endif
        </div>
    </div>

    @if ($related->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 pb-20 sm:px-6">
            <h2 class="text-xl font-semibold">Try these on too</h2>
            <ul class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3">
                @foreach ($related as $item)
                    <li class="rounded-2xl border border-stone-200 bg-white p-4">
                        <a href="{{ route('products.show', $item) }}" class="block">
                            @if ($item->posterUrl())
                                <img src="{{ $item->posterUrl() }}" alt="" loading="lazy" class="aspect-square w-full object-contain">
                            @endif
                            <p class="mt-2 font-semibold">{{ $item->name }}</p>
                            <p class="text-sm text-stone-600">{{ $item->formattedPrice() }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection

@php($version = config('tryon.asset_version'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <title>Try on {{ $product->name }} · {{ config('app.name') }}</title>
    <meta name="description" content="See the {{ $product->name }} on your own feet with your phone camera.">
    @vite(['resources/css/app.css'])

    {{-- Classic scripts, in dependency order (all MIT licensed, see /credits). --}}
    <script src="{{ asset('vendor/three-r131/three.min.js') }}?v={{ $version }}" defer></script>
    <script src="{{ asset('vendor/three-r131/GLTFLoader.js') }}?v={{ $version }}" defer></script>
    <script src="{{ asset('vendor/three-r131/RoomEnvironment.js') }}?v={{ $version }}" defer></script>
    <script src="{{ asset('vendor/webar-rocks/WebARRocksHand.js') }}?v={{ $version }}" defer></script>
    <script src="{{ asset('vendor/webar-rocks/helpers/OneEuroLMStabilizer.js') }}?v={{ $version }}" defer></script>
    <script src="{{ asset('vendor/webar-rocks/helpers/HandTrackerThreeHelper.js') }}?v={{ $version }}" defer></script>
    @vite(['resources/js/tryon/main.js'])
</head>
<body class="bg-black text-white antialiased">
<div id="tryon" class="fixed inset-0 overflow-hidden select-none" data-phase="intro">
    {{-- Camera preview until the tracker draws; then the tracker canvas covers it. --}}
    <video id="camera-video" class="absolute left-0 top-0 z-0 h-full w-full object-cover" playsinline muted></video>
    <canvas id="tracker-canvas" class="absolute left-0 top-0 z-[1]"></canvas>
    <canvas id="scene-canvas" class="pointer-events-none absolute left-0 top-0 z-[2]"></canvas>

    {{-- Running UI --}}
    <div class="absolute inset-0 z-10 flex flex-col justify-between" data-phases="loading running" hidden>
        <header class="flex items-center gap-3 bg-gradient-to-b from-black/60 to-transparent px-4 pb-8 pt-[max(1rem,env(safe-area-inset-top))]">
            <a id="product-link" href="{{ route('products.show', $product) }}" class="grid size-10 place-items-center rounded-full bg-black/40 backdrop-blur" aria-label="Back to product">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
            </a>
            <div class="min-w-0 flex-1 text-center">
                <p id="product-name" class="truncate text-sm font-semibold">{{ $product->name }}</p>
                <p id="product-price" class="text-xs text-white/70">{{ $product->formattedPrice() }}</p>
            </div>
            <button type="button" data-action="flip" class="grid size-10 place-items-center rounded-full bg-black/40 backdrop-blur" aria-label="Switch camera" hidden>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-3.2l-1.6-2.4A1.3 1.3 0 0 0 14.1 4H9.9a1.3 1.3 0 0 0-1.1.6L7.2 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M9 13.5a3 3 0 0 1 5.2-2M15 12.5a3 3 0 0 1-5.2 2"/><path d="M14.5 9.5v2h-2M9.5 16.5v-2h2"/></svg>
            </button>
        </header>

        <div class="pointer-events-none flex flex-1 flex-col items-center gap-3 px-6" data-phases="running">
            <p id="tracking-status" class="flex items-center gap-2 rounded-full bg-black/50 px-3 py-1.5 text-xs font-medium backdrop-blur" role="status" aria-live="polite">
                <span class="size-2 rounded-full bg-amber-400" data-status-dot></span>
                <span data-status-text>Looking for your feet…</span>
            </p>
            <div id="tracking-hint" class="mt-auto mb-4 max-w-xs rounded-2xl bg-black/60 p-4 text-center text-sm leading-relaxed backdrop-blur" hidden>
                Point the camera down at your feet from about <strong>1 m (3 ft)</strong>. Keep both feet in view and in good light.
            </div>
        </div>

        <footer class="bg-gradient-to-t from-black/70 to-transparent pb-[max(1rem,env(safe-area-inset-bottom))] pt-6">
            <div id="loading-panel" class="mx-4 mb-2 rounded-2xl bg-black/60 p-4 backdrop-blur" data-phases="loading">
                <p id="loading-label" class="mb-2 text-sm font-medium">Starting camera…</p>
                <div class="h-1.5 overflow-hidden rounded-full bg-white/20" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-labelledby="loading-label">
                    <div id="loading-progress" class="h-full w-0 rounded-full bg-orange-500 transition-[width] duration-200"></div>
                </div>
                <p class="mt-2 text-xs text-white/60">The first visit downloads about 10 MB. Later visits load instantly.</p>
            </div>

            <div data-phases="running">
                <div id="variant-list" class="mb-3 flex justify-center gap-2 px-4" hidden></div>
                <div class="mb-3 flex justify-center">
                    <button type="button" data-action="snapshot" class="grid size-16 place-items-center rounded-full border-4 border-white/90 bg-white/20 backdrop-blur transition active:scale-95" aria-label="Take a photo">
                        <span class="size-11 rounded-full bg-white"></span>
                    </button>
                </div>
                <div id="product-list" class="flex snap-x gap-3 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" aria-label="Choose a shoe"></div>
            </div>
        </footer>
    </div>

    {{-- Intro --}}
    <section class="absolute inset-0 z-20 flex flex-col overflow-y-auto bg-stone-950" data-phases="intro">
        <div class="relative flex-1">
            @if ($product->posterUrl())
                <img src="{{ $product->posterUrl() }}" alt="" class="absolute inset-0 h-full w-full object-contain p-10 opacity-90">
            @endif
            <a href="{{ route('products.show', $product) }}" class="absolute left-4 top-[max(1rem,env(safe-area-inset-top))] grid size-10 place-items-center rounded-full bg-white/10" aria-label="Back to product">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
            </a>
        </div>
        <div class="mx-auto w-full max-w-md px-6 pb-[max(1.5rem,env(safe-area-inset-bottom))]">
            <p class="text-sm font-medium uppercase tracking-widest text-orange-400">Live try-on</p>
            <h1 class="mt-1 text-3xl font-semibold">{{ $product->name }}</h1>
            <ol class="mt-5 space-y-3 text-sm text-white/80">
                <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-white/10 text-xs font-semibold">1</span>Stand in good light and point your phone at your feet.</li>
                <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-white/10 text-xs font-semibold">2</span>Hold the camera about 1 m (3 ft) away, both feet in view.</li>
                <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-white/10 text-xs font-semibold">3</span>Turn your feet slowly to see every angle.</li>
            </ol>
            <p data-desktop-only class="mt-5 rounded-2xl border border-amber-400/30 bg-amber-400/10 p-3 text-xs leading-relaxed text-amber-200" hidden>
                <strong>On a laptop this works poorly.</strong> Foot tracking expects a phone held above your feet,
                looking down. A built-in webcam sees them side-on from too close, so the shoe may drift or not appear.
                Open this page on your phone for a real result.
            </p>
            <button type="button" data-action="start" class="mt-6 w-full rounded-full bg-orange-500 px-6 py-4 text-base font-semibold text-white transition hover:bg-orange-400 active:scale-[0.99]">
                Start camera
            </button>
            <p class="mt-3 text-center text-xs text-white/50">
                Your camera feed is processed on this device and never uploaded.
                <a href="{{ route('products.show', $product) }}" class="underline">View in 3D instead</a>
            </p>
        </div>
    </section>

    {{-- Error --}}
    <section class="absolute inset-0 z-30 flex items-center justify-center bg-stone-950/95 p-6" data-phases="error" hidden>
        <div class="max-w-sm text-center">
            <div class="mx-auto grid size-14 place-items-center rounded-full bg-red-500/15 text-red-400">
                <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 8v5M12 16.5v.01"/><circle cx="12" cy="12" r="9"/></svg>
            </div>
            <h2 class="mt-4 text-xl font-semibold">Live try-on couldn’t start</h2>
            <p id="error-message" class="mt-2 text-sm text-white/70"></p>
            <div class="mt-6 flex flex-col gap-3">
                <button type="button" data-action="retry" class="rounded-full bg-orange-500 px-6 py-3 font-semibold">Try again</button>
                <a href="{{ route('products.show', $product) }}" class="rounded-full border border-white/20 px-6 py-3 font-semibold">View in 3D</a>
            </div>
        </div>
    </section>

    {{-- Snapshot --}}
    <dialog id="snapshot-dialog" class="m-auto w-[min(92vw,28rem)] rounded-3xl bg-stone-900 p-3 text-white backdrop:bg-black/70">
        <img id="snapshot-image" alt="Your try-on photo" class="max-h-[65vh] w-full rounded-2xl object-contain">
        <div class="mt-3 grid grid-cols-3 gap-2 text-sm font-semibold">
            <button type="button" data-action="share" class="rounded-full bg-orange-500 py-3" hidden>Share</button>
            <button type="button" data-action="download" class="rounded-full bg-white/10 py-3">Save</button>
            <button type="button" data-action="close-snapshot" class="rounded-full bg-white/10 py-3">Close</button>
        </div>
    </dialog>
</div>

<script type="application/json" id="tryon-config">@json($config)</script>
</body>
</html>

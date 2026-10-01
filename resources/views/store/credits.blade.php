@extends('layouts.store')

@section('title', 'Credits & licenses · '.config('app.name'))

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <h1 class="text-3xl font-semibold tracking-tight">Credits &amp; licenses</h1>
        <p class="mt-3 text-stone-600">This store is built on open-source software that allows commercial use. Thank you to their authors.</p>

        <h2 class="mt-10 text-lg font-semibold">Software</h2>
        <ul class="mt-3 divide-y divide-stone-200 rounded-2xl border border-stone-200 bg-white">
            @foreach ([
                ['WebAR.rocks.hand', 'Foot detection and tracking', 'MIT License, © 2020 WebAR.rocks', 'https://github.com/WebAR-rocks/WebAR.rocks.hand', 'vendor/webar-rocks/LICENSE'],
                ['three.js', '3D rendering', 'MIT License, © 2010-2021 three.js authors', 'https://threejs.org', 'vendor/three-r131/LICENSE'],
                ['<model-viewer>', '3D product viewer and AR', 'Apache License 2.0, © Google LLC', 'https://modelviewer.dev', null],
                ['Laravel', 'Web application framework', 'MIT License, © Taylor Otwell', 'https://laravel.com', null],
                ['Tailwind CSS', 'Styling', 'MIT License, © Tailwind Labs', 'https://tailwindcss.com', null],
            ] as [$name, $purpose, $license, $url, $licenseFile])
                <li class="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <a href="{{ $url }}" class="font-semibold hover:underline" rel="noopener">{{ $name }}</a>
                        <p class="text-sm text-stone-600">{{ $purpose }}</p>
                    </div>
                    <p class="text-sm text-stone-500">
                        @if ($licenseFile)
                            <a href="{{ asset($licenseFile) }}" class="hover:underline">{{ $license }}</a>
                        @else
                            {{ $license }}
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>

        @if ($products->isNotEmpty())
            <h2 class="mt-10 text-lg font-semibold">3D models</h2>
            <ul class="mt-3 divide-y divide-stone-200 rounded-2xl border border-stone-200 bg-white">
                @foreach ($products as $product)
                    <li class="p-4">
                        <a href="{{ route('products.show', $product) }}" class="font-semibold hover:underline">{{ $product->name }}</a>
                        <p class="text-sm text-stone-600">{{ $product->attribution }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection

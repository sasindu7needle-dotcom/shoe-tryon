<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('description', 'Try shoes on your own feet with your phone camera, no app needed.')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="flex min-h-screen flex-col bg-stone-50 font-sans text-stone-900 antialiased">
    <header class="sticky top-0 z-40 border-b border-stone-200/70 bg-stone-50/85 backdrop-blur">
        <nav class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 text-lg font-semibold tracking-tight">
                <span class="grid size-8 place-items-center rounded-lg bg-stone-900 text-sm text-white">
                    <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 16.5c0-1 .6-1.8 1.6-2l5.2-1.1 2.6-4.1c.3-.5 1-.6 1.4-.2l1.4 1.2c.8.7 2 .9 3 .5l.8-.3c.6-.2 1.2.1 1.4.7l1.3 3.3c.2.6.3 1.2.3 1.8v.7c0 .6-.4 1-1 1H3c-.6 0-1-.4-1-1v-.5z"/></svg>
                </span>
                {{ config('app.name') }}
            </a>
            <div class="flex items-center gap-5 text-sm font-medium text-stone-600">
                <a href="{{ route('home') }}#catalog" class="hover:text-stone-900">Shop</a>
                @auth
                    @if (auth()->user()->is_admin)
                        <a href="{{ route('admin.dashboard') }}" class="hover:text-stone-900">Admin</a>
                    @endif
                @endauth
            </div>
        </nav>
    </header>

    <main class="flex-1">
        @yield('content')
    </main>

    <footer class="border-t border-stone-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-8 text-sm text-stone-500 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <p>&copy; {{ now()->year }} {{ config('app.name') }}. Camera frames are processed on your device and never uploaded.</p>
            <a href="{{ route('credits') }}" class="font-medium text-stone-700 hover:text-stone-900">Credits &amp; licenses</a>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>

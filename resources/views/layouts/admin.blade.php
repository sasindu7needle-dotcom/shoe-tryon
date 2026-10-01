<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Admin') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    @stack('head')
</head>
<body class="min-h-screen bg-stone-100 font-sans text-stone-900 antialiased">
    <header class="border-b border-stone-200 bg-white">
        <nav class="mx-auto flex h-14 max-w-7xl items-center gap-6 px-4 sm:px-6">
            <a href="{{ route('admin.dashboard') }}" class="font-semibold">{{ config('app.name') }} <span class="text-stone-400">admin</span></a>
            <div class="flex flex-1 items-center gap-4 text-sm font-medium text-stone-600">
                <a href="{{ route('admin.dashboard') }}" @class(['hover:text-stone-900', 'text-stone-900' => request()->routeIs('admin.dashboard')])>Dashboard</a>
                <a href="{{ route('admin.products.index') }}" @class(['hover:text-stone-900', 'text-stone-900' => request()->routeIs('admin.products.*')])>Products</a>
                <a href="{{ route('home') }}" class="hover:text-stone-900" target="_blank">View store ↗</a>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="text-sm font-medium text-stone-600 hover:text-stone-900">Sign out</button>
            </form>
        </nav>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        @if (session('status'))
            <p class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</p>
        @endif
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>

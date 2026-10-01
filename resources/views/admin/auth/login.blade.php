<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sign in · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-stone-100 p-4 font-sans text-stone-900 antialiased">
    <form method="POST" action="{{ route('admin.login') }}" class="w-full max-w-sm rounded-3xl border border-stone-200 bg-white p-8 shadow-sm">
        @csrf
        <h1 class="text-xl font-semibold">Sign in to {{ config('app.name') }}</h1>
        <p class="mt-1 text-sm text-stone-500">Admin access only.</p>

        <label class="mt-6 block text-sm font-medium" for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
               class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 focus:border-stone-900 focus:outline-none">
        @error('email')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <label class="mt-4 block text-sm font-medium" for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password"
               class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 focus:border-stone-900 focus:outline-none">

        <label class="mt-4 flex items-center gap-2 text-sm text-stone-600">
            <input type="checkbox" name="remember" class="rounded border-stone-300"> Keep me signed in
        </label>

        <button class="mt-6 w-full rounded-full bg-stone-900 py-2.5 font-semibold text-white hover:bg-stone-700">Sign in</button>
    </form>
</body>
</html>

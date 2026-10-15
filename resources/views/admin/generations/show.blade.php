@extends('layouts.admin')
@section('title', $generation->draft['name'])
@push('head')
    @vite(['resources/js/admin/generations.js', 'resources/js/product-viewer.js'])
@endpush
@section('content')
    <a href="{{ route('admin.generations.index') }}" class="text-sm text-stone-500 hover:underline">All generations</a>
    <h1 class="mt-2 text-2xl font-semibold">{{ $generation->draft['name'] }}</h1>
    <div data-generation-status data-status="{{ $generation->status }}" data-url="{{ route('admin.generations.status', $generation) }}" class="mt-5 max-w-3xl space-y-3 rounded-2xl border border-stone-200 bg-white p-6">
        <p role="status" class="font-semibold">Status: <span data-status-label>{{ ucfirst($generation->status) }}</span><span data-progress>{{ $generation->provider_progress !== null ? ' ('.$generation->provider_progress.'%)' : '' }}</span></p>
        <p data-error class="text-sm text-red-700">{{ $generation->error }}</p>
        <p class="text-sm text-stone-600">{{ $generation->options['model'] }} · {{ number_format($generation->options['face_limit']) }} maximum faces · PBR textures · {{ count($generation->source_paths) === 1 ? 'Single photo' : 'Multi-view photos' }}</p>
        <p class="text-xs text-stone-500">You can leave this page and reopen it from Model generations. Source photos remain private.</p>
        @if ($generation->provider_task_id)<p class="text-xs text-stone-500">Provider task: {{ $generation->provider_task_id }}</p>@endif
        @if ($errors->any())<p role="alert" class="text-sm text-red-800">{{ $errors->first() }}</p>@endif
        @if ($generation->status === 'draft' || ($generation->status === 'failed' && !$generation->provider_task_id))
            <form data-generation-form method="POST" action="{{ route('admin.generations.start', $generation) }}" class="space-y-3">
                @csrf
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="paid_confirmation" value="1" required class="mt-1">I understand this action uses paid Tripo API credits. Pricing depends on the selected model and options.</label>
                <button type="submit" class="rounded-full bg-orange-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">Generate model{{ $generation->status === 'failed' ? ' (paid retry)' : '' }}</button>
            </form>
        @endif
        @if (in_array($generation->status, ['failed', 'ambiguous']))
            <form data-generation-form method="POST" action="{{ route('admin.generations.resume', $generation) }}" class="space-y-3">
                @csrf
                @if (!$generation->provider_task_id)
                    <p class="text-sm">Check your Tripo console for an existing task matching this submission. Supply its verified task ID; this action only reads the task and does not spend generation credits.</p>
                    <input name="provider_task_id" required placeholder="Verified provider task ID" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                @endif
                <button class="rounded-full border border-stone-300 px-4 py-2 text-sm font-semibold">Resume existing task (no new generation)</button>
            </form>
        @endif
        @if ($generation->status === 'failed' && $generation->provider_task_id)
            <form method="POST" action="{{ route('admin.generations.retry', $generation) }}">@csrf<button class="text-sm font-semibold underline">Create a new draft for a paid retry</button></form>
        @endif
    </div>
    @if ($generation->status === 'ready')
        <div class="mt-6 max-w-3xl space-y-4">
            <p class="text-sm text-stone-600">Review geometry, texture and accuracy from all angles. Automated checks cannot reliably distinguish one shoe from a pair or background objects.</p>
            <model-viewer src="{{ route('admin.generations.asset', $generation) }}" camera-controls class="h-96 w-full rounded-2xl bg-white" alt="Generated shoe model"></model-viewer>
            <a href="{{ route('admin.generations.asset', $generation) }}" class="text-sm font-semibold underline">Download GLB for admin review</a>
            @if ($generation->product)
                <p><a href="{{ route('admin.products.calibration.edit', $generation->product) }}" class="font-semibold underline">Continue calibration</a> · <a href="{{ route('admin.products.edit', $generation->product) }}" class="underline">Product and poster</a></p>
            @else
                <form data-generation-form method="POST" action="{{ route('admin.generations.prepare', $generation) }}" class="space-y-3">
                    @csrf
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="visual_review" value="1" required class="mt-1">I reviewed the generated asset and confirmed that it contains one shoe, without background geometry.</label>
                    <button class="rounded-full bg-stone-900 px-5 py-2 text-sm font-semibold text-white">Create unpublished product and calibrate</button>
                </form>
            @endif
        </div>
    @endif
@endsection

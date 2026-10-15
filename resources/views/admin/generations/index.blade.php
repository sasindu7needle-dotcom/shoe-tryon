@extends('layouts.admin')
@section('title', 'Model generations')
@section('content')
    <div class="flex items-center justify-between gap-4"><h1 class="text-2xl font-semibold">Model generations</h1><a href="{{ route('admin.generations.create') }}" class="rounded-full bg-stone-900 px-4 py-2 text-sm font-semibold text-white">Generate from photos</a></div>
    <div class="mt-6 overflow-x-auto rounded-xl border border-stone-200 bg-white">
        <table class="w-full text-left text-sm"><thead class="bg-stone-50"><tr><th class="p-4">Draft</th><th class="p-4">Status</th><th class="p-4">Created</th><th class="p-4">Review</th></tr></thead><tbody>
            @forelse ($generations as $generation)
                <tr class="border-t border-stone-100"><td class="p-4">{{ $generation->draft['name'] }}</td><td class="p-4">{{ ucfirst($generation->status) }}</td><td class="p-4">{{ $generation->created_at->format('Y-m-d H:i') }}</td><td class="p-4"><a href="{{ route('admin.generations.show', $generation) }}" class="font-semibold underline">Open</a></td></tr>
            @empty
                <tr><td colspan="4" class="p-6 text-stone-500">No generation drafts yet.</td></tr>
            @endforelse
        </tbody></table>
    </div>
    <div class="mt-4">{{ $generations->links() }}</div>
@endsection

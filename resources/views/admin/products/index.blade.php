@extends('layouts.admin')

@section('title', 'Products')

@section('content')
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Products</h1>
        <a href="{{ route('admin.products.create') }}" class="rounded-full bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Add product</a>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-stone-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                <tr>
                    <th class="px-4 py-3">Product</th>
                    <th class="hidden px-4 py-3 sm:table-cell">Price</th>
                    <th class="hidden px-4 py-3 md:table-cell">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($products as $product)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="size-12 shrink-0 rounded-lg bg-stone-100">
                                    @if ($product->posterUrl())
                                        <img src="{{ $product->posterUrl() }}" alt="" class="h-full w-full object-contain p-1">
                                    @endif
                                </div>
                                <div>
                                    <p class="font-medium">{{ $product->name }}</p>
                                    <p class="text-xs text-stone-500">/{{ $product->slug }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="hidden px-4 py-3 tabular-nums sm:table-cell">{{ $product->formattedPrice() }}</td>
                        <td class="hidden px-4 py-3 md:table-cell">
                            <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-emerald-100 text-emerald-800' => $product->is_active, 'bg-stone-100 text-stone-600' => ! $product->is_active])>
                                {{ $product->is_active ? 'Published' : 'Draft' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-3 font-medium">
                                <a href="{{ route('admin.products.calibration.edit', $product) }}" class="text-orange-600 hover:underline">Fit</a>
                                <a href="{{ route('admin.products.edit', $product) }}" class="hover:underline">Edit</a>
                                <a href="{{ route('try-on.show', $product) }}" class="hover:underline" target="_blank">Try on ↗</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-stone-500">No products yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
@endsection

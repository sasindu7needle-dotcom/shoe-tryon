@extends('layouts.admin')

@section('title', 'Dashboard')

@php
    $percent = fn (?float $value) => $value === null ? '–' : number_format($value * 100).'%';
@endphp

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Try-on dashboard</h1>
            <p class="text-sm text-stone-500">Last {{ $rangeDays }} days. Events are anonymous.</p>
        </div>
        <a href="{{ route('admin.products.create') }}" class="rounded-full bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Add product</a>
    </div>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Try-on sessions', number_format($totals['sessions']), 'Shoppers who started the camera'],
            ['Feet detected', $percent($totals['detectionRate']), 'Sessions where tracking locked on'],
            ['Photos taken', number_format($totals['snapshots']), 'Snapshots saved or shared'],
            ['On mobile', $percent($totals['mobileShare']), 'Share of sessions on touch devices'],
        ] as [$label, $value, $help])
            <div class="rounded-2xl border border-stone-200 bg-white p-5">
                <dt class="text-sm text-stone-500">{{ $label }}</dt>
                <dd class="mt-1 text-3xl font-semibold tabular-nums">{{ $value }}</dd>
                <dd class="mt-1 text-xs text-stone-400">{{ $help }}</dd>
            </div>
        @endforeach
    </dl>

    <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-5">
        <h2 class="font-semibold">Sessions, last 14 days</h2>
        @php($peak = max(1, $days->max('sessions')))
        <div class="mt-4 flex h-40 items-end gap-2" role="img" aria-label="Daily try-on sessions">
            @foreach ($days as $day)
                <div class="flex flex-1 flex-col items-center gap-1">
                    <span class="text-xs tabular-nums text-stone-500">{{ $day['sessions'] ?: '' }}</span>
                    <div class="w-full rounded-t-md bg-orange-400" style="height: {{ max(2, round($day['sessions'] / $peak * 120)) }}px"></div>
                    <span class="text-[10px] text-stone-400">{{ $day['label'] }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl border border-stone-200 bg-white">
        <h2 class="p-5 font-semibold">By product</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-y border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-5 py-3">Product</th>
                        <th class="px-5 py-3 text-right">Sessions</th>
                        <th class="px-5 py-3 text-right">Feet detected</th>
                        <th class="px-5 py-3 text-right">Photos</th>
                        <th class="px-5 py-3 text-right">Colourway changes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($products as $row)
                        <tr>
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.products.edit', $row['product']) }}" class="font-medium hover:underline">{{ $row['product']->name }}</a>
                                @unless ($row['product']->is_active)
                                    <span class="ml-2 rounded bg-stone-100 px-1.5 py-0.5 text-xs text-stone-500">Draft</span>
                                @endunless
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ number_format($row['sessions']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ $percent($row['detectionRate']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ number_format($row['snapshots']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ number_format($row['variantChanges']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-8 text-center text-stone-500">No products yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

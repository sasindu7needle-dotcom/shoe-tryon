<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\TryOnEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const DAYS = 30;

    public function __invoke(): View
    {
        $since = now()->subDays(self::DAYS)->startOfDay();

        // Distinct sessions per product and event type: "did this visit reach the step?"
        $funnel = TryOnEvent::query()
            ->where('created_at', '>=', $since)
            ->select('product_id', 'type', DB::raw('count(distinct session_id) as sessions'), DB::raw('count(*) as events'))
            ->groupBy('product_id', 'type')
            ->get()
            ->groupBy('product_id');

        $products = Product::query()->orderBy('sort_order')->orderBy('name')->get()
            ->map(function (Product $product) use ($funnel) {
                $rows = $funnel->get($product->id, collect())->keyBy('type');
                $sessions = (int) ($rows['session_started']->sessions ?? 0);
                $detected = (int) ($rows['foot_detected']->sessions ?? 0);

                return [
                    'product' => $product,
                    'sessions' => $sessions,
                    'detected' => $detected,
                    'detectionRate' => $sessions > 0 ? $detected / $sessions : null,
                    'snapshots' => (int) ($rows['snapshot_taken']->events ?? 0),
                    'variantChanges' => (int) ($rows['variant_selected']->events ?? 0),
                ];
            })
            ->sortByDesc('sessions')
            ->values();

        $daily = TryOnEvent::query()
            ->where('type', 'session_started')
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->select(DB::raw('date(created_at) as day'), DB::raw('count(*) as sessions'))
            ->groupBy('day')
            ->pluck('sessions', 'day');

        $days = collect(range(13, 0))->map(function (int $daysAgo) use ($daily) {
            $day = Carbon::today()->subDays($daysAgo);

            return ['label' => $day->format('M j'), 'sessions' => (int) ($daily[$day->toDateString()] ?? 0)];
        });

        $devices = TryOnEvent::query()
            ->where('type', 'session_started')
            ->where('created_at', '>=', $since)
            ->select('device', DB::raw('count(*) as sessions'))
            ->groupBy('device')
            ->pluck('sessions', 'device');

        $totalSessions = $products->sum('sessions');

        return view('admin.dashboard', [
            'days' => $days,
            'products' => $products,
            'totals' => [
                'sessions' => $totalSessions,
                'detectionRate' => $totalSessions > 0 ? $products->sum('detected') / $totalSessions : null,
                'snapshots' => $products->sum('snapshots'),
                'mobileShare' => $totalSessions > 0 ? ($devices['mobile'] ?? 0) / $totalSessions : null,
            ],
            'rangeDays' => self::DAYS,
        ]);
    }
}

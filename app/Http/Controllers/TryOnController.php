<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TryOnController extends Controller
{
    public function show(Request $request, Product $product): View
    {
        abort_unless($product->is_active || $request->user()?->is_admin, 404);

        // The shoe being tried on leads the carousel, drafts included.
        $catalog = Product::listed()->get()
            ->reject(fn (Product $item) => $item->is($product))
            ->prepend($product);

        return view('try-on.show', [
            'product' => $product,
            'config' => [
                'appName' => config('app.name'),
                'productId' => $product->id,
                'products' => $catalog->map->toTryOnPayload()->values(),
                'tracker' => self::trackerConfig(),
                'eventsUrl' => route('api.try-on-events.store'),
                // ?debug=1 opens the tuning overlay. Admins can use it against
                // production to find the right settings on a real phone.
                'debugAvailable' => (bool) config('app.debug') || (bool) $request->user()?->is_admin,
            ],
        ]);
    }

    /**
     * Settings shared by the live try-on and the admin calibrator.
     *
     * @return array<string, mixed>
     */
    public static function trackerConfig(): array
    {
        $version = config('tryon.asset_version');
        $network = config('tryon.neural_network');

        return [
            'neuralNetworkUrl' => asset($network).'?v='.$version,
            // The file is served compressed; the uncompressed size drives the progress bar.
            'neuralNetworkBytes' => is_file(public_path($network)) ? filesize(public_path($network)) : null,
            'occluderUrl' => asset(config('tryon.occluder_model')).'?v='.$version,
            'threshold' => config('tryon.detection_threshold'),
            'maxFeet' => config('tryon.max_feet'),
            'cameraFov' => config('tryon.camera_fov'),
            'maxDevicePixelRatio' => config('tryon.max_device_pixel_ratio'),
            'networks' => self::availableNetworks(),
        ];
    }

    /**
     * Foot networks present on disk, for the shopper and debug switchers. Only
     * NN_FOOT_31 and NN_BAREFOOT_3 (the default) ship; `npm run models:foot-nets`
     * fetches the rest of the upstream set for comparison.
     *
     * @return array<int, array{name: string, url: string, bytes: int}>
     */
    private static function availableNetworks(): array
    {
        $version = config('tryon.asset_version');

        return collect(glob(public_path('vendor/webar-rocks/neural-nets/NN_*.json')) ?: [])
            ->map(fn (string $path) => [
                'name' => pathinfo($path, PATHINFO_FILENAME),
                'url' => asset('vendor/webar-rocks/neural-nets/'.basename($path)).'?v='.$version,
                'bytes' => filesize($path),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }
}

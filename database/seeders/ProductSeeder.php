<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Sample catalogue. Models are produced by `npm run models:samples` and are
 * oriented toes +X, up +Y, in metres, so they share one calibration.
 */
class ProductSeeder extends Seeder
{
    /**
     * Seed the sample products. Safe to run repeatedly.
     */
    public function run(): void
    {
        $calibration = ['forwardAxis' => '+x'] + Product::DEFAULT_CALIBRATION;
        $sizes = ['US 6', 'US 7', 'US 8', 'US 9', 'US 10', 'US 11', 'US 12'];

        $products = [
            [
                'slug' => 'street-runner',
                'name' => 'Street Runner',
                'brand' => 'Sample Co.',
                'description' => 'A breathable knit everyday runner with a cushioned sole. Available in three colourways.',
                'price_cents' => 8900,
                'default_variant' => 'Street',
                'attribution' => '“Materials Variants Shoe” © 2021 Shopify, licensed under CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/). Re-oriented and textures resized for the web.',
            ],
            [
                'slug' => 'canvas-sneaker',
                'name' => 'Canvas Sneaker',
                'brand' => 'Sample Co.',
                'description' => 'The classic lace-up canvas low-top in black, with a vulcanised rubber sole and white foxing stripe.',
                'price_cents' => 6500,
                'attribution' => '3D model from WebAR.rocks.hand, © 2020 WebAR.rocks, MIT License. Re-oriented and scaled for the web.',
            ],
            [
                'slug' => 'ballet-flat',
                'name' => 'Ballet Flat',
                'brand' => 'Sample Co.',
                'description' => 'A soft, slip-on ballet flat for all-day comfort.',
                'price_cents' => 5400,
                'sizes' => ['EU 36', 'EU 37', 'EU 38', 'EU 39', 'EU 40', 'EU 41'],
                'attribution' => '3D model from WebAR.rocks.hand, © 2020 WebAR.rocks, MIT License. Re-oriented and scaled for the web.',
            ],
            [
                'slug' => 'rain-boot',
                'name' => 'Rain Boot',
                'brand' => 'Sample Co.',
                'description' => 'A waterproof rubber boot for wet days, shown clean or after a muddy walk.',
                'price_cents' => 7200,
                'default_variant' => 'Clean',
                'attribution' => '“Rubber Boots” by Poly Haven, CC0 (public domain).',
            ],
        ];

        foreach ($products as $index => $product) {
            Product::updateOrCreate(['slug' => $product['slug']], $product + [
                'currency' => 'USD',
                'sizes' => $sizes,
                'model_path' => "models/samples/{$product['slug']}.glb",
                'poster_path' => "models/samples/{$product['slug']}.webp",
                'calibration' => $calibration,
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
            ]);
        }
    }
}

<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'brand' => fake()->company(),
            'description' => fake()->sentence(12),
            'price_cents' => fake()->numberBetween(4000, 18000),
            'currency' => 'USD',
            'sizes' => ['US 7', 'US 8', 'US 9', 'US 10'],
            'model_path' => 'models/samples/street-runner.glb',
            'calibration' => Product::DEFAULT_CALIBRATION,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the product is hidden from the storefront.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

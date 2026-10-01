<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalibrationTest extends TestCase
{
    use RefreshDatabase;

    private function calibration(array $overrides = []): array
    {
        return array_replace([
            'forwardAxis' => '-z',
            'upAxis' => '+y',
            'mirror' => true,
            'scale' => 1.05,
            'offsetCm' => [0.5, -1.25, 0],
            'rotationDeg' => [2, 0, -3.5],
        ], $overrides);
    }

    public function test_the_fit_editor_ships_the_model_and_the_reference_shoe(): void
    {
        $product = Product::factory()->create();

        $response = $this->actingAs($this->admin())->get(route('admin.products.calibration.edit', $product));

        $response->assertOk()->assertSee('Detect orientation');
        preg_match('#<script type="application/json" id="calibrator-config">(.*?)</script>#s', $response->getContent(), $matches);
        $config = json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString($product->model_path, $config['product']['modelUrl']);
        $this->assertStringContainsString('canvas-sneaker.glb', $config['reference']['modelUrl']);
        $this->assertFileExists(public_path('models/samples/canvas-sneaker.glb'));
        $this->assertSame(route('admin.products.calibration.update', $product), $config['saveUrl']);
    }

    public function test_a_saved_fit_is_rounded_and_returned(): void
    {
        $product = Product::factory()->create();

        $response = $this->actingAs($this->admin())
            ->putJson(route('admin.products.calibration.update', $product), $this->calibration([
                'scale' => 1.0512345,
                'offsetCm' => [0.512345, -1.25, 0],
            ]));

        $response->assertOk();
        $product->refresh();

        $this->assertSame('-z', $product->calibration['forwardAxis']);
        $this->assertTrue($product->calibration['mirror']);
        $this->assertSame(1.0512, $product->calibration['scale']);
        $this->assertEquals([0.51, -1.25, 0], $product->calibration['offsetCm']);
        $this->assertEquals($product->resolvedCalibration(), $response->json('calibration'));
    }

    public function test_impossible_fits_are_rejected(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->admin());

        // Up must be perpendicular to forward.
        $this->putJson(route('admin.products.calibration.update', $product), $this->calibration(['forwardAxis' => '+y', 'upAxis' => '-y']))
            ->assertJsonValidationErrors('upAxis');

        $this->putJson(route('admin.products.calibration.update', $product), $this->calibration(['scale' => 12]))
            ->assertJsonValidationErrors('scale');

        $this->putJson(route('admin.products.calibration.update', $product), $this->calibration(['offsetCm' => [99, 0, 0]]))
            ->assertJsonValidationErrors('offsetCm.0');

        $this->putJson(route('admin.products.calibration.update', $product), $this->calibration(['rotationDeg' => [0, 0]]))
            ->assertJsonValidationErrors('rotationDeg');

        $this->assertEquals(Product::DEFAULT_CALIBRATION, $product->refresh()->calibration, 'the stored fit is untouched');
    }
}

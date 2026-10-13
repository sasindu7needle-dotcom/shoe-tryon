<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TryOnPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_try_on_page_ships_the_tracker_and_catalogue_config(): void
    {
        $product = Product::factory()->create(['default_variant' => 'street']);
        $other = Product::factory()->create();

        $response = $this->get(route('try-on.show', $product));
        $response->assertOk();

        $config = $this->configFrom($response->getContent());

        $this->assertSame($product->id, $config['productId']);
        $this->assertContains($product->id, array_column($config['products'], 'id'));
        $this->assertContains($other->id, array_column($config['products'], 'id'), 'other shoes are switchable');
        $this->assertSame('street', $config['products'][0]['defaultVariant']);
        // assertEquals, not assertSame: JSON turns 0.0 into 0.
        $this->assertEquals($product->resolvedCalibration(), $config['products'][0]['calibration']);
        $this->assertStringContainsString('NN_FOOT_31.json', $config['tracker']['neuralNetworkUrl']);
        $this->assertSame(2, $config['tracker']['maxFeet']);
        $this->assertSame(route('api.try-on-events.store'), $config['eventsUrl']);
    }

    public function test_the_page_loads_the_tracking_libraries_locally(): void
    {
        $product = Product::factory()->create();

        $response = $this->get(route('try-on.show', $product));

        foreach ([
            'vendor/webar-rocks/WebARRocksHand.js',
            'vendor/webar-rocks/helpers/HandTrackerThreeHelper.js',
            'vendor/three-r131/three.min.js',
        ] as $script) {
            $response->assertSee($script, false);
            $this->assertFileExists(public_path($script), 'the vendored library must ship with the app');
        }
    }

    public function test_the_tracking_model_and_occluder_exist_and_are_sized_for_the_progress_bar(): void
    {
        $product = Product::factory()->create();
        $config = $this->configFrom($this->get(route('try-on.show', $product))->getContent());

        $this->assertFileExists(public_path(config('tryon.neural_network')));
        $this->assertFileExists(public_path(config('tryon.occluder_model')));
        $this->assertSame(filesize(public_path(config('tryon.neural_network'))), $config['tracker']['neuralNetworkBytes']);
    }

    public function test_a_hidden_product_is_still_offered_when_deep_linked_by_an_admin(): void
    {
        $draft = Product::factory()->inactive()->create();
        Product::factory()->create();

        $config = $this->configFrom($this->actingAs($this->admin())->get(route('try-on.show', $draft))->getContent());

        $this->assertSame($draft->id, $config['products'][0]['id'], 'the requested shoe is always first');
    }

    public function test_the_tuning_overlay_is_offered_to_admins_and_debug_environments_only(): void
    {
        $product = Product::factory()->create();
        config(['app.debug' => false]);

        $this->assertFalse($this->configFrom($this->get(route('try-on.show', $product))->getContent())['debugAvailable']);

        $this->assertTrue(
            $this->configFrom($this->actingAs($this->admin())->get(route('try-on.show', $product))->getContent())['debugAvailable'],
            'admins can tune tracking against production',
        );

        config(['app.debug' => true]);
        auth()->logout();
        $this->assertTrue($this->configFrom($this->get(route('try-on.show', $product))->getContent())['debugAvailable']);
    }

    public function test_the_overlay_can_switch_between_the_installed_foot_networks(): void
    {
        $product = Product::factory()->create();

        $networks = $this->configFrom($this->get(route('try-on.show', $product))->getContent())['tracker']['networks'];

        $this->assertContains('NN_FOOT_31', array_column($networks, 'name'));
        foreach ($networks as $network) {
            $this->assertStringContainsString('/vendor/webar-rocks/neural-nets/', $network['url']);
            $this->assertGreaterThan(0, $network['bytes']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function configFrom(string $html): array
    {
        preg_match('#<script type="application/json" id="tryon-config">(.*?)</script>#s', $html, $matches);
        $this->assertNotEmpty($matches, 'the page must embed its configuration');

        return json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_lists_published_products_in_order(): void
    {
        $second = Product::factory()->create(['name' => 'Runner', 'sort_order' => 20]);
        $first = Product::factory()->create(['name' => 'Boot', 'sort_order' => 10]);
        $draft = Product::factory()->inactive()->create(['name' => 'Secret Prototype']);

        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSeeInOrder([$first->name, $second->name])
            ->assertDontSee($draft->name);
    }

    public function test_a_product_page_shows_the_model_and_a_try_on_link(): void
    {
        $product = Product::factory()->create(['attribution' => 'CC BY 4.0, Jane Doe']);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee($product->modelUrl(), false)
            ->assertSee(route('try-on.show', $product), false)
            ->assertSee('CC BY 4.0, Jane Doe');
    }

    public function test_drafts_are_hidden_from_shoppers_but_visible_to_admins(): void
    {
        $draft = Product::factory()->inactive()->create();

        $this->get(route('products.show', $draft))->assertNotFound();
        $this->get(route('try-on.show', $draft))->assertNotFound();

        $this->actingAs($this->admin());
        $this->get(route('products.show', $draft))->assertOk()->assertSee('Draft');
        $this->get(route('try-on.show', $draft))->assertOk();
    }

    public function test_the_credits_page_lists_model_attributions(): void
    {
        Product::factory()->create(['attribution' => 'Poly Haven, CC0']);

        $this->get(route('credits'))
            ->assertOk()
            ->assertSee('WebAR.rocks.hand')
            ->assertSee('Poly Haven, CC0');
    }

    public function test_pages_allow_the_camera_and_refuse_framing_by_other_sites(): void
    {
        $product = Product::factory()->create();

        $this->get(route('try-on.show', $product))
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_configured_store_origins_may_embed_the_try_on_page(): void
    {
        config(['tryon.embed_origins' => ['https://shop.example']]);
        $product = Product::factory()->create();

        $this->get(route('try-on.show', $product))
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://shop.example")
            ->assertHeader('Permissions-Policy', 'camera=(self "https://shop.example"), microphone=(), geolocation=()');

        // Other pages stay unframeable.
        $this->get(route('home'))->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }
}

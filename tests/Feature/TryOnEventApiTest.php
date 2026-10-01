<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TryOnEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TryOnEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_anonymous_try_on_event_is_recorded(): void
    {
        $product = Product::factory()->create();
        $session = (string) Str::uuid();

        $this->postJson(route('api.try-on-events.store'), [
            'product_id' => $product->id,
            'type' => 'foot_detected',
            'session_id' => $session,
            'device' => 'mobile',
        ])->assertNoContent();

        $event = TryOnEvent::sole();
        $this->assertSame('foot_detected', $event->type);
        $this->assertSame($session, $event->session_id);
        $this->assertTrue($product->is($event->product));
        $this->assertNotNull($event->created_at);
    }

    public function test_unknown_event_types_products_and_sessions_are_rejected(): void
    {
        $this->postJson(route('api.try-on-events.store'), [
            'type' => 'bought_everything',
            'session_id' => 'not-a-uuid',
            'product_id' => 9999,
            'device' => 'fridge',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'session_id', 'product_id', 'device']);

        $this->assertSame(0, TryOnEvent::count());
    }

    public function test_the_public_catalogue_api_returns_try_on_ready_products(): void
    {
        $product = Product::factory()->create();
        Product::factory()->inactive()->create();

        $response = $this->getJson(route('api.products.index'));

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', $product->slug);
        $response->assertJsonPath('data.0.tryOnUrl', route('try-on.show', $product));
        $this->assertEquals($product->resolvedCalibration(), $response->json('data.0.calibration'));
    }

    public function test_event_submissions_are_rate_limited(): void
    {
        $payload = ['type' => 'session_started', 'session_id' => (string) Str::uuid(), 'device' => 'desktop'];

        for ($i = 0; $i < 60; $i++) {
            $this->postJson(route('api.try-on-events.store'), $payload)->assertNoContent();
        }

        $this->postJson(route('api.try-on-events.store'), $payload)->assertStatus(429);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_is_closed_to_guests_and_to_ordinary_users(): void
    {
        $product = Product::factory()->create();

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.products.index'))->assertRedirect(route('admin.login'));

        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('admin.products.calibration.edit', $product))->assertForbidden();
    }

    public function test_signing_in_requires_an_admin_account(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'password' => 'correct-horse-battery']);

        $this->post(route('admin.login'), ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $user->forceFill(['is_admin' => true])->save();
        $this->post(route('admin.login'), ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_product_is_created_from_an_uploaded_model_and_starts_as_a_draft(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        $response = $this->post(route('admin.products.store'), [
            'name' => 'Trail Runner X',
            'price' => '129.95',
            'currency' => 'usd',
            'sizes' => 'US 8, US 9 , US 10',
            'model' => $this->fakeGlb(),
            'attribution' => 'Modelled in-house',
            'is_active' => '0',
        ]);

        $product = Product::sole();
        $response->assertRedirect(route('admin.products.calibration.edit', $product));

        $this->assertSame('trail-runner-x', $product->slug);
        $this->assertSame(12995, $product->price_cents);
        $this->assertSame('USD', $product->currency);
        $this->assertSame(['US 8', 'US 9', 'US 10'], $product->sizes);
        $this->assertFalse($product->is_active);
        $this->assertEquals(Product::DEFAULT_CALIBRATION, $product->calibration);
        Storage::disk('public')->assertExists(Product::uploadedDiskPath($product->model_path));
        $this->assertStringEndsWith('.glb', $product->model_path);
    }

    public function test_files_that_are_not_binary_gltf_are_refused(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        $this->post(route('admin.products.store'), [
            'name' => 'Not A Shoe',
            'price' => '10',
            'currency' => 'USD',
            'model' => UploadedFile::fake()->createWithContent('shoe.glb', 'This is not a model at all'),
        ])->assertSessionHasErrors('model');

        $this->post(route('admin.products.store'), [
            'name' => 'Not A Shoe',
            'price' => '10',
            'currency' => 'USD',
            'model' => UploadedFile::fake()->create('shoe.zip', 10),
        ])->assertSessionHasErrors('model');

        $this->assertSame(0, Product::count());
    }

    public function test_replacing_a_model_deletes_the_previous_upload(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        $this->post(route('admin.products.store'), [
            'name' => 'Trail Runner', 'price' => '99', 'currency' => 'USD', 'model' => $this->fakeGlb(),
        ]);
        $product = Product::sole();
        $firstUpload = Product::uploadedDiskPath($product->model_path);

        $this->put(route('admin.products.update', $product), [
            'name' => 'Trail Runner', 'price' => '99', 'currency' => 'USD',
            'model' => $this->fakeGlb('replacement.glb'), 'is_active' => '1',
        ])->assertRedirect(route('admin.products.edit', $product));

        $product->refresh();
        Storage::disk('public')->assertMissing($firstUpload);
        Storage::disk('public')->assertExists(Product::uploadedDiskPath($product->model_path));
        $this->assertTrue($product->is_active);
    }

    public function test_deleting_a_product_removes_its_uploaded_files(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        $this->post(route('admin.products.store'), [
            'name' => 'Trail Runner', 'price' => '99', 'currency' => 'USD',
            'model' => $this->fakeGlb(), 'poster' => UploadedFile::fake()->image('poster.webp'),
        ]);
        $product = Product::sole();
        $model = Product::uploadedDiskPath($product->model_path);
        $poster = Product::uploadedDiskPath($product->poster_path);

        $this->delete(route('admin.products.destroy', $product))->assertRedirect(route('admin.products.index'));

        $this->assertSame(0, Product::count());
        Storage::disk('public')->assertMissing($model);
        Storage::disk('public')->assertMissing($poster);
    }

    public function test_slugs_stay_unique(): void
    {
        $this->actingAs($this->admin());
        Product::factory()->create(['slug' => 'trail-runner']);

        $this->post(route('admin.products.store'), [
            'name' => 'Trail Runner', 'price' => '99', 'currency' => 'USD', 'model' => $this->fakeGlb(),
        ])->assertSessionHasErrors('slug');
    }

    public function test_a_poster_captured_from_the_3d_view_replaces_the_previous_one(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());
        $product = Product::factory()->create(['poster_path' => Product::UPLOAD_PREFIX.'products/posters/old.webp']);
        Storage::disk('public')->put('products/posters/old.webp', 'old');

        $response = $this->post(route('admin.products.poster.store', $product), [
            'poster' => UploadedFile::fake()->image('poster.webp', 600, 600),
        ]);

        $response->assertOk()->assertJsonStructure(['posterUrl']);
        Storage::disk('public')->assertMissing('products/posters/old.webp');
        Storage::disk('public')->assertExists(Product::uploadedDiskPath($product->refresh()->poster_path));
    }

    public function test_the_dashboard_reports_the_try_on_funnel(): void
    {
        $product = Product::factory()->create(['name' => 'Trail Runner']);
        $sessions = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];
        foreach ($sessions as $session) {
            $product->tryOnEvents()->create(['type' => 'session_started', 'session_id' => $session, 'device' => 'mobile']);
        }
        $product->tryOnEvents()->create(['type' => 'foot_detected', 'session_id' => $sessions[0], 'device' => 'mobile']);
        $product->tryOnEvents()->create(['type' => 'snapshot_taken', 'session_id' => $sessions[0], 'device' => 'mobile']);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Trail Runner')
            ->assertSee('50%'); // one of two sessions reached tracking
    }
}

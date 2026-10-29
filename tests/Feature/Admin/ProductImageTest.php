<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gambar barang: unggah saat membuat, ganti, hapus, dan ikut terbuang
 * bersama barangnya.
 */
class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'single',
            'sku' => 'IMG-1',
            'name' => 'Kaos Polos',
            'unit' => 'pcs',
            'min_stock' => 0,
            'is_active' => 1,
        ], $overrides);
    }

    public function test_an_image_is_stored_when_the_product_is_created(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.products.store'), $this->payload([
                'image' => UploadedFile::fake()->image('kaos.jpg', 600, 600),
            ]))
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'IMG-1')->firstOrFail();

        $this->assertNotNull($product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
        $this->assertStringContainsString($product->image_path, $product->image_url);
    }

    public function test_a_product_without_image_has_no_url(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload());

        $this->assertNull(Product::where('sku', 'IMG-1')->firstOrFail()->image_url);
    }

    public function test_replacing_the_image_deletes_the_old_file(): void
    {
        $product = $this->productWithImage();
        $old = $product->image_path;

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->payload([
                'image' => UploadedFile::fake()->image('baru.png'),
            ]))
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertNotSame($old, $product->image_path);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_saving_without_a_new_file_keeps_the_image(): void
    {
        $product = $this->productWithImage();
        $old = $product->image_path;

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->payload(['name' => 'Kaos Baru']))
            ->assertSessionHasNoErrors();

        $this->assertSame($old, $product->refresh()->image_path);
        Storage::disk('public')->assertExists($old);
    }

    public function test_the_image_can_be_removed(): void
    {
        $product = $this->productWithImage();
        $old = $product->image_path;

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->payload(['remove_image' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertNull($product->refresh()->image_path);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_non_image_files_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.products.store'), $this->payload([
                'image' => UploadedFile::fake()->create('daftar.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('products', ['sku' => 'IMG-1']);
    }

    public function test_oversized_images_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.products.store'), $this->payload([
                'image' => UploadedFile::fake()->image('besar.jpg')->size(5000),
            ]))
            ->assertSessionHasErrors('image');
    }

    public function test_deleting_the_product_deletes_its_image(): void
    {
        $product = $this->productWithImage();
        $path = $product->image_path;

        $this->actingAs($this->admin)->delete(route('admin.products.destroy', $product));

        $this->assertModelMissing($product);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_list_and_detail_pages_show_the_image(): void
    {
        $product = $this->productWithImage();

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()->assertSee($product->image_url, false);

        $this->actingAs($this->admin)->get(route('admin.products.show', $product))
            ->assertOk()->assertSee($product->image_url, false);

        $this->actingAs($this->admin)->get(route('admin.products.edit', $product))
            ->assertOk()->assertSee('multipart/form-data', false);
    }

    protected function productWithImage(): Product
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('kaos.jpg'),
        ]));

        return Product::where('sku', 'IMG-1')->firstOrFail();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Produk induk dan varian.
 *
 * Varian adalah baris products biasa: stok, SKU, dan riwayatnya tetap milik
 * varian. Induk hanya mengelompokkan dan membentuk nama varian.
 */
class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        Storage::fake('public');
    }

    protected function makeGroup(): ProductGroup
    {
        $this->actingAs($this->admin)->post(route('admin.product-groups.store'), [
            'name' => 'Kaos Polos',
            'category' => 'Atasan',
            'unit' => 'pcs',
            'option1_name' => 'Warna',
            'option2_name' => 'Ukuran',
            'is_active' => 1,
            'variants' => [
                ['option1' => 'Merah', 'option2' => 'L', 'sku' => 'kaos-merah-l', 'min_stock' => 2],
                ['option1' => 'Merah', 'option2' => 'M', 'sku' => 'KAOS-MERAH-M'],
                ['option1' => 'Biru', 'option2' => 'L', 'sku' => 'KAOS-BIRU-L', 'barcode' => '899001'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return ProductGroup::firstOrFail();
    }

    public function test_group_is_created_with_variants_as_skus(): void
    {
        $group = $this->makeGroup();

        $this->assertSame(3, $group->variants()->count());

        $variant = Product::where('sku', 'KAOS-MERAH-L')->firstOrFail();
        $this->assertSame('Kaos Polos - Merah / L', $variant->name);
        $this->assertSame('Atasan', $variant->category);
        $this->assertSame(Product::TYPE_SINGLE, $variant->type);
        $this->assertSame(0, $variant->stock);
        $this->assertSame(2, $variant->min_stock);
        $this->assertSame('Merah / L', $variant->variantLabel());
        $this->assertSame('899001', Product::where('sku', 'KAOS-BIRU-L')->value('barcode'));
    }

    public function test_duplicate_combinations_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.product-groups.store'), [
            'name' => 'Kaos',
            'unit' => 'pcs',
            'option1_name' => 'Warna',
            'variants' => [
                ['option1' => 'Merah', 'sku' => 'A1'],
                ['option1' => 'merah', 'sku' => 'A2'],
            ],
        ])->assertSessionHasErrors('variants.1.option1');

        $this->assertSame(0, ProductGroup::count());
        $this->assertSame(0, Product::count());
    }

    public function test_renaming_group_renames_all_variants(): void
    {
        $group = $this->makeGroup();

        $this->actingAs($this->admin)->put(route('admin.product-groups.update', $group), [
            'name' => 'Kaos Cotton',
            'category' => 'Kaos',
            'unit' => 'pcs',
            'option1_name' => 'Warna',
            'option2_name' => 'Ukuran',
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Kaos Cotton - Biru / L', Product::where('sku', 'KAOS-BIRU-L')->value('name'));
        $this->assertSame(3, Product::where('category', 'Kaos')->count());
    }

    public function test_existing_product_with_stock_can_be_attached_without_touching_stock(): void
    {
        $group = $this->makeGroup();
        $old = Product::create(['sku' => 'KAOS-HITAM-L', 'name' => 'Kaos hitam L', 'unit' => 'pcs']);
        $old->forceFill(['stock' => 12])->save();

        $this->actingAs($this->admin)->post(route('admin.product-groups.attach', $group), [
            'product_id' => $old->id,
            'option1' => 'Hitam',
            'option2' => 'L',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $old->refresh();
        $this->assertSame($group->id, $old->product_group_id);
        $this->assertSame('Kaos Polos - Hitam / L', $old->name);
        $this->assertSame(12, $old->stock);

        // Lepas lagi: barang tetap ada, stok tetap.
        $this->actingAs($this->admin)->delete(route('admin.product-groups.detach', [$group, $old]))->assertRedirect();
        $old->refresh();
        $this->assertNull($old->product_group_id);
        $this->assertSame(12, $old->stock);
    }

    public function test_add_variants_to_existing_group(): void
    {
        $group = $this->makeGroup();

        $this->actingAs($this->admin)->post(route('admin.product-groups.variants.store', $group), [
            'variants' => [['option1' => 'Hijau', 'option2' => 'S', 'sku' => 'KAOS-HIJAU-S']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Kaos Polos - Hijau / S', Product::where('sku', 'KAOS-HIJAU-S')->value('name'));

        $this->actingAs($this->admin)->post(route('admin.product-groups.variants.store', $group), [
            'variants' => [['option1' => 'Hijau', 'option2' => 'S', 'sku' => 'KAOS-HIJAU-S2']],
        ])->assertSessionHasErrors('variants');
    }

    public function test_editing_a_variant_keeps_name_from_group(): void
    {
        $this->makeGroup();
        $variant = Product::where('sku', 'KAOS-MERAH-M')->firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.products.update', $variant), [
            'sku' => 'KAOS-MERAH-M',
            'name' => 'Nama lain',
            'unit' => 'pcs',
            'min_stock' => 3,
            'is_active' => 1,
            'option1' => 'Merah',
            'option2' => 'XL',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $variant->refresh();
        $this->assertSame('Kaos Polos - Merah / XL', $variant->name);
        $this->assertSame(3, $variant->min_stock);

        // Kombinasi yang sudah dipakai varian lain ditolak.
        $this->actingAs($this->admin)->put(route('admin.products.update', $variant), [
            'sku' => 'KAOS-MERAH-M',
            'unit' => 'pcs',
            'min_stock' => 3,
            'option1' => 'Merah',
            'option2' => 'L',
        ])->assertSessionHasErrors('option1');
    }

    public function test_deleting_group_keeps_variants_as_plain_products(): void
    {
        $group = $this->makeGroup();

        $this->actingAs($this->admin)->delete(route('admin.product-groups.destroy', $group))->assertRedirect();

        $this->assertSame(0, ProductGroup::count());
        $this->assertSame(3, Product::whereNull('product_group_id')->count());
        $this->assertSame('Kaos Polos - Merah / L', Product::where('sku', 'KAOS-MERAH-L')->value('name'));
    }

    public function test_group_image_is_used_as_variant_fallback(): void
    {
        $group = $this->makeGroup();

        $this->actingAs($this->admin)->put(route('admin.product-groups.update', $group), [
            'name' => 'Kaos Polos',
            'category' => 'Atasan',
            'unit' => 'pcs',
            'option1_name' => 'Warna',
            'option2_name' => 'Ukuran',
            'image' => UploadedFile::fake()->image('kaos.jpg'),
        ])->assertSessionHasNoErrors();

        $group->refresh();
        $this->assertNotNull($group->image_path);
        Storage::disk('public')->assertExists($group->image_path);

        $variant = Product::where('sku', 'KAOS-MERAH-L')->firstOrFail();
        $this->assertSame($group->imageUrl(), $variant->displayImageUrl());
    }

    public function test_pages_render(): void
    {
        $group = $this->makeGroup();
        $variant = Product::where('sku', 'KAOS-MERAH-L')->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.product-groups.index'))->assertOk()->assertSee('Kaos Polos');
        $this->actingAs($this->admin)->get(route('admin.product-groups.create'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.product-groups.show', $group))->assertOk()->assertSee('KAOS-BIRU-L');
        $this->actingAs($this->admin)->get(route('admin.product-groups.edit', $group))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.products.index'))->assertOk()->assertSee('Varian');
        $this->actingAs($this->admin)->get(route('admin.products.show', $variant))->assertOk()->assertSee('Varian Produk');
        $this->actingAs($this->admin)->get(route('admin.products.edit', $variant))->assertOk()->assertSee('Varian dari');
    }

    public function test_import_groups_rows_by_parent_name(): void
    {
        $csv = "SKU,Nama Barang,Kategori,Stok,Nama Induk,Warna,Ukuran\n"
            ."GMS-HITAM-S,Gamis hitam S,Gamis,5,Gamis Syari,Hitam,S\n"
            ."GMS-HITAM-M,Gamis hitam M,Gamis,7,Gamis Syari,Hitam,M\n"
            ."FLT-1,Filter Oli,Filter,3,,,\n";

        $file = UploadedFile::fake()->createWithContent('barang.csv', $csv);

        $this->actingAs($this->admin)->post(route('admin.products.import.store'), ['file' => $file])
            ->assertRedirect()->assertSessionHasNoErrors();

        $group = ProductGroup::where('name', 'Gamis Syari')->firstOrFail();
        $this->assertSame('Warna', $group->option1_name);
        $this->assertSame('Ukuran', $group->option2_name);
        $this->assertSame(2, $group->variants()->count());

        $variant = Product::where('sku', 'GMS-HITAM-M')->firstOrFail();
        $this->assertSame('Gamis Syari - Hitam / M', $variant->name);
        $this->assertSame(7, $variant->stock);

        $plain = Product::where('sku', 'FLT-1')->firstOrFail();
        $this->assertNull($plain->product_group_id);
        $this->assertSame('Filter Oli', $plain->name);
    }
}

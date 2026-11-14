<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\QrCode;
use App\Support\QrLabelLayout;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cetak label QR berisi SKU barang dan SKU tiap varian.
 */
class ProductQrLabelTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ProductGroup $kaos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        Product::create(['sku' => 'TOPI-01', 'name' => 'Topi Polos', 'unit' => 'pcs']);

        $this->kaos = ProductGroup::create(['name' => 'Kaos Polos', 'unit' => 'pcs', 'option1_name' => 'Warna']);
        foreach (['Merah' => 'KAOS-MRH', 'Hitam' => 'KAOS-HTM'] as $color => $sku) {
            Product::create([
                'sku' => $sku, 'name' => 'Kaos Polos - '.$color, 'unit' => 'pcs',
                'product_group_id' => $this->kaos->id, 'option1' => $color,
            ]);
        }
    }

    public function test_page_preloads_all_variants_of_a_product(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.products.labels', ['group' => $this->kaos->id]))
            ->assertOk()
            ->assertSee('Cetak Label QR')
            ->assertViewHas('items', fn ($items) => $items->pluck('sku')->sort()->values()->all() === ['KAOS-HTM', 'KAOS-MRH']);
    }

    public function test_page_preloads_selected_ids_and_filtered_scope(): void
    {
        $topi = Product::where('sku', 'TOPI-01')->first();

        $this->actingAs($this->admin)
            ->get(route('admin.products.labels', ['ids' => [$topi->id]]))
            ->assertViewHas('items', fn ($items) => $items->pluck('sku')->all() === ['TOPI-01']);

        $this->actingAs($this->admin)
            ->get(route('admin.products.labels', ['scope' => 'filtered', 'search' => 'KAOS']))
            ->assertViewHas('items', fn ($items) => $items->count() === 2);
    }

    public function test_pdf_is_generated_for_chosen_items_and_copies(): void
    {
        $ids = Product::pluck('id', 'sku');

        $response = $this->actingAs($this->admin)->post(route('admin.products.labels.pdf'), [
            'items' => [
                ['id' => $ids['KAOS-MRH'], 'copies' => 3],
                ['id' => $ids['TOPI-01'], 'copies' => 1],
            ],
            'layout' => 'thermal-50x30',
            'show_name' => 1,
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        // Thermal: satu label per halaman → 4 halaman.
        $this->assertSame(4, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));
    }

    public function test_two_column_thermal_roll_puts_two_labels_per_page(): void
    {
        $layout = QrLabelLayout::get('thermal-33x15-2');
        $this->assertSame(2, $layout['per_page']);
        $this->assertEquals(68, $layout['page_width']);
        $this->assertEquals(15, $layout['page_height']);
        $this->assertEquals(69, QrLabelLayout::withGap($layout, 3)['page_width']);

        $three = QrLabelLayout::get('thermal-33x15-3');
        $this->assertSame(3, $three['per_page']);
        $this->assertEquals(103, $three['page_width']);

        $ids = Product::pluck('id', 'sku');

        $response = $this->actingAs($this->admin)->post(route('admin.products.labels.pdf'), [
            'items' => [
                ['id' => $ids['KAOS-MRH'], 'copies' => 3],
                ['id' => $ids['TOPI-01'], 'copies' => 2],
            ],
            'layout' => 'thermal-33x15-2',
            'gap' => 2.5,
        ]);

        $response->assertOk();
        // 5 label, dua per baris → 3 halaman (baris terakhir berisi satu label).
        $this->assertSame(3, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));
    }

    public function test_text_position_can_be_chosen(): void
    {
        $id = Product::where('sku', 'TOPI-01')->value('id');

        foreach (['below', 'right'] as $position) {
            $this->actingAs($this->admin)->post(route('admin.products.labels.pdf'), [
                'items' => [['id' => $id, 'copies' => 1]],
                'layout' => 'a4-40',
                'text_position' => $position,
            ])->assertOk();
        }

        $this->actingAs($this->admin)
            ->get(route('admin.products.labels'))
            ->assertSee('Di bawah QR')
            ->assertSee('Di kanan QR');

        $this->actingAs($this->admin)
            ->from(route('admin.products.labels'))
            ->post(route('admin.products.labels.pdf'), [
                'items' => [['id' => $id, 'copies' => 1]],
                'text_position' => 'atas',
            ])->assertSessionHasErrors('text_position');
    }

    public function test_label_count_is_capped(): void
    {
        $id = Product::where('sku', 'TOPI-01')->value('id');

        $items = array_fill(0, 3, ['id' => $id, 'copies' => 500]);

        $this->actingAs($this->admin)
            ->from(route('admin.products.labels'))
            ->post(route('admin.products.labels.pdf'), ['items' => $items])
            ->assertSessionHasErrors('items');
    }

    public function test_viewer_without_products_permission_is_refused(): void
    {
        $role = Role::create(['name' => 'Dashboard Saja', 'slug' => 'dashboard-saja']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('admin.products.labels'))->assertForbidden();
    }

    public function test_qr_svg_encodes_the_sku(): void
    {
        $svg = QrCode::svg('KAOS-MRH');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertSame(40, QrLabelLayout::get('a4-40')['per_page']);
        $this->assertSame(1, QrLabelLayout::get('thermal-40x30')['per_page']);
        $this->assertSame('a4-40', QrLabelLayout::get('unknown')['key']);
    }
}

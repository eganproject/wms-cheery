<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman Pesanan: tab statusnya hanya proses internal gudang.
 */
class OrderPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    protected function order(string $resi, array $attributes = []): ShipmentOrder
    {
        $import = ShipmentImport::first() ?? ShipmentImport::create([
            'filename' => 'ginee.xlsx', 'user_id' => $this->admin->id,
        ]);

        return ShipmentOrder::create(array_merge([
            'shipment_import_id' => $import->id,
            'tracking_number' => $resi,
            'order_number' => 'ORD-'.$resi,
            'marketplace' => 'Shopee',
            'store_name' => 'Cheery Official',
            'courier' => 'SPX',
        ], $attributes));
    }

    public function test_tabs_show_only_internal_process_statuses_with_counts(): void
    {
        $this->order('RESI1');
        $this->order('RESI2', ['cancelled_at' => now(), 'order_status' => 'Dibatalkan']);

        $html = $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertOk()->getContent();

        $start = strpos($html, 'data-order-tabs');
        $tabs = substr($html, $start, strpos($html, '</div>', $start) - $start);

        foreach (ShipmentOrder::STAGES as $label) {
            $this->assertStringContainsString($label, $tabs);
        }

        // Status dari marketplace tidak dijadikan tab.
        $this->assertStringNotContainsString('Belum Bayar', $tabs);
        $this->assertMatchesRegularExpression('/Dibatalkan <span[^>]*>1</', $tabs);
        $this->assertMatchesRegularExpression('/Belum QC <span[^>]*>1</', $tabs);
    }

    public function test_a_tab_filters_the_list_by_stage(): void
    {
        $product = Product::create(['sku' => 'SKU-1', 'name' => 'Tas', 'unit' => 'pcs', 'min_stock' => 0]);
        $shipped = $this->order('RESISHIP');
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Pembeli', 'tracking_number' => 'RESISHIP', 'status' => Outbound::STATUS_POSTED,
            'shipment_order_id' => $shipped->id,
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => 1, 'scanned_quantity' => 1]);
        $this->order('RESIWAIT');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['status' => ShipmentOrder::STAGE_SHIPPED]))
            ->assertOk()
            ->assertSee('RESISHIP')
            ->assertDontSee('RESIWAIT');
    }

    public function test_many_waybills_can_be_searched_at_once(): void
    {
        $this->order('RESIA');
        $this->order('RESIB');
        $this->order('RESIC');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['search' => "RESIA\nRESIC"]))
            ->assertOk()
            ->assertSee('RESIA')
            ->assertSee('RESIC')
            ->assertDontSee('RESIB');
    }

    public function test_the_page_is_in_the_menu_and_needs_import_access(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.orders.index'));

        $role = Role::create(['name' => 'Barang Saja', 'slug' => 'barang-saja']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view'])->pluck('id'));

        $this->actingAs(User::factory()->create(['role_id' => $role->id]))
            ->get(route('admin.orders.index'))
            ->assertForbidden();
    }
}

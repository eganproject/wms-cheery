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

        foreach (['Semua', 'Belum QC', 'Sudah QC', 'Dikirim'] as $label) {
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

    public function test_the_old_waybill_status_address_opens_orders(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.imports.status', ['stage' => ShipmentOrder::STAGE_HANDOVER, 'search' => 'RESI1']))
            ->assertRedirect(route('admin.orders.index', ['stage' => ShipmentOrder::STAGE_HANDOVER, 'search' => 'RESI1']));

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Status Resi');
    }

    public function test_a_duplicated_waybill_is_flagged_on_its_row(): void
    {
        $this->order('RESIOLD', ['order_number' => 'ORD-SAME']);
        $this->order('RESINEW', ['order_number' => 'ORD-SAME']);

        $this->actingAs($this->admin)->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('Resi Duplikat')
            ->assertSee('Resi duplikat: ')
            ->assertSee('>Duplikat</', false);
    }

    public function test_an_order_can_be_cancelled_from_its_row_and_comes_back_to_orders(): void
    {
        $order = $this->order('RESICANCEL');

        $this->actingAs($this->admin)->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee(route('admin.imports.orders.cancel', $order));

        $this->actingAs($this->admin)
            ->post(route('admin.imports.orders.cancel', $order), [
                'cancellation_reason' => 'Dibatalkan pembeli',
                'stage' => ShipmentOrder::STAGE_AWAITING_QC,
                'marketplace' => 'Shopee',
            ])
            ->assertRedirect(route('admin.orders.index', ['stage' => ShipmentOrder::STAGE_AWAITING_QC, 'marketplace' => 'Shopee']));

        $this->assertTrue($order->fresh()->isCancelled());
    }

    public function test_unknown_skus_on_imported_waybills_are_called_out(): void
    {
        $product = Product::create(['sku' => 'SKU-ADA', 'name' => 'Barang Ada', 'unit' => 'pcs']);

        $known = $this->order('RESIKNOWN');
        $known->items()->create(['sku' => 'SKU-ADA', 'quantity' => 1, 'product_id' => $product->id]);

        $unknown = $this->order('RESIUNKNOWN');
        $unknown->items()->create(['sku' => 'SKU-ASING', 'product_name' => 'Barang Asing', 'quantity' => 2, 'product_id' => null]);

        $response = $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertOk();

        $this->assertSame(['SKU-ASING'], $response->viewData('unknownSkus')->pluck('sku')->all());
        $response->assertSee('data-unknown-skus', false)
            ->assertSee('1 SKU di resi tidak dikenal sistem')
            ->assertSee('SKU tidak dikenal: ')
            ->assertSee('Tidak ada di sistem')
            ->assertSee(route('admin.products.create', ['sku' => 'SKU-ASING', 'name' => 'Barang Asing']));

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['unmatched' => 1]))
            ->assertSee('RESIUNKNOWN')
            ->assertDontSee('RESIKNOWN');

        $this->actingAs($this->admin)
            ->post(route('admin.imports.rematch'), ['return' => 'orders', 'unmatched' => 1])
            ->assertRedirect(route('admin.orders.index', ['unmatched' => 1]));
    }

    public function test_the_warning_is_hidden_when_every_sku_is_known(): void
    {
        $this->order('RESIEMPTY');

        $this->actingAs($this->admin)->get(route('admin.orders.index'))
            ->assertOk()
            ->assertDontSee('data-unknown-skus', false);
    }
}

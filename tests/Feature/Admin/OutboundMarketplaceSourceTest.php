<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Nama marketplace pada barang keluar tidak diketik di form lagi; satu-satunya
 * sumbernya adalah data import resi yang tertaut ke dokumen.
 */
class OutboundMarketplaceSourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    public function test_the_outbound_table_has_no_marketplace_column(): void
    {
        $this->assertFalse(Schema::hasColumn('outbounds', 'marketplace'));
    }

    public function test_the_form_no_longer_asks_for_a_marketplace(): void
    {
        $this->actingAs($this->admin)->get(route('admin.outbounds.create', ['type' => 'marketplace']))
            ->assertOk()
            ->assertDontSee('Pilih marketplace')
            ->assertDontSee('name="marketplace"', false);
    }

    public function test_marketplace_is_read_from_the_linked_import(): void
    {
        $order = $this->order('SPXID111', 'Tokopedia');
        $linked = $this->outbound('SPXID111', $order);
        $manual = $this->outbound('MANUAL1');

        $this->assertSame('Tokopedia', $linked->marketplace);
        $this->assertNull($manual->marketplace);

        $this->assertSame([$linked->id], Outbound::fromMarketplace('Tokopedia')->pluck('id')->all());
        $this->assertSame([], Outbound::fromMarketplace('Shopee')->pluck('id')->all());
    }

    public function test_changing_the_resi_drops_the_link_to_the_old_order(): void
    {
        $order = $this->order('SPXID111', 'Shopee');
        $outbound = $this->outbound('SPXID111', $order);
        $product = Product::create(['sku' => 'SKU-1', 'name' => 'Barang', 'unit' => 'pcs', 'min_stock' => 0]);

        $this->actingAs($this->admin)->put(route('admin.outbounds.update', $outbound), [
            'date' => now()->format('Y-m-d'),
            'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Andi',
            'tracking_number' => 'SPXID222',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertNull($outbound->refresh()->shipment_order_id);
        $this->assertNull($outbound->marketplace);
    }

    protected function order(string $resi, string $marketplace): ShipmentOrder
    {
        $import = ShipmentImport::first() ?? ShipmentImport::create([
            'filename' => 'ginee.xlsx', 'user_id' => $this->admin->id,
        ]);

        return ShipmentOrder::create([
            'shipment_import_id' => $import->id,
            'tracking_number' => $resi, 'order_number' => 'INV-'.$resi, 'marketplace' => $marketplace,
        ]);
    }

    protected function outbound(string $resi, ?ShipmentOrder $order = null): Outbound
    {
        return Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE, 'recipient' => 'Andi',
            'tracking_number' => $resi, 'shipment_order_id' => $order?->id,
            'status' => Outbound::STATUS_DRAFT,
        ]);
    }
}

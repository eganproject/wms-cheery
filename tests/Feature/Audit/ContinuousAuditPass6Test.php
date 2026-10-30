<?php

namespace Tests\Feature\Audit;

use App\Models\Product;
use App\Models\StockApiSyncRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit berkelanjutan, pass 6: catatan API stok saat SKU berganti.
 */
class ContinuousAuditPass6Test extends TestCase
{
    use RefreshDatabase;

    public function test_renaming_a_sku_retires_the_old_one_in_the_stock_api(): void
    {
        $product = Product::create(['sku' => 'FLT-LAMA', 'name' => 'Filter Oli', 'unit' => 'pcs']);
        $product->forceFill(['stock' => 7])->save();

        $product->update(['sku' => 'FLT-BARU']);

        $old = StockApiSyncRecord::where('sku', 'FLT-LAMA')->firstOrFail();
        $this->assertSame('deleted', $old->status);
        $this->assertNull($old->product_id);
        $this->assertEquals(0, $old->qty);

        $new = StockApiSyncRecord::where('sku', 'FLT-BARU')->firstOrFail();
        $this->assertSame('active', $new->status);
        $this->assertSame($product->id, $new->product_id);
        $this->assertEquals(7, $new->qty);
    }

    public function test_taking_over_a_sku_that_was_deleted_before_does_not_crash(): void
    {
        $gone = Product::create(['sku' => 'OLI-1L', 'name' => 'Oli Lama', 'unit' => 'pcs']);
        $gone->delete();

        $product = Product::create(['sku' => 'OLI-MTC', 'name' => 'Oli', 'unit' => 'pcs']);
        $product->update(['sku' => 'OLI-1L']);

        $this->assertSame(2, StockApiSyncRecord::count());
        $this->assertSame($product->id, StockApiSyncRecord::where('sku', 'OLI-1L')->value('product_id'));
        $this->assertSame('active', StockApiSyncRecord::where('sku', 'OLI-1L')->value('status'));
        $this->assertSame('deleted', StockApiSyncRecord::where('sku', 'OLI-MTC')->value('status'));

        // Penyimpanan berikutnya tetap satu catatan, tidak bercabang.
        $product->update(['name' => 'Oli Mesin']);
        $this->assertSame(1, StockApiSyncRecord::where('product_id', $product->id)->count());
    }

    public function test_a_new_product_without_an_explicit_flag_is_reported_active(): void
    {
        $product = Product::create(['sku' => 'VAR-MERAH-L', 'name' => 'Kaos Merah L', 'unit' => 'pcs']);

        $this->assertSame('active', StockApiSyncRecord::where('product_id', $product->id)->value('status'));
    }

    public function test_a_rejected_package_says_so_on_the_order_list(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = \App\Models\User::where('email', 'admin@cheery.test')->firstOrFail();
        $product = Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs']);

        $order = \App\Models\ShipmentImport::create(['filename' => 't.xlsx', 'source' => 'tiktok'])->orders()->create([
            'tracking_number' => 'SPXTOLAK', 'order_number' => 'INV9', 'marketplace' => 'TikTok Shop',
        ]);
        $order->items()->create(['sku' => 'FLT-OLI-STD', 'product_id' => $product->id, 'quantity' => 1]);

        $outbound = \App\Models\Outbound::create([
            'code' => \App\Models\Outbound::nextCode(), 'date' => now(), 'type' => \App\Models\Outbound::TYPE_MARKETPLACE,
            'recipient' => 'B', 'tracking_number' => 'SPXTOLAK', 'shipment_order_id' => $order->id,
            'status' => \App\Models\Outbound::STATUS_REJECTED, 'resi_verified_at' => now(),
            'rejected_at' => now(), 'rejection_reason' => 'Alamat salah',
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => 1, 'scanned_quantity' => 1]);

        $this->actingAs($admin)->get(route('admin.orders.index', ['range' => 'semua']))
            ->assertOk()
            ->assertSee('ditolak penyetuju: Alamat salah', false);
    }
}

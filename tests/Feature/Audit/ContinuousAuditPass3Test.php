<?php

namespace Tests\Feature\Audit;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit berkelanjutan, pass 3: SKU tertaut sendiri dan tanda retur di Pesanan.
 */
class ContinuousAuditPass3Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    public function test_qc_links_a_sku_registered_with_different_spacing_or_case(): void
    {
        // Data lama: barangnya terdaftar tanpa penautan otomatis.
        $product = Product::withoutEvents(fn () => Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs']));
        $this->giveStock($product, 5);

        $order = $this->makeOrder('SPXQC9', ' flt-oli-std', null);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXQC9'])
            ->assertOk();

        $this->assertSame($product->id, $order->items()->first()->product_id);
    }

    public function test_the_order_list_mentions_a_received_return(): void
    {
        $product = Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs']);
        $order = $this->makeOrder('SPXRT5', 'FLT-OLI-STD', $product->id);

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'B', 'tracking_number' => 'SPXRT5', 'shipment_order_id' => $order->id,
            'status' => Outbound::STATUS_POSTED, 'posted_at' => now(), 'resi_verified_at' => now(),
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => 1, 'scanned_quantity' => 1]);

        ReturnReceipt::create([
            'code' => 'RET-TEST-0001', 'date' => now(), 'type' => ReturnReceipt::TYPE_MARKETPLACE,
            'sender' => 'B', 'tracking_number' => 'SPXRT5', 'shipment_order_id' => $order->id,
            'status' => ReturnReceipt::STATUS_POSTED, 'posted_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['range' => 'semua']))
            ->assertOk()
            ->assertSee('diretur (RET-TEST-0001)');
    }

    public function test_bulk_min_stock_reaches_the_stock_api(): void
    {
        $product = Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs', 'min_stock' => 2]);

        $this->actingAs($this->admin)->patch(route('admin.products.bulk.min-stock'), [
            'scope' => 'selected', 'ids' => [$product->id], 'min_stock' => 15,
        ])->assertSessionHas('success');

        $this->assertSame(15, (int) \App\Models\StockApiSyncRecord::where('product_id', $product->id)->value('min_qty'));
    }

    public function test_a_stock_write_off_is_not_counted_as_demand_for_restock(): void
    {
        $product = Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs']);
        $this->giveStock($product, 100);

        // 40 unit hilang di rak, dicatat lewat penyesuaian.
        $adjustment = \App\Models\StockAdjustment::create([
            'code' => \App\Models\StockAdjustment::nextCode(), 'date' => now(),
            'reason' => \App\Models\StockAdjustment::reasons()[0], 'status' => 'draft',
        ]);
        $adjustment->items()->create(['product_id' => $product->id, 'system_quantity' => 100, 'actual_quantity' => 60]);
        $this->actingAs($this->admin)->post(route('admin.adjustments.submit', $adjustment));
        $this->assertSame(60, $product->refresh()->stock);

        $row = app(\App\Services\RestockReportService::class)
            ->lazy(\App\Support\RestockFilters::fromRequest(\Illuminate\Http\Request::create('/', 'GET', ['view' => 'semua'])))
            ->first(fn ($row) => $row->id === $product->id);

        $this->assertSame(0, $row->outgoing);
    }

    protected function makeOrder(string $tracking, string $sku, ?int $productId): ShipmentOrder
    {
        $import = ShipmentImport::firstOrCreate(['filename' => 'tiktok.xlsx'], ['source' => 'tiktok']);

        $order = $import->orders()->create([
            'tracking_number' => $tracking, 'order_number' => 'INV-'.$tracking,
            'marketplace' => 'TikTok Shop', 'buyer_name' => 'B',
        ]);
        $order->items()->create(['sku' => $sku, 'product_id' => $productId, 'quantity' => 1]);

        return $order;
    }

    protected function giveStock(Product $product, int $quantity): void
    {
        $inbound = Inbound::create(['code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}

<?php

namespace Tests\Feature\Audit;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use App\Services\OrderPlanner;
use App\Services\StockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Perbaikan kecepatan untuk data besar (2026-11-06): shipped_at sebagai
 * penyaring awal resi yang sudah berangkat, sidik jari planner yang hanya
 * membaca resi terbuka, dan pencarian resi kembar lewat indeks.
 */
class PerformanceLargeDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        $this->product = Product::create(['sku' => 'KAOS-01', 'name' => 'Kaos', 'unit' => 'pcs']);

        $inbound = Inbound::create(['code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => 50]);
        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }

    protected function order(string $resi, ?string $orderNumber = null): ShipmentOrder
    {
        $order = ShipmentImport::create(['filename' => 't.xlsx', 'source' => 'tiktok'])->orders()->create([
            'tracking_number' => $resi, 'order_number' => $orderNumber ?? 'INV-'.$resi, 'marketplace' => 'TikTok Shop',
        ]);
        $order->items()->create(['sku' => 'KAOS-01', 'product_id' => $this->product->id, 'quantity' => 1]);

        return $order;
    }

    protected function packed(ShipmentOrder $order): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'B', 'tracking_number' => $order->tracking_number, 'shipment_order_id' => $order->id,
            'status' => Outbound::STATUS_DRAFT, 'resi_verified_at' => now(),
        ]);
        $outbound->items()->create(['product_id' => $this->product->id, 'quantity' => 1, 'scanned_quantity' => 1]);

        return $outbound;
    }

    public function test_shipped_at_follows_posting_reversal_relinking_and_deletion(): void
    {
        $order = $this->order('RESI-A');
        $other = $this->order('RESI-B');
        $outbound = $this->packed($order);

        $this->assertNull($order->refresh()->shipped_at);
        $this->assertSame(1, ShipmentOrder::qualityChecked()->count());

        app(StockService::class)->postOutbound($outbound->refresh());
        $this->assertNotNull($order->refresh()->shipped_at);
        $this->assertSame(0, ShipmentOrder::open()->whereKey($order->id)->count());
        $this->assertSame(1, ShipmentOrder::shipped()->count());

        // Dibatalkan setelah serah terima: resinya kembali terbuka.
        app(StockService::class)->reverseOutbound($outbound->refresh(), 'Batal');
        $this->assertNull($order->refresh()->shipped_at);
        $this->assertSame(1, ShipmentOrder::open()->whereKey($order->id)->count());

        // Dokumen yang sudah dikirim dipindah ke resi lain: keduanya ikut.
        app(StockService::class)->postOutbound($outbound->refresh());
        $outbound->refresh()->forceFill(['shipment_order_id' => $other->id])->save();
        $this->assertNull($order->refresh()->shipped_at);
        $this->assertNotNull($other->refresh()->shipped_at);

        $outbound->refresh()->delete();
        $this->assertNull($other->refresh()->shipped_at);
    }

    public function test_a_stale_empty_shipped_at_never_shows_a_shipped_order_as_open(): void
    {
        $order = $this->order('RESI-LAMA');
        $outbound = $this->packed($order);

        // Ditulis langsung tanpa melewati model, seperti data lama.
        DB::table('outbounds')->where('id', $outbound->id)->update(['status' => Outbound::STATUS_POSTED, 'posted_at' => now()]);
        $this->assertNull($order->refresh()->shipped_at);

        $this->assertSame(0, ShipmentOrder::open()->count());
        $this->assertSame(0, ShipmentOrder::awaitingQc()->count());
        $this->assertSame(0, ShipmentOrder::qualityChecked()->count());
        $this->assertSame(1, ShipmentOrder::shipped()->count());
    }

    public function test_planner_fingerprint_notices_scans_cancellations_and_shipping(): void
    {
        $planner = app(OrderPlanner::class);
        $order = $this->order('RESI-FP');
        $start = $planner->fingerprint();

        $outbound = $this->packed($order);
        $outbound->items()->update(['scanned_quantity' => 0]);
        $afterPacking = $planner->fingerprint();
        $this->assertNotSame($start, $afterPacking);

        $outbound->items()->update(['scanned_quantity' => 1]);
        $afterScan = $planner->fingerprint();
        $this->assertNotSame($afterPacking, $afterScan);

        app(StockService::class)->postOutbound($outbound->refresh());
        $afterShipping = $planner->fingerprint();
        $this->assertNotSame($afterScan, $afterShipping);

        $second = $this->order('RESI-FP2');
        $afterImport = $planner->fingerprint();
        $this->assertNotSame($afterShipping, $afterImport);

        $second->forceFill(['cancelled_at' => now()])->save();
        $this->assertNotSame($afterImport, $planner->fingerprint());
    }

    public function test_planner_only_reads_open_orders_but_still_reserves_stock(): void
    {
        $shipped = $this->order('RESI-KIRIM');
        app(StockService::class)->postOutbound($this->packed($shipped)->refresh());

        $open = $this->order('RESI-BUKA');
        app(OrderPlanner::class)->refresh(force: true);

        $this->assertSame(OrderPlanner::READY, $open->refresh()->stock_status);
        $this->assertSame(1, ShipmentOrder::stockStatus(OrderPlanner::READY)->count());
    }

    public function test_twin_resi_are_found_without_grouping_every_order(): void
    {
        $this->order('RESI-1', 'INV-SAMA');
        $this->order('RESI-2', 'INV-SAMA');
        $this->order('RESI-3', 'INV-SENDIRI');

        $this->assertEqualsCanonicalizing(
            ['RESI-1', 'RESI-2'],
            ShipmentOrder::duplicated()->pluck('tracking_number')->all(),
        );
    }
}

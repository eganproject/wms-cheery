<?php

namespace Tests\Feature\Audit;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit berkelanjutan, pass 5: paket yang batal setelah serah terima.
 */
class ContinuousAuditPass5Test extends TestCase
{
    use RefreshDatabase;

    public function test_a_package_cancelled_after_handover_leaves_the_queue(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        $product = Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs']);

        $inbound = Inbound::create(['code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => 10]);
        $this->actingAs($admin)->post(route('admin.inbounds.submit', $inbound));

        $order = ShipmentImport::create(['filename' => 't.xlsx', 'source' => 'tiktok'])->orders()->create([
            'tracking_number' => 'SPXBTL1', 'order_number' => 'INV1', 'marketplace' => 'TikTok Shop',
        ]);
        $order->items()->create(['sku' => 'FLT-OLI-STD', 'product_id' => $product->id, 'quantity' => 2]);

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'B', 'tracking_number' => 'SPXBTL1', 'shipment_order_id' => $order->id,
            'status' => Outbound::STATUS_DRAFT, 'resi_verified_at' => now(),
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => 2, 'scanned_quantity' => 2]);

        $this->actingAs($admin)->postJson(route('admin.outbounds.handover.scan'), ['code' => 'SPXBTL1'])->assertOk();
        $this->assertSame(8, $product->refresh()->stock);

        $this->actingAs($admin)->post(route('admin.imports.orders.cancel', $order), ['cancellation_reason' => 'Pembeli batal']);
        $this->assertSame(10, $product->refresh()->stock);

        $this->assertSame(0, Outbound::awaitingHandover()->count());
        $this->actingAs($admin)->get(route('admin.outbounds.handover'))->assertOk()->assertDontSee($outbound->code);

        $this->assertSame(Outbound::STAGE_CANCELLED, $outbound->refresh()->stage());
        $this->assertSame(1, Outbound::atStage(Outbound::STAGE_CANCELLED)->count());
        $this->actingAs($admin)->get(route('admin.outbounds.index'))->assertOk()->assertSee('Batal setelah dikirim');

        // Pembatalan dicabut: paketnya kembali ke antrean untuk dikirim ulang.
        $this->actingAs($admin)->delete(route('admin.imports.orders.restore', $order));
        $this->assertSame(1, Outbound::awaitingHandover()->count());
    }
}

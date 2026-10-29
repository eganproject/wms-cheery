<?php

namespace Tests\Feature\Audit;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\OutboundScanService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Audit berkelanjutan, pass 1.
 */
class ContinuousAuditPass1Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0,
        ]);

        $this->giveStock(20);
    }

    /* ------------------------------------------------------ retur -------- */

    public function test_a_return_line_cannot_claim_more_damaged_units_than_it_holds(): void
    {
        $this->actingAs($this->admin)->post(route('admin.returns.store'), [
            'date' => now()->toDateString(),
            'type' => ReturnReceipt::TYPE_REGULAR,
            'sender' => 'Toko Andi',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'damaged_quantity' => 5]],
        ])->assertSessionHasErrors('items.0.damaged_quantity');

        $this->assertDatabaseCount('return_receipts', 0);
    }

    public function test_a_forged_good_quantity_cannot_inflate_stock(): void
    {
        $this->actingAs($this->admin)->post(route('admin.returns.store'), [
            'date' => now()->toDateString(),
            'type' => ReturnReceipt::TYPE_REGULAR,
            'sender' => 'Toko Andi',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'good_quantity' => 100]],
        ])->assertSessionHasNoErrors();

        $return = ReturnReceipt::with('items')->firstOrFail();
        $this->assertSame(1, $return->items->first()->good_quantity);

        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return));

        $this->assertSame(21, $this->product->refresh()->stock);
    }

    public function test_finishing_an_already_received_return_changes_nothing(): void
    {
        $order = $this->makeOrder('SPXRET1');

        $start = $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'SPXRET1'])
            ->assertOk();

        $return = ReturnReceipt::with('items')->firstOrFail();
        $itemId = $return->items->first()->id;

        $this->actingAs($this->admin)->postJson($start->json('urls.finish'), [
            'items' => [$itemId => ['good' => 2, 'damaged' => 0]],
        ])->assertOk();

        $this->assertTrue($return->refresh()->isPosted());
        $this->assertSame(22, $this->product->refresh()->stock);

        // Layar lama mengirim ulang hasil pemeriksaan yang berbeda.
        $this->actingAs($this->admin)->postJson($start->json('urls.finish'), [
            'items' => [$itemId => ['good' => 0, 'damaged' => 2]],
        ])->assertUnprocessable();

        $item = $return->items()->first();
        $this->assertSame(2, $item->good_quantity);
        $this->assertSame(0, $item->damaged_quantity);
        $this->assertSame(22, $this->product->refresh()->stock);
    }

    public function test_changing_a_return_waybill_drops_the_link_to_the_old_order(): void
    {
        $order = $this->makeOrder('SPXRET2');

        $return = ReturnReceipt::create([
            'code' => ReturnReceipt::nextCode(), 'date' => now(), 'type' => ReturnReceipt::TYPE_REGULAR,
            'sender' => 'Pembeli', 'tracking_number' => 'SPXRET2', 'shipment_order_id' => $order->id,
            'status' => ReturnReceipt::STATUS_DRAFT,
        ]);
        $return->items()->create(['product_id' => $this->product->id, 'quantity' => 1, 'good_quantity' => 1, 'damaged_quantity' => 0]);

        $this->actingAs($this->admin)->put(route('admin.returns.update', $return), [
            'date' => now()->toDateString(),
            'type' => ReturnReceipt::TYPE_REGULAR,
            'sender' => 'Pembeli',
            'tracking_number' => 'JNTLAIN99',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertNull($return->refresh()->shipment_order_id);
    }

    /* ------------------------------------------------------ QC kemas ----- */

    public function test_a_double_fired_scan_never_counts_past_the_order(): void
    {
        $outbound = $this->makePackage($this->makeOrder('SPXQC1'), scanned: 1);

        // Permintaan kedua membaca dokumen saat masih 1/2, tetapi permintaan
        // pertama sudah menuntaskannya di database.
        $stale = Outbound::with('items.product')->find($outbound->id);
        $outbound->items()->update(['scanned_quantity' => 2]);

        $this->actingAs($this->admin);

        try {
            app(OutboundScanService::class)->scanItem($stale, 'FLT-OLI-STD');
            $this->fail('Scan ganda seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('scan ganda', collect($exception->errors())->flatten()->implode(' '));
        }

        $this->assertSame(2, $outbound->items()->value('scanned_quantity'));
    }

    public function test_a_pending_package_cannot_be_reset_at_the_scan_station(): void
    {
        $outbound = $this->makePackage($this->makeOrder('SPXQC2'), scanned: 2);
        $outbound->forceFill(['status' => Outbound::STATUS_PENDING, 'submitted_at' => now()])->save();

        $this->actingAs($this->admin)->post(route('admin.outbounds.scan.reset', $outbound))
            ->assertSessionHasErrors('code');

        $this->assertNotNull($outbound->refresh()->resi_verified_at);
        $this->assertSame(2, $outbound->items()->value('scanned_quantity'));
    }

    /* ------------------------------------------------------ opname ------- */

    public function test_a_shipment_before_the_count_is_not_shown_or_booked_as_a_variance(): void
    {
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => StockOpname::SCOPE_ALL,
        ]);
        $opname = StockOpname::with('items')->firstOrFail();
        $this->assertSame(20, $opname->items->first()->system_quantity);

        // Sesi dibuka pagi; 3 unit terkirim sebelum raknya dihitung.
        $this->shipRegular(3);
        $this->assertSame(17, $this->product->refresh()->stock);

        // Rak berisi 17 — cocok dengan kenyataan.
        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$opname->items->first()->id => 17],
        ]);

        $item = $opname->items()->first();
        $this->assertSame(0, $item->difference(), 'Layar penyetuju tidak boleh menampilkan selisih palsu.');

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname));

        $this->assertSame(17, $this->product->refresh()->stock);
        $this->assertSame(0, $opname->items()->first()->applied_difference);
    }

    /* ------------------------------------------------------ import ------- */

    public function test_a_cancellation_from_a_later_import_withdraws_a_pending_shipment(): void
    {
        $order = $this->makeOrder('SPXID777');
        $outbound = $this->makePackage($order, scanned: 2);
        $outbound->forceFill(['status' => Outbound::STATUS_PENDING, 'submitted_at' => now(), 'submitted_by' => $this->admin->id])->save();

        $csv = "Tracking ID,Seller SKU,Quantity,Order Status\nSPXID777,FLT-OLI-STD,2,Dibatalkan";
        $path = tempnam(sys_get_temp_dir(), 'tiktok').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, 'tiktok.csv', 'text/csv', null, true),
        ]);

        $this->assertTrue($order->refresh()->isCancelled());
        $this->assertTrue($outbound->refresh()->isDraft());
        $this->assertNull($outbound->submitted_at);
    }

    /* ------------------------------------------------------ pembantu ----- */

    protected function makeOrder(string $tracking): ShipmentOrder
    {
        $import = ShipmentImport::firstOrCreate(['filename' => 'tiktok.xlsx'], ['source' => 'tiktok']);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'TikTok Shop',
            'buyer_name' => 'Pembeli',
        ]);

        $order->items()->create([
            'sku' => $this->product->sku, 'product_id' => $this->product->id,
            'product_name' => $this->product->name, 'quantity' => 2,
        ]);

        return $order->load('items.product');
    }

    protected function makePackage(ShipmentOrder $order, int $scanned): Outbound
    {
        // Paket berangkat lewat stok yang sudah dimiliki; pastikan resinya
        // sudah dipakai di sistem sebelum retur discan.
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Pembeli', 'tracking_number' => $order->tracking_number,
            'shipment_order_id' => $order->id, 'status' => Outbound::STATUS_DRAFT,
            'resi_verified_at' => now(),
        ]);

        $outbound->items()->create(['product_id' => $this->product->id, 'quantity' => 2, 'scanned_quantity' => $scanned]);

        return $outbound->load('items.product');
    }

    protected function shipRegular(int $quantity): void
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_REGULAR,
            'recipient' => 'Pembeli', 'status' => Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound));
    }

    protected function giveStock(int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}

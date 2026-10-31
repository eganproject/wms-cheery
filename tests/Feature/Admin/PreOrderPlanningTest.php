<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPoPeriod;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use App\Services\OrderPlanner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pesanan Pre Order TikTok: batas kirim dari lama PO, kesiapan stok,
 * "Harus dikirim menurut TikTok", dan packing list harian / keseluruhan.
 */
class PreOrderPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ProductGroup $helena;

    protected Product $helena3;

    protected Product $kalea;

    protected const HEADER = ['Order ID', 'Tracking ID', 'Seller SKU', 'Quantity', 'Normal or Pre-order', 'Created Time', 'Paid Time', 'Delivery Option'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-12 09:00'));

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $this->helena = ProductGroup::create(['name' => 'Helena', 'unit' => 'pcs']);
        $this->helena3 = Product::create(['sku' => 'Helena 3', 'name' => 'Helena - 3', 'unit' => 'pcs', 'min_stock' => 0, 'product_group_id' => $this->helena->id]);
        $this->kalea = Product::create(['sku' => 'Kalea 1', 'name' => 'Kalea - 1', 'unit' => 'pcs', 'min_stock' => 0]);
    }

    protected function upload(array $rows, string $route = 'admin.imports.store')
    {
        $csv = collect([self::HEADER, ...$rows])->map(fn (array $row) => implode(',', $row))->implode("\n");
        $path = tempnam(sys_get_temp_dir(), 'tiktok').'.csv';
        file_put_contents($path, $csv);

        return $this->actingAs($this->admin)->post(route($route), [
            'file' => new UploadedFile($path, 'Untuk Dikirim.csv', 'text/csv', null, true),
        ]);
    }

    protected function period(array $owner, string $startsAt, int $days): void
    {
        ProductPoPeriod::create($owner + ['starts_at' => $startsAt, 'days' => $days]);
    }

    protected function stock(Product $product, int $quantity): void
    {
        $product->forceFill(['stock' => $quantity])->save();
    }

    protected function order(string $resi): ShipmentOrder
    {
        app(OrderPlanner::class)->refresh(force: true);

        return ShipmentOrder::where('tracking_number', $resi)->firstOrFail();
    }

    public function test_import_reads_preorder_paid_time_and_delivery_option(): void
    {
        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '01/06/2026 10:00:00', '01/06/2026 10:05:00', 'Pengiriman standar'],
            ['580002', 'JX02', 'Kalea 1', '1', 'Normal', '11/06/2026 08:00:00', '11/06/2026 08:01:00', 'Instan'],
        ])->assertSessionHas('success');

        $po = ShipmentOrder::where('tracking_number', 'JX01')->firstOrFail();
        $this->assertTrue($po->is_preorder);
        $this->assertSame('2026-06-01 10:05:00', $po->paid_at->toDateTimeString());

        $normal = ShipmentOrder::where('tracking_number', 'JX02')->firstOrFail();
        $this->assertFalse($normal->is_preorder);
        $this->assertTrue($normal->is_priority, 'Instan selalu prioritas.');
        $this->assertSame('2026-06-11 23:59:59', $normal->ship_by->toDateTimeString(), 'Instan dikirim hari yang sama.');
    }

    public function test_deadline_uses_the_po_period_in_force_at_payment_time(): void
    {
        // Contoh Ega: 1 Jun 01:00 → 7 hari, 7 Jun 18:36 → 15 hari.
        $this->period(['product_group_id' => $this->helena->id], '2026-06-01 01:00:00', 7);
        $this->period(['product_group_id' => $this->helena->id], '2026-06-07 18:36:00', 15);

        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '07/06/2026 18:30:00', '07/06/2026 18:35:00', 'Hemat'],
            ['580002', 'JX02', 'Helena 3', '1', 'Pre-order', '07/06/2026 18:36:00', '07/06/2026 18:36:30', 'Hemat'],
        ]);

        $before = $this->order('JX01');
        $this->assertSame(7, $before->po_days);
        $this->assertSame('2026-06-14 18:35:00', $before->ship_by->toDateTimeString());

        $after = $this->order('JX02');
        $this->assertSame(15, $after->po_days);
        $this->assertSame('2026-06-22 18:36:30', $after->ship_by->toDateTimeString());
    }

    public function test_an_order_with_several_items_uses_the_longest_po(): void
    {
        $this->period(['product_group_id' => $this->helena->id], '2026-05-01 00:00:00', 7);
        $this->period(['product_id' => $this->kalea->id], '2026-05-01 00:00:00', 20);

        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '05/06/2026 10:00:00', '05/06/2026 10:00:00', 'Hemat'],
            ['580001', 'JX01', 'Kalea 1', '1', 'Pre-order', '05/06/2026 10:00:00', '05/06/2026 10:00:00', 'Hemat'],
        ]);

        $this->assertSame(20, $this->order('JX01')->po_days);
    }

    public function test_orders_already_running_get_a_deadline_once_the_po_is_filled_in(): void
    {
        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '20/05/2026 10:00:00', '20/05/2026 10:00:00', 'Hemat'],
        ]);

        $this->assertNull($this->order('JX01')->ship_by, 'Belum ada lama PO: belum ada batas kirim.');

        // Periode boleh dimulai di masa lalu, untuk pesanan yang sudah berjalan.
        $this->actingAs($this->admin)->post(route('admin.po-periods.store'), [
            'owners' => ['g'.$this->helena->id],
            'starts_at' => '2026-05-01T00:00',
            'days' => 30,
        ])->assertSessionHas('success');

        $this->assertSame('2026-06-19 10:00:00', $this->order('JX01')->ship_by->toDateTimeString());
    }

    public function test_normal_orders_follow_the_normal_rule(): void
    {
        AppSetting::put(AppSetting::NORMAL_SHIP_DAYS, '3');

        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Normal', '10/06/2026 10:00:00', '10/06/2026 11:00:00', 'Hemat'],
        ]);

        $this->assertSame('2026-06-13 11:00:00', $this->order('JX01')->ship_by->toDateTimeString());
    }

    public function test_a_manual_deadline_is_kept_until_reset(): void
    {
        $this->period(['product_group_id' => $this->helena->id], '2026-05-01 00:00:00', 10);
        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '05/06/2026 10:00:00', '05/06/2026 10:00:00', 'Hemat'],
        ]);
        $order = $this->order('JX01');

        $this->actingAs($this->admin)->put(route('admin.orders.deadline', $order), ['ship_by' => '2026-06-13T12:00'])
            ->assertSessionHas('success');

        $this->period(['product_group_id' => $this->helena->id], '2026-06-01 00:00:00', 20);

        $order = $this->order('JX01');
        $this->assertSame('2026-06-13 12:00:00', $order->ship_by->toDateTimeString());
        $this->assertSame('2026-06-25 10:00:00', $order->computed_ship_by->toDateTimeString());

        $this->actingAs($this->admin)->delete(route('admin.orders.deadline.reset', $order));

        $this->assertSame('2026-06-25 10:00:00', $this->order('JX01')->ship_by->toDateTimeString());
    }

    public function test_stock_goes_to_the_most_urgent_order_first(): void
    {
        $this->period(['product_group_id' => $this->helena->id], '2026-05-01 00:00:00', 10);
        $this->stock($this->helena3, 1);

        $this->upload([
            // Dibayar lebih dulu, tetapi batasnya lebih jauh dari resi Instan.
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '05/06/2026 10:00:00', '05/06/2026 10:00:00', 'Hemat'],
            ['580002', 'JX02', 'Helena 3', '1', 'Normal', '11/06/2026 10:00:00', '11/06/2026 10:00:00', 'Instan'],
            ['580003', 'JX03', 'Kalea 1', '1', 'Normal', '11/06/2026 10:00:00', '11/06/2026 10:00:00', 'Hemat'],
        ]);

        $this->assertSame(OrderPlanner::READY, $this->order('JX02')->stock_status);
        $waiting = $this->order('JX01');
        $this->assertSame(OrderPlanner::WAITING, $waiting->stock_status);
        $this->assertStringContainsString('Helena 3 kurang 1', $waiting->stock_note);
        $this->assertSame(OrderPlanner::WAITING, $this->order('JX03')->stock_status);

        // Stok datang: resi berikutnya ikut siap.
        $this->stock($this->helena3, 2);
        $this->assertSame(OrderPlanner::READY, $this->order('JX01')->stock_status);
    }

    public function test_items_scanned_but_not_yet_handed_over_are_off_the_shelf(): void
    {
        $this->stock($this->kalea, 1);
        $this->upload([
            ['580001', 'JX01', 'Kalea 1', '1', 'Normal', '10/06/2026 10:00:00', '10/06/2026 10:00:00', 'Hemat'],
            ['580002', 'JX02', 'Kalea 1', '1', 'Normal', '10/06/2026 11:00:00', '10/06/2026 11:00:00', 'Hemat'],
        ]);

        // JX02 sudah di-QC: barangnya sudah diambil dari rak walau stok baru
        // berkurang saat serah terima.
        $packed = ShipmentOrder::where('tracking_number', 'JX02')->firstOrFail();
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Pembeli', 'tracking_number' => 'JX02', 'status' => Outbound::STATUS_DRAFT,
            'shipment_order_id' => $packed->id, 'resi_verified_at' => now(),
        ]);
        $outbound->items()->create(['product_id' => $this->kalea->id, 'quantity' => 1, 'scanned_quantity' => 1]);

        $this->assertSame(OrderPlanner::WAITING, $this->order('JX01')->stock_status);
        $this->assertNull($this->order('JX02')->stock_status, 'Sudah QC: tidak perlu dibagi stok lagi.');
    }

    public function test_reimporting_fills_in_preorder_data_on_old_waybills(): void
    {
        ShipmentOrder::create([
            'shipment_import_id' => ShipmentImport::create(['filename' => 'lama.csv'])->id,
            'tracking_number' => 'JX01', 'order_number' => '580001', 'marketplace' => 'TikTok Shop',
        ])->items()->create(['sku' => 'Helena 3', 'quantity' => 1, 'product_id' => $this->helena3->id]);

        $this->upload([
            ['580001', 'JX01', 'Helena 3', '5', 'Pre-order', '05/06/2026 10:00:00', '05/06/2026 10:01:00', 'Hemat'],
        ])->assertSessionHas('success');

        $order = ShipmentOrder::where('tracking_number', 'JX01')->firstOrFail();
        $this->assertTrue($order->is_preorder);
        $this->assertSame('2026-06-05 10:01:00', $order->paid_at->toDateTimeString());
        $this->assertSame(1, $order->totalQuantity(), 'Isi resi lama tidak diubah.');
    }

    public function test_tiktok_priority_upload_locks_today_and_reports_mismatches(): void
    {
        $this->period(['product_group_id' => $this->helena->id], '2026-05-01 00:00:00', 15);
        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '05/06/2026 10:00:00', '05/06/2026 10:00:00', 'Hemat'],
        ]);

        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '05/06/2026 10:00:00', '05/06/2026 10:00:00', 'Hemat'],
            ['580009', 'JX09', 'Kalea 1', '1', 'Normal', '10/06/2026 07:00:00', '10/06/2026 07:00:00', 'Hemat'],
        ], 'admin.orders.priority.store')->assertRedirect(route('admin.orders.priority'));

        $result = session('priority_result');
        $this->assertSame(2, $result['marked']);
        $this->assertSame(1, $result['imported'], 'Resi yang belum ada ikut diimport.');
        $this->assertSame(['JX01'], array_column($result['mismatch'], 'tracking'));

        $order = $this->order('JX01');
        $this->assertSame(OrderPlanner::SOURCE_TIKTOK, $order->ship_by_source);
        $this->assertSame('2026-06-12 23:59:59', $order->ship_by->toDateTimeString());
        $this->assertTrue($order->is_priority);
        $this->assertTrue($order->differsFromTikTok());

        $this->actingAs($this->admin)->get(route('admin.orders.priority'))->assertOk()->assertSee('JX01');
        $this->actingAs($this->admin)->get(route('admin.orders.index', ['deadline' => 'mismatch']))
            ->assertOk()->assertSee('JX01')->assertDontSee('JX09');
    }

    public function test_daily_packing_list_has_only_ready_waybills_most_urgent_first(): void
    {
        $this->period(['product_group_id' => $this->helena->id], '2026-05-01 00:00:00', 10);
        $this->stock($this->helena3, 5);

        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '08/06/2026 10:00:00', '08/06/2026 10:00:00', 'Hemat'],
            ['580002', 'JX02', 'Helena 3', '2', 'Pre-order', '03/06/2026 10:00:00', '03/06/2026 10:00:00', 'Hemat'],
            ['580003', 'JX03', 'Kalea 1', '1', 'Normal', '11/06/2026 10:00:00', '11/06/2026 10:00:00', 'Hemat'],
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.orders.packing'))->assertOk();
        $this->assertSame('harian', $response->viewData('type'));
        $this->assertSame(['JX02', 'JX01'], $response->viewData('resi')->pluck('tracking_number')->all());
        $this->assertSame(3, $response->viewData('rows')->firstWhere('sku', 'Helena 3')['ordered']);

        $all = $this->actingAs($this->admin)->get(route('admin.orders.packing', ['jenis' => 'semua']))->assertOk();
        $this->assertEqualsCanonicalizing(['JX01', 'JX02', 'JX03'], $all->viewData('resi')->pluck('tracking_number')->all());

        $this->actingAs($this->admin)->get(route('admin.orders.packing.excel'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.orders.packing.pdf'))->assertOk();
    }

    public function test_pages_render_with_the_new_markers(): void
    {
        $this->period(['product_group_id' => $this->helena->id], '2026-05-01 00:00:00', 1);
        $this->upload([
            ['580001', 'JX01', 'Helena 3', '1', 'Pre-order', '01/06/2026 10:00:00', '01/06/2026 10:00:00', 'Hemat'],
            ['580002', 'JX02', 'Kalea 1', '1', 'Pre-order', '01/06/2026 10:00:00', '01/06/2026 10:00:00', 'Hemat'],
        ]);

        $this->actingAs($this->admin)->get(route('admin.po-periods.index'))
            ->assertOk()->assertSee('Helena')->assertSee('Kalea 1')->assertSee('resi belum punya batas kirim');

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['deadline' => 'overdue']))
            ->assertOk()->assertSee('JX01')->assertDontSee('JX02')->assertSee('Lewat');

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['deadline' => 'missing']))
            ->assertOk()->assertSee('JX02')->assertSee('Lama PO belum diisi');

        $this->actingAs($this->admin)->get(route('admin.orders.export', ['range' => 'all']))->assertOk();

        $this->actingAs($this->admin)->put(route('admin.po-periods.settings'), ['normal_ship_days' => 4])->assertSessionHas('success');
        $this->assertSame(4, AppSetting::normalShipDays());
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\Role;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use App\Services\OwnerReportService;
use App\Support\DateRange;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OwnerReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        $this->product = Product::create(['sku' => 'BAJU-1', 'name' => 'Baju Satu', 'unit' => 'pcs', 'min_stock' => 0]);
    }

    public function test_every_tab_renders(): void
    {
        $this->makeOrder('RESI1', amount: 50000);

        foreach (array_keys(\App\Http\Controllers\Admin\OwnerReportController::TABS) as $tab) {
            $this->actingAs($this->admin)->get(route('admin.reports.owner', ['tab' => $tab]))->assertOk();
            $this->actingAs($this->admin)->get(route('admin.reports.owner.export', ['tab' => $tab]))->assertOk();
        }

        $this->actingAs($this->admin)->get(route('admin.reports.owner', ['tab' => 'penjualan', 'per' => 'minggu']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.reports.owner', ['tab' => 'penjualan', 'per' => 'bulan']))->assertOk();
    }

    public function test_staff_without_permission_cannot_open_it(): void
    {
        $staff = User::factory()->create();
        $staff->forceFill(['role_id' => Role::where('slug', 'staff-gudang')->value('id')])->save();

        $this->actingAs($staff)->get(route('admin.reports.owner'))->assertForbidden();
    }

    public function test_sales_count_orders_units_and_revenue_and_skip_cancelled(): void
    {
        $this->makeOrder('RESI1', quantity: 2, amount: 100000);
        $this->makeOrder('RESI2', quantity: 1, amount: 40000);
        $cancelled = $this->makeOrder('RESI3', quantity: 5, amount: 999000);
        $cancelled->forceFill(['cancelled_at' => now()])->save();

        $sales = app(OwnerReportService::class)->sales($this->month(), 'hari');

        $this->assertSame(2, $sales['totals']['orders']);
        $this->assertSame(3, $sales['totals']['units']);
        $this->assertEquals(140000, $sales['totals']['revenue']);
        $this->assertSame(1, $sales['totals']['cancelled']);
        $this->assertSame('TikTok Shop', $sales['stores']->first()->marketplace);
    }

    public function test_shipping_punctuality_splits_on_time_late_and_overdue(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00'));

        $onTime = $this->makeOrder('ONTIME', shipBy: '2026-11-05 23:59');
        $this->ship($onTime, '2026-11-05 10:00');

        $late = $this->makeOrder('LATE', shipBy: '2026-11-05 23:59');
        $this->ship($late, '2026-11-07 00:00');

        $this->makeOrder('OVERDUE', shipBy: '2026-11-06 23:59');
        $this->makeOrder('PENDING', shipBy: '2026-11-12 23:59');

        $report = app(OwnerReportService::class)->shipping(new DateRange('2026-11-01', '2026-11-30'));

        $this->assertSame(1, $report['totals']['on_time']);
        $this->assertSame(1, $report['totals']['late']);
        $this->assertSame(1, $report['totals']['overdue']);
        $this->assertSame(1, $report['totals']['pending']);
        $this->assertEqualsWithDelta(33.3, $report['totals']['rate'], 0.1);
        $this->assertSame(['LATE', 'OVERDUE'], $report['late']->pluck('tracking_number')->sort()->values()->all());
    }

    public function test_return_rate_compares_returned_units_with_units_sold(): void
    {
        $this->makeOrder('RESI1', quantity: 10);

        $receipt = ReturnReceipt::create([
            'code' => 'RET-1', 'date' => now()->toDateString(), 'type' => ReturnReceipt::TYPE_MARKETPLACE,
            'sender' => 'Pembeli', 'reason' => 'Barang rusak', 'status' => ReturnReceipt::STATUS_POSTED,
        ]);
        $receipt->items()->create(['product_id' => $this->product->id, 'quantity' => 2, 'good_quantity' => 1, 'damaged_quantity' => 1]);

        $report = app(OwnerReportService::class)->returns($this->month());

        $this->assertSame(2, $report['totals']['units']);
        $this->assertEqualsWithDelta(20.0, $report['totals']['rate'], 0.01);
        $this->assertSame('Barang rusak', $report['reasons']->first()->reason);
    }

    public function test_tiktok_import_reads_the_sale_amount_and_fills_it_in_on_re_upload(): void
    {
        $this->uploadCsv([
            ['Order ID', 'Tracking ID', 'Seller SKU', 'Quantity'],
            ['INV-1', 'SPX1', 'BAJU-1', '2'],
        ]);

        $item = ShipmentOrder::where('tracking_number', 'SPX1')->firstOrFail()->items->first();
        $this->assertNull($item->amount);

        $this->uploadCsv([
            ['Order ID', 'Tracking ID', 'Seller SKU', 'Quantity', 'SKU Subtotal After Discount'],
            ['INV-1', 'SPX1', 'BAJU-1', '2', '"IDR 118.000"'],
            ['INV-2', 'SPX2', 'BAJU-1', '1', '59000'],
        ]);

        $this->assertEquals(118000, $item->fresh()->amount);
        $this->assertEquals(59000, ShipmentOrder::where('tracking_number', 'SPX2')->firstOrFail()->items->first()->amount);
    }

    public function test_gross_profit_subtracts_cost_and_marketplace_fee(): void
    {
        $this->product->forceFill(['cost' => 30000])->save();
        $this->makeOrder('RESI1', quantity: 2, amount: 100000);
        \App\Models\AppSetting::put(\App\Models\AppSetting::MARKETPLACE_FEES, json_encode(['TikTok Shop' => 10]));

        $report = app(OwnerReportService::class)->profit($this->month());

        // 100.000 − 10% potongan − 2 × 30.000 = 30.000
        $this->assertEquals(30000, $report['totals']['profit']);
        $this->assertEqualsWithDelta(30.0, $report['totals']['margin'], 0.01);
        $this->assertSame(0, $report['totals']['missing_cost']);
    }

    public function test_products_without_cost_are_listed_but_not_summed(): void
    {
        $this->makeOrder('RESI1', quantity: 1, amount: 50000);

        $report = app(OwnerReportService::class)->profit($this->month());

        $this->assertSame(1, $report['totals']['missing_cost']);
        $this->assertNull($report['rows']->first()->profit);
        $this->assertEquals(0, $report['totals']['profit']);
    }

    public function test_marketplace_fees_are_saved_from_the_profit_tab(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.reports.owner.fees'), ['fees' => ['TikTok Shop' => '8.5', 'Shopee' => '']])
            ->assertRedirect();

        $this->assertSame(['TikTok Shop' => 8.5], \App\Models\AppSetting::marketplaceFees());
    }

    public function test_stock_value_uses_cost(): void
    {
        $this->product->forceFill(['cost' => 20000, 'stock' => 5])->save();

        $report = app(OwnerReportService::class)->stockAging();

        $this->assertEquals(100000, $report['totals']['value']);
    }

    public function test_cost_is_saved_from_the_product_form_only_by_owner_report_users(): void
    {
        $this->actingAs($this->admin)->put(route('admin.products.update', $this->product), [
            'sku' => 'BAJU-1', 'name' => 'Baju Satu', 'unit' => 'pcs', 'min_stock' => 0, 'is_active' => 1, 'cost' => '45000',
        ])->assertSessionHasNoErrors();
        $this->assertEquals(45000, $this->product->fresh()->cost);

        $staff = User::factory()->create();
        $staff->forceFill(['role_id' => Role::where('slug', 'staff-gudang')->value('id')])->save();

        $this->actingAs($staff)->put(route('admin.products.update', $this->product), [
            'sku' => 'BAJU-1', 'name' => 'Baju Satu', 'unit' => 'pcs', 'min_stock' => 0, 'is_active' => 1, 'cost' => '1',
        ]);
        $this->assertEquals(45000, $this->product->fresh()->cost);
    }

    public function test_product_import_reads_the_cost_column(): void
    {
        $csv = "SKU,Nama Barang,HPP\nBAJU-1,Baju Satu,\"35.000\"\nBAJU-2,Baju Dua,\n";
        $path = tempnam(sys_get_temp_dir(), 'brg').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->admin)->post(route('admin.products.import.store'), [
            'file' => new UploadedFile($path, 'barang.csv', 'text/csv', null, true),
        ]);

        $this->assertEquals(35000, $this->product->fresh()->cost);
        $this->assertNull(Product::where('sku', 'BAJU-2')->value('cost'));
    }

    public function test_manual_sale_amount_is_saved_and_counted_as_revenue(): void
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_REGULAR,
            'recipient' => 'Toko A', 'status' => Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $this->product->id, 'quantity' => 3]);

        $this->actingAs($this->admin)->put(route('admin.outbounds.update', $outbound), [
            'date' => now()->toDateString(), 'recipient' => 'Toko A', 'sale_amount' => '150000',
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(150000, $outbound->fresh()->sale_amount);

        $outbound->fresh()->forceFill(['status' => Outbound::STATUS_POSTED, 'posted_at' => now()])->save();
        $sales = app(OwnerReportService::class)->sales($this->month(), 'hari');

        $this->assertEquals(150000, $sales['totals']['manual_revenue']);
        $this->assertEquals(150000, $sales['totals']['total_revenue']);
    }

    public function test_old_owner_url_redirects_to_the_business_summary(): void
    {
        $this->actingAs($this->admin)->get('/admin/reports/owner?tab=laba')
            ->assertRedirect(route('admin.reports.owner', ['tab' => 'laba']));
    }

    /* ------------------------------------------------------- helpers --- */

    protected function month(): DateRange
    {
        return new DateRange(now()->startOfMonth()->toDateString(), now()->toDateString());
    }

    protected function makeOrder(string $tracking, int $quantity = 1, ?float $amount = null, ?string $shipBy = null): ShipmentOrder
    {
        $import = ShipmentImport::create(['filename' => 't.csv', 'source' => 'tiktok', 'row_count' => 1, 'detected_columns' => []]);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'TikTok Shop',
            'courier' => 'SPX',
            'order_date' => now(),
        ]);
        $order->forceFill(['ship_by' => $shipBy])->save();

        $order->items()->create([
            'sku' => $this->product->sku, 'product_id' => $this->product->id,
            'product_name' => $this->product->name, 'quantity' => $quantity, 'amount' => $amount,
        ]);

        return $order;
    }

    protected function ship(ShipmentOrder $order, string $at): void
    {
        Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Pembeli', 'tracking_number' => $order->tracking_number,
            'shipment_order_id' => $order->id, 'status' => Outbound::STATUS_DRAFT,
        ])->forceFill(['status' => Outbound::STATUS_POSTED, 'posted_at' => $at])->save();
    }

    protected function uploadCsv(array $rows)
    {
        $csv = collect($rows)->map(fn (array $row) => implode(',', $row))->implode("\n");
        $path = tempnam(sys_get_temp_dir(), 'tiktok').'.csv';
        file_put_contents($path, $csv);

        return $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, 'tiktok-orders.csv', 'text/csv', null, true),
        ]);
    }
}

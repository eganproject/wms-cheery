<?php

namespace Tests\Feature\Audit;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Audit web & Android (November 2026).
 */
class AuditNov2026Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs']);
    }

    public function test_decimal_quantities_from_excel_are_not_multiplied_by_ten(): void
    {
        $this->uploadCsv([
            ['Order ID', 'Tracking ID', 'Seller SKU', 'Quantity'],
            ['INV-001', 'SPXID111', 'FLT-OLI-STD', '2.0'],
            ['INV-002', 'SPXID222', 'FLT-OLI-STD', '3'],
        ]);

        $this->assertSame(2, ShipmentOrder::where('tracking_number', 'SPXID111')->firstOrFail()->totalQuantity());
        $this->assertSame(3, ShipmentOrder::where('tracking_number', 'SPXID222')->firstOrFail()->totalQuantity());
    }

    public function test_dates_saved_by_excel_as_serial_numbers_are_read(): void
    {
        $this->uploadCsv([
            ['Order ID', 'Tracking ID', 'Seller SKU', 'Quantity', 'Paid Time', 'Created Time'],
            // 46325.5 = 30 Oktober 2026 12:00 menurut Excel.
            ['INV-001', 'SPXID111', 'FLT-OLI-STD', '1', '46325.5', '30/10/2026'],
        ]);

        $order = ShipmentOrder::where('tracking_number', 'SPXID111')->firstOrFail();

        $this->assertSame('2026-10-30 12:00:00', $order->paid_at->toDateTimeString());
        // Tanggal tanpa jam: 00:00, bukan jam saat import.
        $this->assertSame('2026-10-30 00:00:00', $order->order_date->toDateTimeString());
    }

    public function test_a_waybill_without_items_is_not_opened_at_packing(): void
    {
        $import = ShipmentImport::create(['filename' => 'tiktok.xlsx', 'source' => 'tiktok']);
        ShipmentOrder::create([
            'shipment_import_id' => $import->id,
            'tracking_number' => 'SPXKOSONG',
            'order_number' => 'INV-KOSONG',
            'marketplace' => 'TikTok Shop',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXKOSONG'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Resi ini tidak berisi barang · periksa data import pesanannya');

        $this->assertDatabaseCount('outbounds', 0);
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

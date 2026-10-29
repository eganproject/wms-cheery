<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Export Excel di halaman daftar (Packing List, Pesanan, Barang Keluar, dan
 * lainnya) serta PDF Packing List. Isinya harus mengikuti saringan layar.
 */
class ListExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ShipmentImport $import;

    protected Product $tas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        $this->import = ShipmentImport::create(['filename' => 'tiktok.xlsx', 'user_id' => $this->admin->id]);
        $this->tas = Product::create(['sku' => 'TAS-1', 'name' => 'Tas Mini', 'unit' => 'pcs', 'min_stock' => 0]);
    }

    protected function order(string $resi, int $quantity, array $attributes = []): ShipmentOrder
    {
        $order = ShipmentOrder::create(array_merge([
            'shipment_import_id' => $this->import->id,
            'tracking_number' => $resi,
            'order_number' => 'ORD-'.$resi,
            'marketplace' => 'TikTok',
            'courier' => 'J&T',
        ], $attributes));
        $order->items()->create(['sku' => 'TAS-1', 'quantity' => $quantity, 'product_id' => $this->tas->id]);

        return $order;
    }

    protected function sheet(TestResponse $response, int $index = 0): array
    {
        return $this->book($response)->getSheet($index)->toArray(null, true, false, false);
    }

    protected function book(TestResponse $response): Spreadsheet
    {
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    }

    /**
     * Baris data saja: dimulai setelah baris kepala tabel (yang kolom
     * pertamanya "No" atau label kolom pertama lembar itu).
     */
    protected function rowsAfter(array $sheet, string $firstHeader): array
    {
        foreach ($sheet as $index => $row) {
            if ($row[0] === $firstHeader) {
                return array_values(array_filter(array_slice($sheet, $index + 1), fn ($row) => $row[0] !== 'TOTAL' && $row[0] !== null));
            }
        }

        return [];
    }

    public function test_packing_list_excel_follows_filters_and_keeps_long_resi_as_text(): void
    {
        $this->order('123456789012345678', 2);
        $this->order('R-SHOPEE', 5, ['marketplace' => 'Shopee']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.orders.packing.excel', ['import' => $this->import->id, 'marketplace' => 'TikTok']));

        $rows = $this->rowsAfter($this->sheet($response), 'No');
        $this->assertCount(1, $rows);
        $this->assertSame('TAS-1', $rows[0][1]);
        $this->assertEquals(2, $rows[0][5]);

        $resi = $this->rowsAfter($this->sheet($response, 1), 'SKU');
        $this->assertSame('123456789012345678', $resi[0][2]);
    }

    public function test_packing_list_pdf_downloads(): void
    {
        $this->order('R1', 3);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.orders.packing.pdf', ['import' => $this->import->id]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_packing_list_page_shows_export_buttons(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.orders.packing', ['per' => 'barang']))
            ->assertOk()
            ->assertSee(route('admin.orders.packing.excel', ['per' => 'barang']), false)
            ->assertSee(route('admin.orders.packing.pdf', ['per' => 'barang']), false);
    }

    public function test_orders_export_follows_the_stage_tab(): void
    {
        $this->order('R1', 1);
        $this->order('R2', 1, ['cancelled_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.orders.export', ['stage' => ShipmentOrder::STAGE_CANCELLED, 'range' => 'semua']));

        $rows = $this->rowsAfter($this->sheet($response), 'No');
        $this->assertCount(1, $rows);
        $this->assertSame('R2', $rows[0][1]);

        $items = $this->rowsAfter($this->sheet($response, 1), 'No. Resi');
        $this->assertSame(['R2', 'TAS-1'], [$items[0][0], $items[0][6]]);
    }

    public function test_outbound_and_handover_exports(): void
    {
        $order = $this->order('R1', 2);
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Pembeli', 'tracking_number' => 'R1', 'status' => Outbound::STATUS_DRAFT,
            'shipment_order_id' => $order->id, 'resi_verified_at' => now(),
        ]);
        $outbound->items()->create(['product_id' => $this->tas->id, 'quantity' => 2, 'scanned_quantity' => 2]);

        $rows = $this->rowsAfter($this->sheet($this->actingAs($this->admin)->get(route('admin.outbounds.export'))), 'No');
        $this->assertSame($outbound->code, $rows[0][2]);
        $this->assertEquals(2, $rows[0][10]);

        $rows = $this->rowsAfter($this->sheet($this->actingAs($this->admin)->get(route('admin.outbounds.handover.export'))), 'No');
        $this->assertSame('R1', $rows[0][1]);
        $this->assertSame('J&T', $rows[0][4]);
    }

    public function test_other_list_exports_download(): void
    {
        foreach ([
            'admin.returns.export',
            'admin.adjustments.export',
            'admin.disposals.export',
            'admin.imports.couriers.export',
        ] as $route) {
            $response = $this->actingAs($this->admin)->get(route($route));
            $response->assertOk();
            $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'), $route);
        }

        $this->actingAs($this->admin)->get(route('admin.disposals.export', ['tab' => 'riwayat']))->assertOk();
    }

    public function test_exports_need_the_page_permission(): void
    {
        $user = User::factory()->create();

        foreach (['admin.orders.packing.excel', 'admin.orders.packing.pdf', 'admin.orders.export', 'admin.outbounds.export', 'admin.returns.export'] as $route) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
        }
    }
}

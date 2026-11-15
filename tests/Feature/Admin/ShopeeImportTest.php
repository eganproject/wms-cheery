<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentImportFailure;
use App\Models\ShipmentOrder;
use App\Models\User;
use App\Services\MarketplaceImportService;
use App\Services\OrderPlanner;
use App\Services\ShopeeImportService;
use App\Services\TikTokImportService;
use App\Services\XlsxStreamReader;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Import Resi dari eksport Shopee, dan jaminan bahwa import TikTok tetap
 * berjalan persis seperti sebelumnya.
 */
class ShopeeImportTest extends TestCase
{
    use RefreshDatabase;

    /** Judul kolom eksport Shopee Seller Center (bahasa Indonesia). */
    protected const SHOPEE_HEADER = [
        'No. Pesanan', 'Status Pesanan', 'Alasan Pembatalan', 'Status Pembatalan/ Pengembalian', 'No. Resi',
        'Opsi Pengiriman', 'Antar ke counter/ pick-up', 'Pesanan Harus Dikirimkan Sebelum (Menghindari keterlambatan)',
        'Waktu Pengiriman Diatur', 'Waktu Pesanan Dibuat', 'Waktu Pembayaran Dilakukan', 'Metode Pembayaran',
        'SKU Induk', 'Nama Produk', 'Nomor Referensi SKU', 'Nama Variasi', 'Harga Awal', 'Harga Setelah Diskon',
        'Jumlah', 'Returned quantity', 'Total Harga Produk', 'Total Diskon', 'Jumlah Produk di Pesan',
        'Catatan dari Pembeli', 'Catatan', 'Username (Pembeli)', 'Nama Penerima', 'No. Telepon',
        'Alamat Pengiriman', 'Kota/Kabupaten', 'Provinsi',
    ];

    protected User $admin;

    protected Product $filter;

    protected Product $busi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $this->filter = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 5,
        ]);

        $this->busi = Product::create([
            'sku' => 'BSI-IRIDIUM', 'barcode' => '8991234500073',
            'name' => 'Busi Iridium', 'unit' => 'pcs', 'min_stock' => 10,
        ]);
    }

    /* ------------------------------------------------ Shopee ------------- */

    public function test_a_shopee_excel_export_is_imported_as_shopee_orders(): void
    {
        $this->upload($this->xlsx([
            self::SHOPEE_HEADER,
            $this->shopeeRow(['No. Pesanan' => '2611150A1B2C3D', 'No. Resi' => 'SPXID0001', 'Nomor Referensi SKU' => 'FLT-OLI-STD',
                'Nama Produk' => 'Filter Oli', 'Nama Variasi' => 'Standar', 'Jumlah' => '2', 'Total Harga Produk' => '50000',
                'Catatan dari Pembeli' => 'Bungkus rapi']),
            $this->shopeeRow(['No. Pesanan' => '2611150A1B2C3D', 'No. Resi' => 'SPXID0001', 'Nomor Referensi SKU' => 'BSI-IRIDIUM',
                'Nama Produk' => 'Busi', 'Jumlah' => '1', 'Total Harga Produk' => '75000']),
            $this->shopeeRow(['No. Pesanan' => '2611150E5F6G7H', 'No. Resi' => 'JX0002', 'Nomor Referensi SKU' => '',
                'SKU Induk' => 'FLT-OLI-STD', 'Jumlah' => '3', 'Opsi Pengiriman' => 'J&T Express']),
        ], 'Order.toship.20261115.xlsx'))->assertSessionHas('success');

        $import = ShipmentImport::firstOrFail();
        $this->assertSame('shopee', $import->source);
        $this->assertSame(2, $import->order_count);
        $this->assertSame(0, $import->unmatched_sku_count);

        $order = ShipmentOrder::where('tracking_number', 'SPXID0001')->firstOrFail();
        $this->assertSame('Shopee', $order->marketplace);
        $this->assertSame('2611150A1B2C3D', $order->order_number);
        $this->assertSame('SPX Hemat', $order->courier);
        $this->assertSame('Bungkus rapi', $order->buyer_note);
        $this->assertSame('2026-11-15 09:10:00', $order->order_date->toDateTimeString());
        $this->assertSame('2026-11-15 09:12:00', $order->paid_at->toDateTimeString());
        $this->assertSame(3, $order->totalQuantity());
        $this->assertSame(50000.0, (float) $order->items->firstWhere('sku', 'FLT-OLI-STD')->amount);
        $this->assertSame('Filter Oli - Standar', $order->items->firstWhere('sku', 'FLT-OLI-STD')->product_name);

        // Data pribadi pembeli tidak disimpan.
        $this->assertNull($order->buyer_name);

        // Produk tanpa variasi: SKU Induk dipakai.
        $second = ShipmentOrder::where('tracking_number', 'JX0002')->firstOrFail();
        $this->assertSame('FLT-OLI-STD', $second->items->first()->sku);
        $this->assertTrue($second->items->first()->isMatched());
    }

    public function test_the_shopee_ship_by_date_is_kept_as_a_locked_deadline(): void
    {
        $this->upload($this->xlsx([
            self::SHOPEE_HEADER,
            $this->shopeeRow(['No. Resi' => 'SPXID0001', 'Pesanan Harus Dikirimkan Sebelum (Menghindari keterlambatan)' => '2026-11-17 23:59']),
        ]));

        app(OrderPlanner::class)->refresh(force: true);

        $order = ShipmentOrder::where('tracking_number', 'SPXID0001')->firstOrFail();
        $this->assertSame(OrderPlanner::SOURCE_SHOPEE, $order->ship_by_source);
        $this->assertSame('2026-11-17 23:59:00', $order->ship_by->toDateTimeString());
        $this->assertTrue($order->isShipByLocked());
        // Bukan kuncian dari TikTok: tidak ikut dihitung "Beda dengan TikTok".
        $this->assertFalse($order->differsFromTikTok());
    }

    public function test_an_instant_shopee_order_is_a_priority(): void
    {
        $this->upload($this->xlsx([
            self::SHOPEE_HEADER,
            $this->shopeeRow(['No. Resi' => 'GK0001', 'Opsi Pengiriman' => 'Instant']),
        ]));

        app(OrderPlanner::class)->refresh(force: true);

        $this->assertTrue((bool) ShipmentOrder::where('tracking_number', 'GK0001')->value('is_priority'));
    }

    public function test_shopee_rows_without_waybill_or_sku_are_reported_and_cancelled_orders_marked(): void
    {
        $this->upload($this->xlsx([
            self::SHOPEE_HEADER,
            $this->shopeeRow(['No. Pesanan' => 'A1', 'No. Resi' => '']),
            $this->shopeeRow(['No. Pesanan' => 'A2', 'No. Resi' => 'SPXID0002', 'Nomor Referensi SKU' => '', 'SKU Induk' => '']),
            $this->shopeeRow(['No. Pesanan' => 'A3', 'No. Resi' => 'SPXID0003', 'Status Pesanan' => 'Batal']),
        ]));

        $failures = ShipmentImportFailure::orderBy('row_number')->get();
        $this->assertSame([ShipmentImportFailure::REASON_NO_TRACKING, ShipmentImportFailure::REASON_NO_SKU], $failures->pluck('reason')->all());
        $this->assertStringContainsString('Shopee', $failures[0]->detail);
        $this->assertSame([2, 3], $failures->pluck('row_number')->all());

        $this->assertTrue(ShipmentOrder::where('tracking_number', 'SPXID0003')->firstOrFail()->isCancelled());
    }

    public function test_an_english_shopee_csv_export_is_recognised(): void
    {
        $this->upload($this->csv([
            ['Order ID', 'Order Status', 'Tracking Number*', 'Shipping Option', 'Ship By Date', 'Order Creation Date',
                'Parent SKU Reference No.', 'Product Name', 'SKU Reference No.', 'Variation Name', 'Quantity', 'Product Subtotal'],
            ['2611ABC', 'To ship', 'SPXID0009', 'SPX Standard', '2026-11-18 23:59', '2026-11-15 10:00',
                'FLT', 'Filter Oli', 'FLT-OLI-STD', 'Standar', '2', '40000'],
        ], 'shopee.csv'))->assertSessionHas('success');

        $order = ShipmentOrder::where('tracking_number', 'SPXID0009')->firstOrFail();
        $this->assertSame('Shopee', $order->marketplace);
        $this->assertSame(2, $order->totalQuantity());
        $this->assertSame('2026-11-18 23:59:00', $order->ship_by->toDateTimeString());
    }

    public function test_reuploading_a_shopee_export_skips_known_waybills(): void
    {
        $rows = [self::SHOPEE_HEADER, $this->shopeeRow(['No. Resi' => 'SPXID0001'])];

        $this->upload($this->xlsx($rows));
        $this->upload($this->xlsx($rows));

        $this->assertDatabaseCount('shipment_orders', 1);
        $this->assertSame(1, ShipmentImport::latest('id')->first()->skipped_count);
    }

    /* ------------------------------------------------ TikTok tetap sama --- */

    public function test_a_tiktok_export_is_still_read_by_the_tiktok_reader(): void
    {
        $this->upload($this->xlsx([
            ['Order ID', 'Order Status', 'Seller SKU', 'Product Name', 'Variation', 'Quantity', 'Tracking ID',
                'Shipping Provider Name', 'Normal or Pre-order', 'Paid Time'],
            ['Platform unique order ID.', 'Order status.', 'SKU.', 'Name.', 'Variation.', 'Qty.', 'Tracking.', 'Provider.', 'Kind.', 'Paid.'],
            ['577001', 'To ship', 'FLT-OLI-STD', 'Filter Oli', 'Standar', '2', 'JX111', 'J&T', 'Normal', '15/11/2026 10:00:00'],
        ], 'tiktok.xlsx'))->assertSessionHas('success');

        $import = ShipmentImport::firstOrFail();
        $this->assertSame('tiktok', $import->source);

        $order = ShipmentOrder::where('tracking_number', 'JX111')->firstOrFail();
        $this->assertSame('TikTok Shop', $order->marketplace);
        $this->assertSame('577001', $order->order_number);
        $this->assertFalse($order->is_preorder);
        $this->assertSame('2026-11-15 10:00:00', $order->paid_at->toDateTimeString());
        $this->assertNotSame(OrderPlanner::SOURCE_SHOPEE, $order->ship_by_source);
        // Baris keterangan kolom TikTok tetap dilewati.
        $this->assertSame(0, $import->failed_row_count);
    }

    public function test_a_tiktok_file_with_columns_shared_with_shopee_stays_tiktok(): void
    {
        // "Order ID", "Quantity", "Product Name" juga ada di eksport Shopee
        // berbahasa Inggris; Tracking ID-lah yang menentukan.
        $this->upload($this->csv([
            ['Order ID', 'Tracking ID', 'Seller SKU', 'Product Name', 'Quantity'],
            ['INV-001', 'SPXID111', 'FLT-OLI-STD', 'Filter Oli', '2'],
        ]))->assertSessionHas('success');

        $this->assertSame('TikTok Shop', ShipmentOrder::firstOrFail()->marketplace);
        $this->assertSame('tiktok', ShipmentImport::firstOrFail()->source);
    }

    public function test_an_unrecognised_file_keeps_the_original_tiktok_error(): void
    {
        $this->upload($this->csv([
            ['Nama', 'Alamat'],
            ['Andi', 'Jakarta'],
        ]))->assertSessionHasErrors(['file' => 'Kolom Tracking ID tidak ditemukan. Pastikan berkas adalah eksport pesanan TikTok Shop.']);

        $this->assertDatabaseCount('shipment_imports', 0);
    }

    public function test_tiktok_failure_texts_are_unchanged(): void
    {
        $this->upload($this->csv([
            ['Order ID', 'Tracking ID', 'Seller SKU', 'Variation', 'Quantity'],
            ['INV-1', '', 'FLT-OLI-STD', '', '1'],
            ['INV-2', 'JX2', '', '', '1'],
        ]));

        $details = ShipmentImportFailure::orderBy('row_number')->pluck('detail')->all();

        $this->assertSame([
            'Resi TikTok baru terbit setelah pesanan diatur pengirimannya (Atur Pengiriman). Ekspor ulang setelah itu.',
            'Seller SKU dan Variation sama-sama kosong, jadi barangnya tidak bisa dikenali. Isi Seller SKU produk ini di TikTok Shop.',
        ], $details);
    }

    public function test_the_detector_picks_the_right_reader(): void
    {
        $detector = app(MarketplaceImportService::class);

        $this->assertInstanceOf(ShopeeImportService::class, $detector->detect(
            new UploadedFile($this->xlsx([self::SHOPEE_HEADER, $this->shopeeRow([])]), 'a.xlsx', null, null, true)));

        $tiktok = $detector->detect(new UploadedFile($this->csv([['Tracking ID', 'Seller SKU'], ['X', 'Y']]), 'b.csv', null, null, true));
        $this->assertInstanceOf(TikTokImportService::class, $tiktok);
        $this->assertNotInstanceOf(ShopeeImportService::class, $tiktok);
    }

    public function test_the_stream_reader_can_stop_after_the_first_rows(): void
    {
        $rows = [['Judul A', 'Judul B']];

        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ["a{$i}", "b{$i}"];
        }

        $read = (new XlsxStreamReader)->read($this->xlsx($rows), fn () => null, 10, 10);

        $this->assertSame(10, max(array_keys($read)));
        $this->assertSame(['Judul A', 'Judul B'], array_values($read[1]));

        // Tanpa batas, seluruh lembar tetap terbaca seperti sebelumnya.
        $this->assertCount(51, (new XlsxStreamReader)->read($this->xlsx($rows), fn () => [0, 1]));
    }

    /* ------------------------------------------------ bantuan ----------- */

    /**
     * @param  array<string, string>  $values
     * @return array<int, string>
     */
    protected function shopeeRow(array $values): array
    {
        $defaults = [
            'No. Pesanan' => '2611150A1B2C3D',
            'Status Pesanan' => 'Perlu Dikirim',
            'No. Resi' => 'SPXID0001',
            'Opsi Pengiriman' => 'SPX Hemat',
            'Pesanan Harus Dikirimkan Sebelum (Menghindari keterlambatan)' => '2026-11-17 23:59',
            'Waktu Pesanan Dibuat' => '2026-11-15 09:10',
            'Waktu Pembayaran Dilakukan' => '2026-11-15 09:12',
            'SKU Induk' => 'FLT',
            'Nama Produk' => 'Filter Oli',
            'Nomor Referensi SKU' => 'FLT-OLI-STD',
            'Jumlah' => '1',
            'Total Harga Produk' => '25000',
            'Username (Pembeli)' => 'andi123',
            'Nama Penerima' => 'Andi',
            'No. Telepon' => '6281234567890',
            'Alamat Pengiriman' => 'Jl. Mawar 1',
        ];

        $values += $defaults;

        return array_map(fn (string $title) => $values[$title] ?? '', self::SHOPEE_HEADER);
    }

    protected function upload(string $path, ?string $name = null)
    {
        $name ??= str_ends_with($path, '.csv') ? 'orders.csv' : 'orders.xlsx';

        return $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, $name, null, null, true),
        ]);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function xlsx(array $rows, string $name = 'orders.xlsx'): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                // Disimpan sebagai teks, seperti eksport marketplace.
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], (string) $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'mp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function csv(array $rows, string $name = 'orders.csv'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mp').'.csv';
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        fclose($handle);

        return $path;
    }
}

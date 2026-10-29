<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Susunan kolom eksport "Untuk Dikirim" TikTok Shop (sheet OrderSKUList).
 *
 * Contoh di bawah meniru berkas asli: judul kolom di baris pertama, baris
 * keterangan kolom di baris kedua, lalu satu baris per SKU. Seluruh isinya
 * rekaan; tidak ada data pembeli sungguhan.
 */
class TikTokExportLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected const HEADER = [
        'Order ID', 'Order Status', 'Order Substatus', 'Cancelation/Return Type', 'SKU ID', 'Seller SKU',
        'Product Name', 'Variation', 'Quantity', 'Sku Quantity of return', 'SKU Unit Original Price',
        'Order Amount', 'Created Time', 'Paid Time', 'Tracking ID', 'Delivery Option',
        'Shipping Provider Name', 'Buyer Message', 'Buyer Username', 'Recipient', 'Phone #',
        'Detail Address', 'Package ID', 'Purchase Channel',
    ];

    protected const DESCRIPTION = [
        'Platform unique order ID.', 'Current order status.', '', 'The filed to explain the order is in cancellation or in return/refund.',
        'Platform SKU ID.', 'Seller sku input by the seller in the product system.', 'Platform product name.',
        'Platform SKU variation', 'SKU sold quantity in the order.', 'SKU returned quantity in the order.',
        '1 SKU original price.', 'Order total amount paid by the buyer.', 'Order created time.', 'Order paid time.',
        "The order's tracking number.", "The order's delivery option.", "The order's shipping provider name.",
        '', '', '', '', '', "The order's Package ID", '',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        foreach (['Aluna 14', 'Farasya 2', 'Erika C'] as $sku) {
            Product::create(['sku' => $sku, 'name' => 'Dress '.$sku, 'unit' => 'pcs']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(array $overrides = []): array
    {
        return $overrides + [
            'Order ID' => '580000000000000001',
            'Order Status' => 'Perlu dikirim',
            'Order Substatus' => 'Menunggu pengiriman',
            'Seller SKU' => 'Aluna 14',
            'Product Name' => 'Aluna Dress Bahan Rayon',
            'Variation' => 'Aluna 14',
            'Quantity' => '1',
            'Order Amount' => '58785',
            'Created Time' => '29/10/2026 10:33:55',
            'Tracking ID' => 'JX0000000001',
            'Delivery Option' => 'Pengiriman standar',
            'Shipping Provider Name' => 'J&T Express',
            'Buyer Username' => 'b***contoh',
            'Recipient' => 'Budi Contoh',
            'Phone #' => '(+62)81200000000',
            'Detail Address' => 'Jalan Contoh No. 1',
            'Package ID' => '1200000000000000001',
            'Purchase Channel' => 'Aplikasi TikTok',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sample(): array
    {
        return [
            $this->line(),
            $this->line(['Seller SKU' => 'Farasya 2', 'Variation' => 'Farasya 2', 'Quantity' => '2']),
            $this->line([
                'Order ID' => '580000000000000002', 'Tracking ID' => 'JX0000000002',
                'Seller SKU' => '', 'Product Name' => 'Erika Mini Dress', 'Variation' => 'Erika C',
                'Delivery Option' => 'Hemat', 'Shipping Provider Name' => 'SPX Hemat',
                'Created Time' => '28/10/2026 19:55:14', 'Buyer Message' => 'Tolong dibungkus rapi',
            ]),
            // Belum Atur Pengiriman: resinya belum terbit.
            $this->line(['Order ID' => '580000000000000003', 'Tracking ID' => '']),
        ];
    }

    public function test_the_export_layout_is_imported(): void
    {
        $this->upload($this->sample())->assertSessionHas('error');

        // Dua resi, tiga baris SKU; baris keterangan dan baris tanpa resi dilewati.
        $this->assertDatabaseCount('shipment_orders', 2);
        $this->assertDatabaseCount('shipment_order_items', 3);
        $this->assertSame('tiktok', ShipmentImport::firstOrFail()->source);
    }

    public function test_rows_without_a_tracking_id_are_reported(): void
    {
        $this->upload($this->sample())
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '1 baris dilewati karena Tracking ID belum terbit'));
    }

    public function test_the_needed_columns_are_stored(): void
    {
        $this->upload($this->sample());

        $order = ShipmentOrder::where('tracking_number', 'JX0000000002')->firstOrFail();

        $this->assertSame('580000000000000002', $order->order_number);
        $this->assertSame('TikTok Shop', $order->marketplace);
        $this->assertSame('Perlu dikirim', $order->order_status);
        $this->assertSame('SPX Hemat', $order->courier);
        $this->assertSame('Hemat', $order->shipping_method);
        $this->assertSame('Tolong dibungkus rapi', $order->buyer_note);
        // 28/10/2026 berarti 28 Oktober, lengkap dengan jamnya.
        $this->assertSame('2026-10-28 19:55:14', $order->order_date->toDateTimeString());
    }

    public function test_buyer_personal_data_is_not_stored(): void
    {
        $this->upload($this->sample());

        $this->assertNull(ShipmentOrder::where('tracking_number', 'JX0000000001')->value('buyer_name'));

        foreach (['shipment_orders', 'shipment_order_items', 'shipment_imports'] as $table) {
            $dump = json_encode(DB::table($table)->get());

            foreach (['Budi Contoh', '81200000000', 'Jalan Contoh', 'b***contoh'] as $personal) {
                $this->assertStringNotContainsString($personal, $dump, "{$personal} tidak boleh tersimpan di {$table}.");
            }
        }
    }

    public function test_quantities_per_sku_are_kept(): void
    {
        $this->upload($this->sample());

        $order = ShipmentOrder::where('tracking_number', 'JX0000000001')->firstOrFail();

        $this->assertSame(3, $order->totalQuantity());
        $this->assertTrue($order->isFullyMatched());
        $this->assertEqualsCanonicalizing(['Aluna 14', 'Farasya 2'], $order->items->pluck('sku')->all());
    }

    public function test_the_variation_stands_in_for_an_empty_seller_sku(): void
    {
        $this->upload($this->sample());

        $item = ShipmentOrder::where('tracking_number', 'JX0000000002')->firstOrFail()->items->sole();

        $this->assertSame('Erika C', $item->sku);
        $this->assertNotNull($item->product_id);
        $this->assertSame('Erika Mini Dress - Erika C', $item->product_name);
    }

    public function test_a_file_where_no_waybill_has_been_issued_is_refused_with_a_hint(): void
    {
        $this->upload([
            $this->line(['Tracking ID' => '']),
            $this->line(['Order ID' => '580000000000000002', 'Tracking ID' => '']),
        ])->assertSessionHasErrors(['file' => 'Tracking ID masih kosong pada 2 baris. Resi TikTok baru terbit setelah pesanan diatur pengirimannya (Atur Pengiriman), jadi ekspor ulang setelah itu.']);

        $this->assertDatabaseCount('shipment_imports', 0);
    }

    public function test_a_cancelled_order_is_flagged(): void
    {
        $this->upload([$this->line(['Order Status' => 'Dibatalkan'])]);

        $this->assertTrue(ShipmentOrder::firstOrFail()->isCancelled());
    }

    public function test_an_old_ginee_export_is_refused(): void
    {
        $this->uploadRaw([
            ['Nomor Pesanan', 'Nomor Resi', 'SKU', 'Jumlah'],
            ['250805-A', 'SPXID55501', 'Aluna 14', 1],
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('shipment_orders', 0);
    }

    public function test_the_import_page_shows_the_courier(): void
    {
        $this->upload($this->sample());

        $this->actingAs($this->admin)
            ->get(route('admin.imports.index', ['range' => \App\Support\DateRange::ALL]))
            ->assertOk()
            ->assertSee('J&amp;T Express', false)
            ->assertSee('SPX Hemat')
            ->assertSee('Tolong dibungkus rapi');
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function upload(array $lines)
    {
        $rows = [self::HEADER, self::DESCRIPTION];

        foreach ($lines as $line) {
            $rows[] = array_map(fn (string $column) => $line[$column] ?? '', self::HEADER);
        }

        return $this->uploadRaw($rows);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function uploadRaw(array $rows)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('OrderSKUList');

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                // TikTok menyimpan seluruh sel sebagai teks, termasuk angka.
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], (string) $value, DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'tiktok').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, 'Untuk Dikirim pesanan.xlsx', null, null, true),
        ]);
    }
}

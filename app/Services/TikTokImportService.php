<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Membaca berkas eksport pesanan TikTok Shop (xlsx / csv) menjadi data resi.
 *
 * Eksport TikTok berisi 60-an kolom, termasuk harga, diskon, dan data pribadi
 * pembeli. Hanya kolom yang dipakai gudang yang diambil; sisanya, terutama
 * nama, nomor HP, dan alamat pembeli, sengaja tidak disimpan.
 *
 * Baris kedua eksport TikTok berisi keterangan tiap kolom dalam bahasa
 * Inggris, bukan pesanan, dan ikut dilewati.
 */
class TikTokImportService
{
    public const MARKETPLACE = 'TikTok Shop';

    /**
     * Judul kolom TikTok yang dibaca, dalam huruf kecil tanpa spasi.
     *
     * @var array<string, array<int, string>>
     */
    protected array $columns = [
        'tracking_number' => ['trackingid'],
        'order_number' => ['orderid'],
        'sku' => ['sellersku'],
        'variation' => ['variation'],
        'product_name' => ['productname'],
        'quantity' => ['quantity'],
        'order_status' => ['orderstatus'],
        'courier' => ['shippingprovidername'],
        'shipping_method' => ['deliveryoption'],
        'order_date' => ['createdtime'],
        'buyer_note' => ['buyermessage'],
    ];

    /** Baris yang dilewati karena resinya belum terbit pada import terakhir. */
    protected int $skippedWithoutTracking = 0;

    public function skippedWithoutTracking(): int
    {
        return $this->skippedWithoutTracking;
    }

    /**
     * Proses berkas dan simpan hasilnya.
     */
    public function import(UploadedFile $file): ShipmentImport
    {
        $this->skippedWithoutTracking = 0;

        $rows = $this->readRows($file);

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Berkas tidak berisi data apa pun.',
            ]);
        }

        [$header, $headerIndex] = $this->locateHeader($rows);

        if (! isset($header['tracking_number'])) {
            throw ValidationException::withMessages([
                'file' => 'Kolom Tracking ID tidak ditemukan. Pastikan berkas adalah eksport pesanan TikTok Shop.',
            ]);
        }

        if (! isset($header['sku']) && ! isset($header['variation'])) {
            throw ValidationException::withMessages([
                'file' => 'Kolom Seller SKU tidak ditemukan. Pastikan berkas adalah eksport pesanan TikTok Shop.',
            ]);
        }

        // Baris judul atau keterangan di atas header ikut dibuang.
        $rows = $rows->slice($headerIndex + 1)
            ->reject(fn (array $row) => $this->isDescriptionRow($row, $header))
            ->values();

        $orders = $this->groupRows($rows, $header);

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => $this->skippedWithoutTracking > 0
                    ? "Tracking ID masih kosong pada {$this->skippedWithoutTracking} baris. Resi TikTok baru terbit setelah pesanan diatur pengirimannya (Atur Pengiriman), jadi ekspor ulang setelah itu."
                    : 'Tidak ada baris dengan Tracking ID dan Seller SKU yang bisa diproses.',
            ]);
        }

        return $this->persist($file, $header, $rows->count(), $orders);
    }

    /* ------------------------------------------------------ membaca ------ */

    /**
     * @return Collection<int, array<int, string>>
     */
    protected function readRows(UploadedFile $file): Collection
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return $extension === 'csv' || $extension === 'txt'
            ? $this->readCsv($file)
            : $this->readSpreadsheet($file);
    }

    /**
     * @return Collection<int, array<int, string>>
     */
    protected function readCsv(UploadedFile $file): Collection
    {
        $contents = file_get_contents($file->getRealPath());

        // Buang BOM UTF-8 agar judul kolom pertama tetap terbaca.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        $delimiter = $this->detectDelimiter($contents);

        $rows = collect();
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rows->push(array_map(fn ($value) => trim((string) $value), $row));
        }

        fclose($handle);

        return $rows->filter(fn (array $row) => collect($row)->filter(fn ($v) => $v !== '')->isNotEmpty())->values();
    }

    protected function detectDelimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\n") ?: '';

        $counts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];

        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * @return Collection<int, array<int, string>>
     */
    protected function readSpreadsheet(UploadedFile $file): Collection
    {
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($file->getRealPath())->getActiveSheet();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'file' => 'Berkas tidak bisa dibaca. Pastikan formatnya .xlsx, .xls, atau .csv dari eksport TikTok Shop.',
            ]);
        }

        return collect($sheet->toArray(null, true, false, false))
            ->map(fn (array $row) => array_map(fn ($value) => trim((string) $value), $row))
            ->filter(fn (array $row) => collect($row)->filter(fn ($v) => $v !== '')->isNotEmpty())
            ->values();
    }

    /* ------------------------------------------------------ pemetaan ----- */

    /**
     * Cari baris mana yang menjadi judul kolom: baris pertama di antara
     * beberapa baris awal yang memuat kolom Tracking ID.
     *
     * @param  Collection<int, array<int, string>>  $rows
     * @return array{0: array<string, int>, 1: int}
     */
    protected function locateHeader(Collection $rows): array
    {
        foreach ($rows->take(10) as $index => $row) {
            $header = $this->mapHeader($row);

            if (isset($header['tracking_number'])) {
                return [$header, $index];
            }
        }

        return [[], 0];
    }

    /**
     * Judul kolom dicocokkan persis, supaya "Order Substatus" atau
     * "Sku Quantity of return" tidak terbaca sebagai kolom lain.
     *
     * @param  array<int, string>  $headerRow
     * @return array<string, int>
     */
    protected function mapHeader(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $title) {
            $key = $this->slug($title);

            foreach ($this->columns as $field => $names) {
                if (! isset($map[$field]) && in_array($key, $names, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Baris keterangan kolom ("Platform unique order ID.") langsung di bawah
     * judul. Nomor pesanan TikTok tidak pernah berisi spasi.
     *
     * @param  array<int, string>  $row
     * @param  array<string, int>  $header
     */
    protected function isDescriptionRow(array $row, array $header): bool
    {
        $orderNumber = $this->value($row, $header, 'order_number');

        return $orderNumber !== null && preg_match('/\s/', $orderNumber) === 1;
    }

    protected function slug(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $header
     */
    protected function value(array $row, array $header, string $field): ?string
    {
        if (! isset($header[$field])) {
            return null;
        }

        $value = $row[$header[$field]] ?? null;

        return blank($value) ? null : trim((string) $value);
    }

    /* ------------------------------------------------------ pengelompokan */

    /**
     * Satu pesanan bisa terdiri dari beberapa baris produk, jadi baris
     * digabungkan berdasarkan nomor resi.
     *
     * @param  Collection<int, array<int, string>>  $rows
     * @param  array<string, int>  $header
     * @return Collection<string, array<string, mixed>>
     */
    protected function groupRows(Collection $rows, array $header): Collection
    {
        $orders = collect();

        foreach ($rows as $row) {
            $tracking = $this->value($row, $header, 'tracking_number');
            $variation = $this->value($row, $header, 'variation');

            // Seller SKU kadang kosong pada produk lama; variasinya biasanya
            // sama dengan SKU di master barang. Bila tetap tidak cocok, baris
            // itu ditandai "SKU belum cocok" alih-alih hilang diam-diam.
            $sku = $this->value($row, $header, 'sku') ?? $variation;

            if (blank($sku)) {
                continue;
            }

            if (blank($tracking)) {
                $this->skippedWithoutTracking++;

                continue;
            }

            $key = strtoupper(preg_replace('/\s+/', '', $tracking));

            if (! $orders->has($key)) {
                $orders->put($key, [
                    'tracking_number' => $tracking,
                    'order_number' => $this->value($row, $header, 'order_number'),
                    'marketplace' => self::MARKETPLACE,
                    'order_status' => $this->value($row, $header, 'order_status'),
                    'courier' => $this->value($row, $header, 'courier'),
                    'shipping_method' => $this->value($row, $header, 'shipping_method'),
                    'buyer_note' => $this->value($row, $header, 'buyer_note'),
                    'order_date' => $this->parseDate($this->value($row, $header, 'order_date')),
                    'items' => [],
                ]);
            }

            $order = $orders->get($key);
            $skuKey = strtoupper(trim($sku));
            $quantity = max(1, (int) preg_replace('/[^0-9]/', '', (string) $this->value($row, $header, 'quantity')) ?: 1);

            // SKU yang sama dalam satu resi dijumlahkan.
            if (isset($order['items'][$skuKey])) {
                $order['items'][$skuKey]['quantity'] += $quantity;
            } else {
                $order['items'][$skuKey] = [
                    'sku' => trim($sku),
                    'product_name' => $this->productName($this->value($row, $header, 'product_name'), $variation),
                    'quantity' => $quantity,
                ];
            }

            $orders->put($key, $order);
        }

        return $orders;
    }

    protected function productName(?string $name, ?string $variation): ?string
    {
        $label = trim(implode(' - ', array_filter([$name, $variation])));

        return $label === '' ? null : Str::limit($label, 250, '');
    }

    protected function parseDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        // TikTok menulis tanggal sebagai hari/bulan/tahun, mis. 29/10/2026 19:55:14.
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y'] as $format) {
            try {
                return \Illuminate\Support\Carbon::createFromFormat($format, $value)->toDateTimeString();
            } catch (\Throwable) {
                // Coba format berikutnya.
            }
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /* ------------------------------------------------------ penyimpanan -- */

    /**
     * @param  array<string, int>  $header
     * @param  Collection<string, array<string, mixed>>  $orders
     */
    protected function persist(UploadedFile $file, array $header, int $rowCount, Collection $orders): ShipmentImport
    {
        // SKU adalah kunci pencocokan ke master barang.
        $products = Product::pluck('id', 'sku')
            ->mapWithKeys(fn ($id, $sku) => [strtoupper(trim($sku)) => $id]);

        return DB::transaction(function () use ($file, $header, $rowCount, $orders, $products) {
            $import = ShipmentImport::create([
                'filename' => $file->getClientOriginalName(),
                'source' => 'tiktok',
                'row_count' => $rowCount,
                'detected_columns' => array_keys($header),
                'user_id' => auth()->id(),
            ]);

            $itemCount = 0;
            $unmatched = 0;

            foreach ($orders as $data) {
                $items = $data['items'];
                unset($data['items']);

                $order = $this->upsertOrder($import, $data);

                // Isi pesanan selalu diambil ulang dari berkas terbaru.
                $order->items()->delete();

                foreach ($items as $item) {
                    $productId = $products[strtoupper(trim($item['sku']))] ?? null;

                    $order->items()->create($item + ['product_id' => $productId]);

                    $itemCount++;
                    $productId ?: $unmatched++;
                }
            }

            $import->update([
                'order_count' => $orders->count(),
                'item_count' => $itemCount,
                'unmatched_sku_count' => $unmatched,
            ]);

            return $import->refresh();
        });
    }

    /**
     * Perbarui baris resi yang sudah ada, atau buat baru.
     *
     * Dulu baris lama dihapus lalu dibuat dari nol. Kolom shipment_order_id
     * pada dokumen barang keluar diatur nullOnDelete, sehingga setiap import
     * ulang diam-diam memutus tautan dokumen yang sudah ada — termasuk yang
     * sudah dikirim, yang lalu muncul lagi sebagai "Belum QC" di halaman
     * Status Resi padahal stoknya sudah berkurang.
     *
     * @param  array<string, mixed>  $data
     */
    protected function upsertOrder(ShipmentImport $import, array $data): ShipmentOrder
    {
        $order = ShipmentOrder::firstOrNew(['tracking_number' => $data['tracking_number']]);

        // Import terbaru yang menyebut resi ini menjadi pemiliknya.
        $order->fill($data + ['shipment_import_id' => $import->id]);

        // Berkas import hanya boleh menambahkan pembatalan, tidak pernah
        // mencabutnya: petugas yang menandai batal dari aplikasi marketplace
        // biasanya tahu lebih dulu daripada berkas yang diekspor belakangan.
        if (ShipmentOrder::looksCancelled($data['order_status'] ?? null) && ! $order->isCancelled()) {
            $order->cancelled_at = now();
            $order->cancelled_by = null;
            $order->cancellation_reason = null;
        }

        $order->save();

        return $order;
    }
}

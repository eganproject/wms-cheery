<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentImportFailure;
use App\Models\ShipmentOrder;
use App\Models\ShipmentOrderItem;
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

    /**
     * Proses berkas dan simpan hasilnya.
     *
     * Baris yang tidak bisa masuk tidak menggagalkan seluruh berkas: ia
     * dicatat bersama alasannya dan bisa diunduh dari halaman detail import.
     * Hanya berkas yang memang bukan eksport TikTok yang ditolak utuh.
     */
    public function import(UploadedFile $file): ShipmentImport
    {
        $rows = $this->readRows($file);

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Berkas tidak berisi data apa pun.',
            ]);
        }

        [$header, $headerLine] = $this->locateHeader($rows);

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
        $rows = $rows
            ->filter(fn (array $row, int $line) => $line > $headerLine)
            ->reject(fn (array $row) => $this->isDescriptionRow($row, $header));

        [$orders, $failures] = $this->groupRows($rows, $header);

        [$orders, $failures, $cancellations] = $this->rejectRedundantWaybills($orders, $failures);

        return $this->persist($file, $header, $rows->count(), $orders, $failures, $cancellations);
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

        $line = 0;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rows->put(++$line, array_map(fn ($value) => trim((string) $value), $row));
        }

        fclose($handle);

        return $rows->filter(fn (array $row) => collect($row)->filter(fn ($v) => $v !== '')->isNotEmpty());
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

        // Kunci koleksi = nomor baris di Excel, dipakai pada laporan baris gagal.
        return collect($sheet->toArray(null, true, false, false))
            ->mapWithKeys(fn (array $row, int $index) => [$index + 1 => array_map(fn ($value) => trim((string) $value), $row)])
            ->filter(fn (array $row) => collect($row)->filter(fn ($v) => $v !== '')->isNotEmpty());
    }

    /* ------------------------------------------------------ pemetaan ----- */

    /**
     * Cari baris mana yang menjadi judul kolom: baris pertama di antara
     * beberapa baris awal yang memuat kolom Tracking ID. Yang dikembalikan
     * adalah nomor barisnya di berkas.
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
     * digabungkan berdasarkan nomor resi. Baris yang tidak bisa dipakai
     * dikumpulkan terpisah bersama alasannya.
     *
     * @param  Collection<int, array<int, string>>  $rows
     * @param  array<string, int>  $header
     * @return array{0: Collection<string, array<string, mixed>>, 1: Collection<int, array<string, mixed>>}
     */
    protected function groupRows(Collection $rows, array $header): array
    {
        $orders = collect();
        $failures = collect();

        foreach ($rows as $rowNumber => $row) {
            $tracking = $this->value($row, $header, 'tracking_number');
            $variation = $this->value($row, $header, 'variation');

            // Seller SKU kadang kosong pada produk lama; variasinya biasanya
            // sama dengan SKU di master barang. Bila tetap tidak cocok, baris
            // itu ditandai "SKU belum cocok" alih-alih hilang diam-diam.
            $sku = $this->value($row, $header, 'sku') ?? $variation;

            $line = [
                'row_number' => $rowNumber,
                'order_number' => $this->value($row, $header, 'order_number'),
                'tracking_number' => $tracking,
                'sku' => $sku,
                'product_name' => $this->productName($this->value($row, $header, 'product_name'), $variation),
                'quantity' => max(1, (int) preg_replace('/[^0-9]/', '', (string) $this->value($row, $header, 'quantity')) ?: 1),
            ];

            if (blank($tracking)) {
                $failures->push($line + [
                    'reason' => ShipmentImportFailure::REASON_NO_TRACKING,
                    'detail' => 'Resi TikTok baru terbit setelah pesanan diatur pengirimannya (Atur Pengiriman). Ekspor ulang setelah itu.',
                ]);

                continue;
            }

            if (blank($sku)) {
                $failures->push($line + [
                    'reason' => ShipmentImportFailure::REASON_NO_SKU,
                    'detail' => 'Seller SKU dan Variation sama-sama kosong, jadi barangnya tidak bisa dikenali. Isi Seller SKU produk ini di TikTok Shop.',
                ]);

                continue;
            }

            $key = $this->key($tracking);

            if (! $orders->has($key)) {
                $orders->put($key, [
                    'tracking_number' => $tracking,
                    'order_number' => $line['order_number'],
                    'marketplace' => self::MARKETPLACE,
                    'order_status' => $this->value($row, $header, 'order_status'),
                    'courier' => $this->value($row, $header, 'courier'),
                    'shipping_method' => $this->value($row, $header, 'shipping_method'),
                    'buyer_note' => $this->value($row, $header, 'buyer_note'),
                    'order_date' => $this->parseDate($this->value($row, $header, 'order_date')),
                    'items' => [],
                    'lines' => [],
                ]);
            }

            $order = $orders->get($key);
            $order['lines'][] = $line;
            $skuKey = strtoupper(trim($sku));

            // SKU yang sama dalam satu resi dijumlahkan.
            if (isset($order['items'][$skuKey])) {
                $order['items'][$skuKey]['quantity'] += $line['quantity'];
            } else {
                $order['items'][$skuKey] = [
                    'sku' => trim($sku),
                    'product_name' => $line['product_name'],
                    'quantity' => $line['quantity'],
                ];
            }

            $orders->put($key, $order);
        }

        return [$orders, $failures];
    }

    protected function key(string $tracking): string
    {
        return strtoupper(preg_replace('/\s+/', '', $tracking));
    }

    /* ------------------------------------------------------ redundansi --- */

    /**
     * Cegat resi yang berulang, di dalam berkas maupun terhadap data yang
     * sudah ada. Resi yang dicegat tidak menyentuh data lama sama sekali,
     * kecuali tanda batal: berkas boleh menambahkan pembatalan, karena
     * melewatkannya justru bisa membuat pesanan batal tetap dikirim.
     *
     * @param  Collection<string, array<string, mixed>>  $orders
     * @param  Collection<int, array<string, mixed>>  $failures
     * @return array{0: Collection<string, array<string, mixed>>, 1: Collection<int, array<string, mixed>>, 2: Collection<int, ShipmentOrder>}
     */
    protected function rejectRedundantWaybills(Collection $orders, Collection $failures): array
    {
        $reject = function (string $key, string $reason, string $detail) use (&$orders, $failures) {
            foreach ($orders->get($key)['lines'] as $line) {
                $failures->push($line + ['reason' => $reason, 'detail' => $detail]);
            }

            $orders->forget($key);
        };

        // 1. Satu resi di berkas dipakai oleh beberapa Order ID.
        foreach ($orders as $key => $order) {
            $numbers = collect($order['lines'])->pluck('order_number')->filter()->unique();

            if ($numbers->count() > 1) {
                $reject($key, ShipmentImportFailure::REASON_TRACKING_SHARED,
                    "Resi {$order['tracking_number']} tertulis untuk Order ID {$numbers->implode(', ')}. Satu resi hanya boleh untuk satu pesanan; periksa pesanannya di TikTok Shop.");
            }
        }

        // 2. Satu Order ID di berkas muncul dengan beberapa resi.
        $orders->groupBy(fn (array $order) => $order['order_number'] ?? '', true)
            ->reject(fn (Collection $group, string $number) => $number === '' || $group->count() < 2)
            ->each(function (Collection $group, string $number) use ($reject) {
                $waybills = $group->pluck('tracking_number')->implode(', ');

                foreach ($group->keys() as $key) {
                    $reject($key, ShipmentImportFailure::REASON_ORDER_SPLIT,
                        "Order ID {$number} muncul dengan resi {$waybills}. Pastikan resi mana yang berlaku sebelum diimport ulang.");
                }
            });

        // 3. Resi yang sudah pernah diimport.
        $cancellations = collect();

        $existing = $orders->isEmpty() ? collect() : ShipmentOrder::query()
            ->with('import')
            ->whereIn(DB::raw("UPPER(REPLACE(tracking_number, ' ', ''))"), $orders->keys()->all())
            ->get()
            ->keyBy(fn (ShipmentOrder $order) => $this->key($order->tracking_number));

        foreach ($existing as $key => $old) {
            $order = $orders->get($key);

            if (! $order) {
                continue;
            }

            $detail = "Resi ini sudah diimport {$old->created_at?->translatedFormat('d M Y H:i')}"
                .($old->import ? " dari berkas \"{$old->import->filename}\"" : '')
                .($old->order_number ? " untuk Order ID {$old->order_number}" : '')
                .'. Data lama tidak diubah.';

            if (ShipmentOrder::looksCancelled($order['order_status']) && ! $old->isCancelled()) {
                $cancellations->push($old);
                $detail .= ' Status batal dari berkas ini tetap dicatat pada resi lama.';
            }

            $reject($key, ShipmentImportFailure::REASON_TRACKING_IN_SYSTEM, $detail);
        }

        // 4. Order ID yang sudah punya resi lain (dan belum dibatalkan).
        $numbers = $orders->pluck('order_number')->filter()->unique()->values();

        $taken = $numbers->isEmpty() ? collect() : ShipmentOrder::query()
            ->whereIn('order_number', $numbers->all())
            ->whereNull('cancelled_at')
            ->get(['order_number', 'tracking_number'])
            ->groupBy('order_number');

        foreach ($orders as $key => $order) {
            $others = $taken->get($order['order_number'] ?? '');

            if ($others) {
                $reject($key, ShipmentImportFailure::REASON_ORDER_IN_SYSTEM,
                    "Order ID {$order['order_number']} sudah terdaftar dengan resi {$others->pluck('tracking_number')->implode(', ')}. Bila resi lama memang diganti, batalkan resi lama dulu lalu import ulang.");
            }
        }

        return [$orders, $failures->sortBy('row_number')->values(), $cancellations];
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
     * @param  Collection<int, array<string, mixed>>  $failures
     * @param  Collection<int, ShipmentOrder>  $cancellations
     */
    protected function persist(
        UploadedFile $file,
        array $header,
        int $rowCount,
        Collection $orders,
        Collection $failures,
        Collection $cancellations,
    ): ShipmentImport {
        // SKU adalah kunci pencocokan ke master barang.
        $products = Product::pluck('id', 'sku')
            ->mapWithKeys(fn ($id, $sku) => [strtoupper(trim($sku)) => $id]);

        $import = DB::transaction(function () use ($file, $header, $rowCount, $orders, $failures, $cancellations, $products) {
            $import = ShipmentImport::create([
                'filename' => $file->getClientOriginalName(),
                'source' => 'tiktok',
                'row_count' => $rowCount,
                'detected_columns' => array_keys($header),
                'user_id' => auth()->id(),
            ]);

            $itemCount = 0;
            $unmatched = 0;
            $itemRows = [];
            $now = now();
            $itemColumns = array_flip((new ShipmentOrderItem)->getFillable());

            foreach ($orders as $data) {
                $items = $data['items'];
                unset($data['items'], $data['lines']);

                $order = $this->createOrder($import, $data);

                foreach ($items as $item) {
                    $productId = $products[strtoupper(trim($item['sku']))] ?? null;

                    // Baris barang ditulis borongan di bawah: satu berkas
                    // berisi ribuan resi, dan satu insert per baris membuat
                    // import terasa lama.
                    $itemRows[] = array_intersect_key($item, $itemColumns) + [
                        'shipment_order_id' => $order->id,
                        'product_id' => $productId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $itemCount++;
                    $productId ?: $unmatched++;
                }
            }

            foreach (array_chunk($itemRows, 500) as $chunk) {
                ShipmentOrderItem::insert($chunk);
            }

            foreach ($failures->chunk(500) as $chunk) {
                $now = now();

                ShipmentImportFailure::insert($chunk->map(fn (array $failure) => [
                    'shipment_import_id' => $import->id,
                    'order_number' => Str::limit((string) $failure['order_number'], 250, '') ?: null,
                    'tracking_number' => Str::limit((string) $failure['tracking_number'], 250, '') ?: null,
                    'sku' => Str::limit((string) $failure['sku'], 250, '') ?: null,
                    'product_name' => $failure['product_name'],
                    'quantity' => $failure['quantity'],
                    'row_number' => $failure['row_number'],
                    'reason' => $failure['reason'],
                    'detail' => $failure['detail'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            foreach ($cancellations as $order) {
                $order->forceFill([
                    'cancelled_at' => now(),
                    'cancelled_by' => null,
                    'cancellation_reason' => null,
                ])->save();
            }

            $import->update([
                'order_count' => $orders->count(),
                'item_count' => $itemCount,
                'unmatched_sku_count' => $unmatched,
                'failed_row_count' => $failures->count(),
            ]);

            return $import->refresh();
        });

        // Marketplace, toko, atau kurir baru bisa ikut masuk bersama berkas ini.
        ShipmentOrder::forgetFilterOptions();

        return $import;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createOrder(ShipmentImport $import, array $data): ShipmentOrder
    {
        $order = new ShipmentOrder($data + ['shipment_import_id' => $import->id]);

        if (ShipmentOrder::looksCancelled($data['order_status'] ?? null)) {
            $order->cancelled_at = now();
        }

        $order->save();

        return $order;
    }
}

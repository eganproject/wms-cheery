<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockApiSyncRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class StockApiSyncService
{
    protected static ?bool $ready = null;

    /**
     * Apakah tabel sinkronisasinya sudah ada (belum ada selama migrasi awal).
     *
     * Diingat sekali per proses: sinkronisasi berjalan pada setiap
     * pergerakan stok, dan menanyakan skema basis data tiap kali berarti satu
     * kueri tambahan per barang pada setiap serah terima paket.
     */
    protected static function ready(): bool
    {
        // Hanya jawaban "ya" yang diingat, supaya tabel yang baru dibuat di
        // tengah proses (mis. saat migrasi) tetap terbaca.
        if (static::$ready !== true) {
            static::$ready = Schema::hasTable('stock_api_sync_records');
        }

        return static::$ready;
    }

    public static function sync(Product $product, ?Carbon $changedAt = null): void
    {
        if (! static::ready()) {
            return;
        }

        /*
            Paket bundling tidak dilaporkan ke pusat.

            Ia tidak punya saldo sendiri, jadi angka yang bisa dikirim hanyalah
            turunan dari komponennya — dan turunan itu punya sifat yang berbeda
            dari SKU biasa: dua paket yang berbagi komponen sama-sama melaporkan
            ketersediaan penuh, sehingga penjumlahannya melebih-lebihkan.

            Sistem di seberang tidak tahu perbedaan itu dan tidak ada di bawah
            kendali kita. Mengirimkannya berarti mengubah arti data yang sudah
            dibaca orang lain tanpa bisa mengujinya lebih dulu — jadi untuk
            sekarang pusat melihat kumpulan SKU yang persis sama seperti
            sebelum paket ada. Kalau kelak pusat memang membutuhkannya, itu
            keputusan tersendiri, bukan efek samping fitur ini.
        */
        if ($product->isBundle()) {
            // Barang biasa yang diubah menjadi paket: SKU-nya tidak boleh
            // tertinggal di pusat sebagai stok aktif dengan angka lama.
            static::retire($product->id);

            return;
        }

        $data = [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category,
            'uom' => $product->unit ?: 'pcs',
            // API pusat hanya menerima stok layak jual. Stok rusak sengaja tidak dijumlahkan.
            'qty' => max(0, (int) $product->stock),
            'min_qty' => (int) $product->min_stock ?: null,
            'status' => $product->is_active ? 'active' : 'inactive',
            'source_updated_at' => $changedAt ?? $product->updated_at ?? now(),
        ];

        /*
            Satu catatan per SKU, karena pusat membaca menurut SKU.

            Bila SKU barangnya diganti, catatan SKU lama tidak ikut berganti
            nama: pusat akan kehilangan SKU lama tanpa pernah tahu ia sudah
            tidak ada, dan angka lamanya tertinggal di sana. Catatan lama
            ditandai dihapus, lalu SKU baru mendapat catatannya sendiri —
            atau memakai ulang catatan SKU itu bila dulu pernah ada.
        */
        $sku = static::normalize($product->sku);

        $records = StockApiSyncRecord::query()
            ->where('product_id', $product->id)
            ->orWhere('sku', $product->sku)
            ->get();

        $record = $records->first(fn (StockApiSyncRecord $record) => static::normalize($record->sku) === $sku);

        static::retire($product->id, except: $record?->id);

        $record
            ? $record->fill($data)->save()
            : StockApiSyncRecord::create($data);
    }

    /**
     * Tandai dihapus seluruh catatan milik barang ini, kecuali satu.
     */
    protected static function retire(int $productId, ?int $except = null): void
    {
        StockApiSyncRecord::query()
            ->where('product_id', $productId)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->each(fn (StockApiSyncRecord $record) => $record->update([
                'product_id' => null,
                'qty' => 0,
                'min_qty' => null,
                'status' => 'deleted',
                'source_updated_at' => now(),
            ]));
    }

    protected static function normalize(?string $sku): string
    {
        return mb_strtoupper(trim((string) $sku));
    }

    public static function markDeleted(Product $product): void
    {
        if (! static::ready()) {
            return;
        }
        static::retire($product->id);
    }
}

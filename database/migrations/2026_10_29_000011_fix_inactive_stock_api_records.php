<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Barang yang dibuat tanpa menyebut is_active (varian dari halaman produk
 * induk, misalnya) dulu terkirim ke API stok sebagai "inactive", padahal
 * kolomnya bernilai aktif. Catatannya disamakan sekali di sini; yang baru
 * dibuat sudah benar sejak model membawa bawaan kolomnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_api_sync_records')
            ->where('status', 'inactive')
            ->whereIn('product_id', DB::table('products')->where('is_active', true)->select('id'))
            ->update(['status' => 'active', 'source_updated_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};

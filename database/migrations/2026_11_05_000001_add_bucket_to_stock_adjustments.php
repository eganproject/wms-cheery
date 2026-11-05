<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian stok bisa mengoreksi saldo rusak.
 *
 * Sebelumnya saldo rusak yang salah hitung (misalnya barang yang sama
 * terhitung dua kali saat opname) hanya bisa diturunkan dengan dokumen
 * "dibuang" yang tidak pernah terjadi. Sekarang dokumen penyesuaian menyebut
 * saldo mana yang dikoreksinya.
 *
 * Dokumen lama seluruhnya mengoreksi stok layak jual, jadi itulah bawaannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            // good | damaged
            $table->string('bucket')->default('good')->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropColumn('bucket');
        });
    }
};

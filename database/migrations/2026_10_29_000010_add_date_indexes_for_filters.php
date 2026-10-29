<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks untuk saringan tanggal yang paling sering dipakai.
 *
 * Halaman Pesanan dan Riwayat Stok menyaring menurut waktu dibuat, dan
 * bawaannya hari ini. Tanpa indeks, basis data membaca seluruh tabel resi
 * atau kartu stok setiap kali halaman dibuka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};

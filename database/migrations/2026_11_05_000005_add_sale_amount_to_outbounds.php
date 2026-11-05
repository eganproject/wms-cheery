<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Total harga jual pada dokumen Penjualan Manual, dasar omzet penjualan
 * manual di Ringkasan Bisnis. Kosong berarti belum diisi.
 *
 * Sekaligus mengganti nama hak akses "Laporan Owner" menjadi "Ringkasan
 * Bisnis"; slug-nya tetap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbounds', function (Blueprint $table) {
            $table->decimal('sale_amount', 15, 2)->nullable()->after('note');
        });

        DB::table('permissions')->where('slug', 'owner-reports.view')->update([
            'name' => 'Melihat Ringkasan Bisnis (omzet, laba, HPP, ketepatan kirim, retur, kinerja tim)',
        ]);
    }

    public function down(): void
    {
        Schema::table('outbounds', function (Blueprint $table) {
            $table->dropColumn('sale_amount');
        });
    }
};

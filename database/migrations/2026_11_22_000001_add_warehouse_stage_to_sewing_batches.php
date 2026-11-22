<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil potong bisa disimpan dulu di gudang sebelum ke penjahit: batch tanpa
 * penjahit (supplier_id kosong) berstatus "Di Gudang". sent_at = tanggal
 * dikirim ke penjahit; batch lama dianggap dikirim pada tanggal batchnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sewing_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->change();
            $table->date('sent_at')->nullable()->after('date');
        });

        DB::table('sewing_batches')->whereNotNull('supplier_id')->update(['sent_at' => DB::raw('date')]);
    }

    public function down(): void
    {
        Schema::table('sewing_batches', function (Blueprint $table) {
            $table->dropColumn('sent_at');
        });
    }
};

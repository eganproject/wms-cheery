<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch hasil potong bisa diinput manual tanpa Cutting (saldo hasil potong di
 * gudang atau yang sedang dijahit yang sudah berjalan sebelum sistem dipakai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sewing_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('fabric_cutting_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Batch manual tidak punya cutting; kolom dibiarkan boleh kosong.
    }
};

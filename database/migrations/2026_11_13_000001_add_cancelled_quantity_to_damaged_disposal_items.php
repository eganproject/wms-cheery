<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur ke pemasok yang sudah diproses tetapi tidak jadi dikirim bisa
 * dibatalkan: unitnya kembali ke saldo asal dan dicatat di kolom ini, supaya
 * tidak terhitung sebagai sisa yang ditunggu dari pemasok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damaged_disposal_items', function (Blueprint $table) {
            $table->unsignedInteger('cancelled_quantity')->default(0)->after('rejected_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('damaged_disposal_items', function (Blueprint $table) {
            $table->dropColumn('cancelled_quantity');
        });
    }
};

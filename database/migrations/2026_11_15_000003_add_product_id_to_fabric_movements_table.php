<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kain keluar dicatat untuk barang apa (menggantikan isian tujuan).
     * Kolom destination tetap ada untuk riwayat lama.
     */
    public function up(): void
    {
        Schema::table('fabric_movements', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('balance_after')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fabric_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};

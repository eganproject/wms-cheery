<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Foto kain, supaya kain mudah dikenali di gudang dan saat scan. */
    public function up(): void
    {
        Schema::table('fabrics', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('fabrics', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};

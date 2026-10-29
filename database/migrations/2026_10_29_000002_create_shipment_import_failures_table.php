<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris berkas import yang tidak masuk ke sistem, beserta alasannya, supaya
 * bisa diunduh dan diperbaiki.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_imports', function (Blueprint $table) {
            $table->unsignedInteger('failed_row_count')->default(0)->after('unmatched_sku_count');
        });

        Schema::create('shipment_import_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_import_id')->constrained()->cascadeOnDelete();
            // Nomor baris di berkas Excel aslinya, supaya mudah dicari.
            $table->unsignedInteger('row_number');
            $table->string('order_number')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('sku')->nullable();
            $table->string('product_name')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('reason');
            $table->text('detail')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_import_failures');

        Schema::table('shipment_imports', function (Blueprint $table) {
            $table->dropColumn('failed_row_count');
        });
    }
};

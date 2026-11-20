<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cutting & WIP Jahit (percobaan).
 *
 * Semua tambahan: kolom baru boleh kosong, jadi data lama (kain keluar,
 * penerimaan, pemasok) tetap utuh dan tetap terbaca seperti sebelumnya.
 *
 *   kain keluar (scan) → Cutting (per barang + pemotong)
 *   hasil cutting per varian → batch WIP Jahit di penjahit (bertahap)
 *   penerimaan barang dari penjahit → mengurangi batch
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_tailor')->default(false)->after('is_active');
        });

        Schema::create('fabric_cuttings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('cutting')->index();
            $table->timestamp('finished_at')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('fabric_movements', function (Blueprint $table) {
            $table->foreignId('fabric_cutting_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });

        Schema::create('sewing_batches', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('fabric_cutting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sewing_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sewing_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unique(['sewing_batch_id', 'product_id']);
        });

        Schema::table('inbounds', function (Blueprint $table) {
            $table->foreignId('sewing_batch_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inbounds', fn (Blueprint $table) => $table->dropConstrainedForeignId('sewing_batch_id'));
        Schema::dropIfExists('sewing_batch_items');
        Schema::dropIfExists('sewing_batches');
        Schema::table('fabric_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('fabric_cutting_id'));
        Schema::dropIfExists('fabric_cuttings');
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('is_tailor'));
    }
};

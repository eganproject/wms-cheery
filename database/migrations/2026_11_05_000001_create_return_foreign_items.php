<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang bukan milik kita yang ikut datang di paket retur.
 *
 * Dicatat sebagai bukti (keterangan, jumlah, foto) dan tidak pernah masuk
 * stok. Tindak lanjutnya — biasanya klaim ke marketplace — dilacak per
 * dokumen retur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_receipt_foreign_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_receipt_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('photo_path')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('return_receipts', function (Blueprint $table) {
            $table->string('foreign_status', 20)->nullable()->after('note')->index();
            $table->text('foreign_note')->nullable()->after('foreign_status');
            $table->timestamp('foreign_updated_at')->nullable()->after('foreign_note');
            $table->foreignId('foreign_updated_by')->nullable()->after('foreign_updated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('foreign_updated_by');
            $table->dropIndex(['foreign_status']);
            $table->dropColumn(['foreign_status', 'foreign_note', 'foreign_updated_at']);
        });

        Schema::dropIfExists('return_receipt_foreign_items');
    }
};

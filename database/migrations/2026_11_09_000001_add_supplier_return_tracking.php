<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pelacakan barang yang dikembalikan ke pemasok.
 *
 * Dulu dokumen "Dikembalikan ke pemasok" langsung selesai begitu stoknya
 * keluar. Sekarang dokumennya punya tindak lanjut: dikirim (ekspedisi, resi),
 * barang pengganti diterima masuk stok, dan sisa yang tidak diganti ditutup
 * sebagai ditolak pemasok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damaged_disposals', function (Blueprint $table) {
            // belum_dikirim | dikirim | selesai — hanya untuk retur ke pemasok yang sudah diproses.
            $table->string('return_status', 20)->nullable()->after('supplier_id');
            $table->date('shipped_at')->nullable()->after('return_status');
            $table->string('courier', 100)->nullable()->after('shipped_at');
            $table->string('tracking_number', 100)->nullable()->after('courier');
            $table->timestamp('closed_at')->nullable()->after('tracking_number');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->text('close_note')->nullable()->after('closed_by');
            $table->index(['action', 'return_status']);
        });

        Schema::table('damaged_disposal_items', function (Blueprint $table) {
            $table->unsignedInteger('replaced_quantity')->default(0)->after('quantity');
            $table->unsignedInteger('rejected_quantity')->default(0)->after('replaced_quantity');
        });

        // Satu kali penerimaan barang pengganti dari pemasok; boleh bertahap.
        Schema::create('supplier_replacements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('damaged_disposal_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('note', 1000)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_replacement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_replacement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('damaged_disposal_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('quantity');
        });

        // Retur lama yang sudah diproses dianggap sudah dikirim pada tanggal
        // dokumennya, supaya muncul di daftar dan bisa ditindaklanjuti.
        DB::table('damaged_disposals')
            ->where('action', 'dikembalikan')
            ->where('status', 'posted')
            ->update(['return_status' => 'dikirim', 'shipped_at' => DB::raw('date')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_replacement_items');
        Schema::dropIfExists('supplier_replacements');

        Schema::table('damaged_disposal_items', function (Blueprint $table) {
            $table->dropColumn(['replaced_quantity', 'rejected_quantity']);
        });

        Schema::table('damaged_disposals', function (Blueprint $table) {
            $table->dropIndex(['action', 'return_status']);
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['return_status', 'shipped_at', 'courier', 'tracking_number', 'closed_at', 'close_note']);
        });
    }
};

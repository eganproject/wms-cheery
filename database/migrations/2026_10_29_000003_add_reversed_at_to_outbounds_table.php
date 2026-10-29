<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak paket yang dibatalkan setelah serah terima: stoknya dikembalikan dan
 * dokumennya kembali ke draft. Dokumen seperti ini tidak boleh dihapus karena
 * kartu stok menunjuk ke sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbounds', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('posted_at');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbounds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn('reversed_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batas kirim dan kesiapan stok per resi, untuk pesanan Pre Order TikTok.
 *
 * - is_preorder / paid_at dibaca dari berkas TikTok ("Normal or Pre-order",
 *   "Paid Time").
 * - computed_ship_by: batas kirim menurut hitungan sistem (waktu bayar + lama
 *   PO barangnya). Selalu dihitung ulang selama resi belum dikirim.
 * - ship_by: batas kirim yang berlaku. Sama dengan computed_ship_by, kecuali
 *   dikunci orang (manual) atau oleh upload "Harus dikirim menurut TikTok".
 * - is_priority: pengiriman Instan / besok sampai, atau dikunci menurut TikTok.
 * - stock_status: siap / menunggu / sku, hasil pembagian stok ke resi yang
 *   belum dikemas. Dihitung ulang otomatis setiap kali datanya berubah.
 *
 * product_po_periods: riwayat lama PO per produk induk (atau per barang yang
 * tidak punya induk), berlaku mulai tanggal + jam tertentu sampai periode
 * berikutnya dimulai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->boolean('is_preorder')->nullable()->after('order_date');
            $table->timestamp('paid_at')->nullable()->after('is_preorder');
            $table->timestamp('computed_ship_by')->nullable()->after('paid_at');
            $table->unsignedSmallInteger('po_days')->nullable()->after('computed_ship_by');
            $table->timestamp('ship_by')->nullable()->after('po_days');
            $table->string('ship_by_source', 20)->nullable()->after('ship_by');
            $table->foreignId('ship_by_set_by')->nullable()->after('ship_by_source')->constrained('users')->nullOnDelete();
            $table->timestamp('ship_by_set_at')->nullable()->after('ship_by_set_by');
            $table->boolean('is_priority')->default(false)->after('ship_by_set_at');
            $table->string('stock_status', 20)->nullable()->after('is_priority');
            $table->string('stock_note')->nullable()->after('stock_status');

            $table->index('ship_by');
            $table->index('stock_status');
        });

        Schema::table('shipment_imports', function (Blueprint $table) {
            $table->unsignedInteger('skipped_count')->default(0)->after('failed_row_count');
        });

        Schema::create('product_po_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('days');
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['product_group_id', 'starts_at']);
            $table->index(['product_id', 'starts_at']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('product_po_periods');

        Schema::table('shipment_imports', function (Blueprint $table) {
            $table->dropColumn('skipped_count');
        });

        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ship_by_set_by');
            $table->dropIndex(['ship_by']);
            $table->dropIndex(['stock_status']);
            $table->dropColumn([
                'is_preorder', 'paid_at', 'computed_ship_by', 'po_days', 'ship_by',
                'ship_by_source', 'ship_by_set_at', 'is_priority', 'stock_status', 'stock_note',
            ]);
        });
    }
};

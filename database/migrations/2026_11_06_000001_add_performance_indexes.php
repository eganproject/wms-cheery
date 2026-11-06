<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supaya halaman tetap cepat setelah data bertahun-tahun.
 *
 * Resi yang masih dikerjakan gudang hanya beberapa ribu, tetapi untuk tahu
 * mana saja, basis data selama ini memeriksa seluruh resi yang pernah ada
 * (ratusan ribu) satu per satu terhadap dokumen kirimnya. shipped_at
 * menyimpan kapan resi berangkat, sehingga resi yang sudah berangkat bisa
 * dilewati lewat indeks. Tahapannya tetap ditentukan dari dokumen kirim;
 * kolom ini hanya penyaring awal (lihat ShipmentOrder::syncShipped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->timestamp('shipped_at')->nullable()->after('cancellation_reason');
            $table->index(['shipped_at', 'cancelled_at']);
            // Ringkasan Bisnis menyaring menurut tanggal pesanan.
            $table->index('order_date');
        });

        Schema::table('outbounds', function (Blueprint $table) {
            // Mencari dokumen kirim satu resi beserta statusnya. MySQL bisa
            // memakainya untuk kunci asing, jadi down() tidak menghapusnya
            // dan up() tidak membuatnya dua kali.
            if (! Schema::hasIndex('outbounds', ['shipment_order_id', 'status'])) {
                $table->index(['shipment_order_id', 'status']);
            }
            // Dasbor dan laporan menyaring menurut waktu kirim / waktu QC.
            $table->index(['status', 'posted_at']);
            $table->index('resi_verified_at');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // "Terakhir keluar" per barang (Ringkasan Bisnis > Umur Stok,
            // Laporan Stok) bisa dibaca dari indeks saja.
            $table->index(['reference_type', 'type', 'bucket', 'product_id', 'created_at'], 'stock_movements_last_out_index');
        });

        DB::table('shipment_orders')->update([
            'shipped_at' => DB::table('outbounds')
                ->selectRaw('MIN(outbounds.posted_at)')
                ->whereColumn('outbounds.shipment_order_id', 'shipment_orders.id')
                ->where('outbounds.status', 'posted'),
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_last_out_index');
        });

        Schema::table('outbounds', function (Blueprint $table) {
            $table->dropIndex(['resi_verified_at']);
            $table->dropIndex(['status', 'posted_at']);
        });

        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->dropIndex(['order_date']);
            $table->dropIndex(['shipped_at', 'cancelled_at']);
            $table->dropColumn('shipped_at');
        });
    }
};

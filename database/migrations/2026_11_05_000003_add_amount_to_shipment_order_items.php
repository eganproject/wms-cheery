<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai penjualan per baris barang, dari kolom "SKU Subtotal After Discount"
 * pada eksport TikTok. Dipakai Laporan Owner untuk omzet.
 *
 * Kosong untuk resi lama yang diimport sebelum kolom ini dibaca; mengunggah
 * ulang berkasnya melengkapi angka itu tanpa mengubah data lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_order_items', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->nullable()->after('quantity');
        });

        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['slug' => 'owner-reports.view'],
            ['name' => 'Melihat Laporan Owner (omzet, ketepatan kirim, retur, kinerja tim)', 'group' => 'Laporan', 'updated_at' => $now, 'created_at' => $now],
        );

        $permissionId = DB::table('permissions')->where('slug', 'owner-reports.view')->value('id');
        $roleIds = DB::table('roles')->where(fn ($query) => $query
            ->where('is_super_admin', true)->orWhere('slug', 'admin'))->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'owner-reports.view')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();

        Schema::table('shipment_order_items', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};

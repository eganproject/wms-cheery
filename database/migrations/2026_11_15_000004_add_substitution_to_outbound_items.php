<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Substitusi SKU saat QC Kemas.
 *
 * Baris pengganti adalah baris barang biasa (stok bergerak lewat product_id
 * seperti baris lain), ditambah keterangan SKU asli yang digantikannya,
 * alasannya, siapa yang mengganti, dan kapan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_items', function (Blueprint $table) {
            $table->foreignId('substituted_from_id')->nullable()->after('product_id')
                ->constrained('products')->restrictOnDelete();
            $table->string('substitution_reason', 30)->nullable()->after('note');
            $table->string('substitution_note')->nullable()->after('substitution_reason');
            $table->foreignId('substituted_by')->nullable()->after('substitution_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('substituted_at')->nullable()->after('substituted_by');

            $table->index('substituted_at');
        });

        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['slug' => 'outbounds.substitute'],
            [
                'name' => 'Substitusi SKU saat QC Kemas',
                'group' => 'Penjualan (Manual & Marketplace)',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $permission = DB::table('permissions')->where('slug', 'outbounds.substitute')->value('id');
        $roles = DB::table('roles')->where(function ($query): void {
            $query->where('is_super_admin', true)->orWhere('slug', 'admin');
        })->pluck('id');

        foreach ($roles as $roleId) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        Schema::table('outbound_items', function (Blueprint $table) {
            $table->dropIndex(['substituted_at']);
            $table->dropConstrainedForeignId('substituted_by');
            $table->dropConstrainedForeignId('substituted_from_id');
            $table->dropColumn(['substitution_reason', 'substitution_note', 'substituted_at']);
        });

        $ids = DB::table('permissions')->where('slug', 'outbounds.substitute')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

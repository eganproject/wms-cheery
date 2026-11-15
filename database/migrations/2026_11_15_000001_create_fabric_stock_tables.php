<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stok kain: jenis kain, roll (gulungan) ber-QR, kain masuk, mutasi kain,
 * dan pemakaian kain per barang untuk menghitung kebutuhan kain.
 *
 * Stok kain terpisah dari stok barang jadi: satuannya bisa meter, yard, kg,
 * atau lainnya, dan jumlahnya pecahan. Kain hanya keluar lewat scan QR roll.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    protected array $permissions = [
        'fabrics.view' => 'Melihat stok kain, kain masuk/keluar & kebutuhan kain',
        'fabrics.manage' => 'Mengelola jenis kain & pemakaian kain per barang',
        'fabrics.receive' => 'Mencatat kain masuk & mencetak label QR roll',
        'fabrics.issue' => 'Scan kain keluar',
        'fabrics.adjust' => 'Koreksi sisa roll (cek kain) & membatalkan kain keluar',
    ];

    public function up(): void
    {
        Schema::create('fabrics', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60)->unique();
            $table->string('name');
            $table->string('color', 100)->nullable();
            $table->string('unit', 20)->default('meter');
            $table->decimal('min_stock', 12, 2)->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('last_roll_number')->default(0);
            $table->timestamps();
        });

        Schema::create('fabric_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->date('date');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fabric_rolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_id')->constrained()->restrictOnDelete();
            $table->foreignId('fabric_receipt_id')->nullable()->constrained()->nullOnDelete();
            // Isi QR: SKU kain + nomor roll, contoh TYB-HTM-007.
            $table->string('code', 80)->unique();
            $table->unsignedInteger('number');
            $table->decimal('initial_quantity', 12, 2);
            $table->decimal('quantity', 12, 2);
            $table->string('lot', 60)->nullable();
            $table->string('location', 60)->nullable();
            $table->timestamp('emptied_at')->nullable();
            $table->timestamps();

            $table->index(['fabric_id', 'quantity']);
        });

        Schema::create('fabric_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_id')->constrained()->restrictOnDelete();
            $table->foreignId('fabric_roll_id')->constrained()->restrictOnDelete();
            $table->foreignId('fabric_receipt_id')->nullable()->constrained()->nullOnDelete();
            // in = kain masuk, out = kain keluar (scan), adjust = koreksi sisa,
            // cancel = pembatalan kain keluar (mengembalikan ke roll).
            $table->string('type', 10);
            // Bertanda: masuk positif, keluar negatif.
            $table->decimal('quantity', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('destination', 120)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('fabric_movements')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->index(['type', 'moved_at']);
            $table->index(['fabric_id', 'moved_at']);
        });

        Schema::create('fabric_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fabric_id')->constrained()->cascadeOnDelete();
            // Banyaknya kain (satuan kainnya) untuk membuat 1 unit barang.
            $table->decimal('quantity', 10, 3);
            $table->timestamps();

            $table->unique(['product_id', 'fabric_id']);
        });

        $now = now();
        foreach ($this->permissions as $slug => $name) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'group' => 'Stok Kain', 'updated_at' => $now, 'created_at' => $now],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', array_keys($this->permissions))->pluck('id');
        $roleIds = DB::table('roles')->where(function ($query): void {
            $query->where('is_super_admin', true)->orWhere('slug', 'admin');
        })->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        // Staff gudang ikut menerima dan mengeluarkan kain.
        $staff = DB::table('roles')->where('slug', 'staff-gudang')->value('id');
        if ($staff) {
            foreach (DB::table('permissions')->whereIn('slug', ['fabrics.view', 'fabrics.receive', 'fabrics.issue'])->pluck('id') as $permissionId) {
                DB::table('permission_role')->updateOrInsert(['role_id' => $staff, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_usages');
        Schema::dropIfExists('fabric_movements');
        Schema::dropIfExists('fabric_rolls');
        Schema::dropIfExists('fabric_receipts');
        Schema::dropIfExists('fabrics');

        $ids = DB::table('permissions')->whereIn('slug', array_keys($this->permissions))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

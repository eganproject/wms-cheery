<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * APK aplikasi Android yang diunggah admin (menu Pengaturan > Aplikasi
 * Android). HP memperbarui diri dari versi aktif terbaru di sini.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    protected array $permissions = [
        'mobile-app.view' => 'Melihat versi aplikasi Android',
        'mobile-app.update' => 'Mengunggah & mengatur versi aplikasi Android',
        'mobile-app.delete' => 'Menghapus versi aplikasi Android',
    ];

    public function up(): void
    {
        Schema::create('mobile_app_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version_code')->unique();
            $table->string('version_name', 50);
            $table->text('notes')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        foreach ($this->permissions as $slug => $name) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'group' => 'Aplikasi Android', 'updated_at' => $now, 'created_at' => $now],
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
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys($this->permissions))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::dropIfExists('mobile_app_releases');
    }
};

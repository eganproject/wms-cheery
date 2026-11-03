<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    protected array $permissions = [
        'attendance.view' => ['Melihat absensi harian & rekap absensi', 'Absensi'],
        'leaves.view' => ['Melihat izin, sakit & cuti', 'Absensi'],
        'leaves.create' => ['Mencatat izin, sakit & cuti', 'Absensi'],
        'leaves.update' => ['Mengubah catatan izin, sakit & cuti', 'Absensi'],
        'leaves.delete' => ['Menghapus catatan izin, sakit & cuti', 'Absensi'],
        'employees.view' => ['Melihat data karyawan', 'Karyawan & Jam Kerja'],
        'employees.create' => ['Menambah karyawan', 'Karyawan & Jam Kerja'],
        'employees.update' => ['Mengubah karyawan', 'Karyawan & Jam Kerja'],
        'employees.delete' => ['Menghapus karyawan', 'Karyawan & Jam Kerja'],
        'work-shifts.view' => ['Melihat jam kerja & hari libur', 'Karyawan & Jam Kerja'],
        'work-shifts.update' => ['Mengatur jam kerja & hari libur', 'Karyawan & Jam Kerja'],
        'attendance-settings.view' => ['Melihat lokasi & mesin absen', 'Absensi'],
        'attendance-settings.update' => ['Mengatur lokasi & mesin absen', 'Absensi'],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions as $slug => [$name, $group]) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group, 'updated_at' => $now, 'created_at' => $now],
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
    }
};

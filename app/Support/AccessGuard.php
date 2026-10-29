<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Penjaga agar hak akses tidak bisa dinaikkan sendiri.
 *
 * Role "admin" sengaja tidak diberi izin menghapus pengguna atau mengatur
 * matriks hak akses. Tanpa penjagaan ini batas itu hanya hiasan: pemegang
 * izin mengubah pengguna cukup memindahkan dirinya ke role super admin, dan
 * pemegang izin mengubah role cukup mencentang izin yang tidak ia punya pada
 * role-nya sendiri.
 *
 * Aturannya satu: selain super admin, seseorang hanya bisa memberikan apa
 * yang ia sendiri miliki. Super admin tidak tersentuh.
 */
class AccessGuard
{
    /**
     * Izin yang akan ditambahkan pada role tetapi tidak dimiliki pengubahnya.
     *
     * Izin yang memang sudah ada pada role tidak dihitung, supaya mengubah
     * nama role yang izinnya lebih luas tidak ikut ditolak.
     *
     * @param  array<int, int|string>  $permissionIds
     * @return array<int, int>
     */
    public static function unheldPermissions(User $actor, array $permissionIds, ?Role $role = null): array
    {
        if ($actor->isSuperAdmin()) {
            return [];
        }

        $actor->loadMissing('role.permissions');

        $allowed = array_merge(
            $actor->role?->permissions->pluck('id')->all() ?? [],
            $role?->permissions()->pluck('permissions.id')->all() ?? [],
        );

        return array_values(array_diff(array_map('intval', $permissionIds), $allowed));
    }

    /** Hanya super admin yang boleh menyentuh akun atau role super admin. */
    public static function mayManage(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin() || ! $target->isSuperAdmin();
    }

    /**
     * Apakah target adalah satu-satunya super admin yang masih aktif.
     * Tanpa dia, tidak ada lagi yang bisa mengatur hak akses.
     */
    public static function isLastActiveSuperAdmin(User $target): bool
    {
        if (! $target->isSuperAdmin() || ! $target->is_active) {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($target->getKey())
            ->where('is_active', true)
            ->whereHas('role', fn ($role) => $role->where('is_super_admin', true))
            ->exists();
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class PermissionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:permissions.view', only: ['index']),
            new Middleware('can:permissions.update', only: ['update']),
        ];
    }

    /**
     * Hak akses diatur satu role sekaligus: pilih role di kiri, centang
     * aksesnya per kelompok menu di kanan.
     */
    public function index(Request $request): View
    {
        $roles = Role::query()
            ->with(['permissions:id', 'jobPositions:id,name,role_id'])
            ->withCount('users')
            ->orderByDesc('is_super_admin')
            ->orderBy('name')
            ->get();

        $role = $roles->firstWhere('id', $request->integer('role'))
            ?? $roles->firstWhere('is_super_admin', false)
            ?? $roles->first();

        $permissionGroups = Permission::orderBy('id')->get()->groupBy('group');

        // Selain super admin, hanya izin yang dimiliki sendiri (atau yang sudah
        // dimiliki role ini) yang boleh dicentang.
        $actor = $request->user()->loadMissing('role.permissions');
        $grantable = $actor->isSuperAdmin()
            ? null
            : array_values(array_unique(array_merge(
                $actor->role?->permissions->pluck('id')->all() ?? [],
                $role?->permissions->pluck('id')->all() ?? [],
            )));

        return view('admin.permissions.index', [
            'roles' => $roles,
            'role' => $role,
            'permissionGroups' => $permissionGroups,
            'assigned' => $role?->permissions->pluck('id')->all() ?? [],
            'grantable' => $grantable,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = Role::findOrFail($validated['role_id']);
        $back = redirect()->route('admin.permissions.index', ['role' => $role->id]);

        if ($role->is_super_admin) {
            return $back->with('error', 'Role super admin selalu memiliki semua hak akses dan tidak perlu diatur.');
        }

        $permissions = $validated['permissions'] ?? [];

        if (\App\Support\AccessGuard::unheldPermissions($request->user(), $permissions, $role)) {
            return $back->with('error', 'Anda hanya bisa memberikan hak akses yang Anda miliki sendiri.');
        }

        $role->permissions()->sync($permissions);

        return $back->with('success', "Hak akses {$role->name} berhasil disimpan.");
    }
}

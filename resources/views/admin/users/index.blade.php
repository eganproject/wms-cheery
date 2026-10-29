<x-app-layout title="Pengguna">
    <x-ui.page-header title="Pengguna" icon="users"
                      subtitle="Kelola akun, role, dan status akses pengguna sistem.">
        <x-slot name="actions">
            @can('users.create')
                <x-ui.button :href="route('admin.users.create')" icon="user-plus">Tambah Pengguna</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>


    {{-- Toolbar --}}
    <form method="GET" action="{{ route('admin.users.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nama, email, atau telepon..." class="pl-8" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="role" class="sm:w-44">
                <option value="">Semua role</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}" @selected(request('role') == $role->id)>{{ $role->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="status" class="sm:w-36">
                <option value="">Semua status</option>
                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            </x-ui.select>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'role', 'status']))
                <x-ui.button :href="route('admin.users.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($users->isEmpty())
            <x-ui.empty-state icon="users" title="Pengguna tidak ditemukan"
                              description="Coba ubah kata kunci pencarian atau tambahkan pengguna baru.">
                @can('users.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.users.create')" icon="plus">Tambah Pengguna</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            {{-- Desktop --}}
            <div class="hidden overflow-x-auto md:block">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th scope="col">Pengguna</th>
                            <th scope="col">Role</th>
                            <th scope="col">Kontak</th>
                            <th scope="col">Status</th>
                            <th scope="col">Terakhir Masuk</th>
                            <th scope="col" class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :name="$user->name" size="sm" />
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-ink-950">{{ $user->name }}</p>
                                            <p class="truncate text-xs text-ink-400">{{ $user->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @if ($user->role)
                                        <x-ui.badge :variant="$user->role->is_super_admin ? 'dark' : 'neutral'" icon="shield">
                                            {{ $user->role->name }}
                                        </x-ui.badge>
                                    @else
                                        <span class="text-xs text-ink-300">—</span>
                                    @endif
                                </td>
                                <td class="text-sm text-ink-600">
                                    {{ $user->phone ?: '—' }}
                                </td>
                                <td>
                                    <x-ui.badge :variant="$user->is_active ? 'success' : 'danger'"
                                                :icon="$user->is_active ? 'check-circle' : 'x-circle'">
                                        {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </x-ui.badge>
                                </td>
                                <td class="text-sm text-ink-500">
                                    {{ $user->last_login_at?->diffForHumans() ?? 'Belum pernah' }}
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.users.show', $user) }}" title="Detail"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                        @can('users.update')
                                            <a href="{{ route('admin.users.edit', $user) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                        @can('users.delete')
                                            @unless ($user->is(auth()->user()))
                                                <x-ui.confirm-delete :action="route('admin.users.destroy', $user)"
                                                                     title="Hapus pengguna ini?"
                                                                     :description="'Akun '.$user->name.' akan dihapus permanen dari sistem.'" />
                                            @endunless
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Mobile --}}
            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($users as $user)
                    <div class="p-4">
                        <div class="flex items-start gap-3">
                            <x-ui.avatar :name="$user->name" size="md" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-ink-950">{{ $user->name }}</p>
                                <p class="truncate text-xs text-ink-400">{{ $user->email }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <x-ui.badge :variant="$user->is_active ? 'success' : 'danger'">
                                        {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </x-ui.badge>
                                    @if ($user->role)
                                        <x-ui.badge variant="outline" icon="shield">{{ $user->role->name }}</x-ui.badge>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex items-center justify-end gap-1 border-t border-ink-50 pt-3">
                            <x-ui.button :href="route('admin.users.show', $user)" variant="ghost" size="sm" icon="eye">Detail</x-ui.button>
                            @can('users.update')
                                <x-ui.button :href="route('admin.users.edit', $user)" variant="secondary" size="sm" icon="pencil">Ubah</x-ui.button>
                            @endcan
                            @can('users.delete')
                                @unless ($user->is(auth()->user()))
                                    <x-ui.confirm-delete :action="route('admin.users.destroy', $user)"
                                                         title="Hapus pengguna ini?"
                                                         :description="'Akun '.$user->name.' akan dihapus permanen dari sistem.'" />
                                @endunless
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$users" />
        @endif
    </div>
</x-app-layout>

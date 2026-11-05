<x-app-layout title="Jabatan">
    <x-ui.page-header title="Jabatan" icon="shield"
                      subtitle="Daftar jabatan karyawan dan role bawaannya. Akun login karyawan otomatis memakai role sesuai jabatannya.">
        <x-slot name="actions">
            @can('employees.update')
                <x-ui.button :href="route('admin.job-positions.create')" icon="plus">Tambah Jabatan</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($positions->isEmpty())
            <x-ui.empty-state icon="shield" title="Belum ada jabatan"
                              description="Buat jabatan seperti Packer, Admin Gudang, atau Supervisor, lalu pilih role bawaannya.">
                @can('employees.update')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.job-positions.create')" icon="plus">Tambah Jabatan</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th><x-ui.sort key="name">Jabatan</x-ui.sort></th>
                            <th><span class="inline-flex items-center gap-1"><x-ui.sort key="role">Role bawaan</x-ui.sort> <x-ui.hint text="Role yang diberikan ke akun login karyawan dengan jabatan ini. Hak akses tiap role diatur di Pengguna & Akses." /></span></th>
                            <th class="text-right"><x-ui.sort key="employees" first="desc">Karyawan aktif</x-ui.sort></th>
                            <th><x-ui.sort key="status" first="desc">Status</x-ui.sort></th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($positions as $position)
                            <tr>
                                <td>
                                    <p class="text-sm font-medium text-ink-950">{{ $position->name }}</p>
                                    @if ($position->description)
                                        <p class="text-[11px] text-ink-400">{{ $position->description }}</p>
                                    @endif
                                </td>
                                <td class="text-sm">
                                    @if ($position->role)
                                        <x-ui.badge variant="info">{{ $position->role->name }}</x-ui.badge>
                                        @if ($position->mismatched_count)
                                            <p class="mt-1 text-[11px] text-amber-700">{{ $position->mismatched_count }} akun memakai role lain</p>
                                        @endif
                                    @else
                                        <span class="text-ink-400">Belum diatur</span>
                                    @endif
                                </td>
                                <td class="text-right text-sm font-semibold">
                                    <a href="{{ route('admin.employees.index', ['position' => $position->id]) }}" class="hover:underline">{{ $position->employees_count }}</a>
                                </td>
                                <td><x-ui.badge :variant="$position->is_active ? 'success' : 'danger'">{{ $position->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge></td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @can('employees.update')
                                            <a href="{{ route('admin.job-positions.edit', $position) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                            <x-ui.confirm-delete :action="route('admin.job-positions.destroy', $position)"
                                                                 title="Hapus jabatan ini?"
                                                                 :description="'Jabatan '.$position->name.' akan dihapus. Jabatan yang masih dipakai karyawan tidak bisa dihapus.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pagination :paginator="$positions" unit="jabatan" />
        @endif
    </div>
</x-app-layout>

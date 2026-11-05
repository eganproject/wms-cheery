@php($methodVariants = ['machine' => 'neutral', 'phone' => 'info', 'both' => 'purple'])
<x-app-layout title="Karyawan">
    <x-ui.page-header title="Karyawan" icon="users" subtitle="Data karyawan gudang, cara absennya, dan jam kerjanya.">
        <x-slot name="actions">
            @can('employees.create')
                <x-ui.button :href="route('admin.employees.create')" icon="plus">Tambah Karyawan</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <form method="GET" action="{{ route('admin.employees.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')" placeholder="Cari nama, kode, atau jabatan..." class="pl-8" />
        </div>
        @if ($positions->isNotEmpty())
            <x-ui.select name="position" class="sm:w-48">
                <option value="">Semua jabatan</option>
                @foreach ($positions as $position)
                    <option value="{{ $position->id }}" @selected((int) request('position') === $position->id)>{{ $position->name }}</option>
                @endforeach
            </x-ui.select>
        @endif
        <x-ui.select name="method" class="sm:w-48">
            <option value="">Semua metode absen</option>
            @foreach (\App\Models\Employee::METHODS as $value => $label)
                <option value="{{ $value }}" @selected(request('method') === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select name="status" class="sm:w-40">
            <option value="">Semua status</option>
            <option value="active" @selected(request('status') === 'active')>Aktif</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
        </x-ui.select>
        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'position', 'method', 'status']))
                <x-ui.button :href="route('admin.employees.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($employees->isEmpty())
            <x-ui.empty-state icon="users" title="Belum ada karyawan"
                              description="Tambahkan karyawan, pilih cara absennya (mesin, HP, atau keduanya), dan jam kerjanya.">
                @can('employees.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.employees.create')" icon="plus">Tambah Karyawan</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th><x-ui.sort key="name">Karyawan</x-ui.sort></th>
                            <th><span class="inline-flex items-center gap-1">Akun login <x-ui.hint text="Akun WMS yang terhubung. Wajib untuk absen lewat HP; boleh kosong untuk staf yang hanya tap di mesin." /></span></th>
                            <th><span class="inline-flex items-center gap-1"><x-ui.sort key="method">Metode absen</x-ui.sort> <x-ui.hint text="Mesin = tap jari di mesin fingerprint. HP = absen dari aplikasi Android dengan selfie dan lokasi." /></span></th>
                            <th><x-ui.sort key="shift">Jam kerja</x-ui.sort></th>
                            <th><span class="inline-flex items-center gap-1"><x-ui.sort key="pin">PIN mesin</x-ui.sort> <x-ui.hint text="Nomor ID karyawan yang didaftarkan di mesin fingerprint." /></span></th>
                            <th><x-ui.sort key="status" first="desc">Status</x-ui.sort></th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center rounded-md bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-ink-800 ring-1 ring-inset ring-ink-200">{{ $employee->code }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-ink-950">{{ $employee->name }}</p>
                                            <p class="text-[11px] text-ink-400">{{ $employee->position ?: '—' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-sm">
                                    @if ($employee->user)
                                        <span class="text-ink-700">{{ $employee->user->email }}</span>
                                    @else
                                        <span class="text-ink-400">Tanpa akun</span>
                                    @endif
                                </td>
                                <td><x-ui.badge :variant="$methodVariants[$employee->attendance_method] ?? 'neutral'">{{ $employee->methodLabel() }}</x-ui.badge></td>
                                <td class="text-sm text-ink-700">
                                    @if ($employee->workShift)
                                        {{ $employee->workShift->name }} <span class="text-ink-400">· {{ $employee->workShift->timeRange() }}</span>
                                    @else
                                        <span class="text-amber-700">Belum diatur</span>
                                    @endif
                                </td>
                                <td class="font-mono text-sm text-ink-700">{{ $employee->machine_pin ?: '—' }}</td>
                                <td>
                                    <x-ui.badge :variant="$employee->is_active ? 'success' : 'danger'">{{ $employee->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @can('attendance.view')
                                            <a href="{{ route('admin.attendance.employee', $employee) }}" title="Riwayat absensi"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="eye" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                        @can('employees.update')
                                            <a href="{{ route('admin.employees.edit', $employee) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                        @can('employees.delete')
                                            <x-ui.confirm-delete :action="route('admin.employees.destroy', $employee)"
                                                                 title="Hapus karyawan ini?"
                                                                 :description="$employee->name.' akan dihapus. Karyawan yang sudah punya riwayat absensi hanya bisa dinonaktifkan.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($employees as $employee)
                    <a href="{{ auth()->user()->can('employees.update') ? route('admin.employees.edit', $employee) : '#' }}" class="block p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-[11px] text-ink-400">{{ $employee->code }}</p>
                                <p class="truncate text-sm font-semibold text-ink-950">{{ $employee->name }}</p>
                                <p class="truncate text-xs text-ink-400">{{ $employee->workShift?->timeRange() ?? 'Jam kerja belum diatur' }}</p>
                            </div>
                            <x-ui.badge :variant="$methodVariants[$employee->attendance_method] ?? 'neutral'">{{ $employee->methodLabel() }}</x-ui.badge>
                        </div>
                    </a>
                @endforeach
            </div>
            <x-ui.pagination :paginator="$employees" />
        @endif
    </div>
</x-app-layout>

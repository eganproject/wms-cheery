<x-app-layout title="Izin, Sakit & Cuti">
    <x-ui.page-header title="Izin, Sakit & Cuti" icon="calendar"
                      subtitle="Ketidakhadiran yang punya keterangan. Hari-hari ini tidak dihitung tidak hadir.">
        <x-slot name="actions">
            @can('leaves.create')
                <x-ui.button :href="route('admin.attendance-leaves.create')" icon="plus">Catat Izin / Sakit / Cuti</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat-card label="Izin bulan ini" :value="$counts['izin'] ?? 0" icon="calendar" />
        <x-ui.stat-card label="Sakit bulan ini" :value="$counts['sakit'] ?? 0" icon="warning" />
        <x-ui.stat-card label="Cuti bulan ini" :value="$counts['cuti'] ?? 0" icon="calendar-days" />
        <x-ui.stat-card label="Tidak masuk hari ini" :value="$awayToday" icon="users" hint="izin/sakit/cuti sehari penuh" />
    </div>

    <form method="GET" action="{{ route('admin.attendance-leaves.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')" placeholder="Cari nama karyawan..." class="pl-8" />
        </div>
        <x-ui.select name="type" class="sm:w-40">
            <option value="">Semua jenis</option>
            @foreach (\App\Models\AttendanceLeave::TYPES as $value => $label)
                <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.select>
        <x-text-input type="month" name="month" :value="$month->format('Y-m')" class="sm:w-44" />
        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'type', 'month']))
                <x-ui.button :href="route('admin.attendance-leaves.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($leaves->isEmpty())
            <x-ui.empty-state icon="calendar" title="Belum ada catatan bulan ini"
                              description="Catat izin, sakit, atau cuti supaya hari itu tidak dihitung tidak hadir." />
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th><x-ui.sort key="employee">Karyawan</x-ui.sort></th>
                            <th><x-ui.sort key="type">Jenis</x-ui.sort></th>
                            <th><x-ui.sort key="date" first="desc">Tanggal</x-ui.sort></th>
                            <th class="text-right">Lama</th>
                            <th>Keterangan</th>
                            <th>Lampiran</th>
                            <th>Dicatat oleh</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leaves as $leave)
                            <tr>
                                <td>
                                    <p class="text-sm font-medium text-ink-950">{{ $leave->employee->name }}</p>
                                    <p class="text-[11px] text-ink-400">{{ $leave->employee->position ?: $leave->employee->code }}</p>
                                </td>
                                <td><x-ui.badge :variant="$leave->variant()">{{ $leave->typeLabel() }}</x-ui.badge></td>
                                <td class="whitespace-nowrap text-sm text-ink-700">{{ $leave->periodLabel() }}</td>
                                <td class="text-right text-sm text-ink-700">{{ $leave->is_half_day ? '½ hari' : $leave->days().' hari' }}</td>
                                <td class="max-w-xs truncate text-sm text-ink-600" title="{{ $leave->reason }}">{{ $leave->reason ?: '—' }}</td>
                                <td>
                                    @if ($leave->attachment_path)
                                        <a href="{{ route('admin.attendance-leaves.attachment', $leave) }}" target="_blank" rel="noopener"
                                           class="inline-flex items-center gap-1 text-xs font-medium text-sky-700 hover:underline">
                                            <x-icon name="document" class="h-3.5 w-3.5" />Lihat
                                        </a>
                                    @else
                                        <span class="text-xs text-ink-400">—</span>
                                    @endif
                                </td>
                                <td class="text-xs text-ink-500">{{ $leave->creator?->name ?? '—' }}</td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @can('leaves.update')
                                            <a href="{{ route('admin.attendance-leaves.edit', $leave) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                        @can('leaves.delete')
                                            <x-ui.confirm-delete :action="route('admin.attendance-leaves.destroy', $leave)"
                                                                 title="Hapus catatan ini?"
                                                                 :description="$leave->typeLabel().' '.$leave->employee->name.' ('.$leave->periodLabel().') akan dihapus. Hari itu kembali dihitung dari tap absen.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($leaves as $leave)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-ink-950">{{ $leave->employee->name }}</p>
                                <p class="text-xs text-ink-500">{{ $leave->periodLabel() }} · {{ $leave->is_half_day ? '½ hari' : $leave->days().' hari' }}</p>
                            </div>
                            <x-ui.badge :variant="$leave->variant()">{{ $leave->typeLabel() }}</x-ui.badge>
                        </div>
                        @if ($leave->reason)
                            <p class="mt-1 text-xs text-ink-500">{{ $leave->reason }}</p>
                        @endif
                        @can('leaves.update')
                            <a href="{{ route('admin.attendance-leaves.edit', $leave) }}" class="mt-2 inline-block text-xs font-medium text-ink-700 underline">Ubah</a>
                        @endcan
                    </div>
                @endforeach
            </div>
            <x-ui.pagination :paginator="$leaves" />
        @endif
    </div>
</x-app-layout>

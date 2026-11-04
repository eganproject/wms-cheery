<x-app-layout title="Absensi Harian">
    <x-ui.page-header title="Absensi Harian" icon="clock"
                      subtitle="Siapa yang sudah masuk pada tanggal ini, dari mesin fingerprint maupun HP.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.attendance.export', request()->query())" variant="secondary" icon="excel">Export Excel</x-ui.button>
            @can('leaves.create')
                <x-ui.button :href="route('admin.attendance-leaves.create', ['date' => $date->format('Y-m-d')])" icon="plus">Catat Izin / Sakit / Cuti</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-ui.stat-card label="Hadir" :value="$stats['present']" icon="check-circle"
                        :hint="'dari '.$stats['scheduled'].' karyawan terjadwal'" />
        <x-ui.stat-card label="Telat" :value="$stats['late']" icon="clock" hint="lewat toleransi jam masuk"
                        info="Masuk setelah jam masuk ditambah toleransi telat di menu Jam Kerja." />
        <x-ui.stat-card label="Belum absen" :value="$stats['not_yet']" icon="warning" hint="shift belum selesai" />
        <x-ui.stat-card label="Izin / Sakit / Cuti" :value="$stats['leave']" icon="calendar"
                        :hint="$stats['izin'].' izin · '.$stats['sakit'].' sakit · '.$stats['cuti'].' cuti'" />
        <x-ui.stat-card label="Tidak hadir" :value="$stats['absent']" icon="x-circle" hint="tanpa keterangan" />
    </div>

    <form method="GET" action="{{ route('admin.attendance.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
        <x-text-input type="date" name="date" :value="$date->format('Y-m-d')" class="sm:w-44" />
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')" placeholder="Cari nama, kode, atau jabatan..." class="pl-8" />
        </div>
        <x-ui.select name="status" class="sm:w-48">
            <option value="">Semua status</option>
            @foreach ($statusFilters as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select name="source" class="sm:w-40">
            <option value="">Mesin & HP</option>
            <option value="machine" @selected(request('source') === 'machine')>Mesin</option>
            <option value="phone" @selected(request('source') === 'phone')>HP</option>
        </x-ui.select>
        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'status', 'source', 'date']))
                <x-ui.button :href="route('admin.attendance.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($days->isEmpty())
            <x-ui.empty-state icon="clock" :title="$hasEmployees ? 'Tidak ada yang cocok dengan saringan' : 'Belum ada karyawan'"
                              :description="$hasEmployees ? 'Ubah atau reset saringan di atas.' : 'Tambahkan karyawan dan jam kerjanya dulu, lalu absensinya muncul di sini.'">
                @if (! $hasEmployees)
                    @can('employees.create')
                        <x-slot name="action">
                            <x-ui.button :href="route('admin.employees.create')" icon="plus">Tambah Karyawan</x-ui.button>
                        </x-slot>
                    @endcan
                @endif
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th><x-ui.sort key="name">Karyawan</x-ui.sort></th>
                            <th><x-ui.sort key="shift">Jam kerja</x-ui.sort></th>
                            <th><x-ui.sort key="in">Masuk</x-ui.sort></th>
                            <th><x-ui.sort key="out" first="desc">Pulang</x-ui.sort></th>
                            <th><x-ui.sort key="status">Status</x-ui.sort></th>
                            <th>Keterangan</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($days as $day)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2.5">
                                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink-100 text-xs font-semibold text-ink-700">{{ $day->employee->initials() }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-ink-950">{{ $day->employee->name }}</p>
                                            <p class="text-[11px] text-ink-400">{{ $day->employee->position ?: $day->employee->code }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-sm text-ink-700">{{ $day->shift?->timeRange() ?? '—' }}</td>
                                <td>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-sm font-medium tabular-nums text-ink-950">{{ $day->checkIn?->punched_at->format('H:i') ?? '—' }}</span>
                                        @include('admin.attendance._source', ['punch' => $day->checkIn])
                                    </div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-sm font-medium tabular-nums text-ink-950">{{ $day->checkOut?->punched_at->format('H:i') ?? '—' }}</span>
                                        @include('admin.attendance._source', ['punch' => $day->checkOut])
                                    </div>
                                </td>
                                <td><x-ui.badge :variant="$day->variant">{{ $day->label }}</x-ui.badge></td>
                                <td class="max-w-xs text-xs text-ink-500">{{ $day->note() ?? '—' }}</td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @foreach (collect([$day->checkIn, $day->checkOut])->filter(fn ($punch) => $punch?->selfie_path)->unique('id') as $punch)
                                            <a href="{{ route('admin.attendance.selfie', $punch) }}" target="_blank" rel="noopener"
                                               title="Selfie {{ $punch->is($day->checkIn) ? 'masuk' : 'pulang' }} {{ $punch->punched_at->format('H:i') }}"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="camera" class="h-4 w-4" />
                                            </a>
                                        @endforeach
                                        @if (in_array($day->status, ['tidak_hadir', 'belum_absen'], true))
                                            @can('leaves.create')
                                                <a href="{{ route('admin.attendance-leaves.create', ['employee' => $day->employee->id, 'date' => $date->format('Y-m-d')]) }}"
                                                   title="Catat izin / sakit / cuti"
                                                   class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                    <x-icon name="calendar" class="h-4 w-4" />
                                                </a>
                                            @endcan
                                        @endif
                                        <a href="{{ route('admin.attendance.employee', [$day->employee, 'month' => $date->format('Y-m'), 'date' => $date->format('Y-m-d')]) }}"
                                           title="Riwayat tap bulan ini"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($days as $day)
                    <a href="{{ route('admin.attendance.employee', [$day->employee, 'month' => $date->format('Y-m'), 'date' => $date->format('Y-m-d')]) }}" class="block p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-ink-950">{{ $day->employee->name }}</p>
                                <p class="text-xs text-ink-400">{{ $day->shift?->timeRange() ?? 'Tanpa jam kerja' }}</p>
                            </div>
                            <x-ui.badge :variant="$day->variant">{{ $day->label }}</x-ui.badge>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span class="flex items-center gap-1.5 text-ink-500">Masuk <b class="tabular-nums text-ink-950">{{ $day->checkIn?->punched_at->format('H:i') ?? '—' }}</b>@include('admin.attendance._source', ['punch' => $day->checkIn])</span>
                            <span class="flex items-center gap-1.5 text-ink-500">Pulang <b class="tabular-nums text-ink-950">{{ $day->checkOut?->punched_at->format('H:i') ?? '—' }}</b>@include('admin.attendance._source', ['punch' => $day->checkOut])</span>
                        </div>
                        @if ($day->note())
                            <p class="mt-1 text-xs text-ink-500">{{ $day->note() }}</p>
                        @endif
                    </a>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$days" unit="karyawan" />
        @endif
    </div>
</x-app-layout>

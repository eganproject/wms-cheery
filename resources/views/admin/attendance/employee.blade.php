<x-app-layout :title="'Absensi '.$employee->name">
    <x-ui.page-header :title="$employee->name" :back="route('admin.attendance.index')"
                      :subtitle="collect([$employee->code, $employee->position, $employee->workShift ? 'Jam kerja '.$employee->workShift->name.' '.$employee->workShift->timeRange() : 'Belum diatur jam kerja', 'Absen: '.$employee->methodLabel()])->filter()->implode(' · ')">
        <x-slot name="actions">
            <form method="GET" data-auto-submit class="flex items-center gap-2">
                <x-text-input type="month" name="month" :value="$month->format('Y-m')" class="w-44" />
            </form>
            @can('employees.update')
                <x-ui.button :href="route('admin.employees.edit', $employee)" variant="secondary" icon="pencil">Ubah Karyawan</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-ui.stat-card label="Hari kerja" :value="$summary['workdays']" icon="calendar-days" />
        <x-ui.stat-card label="Hadir" :value="$summary['present']" icon="check-circle" />
        <x-ui.stat-card label="Telat" :value="$summary['late']" icon="clock" :hint="$summary['late_minutes'].' menit total'" />
        <x-ui.stat-card label="Pulang cepat" :value="$summary['early']" icon="arrow-left" />
        <x-ui.stat-card label="Izin / Sakit / Cuti" :value="$summary['izin'] + $summary['sakit'] + $summary['cuti']" icon="calendar" />
        <x-ui.stat-card label="Tidak hadir" :value="$summary['absent']" icon="x-circle"
                        :hint="$summary['rate'] !== null ? 'kehadiran '.$summary['rate'].'%' : null" />
    </div>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        <div class="overflow-x-auto">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Masuk</th>
                        <th>Pulang</th>
                        <th>Status</th>
                        <th>Semua tap <x-ui.hint text="Setiap tap yang tercatat pada tanggal itu, dari mesin maupun HP." /></th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($days as $key => $day)
                        <tr @class(['bg-amber-50/60' => $focus === $key])>
                            <td class="whitespace-nowrap text-sm {{ $day->isWorkday ? 'text-ink-950' : 'text-ink-400' }}">
                                {{ $day->date->translatedFormat('D, j M') }}
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-sm font-medium tabular-nums">{{ $day->checkIn?->punched_at->format('H:i') ?? '—' }}</span>
                                    @include('admin.attendance._source', ['punch' => $day->checkIn])
                                </div>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-sm font-medium tabular-nums">{{ $day->checkOut?->punched_at->format('H:i') ?? '—' }}</span>
                                    @include('admin.attendance._source', ['punch' => $day->checkOut])
                                </div>
                            </td>
                            <td><x-ui.badge :variant="$day->variant">{{ $day->label }}</x-ui.badge></td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($punches->get($key, collect()) as $punch)
                                        @if ($punch->selfie_path)
                                            <a href="{{ route('admin.attendance.selfie', $punch) }}" target="_blank" rel="noopener"
                                               class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-sky-700 hover:bg-sky-100"
                                               title="Lihat selfie{{ $punch->location ? ' · '.$punch->location->name : '' }}{{ $punch->distance_meters !== null ? ' · '.$punch->distance_meters.' m' : '' }}">
                                                <x-icon name="camera" class="h-3 w-3" />{{ $punch->punched_at->format('H:i') }}
                                            </a>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium tabular-nums {{ $punch->source === 'phone' ? 'bg-sky-50 text-sky-700' : 'bg-ink-100 text-ink-600' }}"
                                                  title="{{ $punch->sourceLabel() }}{{ $punch->device ? ' · '.$punch->device->name : '' }}{{ $punch->location ? ' · '.$punch->location->name : '' }}">
                                                <x-icon :name="$punch->source === 'phone' ? 'phone' : 'hash'" class="h-3 w-3" />{{ $punch->punched_at->format('H:i') }}
                                            </span>
                                        @endif
                                    @empty
                                        <span class="text-xs text-ink-400">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="max-w-xs text-xs text-ink-500">{{ $day->note() ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>

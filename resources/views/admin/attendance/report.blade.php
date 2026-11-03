<x-app-layout title="Rekap Absensi">
    <x-ui.page-header title="Rekap Absensi" icon="calendar-days"
                      subtitle="Ringkasan kehadiran per karyawan dalam satu bulan. Bulan berjalan dihitung sampai hari ini.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.attendance.report.export', request()->query())" variant="secondary" icon="excel">Export Excel</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <form method="GET" action="{{ route('admin.attendance.report') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
        <x-text-input type="month" name="month" :value="$month->format('Y-m')" class="sm:w-44" />
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')" placeholder="Cari nama, kode, atau jabatan..." class="pl-8" />
        </div>
        <x-ui.button type="submit" variant="secondary" icon="filter">Terapkan</x-ui.button>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($rows->isEmpty())
            <x-ui.empty-state icon="calendar-days" title="Belum ada karyawan" description="Rekap muncul setelah karyawan ditambahkan di menu Karyawan." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Karyawan</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Hari kerja <x-ui.hint text="Hari terjadwal masuk menurut jam kerja, tanpa hari libur, sampai hari ini." /></span></th>
                            <th class="text-right">Hadir</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Telat <x-ui.hint text="Berapa kali telat, dan total menitnya." /></span></th>
                            <th class="text-right">Pulang cepat</th>
                            <th class="text-right">Izin</th>
                            <th class="text-right">Sakit</th>
                            <th class="text-right">Cuti</th>
                            <th class="text-right">Tidak hadir</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Kehadiran <x-ui.hint text="Hari hadir dibagi hari kerja, tanpa menghitung hari izin, sakit, atau cuti." /></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($s = $row['summary'])
                            <tr>
                                <td>
                                    <a href="{{ route('admin.attendance.employee', [$row['employee'], 'month' => $month->format('Y-m')]) }}" class="block hover:underline">
                                        <p class="text-sm font-medium text-ink-950">{{ $row['employee']->name }}</p>
                                        <p class="text-[11px] text-ink-400">{{ $row['employee']->position ?: $row['employee']->code }}@unless ($row['employee']->is_active) · nonaktif @endunless</p>
                                    </a>
                                </td>
                                <td class="text-right text-sm tabular-nums">{{ $s['workdays'] }}</td>
                                <td class="text-right text-sm font-semibold tabular-nums text-ink-950">{{ $s['present'] }}</td>
                                <td class="text-right text-sm tabular-nums {{ $s['late'] ? 'text-amber-700' : 'text-ink-400' }}">
                                    {{ $s['late'] }}@if ($s['late'])<span class="text-[11px] text-ink-400"> · {{ $s['late_minutes'] }} mnt</span>@endif
                                </td>
                                <td class="text-right text-sm tabular-nums {{ $s['early'] ? 'text-amber-700' : 'text-ink-400' }}">{{ $s['early'] }}</td>
                                <td class="text-right text-sm tabular-nums {{ $s['izin'] ? '' : 'text-ink-400' }}">{{ $s['izin'] }}</td>
                                <td class="text-right text-sm tabular-nums {{ $s['sakit'] ? '' : 'text-ink-400' }}">{{ $s['sakit'] }}</td>
                                <td class="text-right text-sm tabular-nums {{ $s['cuti'] ? '' : 'text-ink-400' }}">{{ $s['cuti'] }}</td>
                                <td class="text-right text-sm tabular-nums {{ $s['absent'] ? 'font-semibold text-rose-700' : 'text-ink-400' }}">{{ $s['absent'] }}</td>
                                <td class="text-right">
                                    @if ($s['rate'] === null)
                                        <span class="text-xs text-ink-400">—</span>
                                    @else
                                        <x-ui.badge :variant="$s['rate'] >= 95 ? 'success' : ($s['rate'] >= 85 ? 'warning' : 'danger')">{{ $s['rate'] }}%</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>

@php
    $t = $data['totals'];
    $max = max(1, $data['rows']->max('packages') ?? 1);
@endphp

<div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
    <x-ui.stat-card label="Paket Dikemas" :value="$n($t['packages'])" icon="packing" accent
                    info="Resi yang lolos verifikasi di QC Kemas pada periode ini, termasuk lewat aplikasi Android."
                    :hint="$n($t['units']).' unit discan'" />
    <x-ui.stat-card label="Rata-rata per Hari" :value="$n(round($t['per_day']))" icon="chart" :hint="'Dari '.$n($t['days']).' hari kerja ada paket'" />
    <x-ui.stat-card label="Petugas Mengemas" :value="$n($t['people'])" icon="users" />
    <x-ui.stat-card label="Hari Tersibuk" :value="$data['daily']->sortByDesc('packages')->first() ? $n($data['daily']->sortByDesc('packages')->first()->packages) : '—'" icon="trending-up"
                    :hint="($busy = $data['daily']->sortByDesc('packages')->first()) ? \Illuminate\Support\Carbon::parse($busy->day)->translatedFormat('D, d M Y') : null" />
</div>

<div class="mt-5 overflow-hidden rounded-2xl border border-ink-100 bg-white">
    <div class="border-b border-ink-100 px-4 py-3">
        <p class="text-sm font-medium text-ink-950">Per Petugas</p>
        <p class="text-xs text-ink-500">Hari hadir diambil dari Absensi bila akun petugas sudah dihubungkan ke data Karyawan.</p>
    </div>
    @if ($data['rows']->isEmpty())
        <x-ui.empty-state icon="packing" title="Belum ada paket dikemas" description="Belum ada resi yang lolos QC Kemas pada periode ini." />
    @else
        <div class="overflow-x-auto">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Petugas</th>
                        <th class="px-4 text-right">Paket</th>
                        <th class="px-4 text-right">Unit</th>
                        <th class="px-4 text-right"><span class="inline-flex items-center gap-1">Hari Mengemas <x-ui.hint text="Jumlah hari petugas ini memindai paling tidak satu resi." /></span></th>
                        <th class="px-4 text-right"><span class="inline-flex items-center gap-1">Hadir <x-ui.hint text="Hari hadir menurut Absensi. Kosong bila akunnya belum dihubungkan ke Karyawan." /></span></th>
                        <th class="px-4 text-right">Telat</th>
                        <th class="px-4 text-right"><span class="inline-flex items-center gap-1">Paket / Hari <x-ui.hint text="Paket dibagi hari hadir; bila tidak ada data absensi, dibagi hari mengemas." /></span></th>
                        <th class="w-40"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['rows'] as $row)
                        <tr>
                            <td class="text-sm">
                                <p class="font-medium text-ink-950">{{ $row->name }}</p>
                                @if ($row->user && $row->user !== $row->name)
                                    <p class="text-xs text-ink-500">Akun {{ $row->user }}</p>
                                @endif
                            </td>
                            <td class="px-4 text-right text-sm font-semibold tabular-nums">{{ $n($row->packages) }}</td>
                            <td class="px-4 text-right text-sm tabular-nums text-ink-600">{{ $n($row->units) }}</td>
                            <td class="px-4 text-right text-sm tabular-nums text-ink-600">{{ $n($row->days) }}</td>
                            <td class="px-4 text-right text-sm tabular-nums text-ink-600">{{ $row->present !== null ? $n($row->present) : '—' }}</td>
                            <td class="px-4 text-right text-sm tabular-nums {{ $row->late ? 'text-amber-700' : 'text-ink-300' }}">{{ $row->late ? $n($row->late) : '—' }}</td>
                            <td class="px-4 text-right text-sm font-semibold tabular-nums">{{ number_format($row->per_day, 1, ',', '.') }}</td>
                            <td>
                                <div class="h-1.5 w-32 overflow-hidden rounded-full bg-ink-100">
                                    <div class="h-full rounded-full bg-brand-500" style="width: {{ round($row->packages / $max * 100) }}%"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

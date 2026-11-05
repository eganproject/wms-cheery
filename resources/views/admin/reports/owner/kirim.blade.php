@php
    $t = $data['totals'];
    $statusMeta = [
        'telat' => ['Telat dikirim', 'bg-amber-50 text-amber-700'],
        'lewat' => ['Belum dikirim', 'bg-red-50 text-red-700'],
    ];
    $hours = fn (int $h) => $h >= 24 ? floor($h / 24).' hari '.($h % 24 ? ($h % 24).' jam' : '') : $h.' jam';
@endphp

<div class="grid grid-cols-2 gap-4 xl:grid-cols-5">
    <x-ui.stat-card label="Tepat Waktu" :value="$pct($t['rate'])" icon="check-circle" accent
                    info="Bagian resi yang berangkat sebelum batas kirimnya, dari resi yang sudah bisa dinilai (sudah dikirim, atau sudah lewat batas)."
                    :hint="$n($t['on_time']).' resi tepat waktu'" />
    <x-ui.stat-card label="Telat Dikirim" :value="$n($t['late'])" icon="clock"
                    info="Sudah berangkat, tetapi setelah batas kirimnya. Berisiko kena penalti marketplace." />
    <x-ui.stat-card label="Lewat Batas" :value="$n($t['overdue'])" icon="warning"
                    info="Batas kirimnya sudah lewat dan paketnya belum berangkat. Ini yang paling mendesak."
                    :href="route('admin.orders.deadlines')" />
    <x-ui.stat-card label="Belum Jatuh Tempo" :value="$n($t['pending'])" icon="calendar" hint="Belum dikirim, masih ada waktu" />
    <x-ui.stat-card label="Rata-rata Telat" :value="$t['avg_late_hours'] ? $hours((int) round($t['avg_late_hours'])) : '—'" icon="history"
                    hint="Dari resi yang telat atau lewat batas" />
</div>

<p class="mt-3 text-xs text-ink-500">
    Dihitung dari resi yang batas kirimnya jatuh pada {{ $range->label() }} dan tidak dibatalkan. Saat berangkat = saat
    dokumen keluarnya disetujui di Serah Terima Paket.
</p>

<div class="mt-5 grid gap-5">
    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        <p class="border-b border-ink-100 px-4 py-3 text-sm font-medium text-ink-950">Per Tanggal Batas Kirim</p>
        @if ($data['days']->isEmpty())
            <x-ui.empty-state icon="clock" title="Tidak ada resi bertenggat" description="Belum ada resi yang batas kirimnya jatuh di periode ini." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th class="px-3 text-right">Resi</th>
                            <th class="px-3 text-right">Tepat</th>
                            <th class="px-3 text-right">Telat</th>
                            <th class="px-3 text-right">Lewat</th>
                            <th class="px-3 text-right">Menunggu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['days'] as $row)
                            <tr>
                                <td class="whitespace-nowrap text-sm text-ink-950">{{ $row->label }}</td>
                                <td class="px-3 text-right text-sm font-semibold tabular-nums">{{ $n($row->total) }}</td>
                                <td class="px-3 text-right text-sm tabular-nums text-emerald-700">{{ $row->on_time ? $n($row->on_time) : '—' }}</td>
                                <td class="px-3 text-right text-sm tabular-nums {{ $row->late ? 'text-amber-700' : 'text-ink-300' }}">{{ $row->late ? $n($row->late) : '—' }}</td>
                                <td class="px-3 text-right text-sm tabular-nums {{ $row->overdue ? 'font-semibold text-red-600' : 'text-ink-300' }}">{{ $row->overdue ? $n($row->overdue) : '—' }}</td>
                                <td class="px-3 text-right text-sm tabular-nums text-ink-500">{{ $row->pending ? $n($row->pending) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        <p class="border-b border-ink-100 px-4 py-3 text-sm font-medium text-ink-950">Resi Telat & Lewat Batas</p>
        @if ($data['late']->isEmpty())
            <x-ui.empty-state icon="check-circle" title="Tidak ada yang telat" description="Semua resi pada periode ini berangkat sebelum batas kirimnya." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Resi</th>
                            <th>Status</th>
                            <th>Batas Kirim</th>
                            <th>Dikirim</th>
                            <th class="px-4 text-right">Telat</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['late'] as $row)
                            <tr>
                                <td class="text-sm">
                                    <a href="{{ route('admin.orders.index', ['search' => $row->tracking_number, 'range' => 'semua']) }}"
                                       class="font-mono text-ink-950 underline-offset-4 hover:underline">{{ $row->tracking_number }}</a>
                                    <p class="text-xs text-ink-500">{{ $row->courier ?: '—' }} · {{ $row->is_preorder ? 'PO' : 'Normal' }}</p>
                                </td>
                                <td><span class="inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-medium {{ $statusMeta[$row->status][1] }}">{{ $statusMeta[$row->status][0] }}</span></td>
                                <td class="whitespace-nowrap text-sm text-ink-600">{{ \Illuminate\Support\Carbon::parse($row->ship_by)->format('d/m/Y H:i') }}</td>
                                <td class="whitespace-nowrap text-sm text-ink-600">{{ $row->shipped_at ? \Illuminate\Support\Carbon::parse($row->shipped_at)->format('d/m/Y H:i') : '—' }}</td>
                                <td class="whitespace-nowrap px-4 text-right text-sm font-semibold tabular-nums text-red-600">{{ $hours($row->late_hours) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ui.pagination :paginator="$data['late']" unit="resi" />
        @endif
    </div>
</div>

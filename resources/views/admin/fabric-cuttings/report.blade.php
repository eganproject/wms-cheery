{{--
    Rekap Cutting & Jahit per periode: hasil kerja pemotong (kain terpakai vs
    standar BOM, pcs per barang) dan penjahit (diserahkan, diterima, sisa,
    selisih batch ditutup).
--}}
@php
    $n = fn ($value, $decimals = 0) => number_format((float) $value, $decimals, ',', '.');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
@endphp
<x-app-layout title="Rekap Cutting & Jahit">
    @include('admin.fabric-cuttings.partials.header')

    <form method="GET" action="{{ route('admin.fabric-cuttings.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 lg:flex-row lg:items-center">
        <input type="hidden" name="lihat" value="rekap">
        <div class="flex-1">
            <x-ui.date-filter label="Periode" :allow-all="false"
                              :range="new \App\Support\DateRange($from->toDateString(), $to->toDateString())" />
        </div>
        <x-ui.button type="submit" variant="secondary" icon="filter" class="lg:flex-none">Terapkan</x-ui.button>
        <x-ui.button :href="route('admin.fabric-cuttings.export', ['from' => $from->toDateString(), 'to' => $to->toDateString()])"
                     variant="secondary" icon="download" class="lg:flex-none">Export Excel</x-ui.button>
    </form>

    <div class="space-y-6">
        <x-ui.card title="Pemotong" padding="p-0"
                   subtitle="Roll diambil menurut tanggal scan keluar; pcs hasil potong menurut tanggal diserahkan ke penjahit.">
            @if ($report->cutters->isEmpty())
                <p class="px-5 py-6 text-sm text-ink-400">Belum ada cutting pada periode ini.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Pemotong</th>
                                <th class="text-right">Cutting Dimulai</th>
                                <th class="text-right">Roll Diambil</th>
                                <th class="text-right">Pcs Hasil Potong</th>
                                <th>Barang</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report->cutters as $row)
                                <tr>
                                    <td>
                                        <span class="block text-sm font-semibold text-ink-950">{{ $row['name'] }}</span>
                                        @if ($row['position'])
                                            <span class="block text-[11px] text-ink-400">{{ $row['position'] }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right text-sm tabular-nums">{{ $n($row['cuttings']) }}</td>
                                    <td class="text-right text-sm tabular-nums">{{ $n($row['rolls']) }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums">{{ $n($row['pieces']) }} pcs</td>
                                    <td class="text-xs text-ink-500">
                                        {{ $report->cutterProducts->where('cutter', $row['name'])->map(fn ($item) => $item['sku'].' '.$n($item['pieces']))->implode(' · ') ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Pemakaian Kain per Pemotong" padding="p-0"
                   subtitle="Standar BOM = pcs hasil potong × BOM kain. Selisih plus berarti kain terpakai lebih banyak dari standar; wajar selama cutting belum selesai diserahkan.">
            @if ($report->fabrics->isEmpty())
                <p class="px-5 py-6 text-sm text-ink-400">Belum ada kain keluar untuk cutting pada periode ini.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Pemotong</th>
                                <th>Kain</th>
                                <th class="text-right">Terpakai</th>
                                <th class="text-right">Standar BOM</th>
                                <th class="text-right">Selisih</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report->fabrics as $row)
                                <tr>
                                    <td class="text-sm font-medium text-ink-950">{{ $row['cutter'] }}</td>
                                    <td class="text-sm">
                                        <span class="block font-mono text-[11px] text-ink-500">{{ $row['fabric']->sku }}</span>
                                        <span class="block text-ink-950">{{ $row['fabric']->displayName() }}</span>
                                    </td>
                                    <td class="text-right text-sm tabular-nums">{{ $qty($row['used']) }} {{ $row['fabric']->unit }}</td>
                                    <td class="text-right text-sm tabular-nums">{{ $row['standard'] === null ? '—' : $qty($row['standard']).' '.$row['fabric']->unit }}</td>
                                    <td @class(['text-right text-sm font-semibold tabular-nums',
                                                'text-rose-600' => ($row['difference'] ?? 0) > 0,
                                                'text-emerald-600' => ($row['difference'] ?? 0) < 0])>
                                        {{ $row['difference'] === null ? '—' : ($row['difference'] > 0 ? '+' : '').$qty($row['difference']).' '.$row['fabric']->unit }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Penjahit" padding="p-0"
                   subtitle="Batch yang diserahkan pada periode ini. Diterima = penerimaan barang yang sudah disetujui.">
            @if ($report->tailors->isEmpty())
                <p class="px-5 py-6 text-sm text-ink-400">Belum ada batch WIP Jahit pada periode ini.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Penjahit / Barang</th>
                                <th class="text-right">Batch</th>
                                <th class="text-right">Diserahkan</th>
                                <th class="text-right">Diterima</th>
                                <th class="text-right">Sisa di Penjahit</th>
                                <th class="text-right">Selisih Ditutup</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report->tailors as $row)
                                <tr class="bg-ink-50/60">
                                    <td class="text-sm font-semibold text-ink-950">{{ $row['name'] }}</td>
                                    <td class="text-right text-sm tabular-nums">{{ $n($row['batches']) }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums">{{ $n($row['handed']) }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums">{{ $n($row['received']) }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums">{{ $n($row['open']) }}</td>
                                    <td @class(['text-right text-sm font-semibold tabular-nums', 'text-rose-600' => $row['closed_short'] > 0])>{{ $n($row['closed_short']) }}</td>
                                </tr>
                                @foreach ($report->tailorProducts->where('tailor', $row['name']) as $line)
                                    <tr>
                                        <td class="pl-8 text-xs">
                                            <span class="font-mono text-ink-500">{{ $line['sku'] }}</span>
                                            <span class="text-ink-700">{{ $line['name'] }}</span>
                                        </td>
                                        <td></td>
                                        <td class="text-right text-xs tabular-nums">{{ $n($line['handed']) }}</td>
                                        <td class="text-right text-xs tabular-nums">{{ $n($line['received']) }}</td>
                                        <td class="text-right text-xs tabular-nums">{{ $n($line['open']) }}</td>
                                        <td class="text-right text-xs tabular-nums">{{ $n($line['closed_short']) }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-app-layout>

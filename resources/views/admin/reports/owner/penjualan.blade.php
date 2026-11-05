@php
    $t = $data['totals'];
    $chart = $data['periods']->reverse()->values()->take(-31);
    $max = max(1, $chart->max('orders') ?? 1);
    $coverage = $t['lines'] > 0 ? $t['priced_lines'] / $t['lines'] * 100 : 0;
@endphp

<div class="grid grid-cols-2 gap-4 xl:grid-cols-5">
    <x-ui.stat-card label="Omzet" :value="$rp($t['total_revenue'])" icon="chart" accent
                    info="Marketplace: harga jual setelah diskon dari import TikTok (pesanan yang tidak batal). Penjualan manual: Total Harga Jual di dokumennya."
                    :hint="'Marketplace '.$rp($t['revenue']).' · manual '.$rp($t['manual_revenue'])" />
    <x-ui.stat-card label="Pesanan Marketplace" :value="$n($t['orders'])" icon="shopping-cart"
                    info="Resi yang tanggal pesanannya masuk periode ini, tidak termasuk yang dibatalkan sebelum dikirim." />
    <x-ui.stat-card label="Unit Terjual" :value="$n($t['units'])" icon="box" hint="Dari pesanan marketplace" />
    <x-ui.stat-card label="Dibatalkan" :value="$n($t['cancelled'])" icon="close"
                    :hint="$pct($t['cancel_rate']).' dari semua pesanan'" />
    <x-ui.stat-card label="Penjualan Manual" :value="$n($t['manual_orders'])" icon="outbound"
                    :hint="$n($t['manual_units']).' unit · '.$rp($t['manual_revenue'])"
                    info="Dokumen Penjualan Manual yang disetujui pada periode ini. Omzetnya dari kolom Total Harga Jual di dokumen." />
</div>

@if ($t['lines'] > 0 && $coverage < 100)
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Baru {{ $pct($coverage) }} baris barang yang punya harga. Resi yang diimport sebelum harga dibaca belum masuk omzet.
        Unggah ulang berkas eksport TikTok-nya di Import Resi untuk melengkapi harganya; data lain tidak berubah.
    </div>
@endif

<div class="mt-5 frappe-card p-4">
    <div class="mb-3 flex items-center justify-between">
        <p class="text-sm font-medium text-ink-950">Pesanan {{ strtolower($periods[$per]) }}</p>
        <p class="text-xs text-ink-500">{{ $range->label() }}</p>
    </div>
    @if ($chart->isEmpty())
        <p class="py-8 text-center text-sm text-ink-400">Belum ada pesanan pada periode ini.</p>
    @else
        <div class="flex h-40 items-end gap-1">
            @foreach ($chart as $row)
                <div class="group relative flex h-full min-w-0 flex-1 flex-col justify-end"
                     data-hint-title="{{ $row->label }}"
                     data-hint="{{ $n($row->orders) }} pesanan · {{ $n($row->units) }} unit · {{ $rp($row->revenue) }}">
                    <div class="w-full rounded-t bg-brand-500/80 transition group-hover:bg-brand-600"
                         style="height: {{ max(2, round($row->orders / $max * 100)) }}%"></div>
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex justify-between text-[11px] text-ink-400">
            <span>{{ $chart->first()->label }}</span>
            <span>{{ $chart->last()->label }}</span>
        </div>
    @endif
</div>

<div class="mt-5 grid gap-5 xl:grid-cols-5">
    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white xl:col-span-3">
        <p class="border-b border-ink-100 px-4 py-3 text-sm font-medium text-ink-950">{{ $periods[$per] }}</p>
        @if ($data['periods']->isEmpty())
            <x-ui.empty-state icon="chart" title="Belum ada penjualan" description="Coba lebarkan periodenya." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <th class="px-4 text-right">Pesanan</th>
                            <th class="px-4 text-right">Unit</th>
                            <th class="px-4 text-right">Omzet</th>
                            <th class="px-4 text-right">Batal</th>
                            <th class="px-4 text-right"><span class="inline-flex items-center gap-1">Manual <x-ui.hint text="Dokumen Penjualan Manual yang disetujui: jumlah dokumen dan Total Harga Jual-nya." /></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['periods'] as $row)
                            <tr>
                                <td class="whitespace-nowrap text-sm text-ink-950">{{ $row->label }}</td>
                                <td class="px-4 text-right text-sm font-semibold tabular-nums">{{ $n($row->orders) }}</td>
                                <td class="px-4 text-right text-sm tabular-nums text-ink-600">{{ $n($row->units) }}</td>
                                <td class="whitespace-nowrap px-4 text-right text-sm tabular-nums text-ink-950">{{ $rp($row->revenue) }}</td>
                                <td class="px-4 text-right text-sm tabular-nums {{ $row->cancelled ? 'text-red-600' : 'text-ink-300' }}">{{ $row->cancelled ? $n($row->cancelled) : '—' }}</td>
                                <td class="whitespace-nowrap px-4 text-right text-sm tabular-nums text-ink-600">
                                    @if ($row->manual_orders)
                                        {{ $n($row->manual_orders) }}
                                        @if ($row->manual_revenue > 0)<span class="block text-xs text-ink-400">{{ $rp($row->manual_revenue) }}</span>@endif
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white xl:col-span-2">
        <p class="border-b border-ink-100 px-4 py-3 text-sm font-medium text-ink-950">Per Toko & Marketplace</p>
        @if ($data['stores']->isEmpty())
            <x-ui.empty-state icon="marketplace" title="Belum ada penjualan" description="Coba lebarkan periodenya." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Toko</th>
                            <th class="px-4 text-right">Pesanan</th>
                            <th class="px-4 text-right">Unit</th>
                            <th class="px-4 text-right">Omzet</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['stores'] as $row)
                            <tr>
                                <td class="text-sm">
                                    <p class="font-medium text-ink-950">{{ $row->marketplace }}</p>
                                    @if ($row->store !== '')
                                        <p class="text-xs text-ink-500">{{ $row->store }}</p>
                                    @endif
                                </td>
                                <td class="px-4 text-right text-sm font-semibold tabular-nums">{{ $n($row->orders) }}</td>
                                <td class="px-4 text-right text-sm tabular-nums text-ink-600">{{ $n($row->units) }}</td>
                                <td class="whitespace-nowrap px-4 text-right text-sm tabular-nums">{{ $row->revenue > 0 ? $rp($row->revenue) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

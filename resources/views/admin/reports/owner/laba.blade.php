@php
    $t = $data['totals'];
    $marketplaceList = $marketplaces->concat(array_keys($data['fees']))->push('TikTok Shop')->unique()->sort()->values();
@endphp

<div class="grid grid-cols-2 gap-4 xl:grid-cols-5">
    <x-ui.stat-card label="Laba Kotor" :value="$rp($t['profit'])" icon="trending-up" accent
                    info="Omzet dikurangi potongan marketplace dan HPP × unit terjual, dari barang yang sudah punya HPP dan harga jual."
                    :hint="'Margin '.$pct($t['margin'])" />
    <x-ui.stat-card label="Omzet Dihitung" :value="$rp($t['costed_revenue'])" icon="chart" hint="Dari barang yang sudah ada HPP-nya" />
    <x-ui.stat-card label="Total HPP" :value="$rp($t['cost'])" icon="box" />
    <x-ui.stat-card label="Potongan Marketplace" :value="$rp($t['fee'])" icon="marketplace" hint="Dari persen di bawah" />
    <x-ui.stat-card label="Barang Tanpa HPP" :value="$n($t['missing_cost'])" icon="warning"
                    :hint="$t['missing_cost'] ? $rp($t['missing_cost_revenue']).' omzet belum dihitung' : 'Semua sudah ada HPP'"
                    :href="route('admin.products.index')" />
</div>

@if ($t['missing_cost'] || $t['unpriced_units'])
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        @if ($t['missing_cost'])
            {{ $n($t['missing_cost']) }} barang terjual belum punya HPP, jadi labanya belum dihitung. Isi HPP di menu Barang (form barang atau import Excel kolom "HPP").
        @endif
        @if ($t['unpriced_units'])
            {{ $n($t['unpriced_units']) }} unit terjual belum punya harga jual karena resinya diimport sebelum harga dibaca. Unggah ulang berkas TikTok-nya untuk melengkapi.
        @endif
    </div>
@endif

<div class="mt-5 grid gap-5 xl:grid-cols-4">
    <form method="POST" action="{{ route('admin.reports.owner.fees') }}" class="frappe-card h-fit p-4">
        @csrf
        <input type="hidden" name="from" value="{{ $range->from }}">
        <input type="hidden" name="to" value="{{ $range->to }}">
        <p class="text-sm font-medium text-ink-950">Potongan Marketplace</p>
        <p class="mb-3 text-xs text-ink-500">Persen dari omzet yang dipotong marketplace (komisi, biaya layanan, dan sejenisnya).</p>
        <div class="space-y-2">
            @foreach ($marketplaceList as $marketplace)
                <label class="flex items-center justify-between gap-3 text-sm">
                    <span class="text-ink-700">{{ $marketplace }}</span>
                    <span class="relative w-24">
                        <input type="number" name="fees[{{ $marketplace }}]" min="0" max="100" step="0.01"
                               value="{{ $data['fees'][$marketplace] ?? '' }}" placeholder="0" class="form-control pr-6 text-right">
                        <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-ink-400">%</span>
                    </span>
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('fees.*')" class="mt-2" />
        <x-ui.button type="submit" variant="secondary" class="mt-3 w-full">Simpan Potongan</x-ui.button>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white xl:col-span-3">
        <p class="border-b border-ink-100 px-4 py-3 text-sm font-medium text-ink-950">Laba per Barang</p>
        @if ($data['rows']->isEmpty())
            <x-ui.empty-state icon="trending-up" title="Belum ada penjualan berharga" description="Belum ada barang terjual dengan harga jual pada periode ini." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th class="px-3 text-right"><x-ui.sort key="units" first="desc">Unit</x-ui.sort></th>
                            <th class="px-3 text-right"><x-ui.sort key="revenue" first="desc">Omzet</x-ui.sort></th>
                            <th class="px-3 text-right">Potongan</th>
                            <th class="px-3 text-right">HPP</th>
                            <th class="px-3 text-right"><x-ui.sort key="profit" first="desc">Laba Kotor</x-ui.sort></th>
                            <th class="px-3 text-right"><x-ui.sort key="margin" first="desc">Margin</x-ui.sort></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['rows'] as $row)
                            <tr>
                                <td class="text-sm">
                                    <a href="{{ route('admin.products.edit', $row->product) }}" class="font-medium text-ink-950 underline-offset-4 hover:underline">{{ $row->product->name }}</a>
                                    <p class="font-mono text-xs text-ink-500">{{ $row->product->sku }}@if ($row->unit_cost !== null) · HPP {{ $rp($row->unit_cost) }}@endif</p>
                                </td>
                                <td class="px-3 text-right text-sm tabular-nums">{{ $n($row->units) }}</td>
                                <td class="whitespace-nowrap px-3 text-right text-sm tabular-nums">{{ $rp($row->revenue) }}</td>
                                <td class="whitespace-nowrap px-3 text-right text-sm tabular-nums text-ink-600">{{ $row->fee > 0 ? $rp($row->fee) : '—' }}</td>
                                <td class="whitespace-nowrap px-3 text-right text-sm tabular-nums text-ink-600">{{ $row->cost !== null ? $rp($row->cost) : '' }}</td>
                                <td class="whitespace-nowrap px-3 text-right text-sm font-semibold tabular-nums {{ ($row->profit ?? 0) < 0 ? 'text-red-600' : 'text-ink-950' }}">
                                    @if ($row->profit !== null)
                                        {{ $rp($row->profit) }}
                                    @else
                                        <a href="{{ route('admin.products.edit', $row->product) }}" class="text-xs font-medium text-amber-700 hover:underline">Isi HPP</a>
                                    @endif
                                </td>
                                <td class="px-3 text-right text-sm tabular-nums {{ ($row->margin ?? 0) < 0 ? 'text-red-600' : 'text-ink-600' }}">{{ $pct($row->margin) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ui.pagination :paginator="$data['rows']" unit="barang" />
        @endif
    </div>
</div>

@php
    $t = $data['totals'];
    $active = request('umur');
    $tones = ['0-30' => 'text-emerald-700', '31-60' => 'text-ink-950', '61-90' => 'text-amber-700', '90+' => 'text-red-600', 'never' => 'text-red-600'];
@endphp

<div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
    <x-ui.stat-card label="Barang Berstok" :value="$n($t['products'])" icon="box" accent hint="Barang satuan dengan stok lebih dari 0" />
    <x-ui.stat-card label="Total Unit" :value="$n($t['units'])" icon="layers" />
    <x-ui.stat-card label="Stok Diam > 60 Hari" :value="$n($t['idle_units'])" icon="warning"
                    info="Unit dari barang yang terakhir terjual lebih dari 60 hari lalu, atau belum pernah terjual. Ini modal yang tertahan."
                    :hint="$t['units'] > 0 ? $pct($t['idle_units'] / $t['units'] * 100).' dari total unit' : null" />
    <x-ui.stat-card label="Stok Rusak" :value="$n($t['damaged'])" icon="damaged" :href="route('admin.disposals.index')" />
</div>

<div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
    @foreach ($data['buckets'] as $bucket)
        <a href="{{ route('admin.reports.owner', array_filter(['tab' => 'stok', 'umur' => $active === $bucket->key ? null : $bucket->key])) }}"
           @class(['frappe-card block px-4 py-3 transition hover:bg-ink-50', 'ring-2 ring-brand-500' => $active === $bucket->key])>
            <p class="text-xs text-ink-500">{{ $bucket->label }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums {{ $tones[$bucket->key] }}">{{ $n($bucket->units) }} <span class="text-xs font-normal text-ink-400">unit</span></p>
            <p class="text-xs text-ink-500">{{ $n($bucket->products) }} barang</p>
        </a>
    @endforeach
</div>

<div class="mt-5 overflow-hidden rounded-2xl border border-ink-100 bg-white">
    <div class="border-b border-ink-100 px-4 py-3">
        <p class="text-sm font-medium text-ink-950">Umur Stok per Barang</p>
        <p class="text-xs text-ink-500">Diurutkan dari yang paling lama tidak terjual. Klik kotak di atas untuk menyaring.</p>
    </div>
    @if ($data['rows']->isEmpty())
        <x-ui.empty-state icon="box" title="Tidak ada barang" description="Tidak ada barang berstok pada kelompok ini." />
    @else
        <div class="overflow-x-auto">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Barang</th>
                        <th class="px-4 text-right">Stok</th>
                        <th>Terakhir Terjual</th>
                        <th class="px-4 text-right">Diam</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['rows'] as $row)
                        <tr>
                            <td class="text-sm">
                                <a href="{{ route('admin.products.show', $row->product) }}" class="font-medium text-ink-950 underline-offset-4 hover:underline">{{ $row->product->name }}</a>
                                <p class="font-mono text-xs text-ink-500">{{ $row->product->sku }}</p>
                            </td>
                            <td class="px-4 text-right text-sm font-semibold tabular-nums">{{ $n($row->stock) }}</td>
                            <td class="whitespace-nowrap text-sm text-ink-600">{{ $row->last_out?->format('d/m/Y') ?? 'Belum pernah' }}</td>
                            <td class="whitespace-nowrap px-4 text-right text-sm font-semibold tabular-nums {{ $tones[$row->bucket] }}">{{ $row->age !== null ? $n($row->age).' hari' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-ui.pagination :paginator="$data['rows']" unit="barang" />
    @endif
</div>

<p class="mt-4 text-xs leading-relaxed text-ink-400">
    Nilai stok dalam rupiah belum bisa dihitung karena data barang belum punya HPP (harga pokok). Begitu HPP diisi,
    tab ini bisa menampilkan total nilai stok dan nilai stok yang tertahan.
</p>

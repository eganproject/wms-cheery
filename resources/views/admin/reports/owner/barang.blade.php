<div class="grid gap-5 xl:grid-cols-2">
    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        <div class="border-b border-ink-100 px-4 py-3">
            <p class="text-sm font-medium text-ink-950">Barang Terlaris</p>
            <p class="text-xs text-ink-500">Unit terjual di marketplace pada {{ $range->label() }}. Kandidat produksi ulang.</p>
        </div>
        @if ($data['best']->isEmpty())
            <x-ui.empty-state icon="box" title="Belum ada barang terjual" description="Coba lebarkan periodenya." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th class="px-3 text-right"><x-ui.sort key="units" for="laris" first="desc">Terjual</x-ui.sort></th>
                            <th class="px-3 text-right"><x-ui.sort key="revenue" for="laris" first="desc">Omzet</x-ui.sort></th>
                            <th class="px-3 text-right">Stok</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['best'] as $row)
                            <tr>
                                <td class="text-sm">
                                    <a href="{{ route('admin.products.show', $row->product) }}" class="font-medium text-ink-950 underline-offset-4 hover:underline">{{ $row->product->name }}</a>
                                    <p class="font-mono text-xs text-ink-500">{{ $row->product->sku }}</p>
                                </td>
                                <td class="px-3 text-right text-sm font-semibold tabular-nums">{{ $n($row->units) }}</td>
                                <td class="whitespace-nowrap px-3 text-right text-sm tabular-nums">{{ $row->revenue > 0 ? $rp($row->revenue) : '—' }}</td>
                                <td class="px-3 text-right text-sm tabular-nums text-ink-600">{{ $row->product->isBundle() ? 'Paket' : $n($row->product->stock) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ui.pagination :paginator="$data['best']" unit="barang" />
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        <div class="border-b border-ink-100 px-4 py-3">
            <p class="text-sm font-medium text-ink-950">Tidak Laku</p>
            <p class="text-xs text-ink-500">Masih ada stok, tetapi tidak terjual sama sekali pada periode ini. Kandidat diobral.</p>
        </div>
        @if ($data['idle']->isEmpty())
            <x-ui.empty-state icon="check-circle" title="Semua barang bergerak" description="Setiap barang yang punya stok terjual paling tidak sekali." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th class="px-3 text-right"><x-ui.sort key="stock" for="diam" first="desc">Stok</x-ui.sort></th>
                            <th class="px-3"><x-ui.sort key="last_out" for="diam">Terakhir Terjual</x-ui.sort></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['idle'] as $row)
                            <tr>
                                <td class="text-sm">
                                    <a href="{{ route('admin.products.show', $row->product) }}" class="font-medium text-ink-950 underline-offset-4 hover:underline">{{ $row->product->name }}</a>
                                    <p class="font-mono text-xs text-ink-500">{{ $row->product->sku }}</p>
                                </td>
                                <td class="px-3 text-right text-sm font-semibold tabular-nums">{{ $n($row->stock) }}</td>
                                <td class="whitespace-nowrap px-3 text-sm text-ink-600">{{ $row->last_out ? $row->last_out->format('d/m/Y').' ('.$row->last_out->diffForHumans().')' : 'Belum pernah' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ui.pagination :paginator="$data['idle']" unit="barang" />
        @endif
    </div>
</div>

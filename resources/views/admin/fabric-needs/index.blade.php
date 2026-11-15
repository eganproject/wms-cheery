{{--
    Kebutuhan Kain dari Rencana Produksi: harus diproduksi × pemakaian kain
    per unit, dijumlahkan per jenis kain lalu dibandingkan dengan sisa roll.
--}}
<x-app-layout title="Kebutuhan Kain">
    <x-ui.page-header title="Kebutuhan Kain" icon="restock"
                      subtitle="Dari Rencana Produksi: barang yang harus diproduksi × pemakaian kain per unit, dibandingkan dengan sisa roll kain.">
        <x-slot name="actions">
            <x-ui.button :href="$productionUrl" variant="secondary" icon="calendar-days">Rencana Produksi</x-ui.button>
            <x-ui.button :href="route('admin.fabric-needs.export', request()->query())" variant="secondary" icon="excel" data-no-ajax>
                Export Excel
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <form method="GET" action="{{ route('admin.fabric-needs.index') }}"
          class="mb-5 grid grid-cols-2 gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex sm:flex-wrap sm:items-end">
        <x-ui.field label="Jenis pesanan" class="col-span-2 sm:w-44">
            <x-ui.select name="jenis">
                @foreach ($kinds as $value => $label)
                    <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Batas kirim dari" class="sm:w-40">
            <x-text-input type="date" name="dari" :value="$from->format('Y-m-d')" />
        </x-ui.field>
        <x-ui.field label="sampai" class="sm:w-40">
            <x-text-input type="date" name="sampai" :value="$to->format('Y-m-d')" />
        </x-ui.field>
        <x-ui.field label="Kurangi kain keluar sejak" info="Kain yang sudah keluar ke penjahit tetapi barang jadinya belum masuk gudang masih terhitung harus diproduksi. Isi tanggal ini supaya kain yang sudah keluar sejak tanggal itu mengurangi kebutuhan." class="col-span-2 sm:w-52">
            <x-text-input type="date" name="keluar_sejak" :value="$issuedSince?->format('Y-m-d')" />
        </x-ui.field>
        <div class="col-span-2 flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter">Hitung</x-ui.button>
            @if (request()->hasAny(['jenis', 'dari', 'sampai', 'keluar_sejak']))
                <x-ui.button :href="route('admin.fabric-needs.index')" variant="ghost" size="icon" title="Kembali ke bawaan">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <p class="mb-4 text-xs text-ink-500">{{ $description }} · {{ number_format($plan->totals['orders'], 0, ',', '.') }} resi</p>

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Harus Diproduksi" :value="number_format($needs->totals['units'], 0, ',', '.').' unit'" icon="box"
                        :hint="$needs->totals['products'].' barang kurang stok'" :href="$productionUrl" />
        <x-ui.stat-card label="Jenis Kain Dibutuhkan" :value="number_format($needs->totals['fabrics'], 0, ',', '.')" icon="layers" hint="Dari pemakaian kain per barang" />
        <x-ui.stat-card label="Kain Kurang" :value="number_format($needs->totals['short_fabrics'], 0, ',', '.')" icon="warning" accent
                        hint="Jenis kain yang stoknya tidak cukup" />
        <x-ui.stat-card label="Belum Ada Pemakaian" :value="number_format($needs->totals['missing'], 0, ',', '.')" icon="info"
                        :hint="$needs->totals['missing_units'].' unit tidak terhitung kainnya'" />
    </div>

    <div class="mt-5 overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($needs->rows->isEmpty())
            <x-ui.empty-state icon="layers" title="Tidak ada kebutuhan kain"
                              :description="$needs->totals['products'] > 0
                                ? 'Ada barang yang harus diproduksi, tetapi belum ada yang diatur pemakaian kainnya. Atur di halaman detail tiap jenis kain.'
                                : 'Tidak ada barang yang harus diproduksi pada rentang batas kirim ini.'" />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Kain</th>
                            <th class="text-right">Unit Diproduksi</th>
                            <th class="text-right">Kebutuhan</th>
                            @if ($issuedSince)
                                <th class="text-right">Sudah Keluar</th>
                            @endif
                            <th class="text-right">Stok Kain</th>
                            <th class="text-right">Kurang</th>
                            <th class="text-right">Sisa Setelahnya</th>
                        </tr>
                    </thead>
                    @foreach ($needs->rows as $row)
                        @php($fabric = $row['fabric'])
                        <tbody x-data="{ open: false }" class="border-b border-ink-100">
                            <tr class="cursor-pointer" x-on:click="open = ! open">
                                <td>
                                    <div class="flex items-center gap-2">
                                        <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-ink-400 transition" x-bind:class="open && 'rotate-90'" />
                                        <div class="min-w-0">
                                            <a href="{{ route('admin.fabrics.show', $fabric) }}" x-on:click.stop class="font-mono text-xs font-semibold text-ink-800 hover:underline">{{ $fabric->sku }}</a>
                                            <p class="truncate text-sm font-medium text-ink-950">{{ $fabric->displayName() }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right text-sm tabular-nums">{{ number_format($row['units'], 0, ',', '.') }}</td>
                                <td class="text-right text-sm font-semibold tabular-nums">{{ $fabric->format($row['need']) }}</td>
                                @if ($issuedSince)
                                    <td class="text-right text-sm tabular-nums text-ink-500">{{ $fabric->format($row['issued']) }}</td>
                                @endif
                                <td class="text-right text-sm tabular-nums">{{ $fabric->format($row['stock']) }}
                                    <p class="text-[11px] text-ink-400">{{ $row['open_rolls'] }} roll</p></td>
                                <td class="text-right">
                                    @if ($row['short'] > 0)
                                        <span class="text-sm font-semibold tabular-nums text-rose-600">{{ $fabric->format($row['short']) }}</span>
                                    @else
                                        <x-ui.badge variant="success">Cukup</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-right text-sm tabular-nums {{ $row['after'] < 0 ? 'text-rose-600' : 'text-ink-600' }}">{{ $fabric->format($row['after']) }}</td>
                            </tr>
                            <tr x-show="open" x-cloak class="bg-ink-50/60">
                                <td colspan="{{ $issuedSince ? 7 : 6 }}" class="!py-0">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-[11px] uppercase tracking-wide text-ink-400">
                                                <th class="py-2 pl-6 text-left font-medium">Barang</th>
                                                <th class="py-2 text-right font-medium">Harus diproduksi</th>
                                                <th class="py-2 text-right font-medium">Pemakaian / unit</th>
                                                <th class="py-2 pr-2 text-right font-medium">Kebutuhan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($row['products'] as $product)
                                                <tr class="border-t border-ink-100">
                                                    <td class="py-2 pl-6"><span class="font-mono text-xs">{{ $product['sku'] }}</span> <span class="text-ink-600">{{ $product['name'] }}</span></td>
                                                    <td class="py-2 text-right tabular-nums">{{ number_format($product['short'], 0, ',', '.') }}</td>
                                                    <td class="py-2 text-right tabular-nums text-ink-500">{{ $fabric->format($product['usage']) }}</td>
                                                    <td class="py-2 pr-2 text-right font-semibold tabular-nums">{{ $fabric->format($product['need']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

    @if ($needs->missing->isNotEmpty())
        <x-ui.card class="mt-6" title="Barang Belum Ada Pemakaian Kain" padding="p-0"
                   subtitle="Barang ini harus diproduksi tetapi kainnya belum diatur, jadi tidak ikut terhitung. Buka jenis kainnya lalu tambahkan barang ini di Pemakaian Kain per Barang.">
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead><tr><th>Barang</th><th class="text-right">Harus Diproduksi</th></tr></thead>
                    <tbody>
                        @foreach ($needs->missing->take(100) as $product)
                            <tr>
                                <td><span class="font-mono text-xs font-semibold">{{ $product['sku'] }}</span> <span class="text-sm text-ink-700">{{ $product['name'] }}</span></td>
                                <td class="text-right text-sm tabular-nums">{{ number_format($product['short'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($needs->missing->count() > 100)
                <p class="px-5 py-3 text-xs text-ink-400">Dan {{ $needs->missing->count() - 100 }} barang lainnya (lihat Export Excel).</p>
            @endif
        </x-ui.card>
    @endif
</x-app-layout>

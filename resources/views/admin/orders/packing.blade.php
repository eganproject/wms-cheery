{{--
    Packing list: barang yang harus dikemas dari resi yang diimport, dan
    sisanya yang belum lolos QC Kemas. Hanya membaca.
--}}
@php
    use App\Http\Controllers\Admin\PackingListController;

    $keep = fn (array $except, array $set = []) => array_filter(
        array_merge(request()->except($except), $set),
        fn ($value) => filled($value),
    );

    $pill = 'inline-flex max-w-56 items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs whitespace-nowrap transition';
    $pillOn = 'border-brand-500 bg-brand-50 font-medium text-brand-800';
    $pillOff = 'border-ink-200 bg-white text-ink-600 hover:border-ink-300 hover:text-ink-950';
    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $totals = $list->totals;
    $percent = $totals['ordered'] > 0 ? (int) floor($totals['scanned'] * 100 / $totals['ordered']) : 0;
@endphp

<x-app-layout title="Packing List">
    <x-ui.page-header title="Packing List"
                      subtitle="Total barang yang harus dikemas dari resi yang diimport, dan sisanya yang belum lolos QC Kemas. Resi yang sudah dikirim atau dibatalkan tidak dihitung.">
        <x-slot name="actions">
            <x-ui.button type="button" variant="secondary" icon="printer" onclick="window.print()">Cetak</x-ui.button>
            @can('outbounds.scan')
                <x-ui.button :href="route('admin.outbounds.marketplace')" icon="packing">QC Kemas</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" data-packing-totals>
        <x-ui.stat-card label="Resi" :value="$num($totals['orders'])" icon="document" accent
                        :hint="$num($totals['orders_done']).' lolos QC · '.$num($totals['orders_open']).' belum'" />
        <x-ui.stat-card label="Unit Harus Dikemas" :value="$num($totals['ordered'])" icon="box"
                        :hint="$num($totals['skus']).' barang berbeda'" />
        <x-ui.stat-card label="Sudah QC Kemas" :value="$num($totals['scanned'])" icon="check" :hint="$percent.'% dari total unit'" />
        <x-ui.stat-card label="Sisa Belum Dikemas" :value="$num($totals['remaining'])" icon="clock"
                        :hint="$totals['unmatched'] ? $num($totals['unmatched']).' SKU belum terdaftar' : 'Unit yang belum discan'" />
    </div>

    <div class="frappe-card">
        <div class="space-y-2 border-b border-ink-100 px-4 pb-3 pt-4 text-sm print:hidden">
            @if ($marketplaces->isNotEmpty())
                <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start">
                    <span class="w-20 shrink-0 pt-1 text-xs text-ink-500">Marketplace</span>
                    <div class="flex flex-wrap gap-1.5">
                        <a href="{{ route('admin.orders.packing', $keep(['marketplace'])) }}"
                           @class([$pill, $pillOn => ! request()->filled('marketplace'), $pillOff => request()->filled('marketplace')])>Semua</a>
                        @foreach ($marketplaces as $marketplace)
                            <a href="{{ route('admin.orders.packing', $keep(['marketplace'], ['marketplace' => $marketplace])) }}"
                               @class([$pill, $pillOn => request('marketplace') === $marketplace, $pillOff => request('marketplace') !== $marketplace])>
                                <x-ui.marketplace-mark :marketplace="$marketplace" />
                                <span class="truncate">{{ $marketplace }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($stores->isNotEmpty())
                @php $storeLimit = 12; @endphp
                <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start" x-data="{ more: false }">
                    <span class="w-20 shrink-0 pt-1 text-xs text-ink-500">Toko</span>
                    <div class="flex flex-wrap gap-1.5">
                        <a href="{{ route('admin.orders.packing', $keep(['store'])) }}"
                           @class([$pill, $pillOn => ! request()->filled('store'), $pillOff => request()->filled('store')])>Semua</a>
                        @foreach ($stores as $store)
                            <a href="{{ route('admin.orders.packing', $keep(['store'], ['store' => $store])) }}" title="{{ $store }}"
                               @if ($loop->index >= $storeLimit && request('store') !== $store) x-show="more" x-cloak @endif
                               @class([$pill, $pillOn => request('store') === $store, $pillOff => request('store') !== $store])>
                                <x-icon name="store" class="h-3.5 w-3.5" />
                                <span class="truncate">{{ $store }}</span>
                            </a>
                        @endforeach
                        @if ($stores->count() > $storeLimit)
                            <button type="button" class="px-1.5 py-1 text-xs font-medium text-brand-700 hover:underline" @click="more = ! more"
                                    x-text="more ? 'Lebih sedikit' : 'Lainnya ({{ $stores->count() - $storeLimit }})'"></button>
                        @endif
                    </div>
                </div>
            @endif

            @if ($couriers->isNotEmpty())
                <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start">
                    <span class="w-20 shrink-0 pt-1 text-xs text-ink-500">Kurir</span>
                    <div class="flex flex-wrap gap-1.5">
                        <a href="{{ route('admin.orders.packing', $keep(['courier'])) }}"
                           @class([$pill, $pillOn => ! request()->filled('courier'), $pillOff => request()->filled('courier')])>Semua</a>
                        @foreach ($couriers as $courier)
                            <a href="{{ route('admin.orders.packing', $keep(['courier'], ['courier' => $courier])) }}" title="{{ $courier }}"
                               @class([$pill, $pillOn => request('courier') === $courier, $pillOff => request('courier') !== $courier])>
                                <x-icon name="truck" class="h-3.5 w-3.5" />
                                <span class="truncate">{{ $courier }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start">
                <span class="w-20 shrink-0 pt-1 text-xs text-ink-500">Tampilkan</span>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (PackingListController::SHOW as $value => $label)
                        <a href="{{ route('admin.orders.packing', $keep(['show'], ['show' => $value])) }}"
                           @class([$pill, $pillOn => $show === $value, $pillOff => $show !== $value])>{{ $label }}</a>
                    @endforeach
                </div>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.orders.packing') }}" class="flex flex-col gap-3 border-b border-ink-100 p-4 lg:flex-row lg:items-end print:hidden">
            @foreach (['marketplace', 'store', 'courier', 'show'] as $field)
                @if (request()->filled($field))
                    <input type="hidden" name="{{ $field }}" value="{{ request($field) }}">
                @endif
            @endforeach

            <div class="w-full lg:w-72">
                <label for="packing-import" class="mb-1 block text-xs text-ink-500">Batch import</label>
                <select id="packing-import" name="import" class="form-control" onchange="this.form.requestSubmit()">
                    <option value="">Semua batch (pakai tanggal unggah)</option>
                    @foreach ($imports as $batch)
                        <option value="{{ $batch->id }}" @selected($import?->id === $batch->id)>
                            {{ $batch->created_at->format('d/m H:i') }} · {{ \Illuminate\Support\Str::limit($batch->filename, 32) }} ({{ $batch->order_count }} resi)
                        </option>
                    @endforeach
                    @if ($import && ! $imports->contains('id', $import->id))
                        <option value="{{ $import->id }}" selected>{{ $import->filename }}</option>
                    @endif
                </select>
            </div>

            @unless ($import)
                <x-ui.date-filter label="Tanggal unggah berkas" />
            @endunless

            <div class="relative flex-1">
                <span class="pointer-events-none absolute left-0 top-0 flex h-8 w-8 items-center justify-center text-ink-400">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU atau nama barang..." class="form-control pl-8">
            </div>

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" icon="filter">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'import', 'from', 'to', 'range']))
                    <x-ui.button :href="route('admin.orders.packing', request()->only(['marketplace', 'store', 'courier', 'show']))"
                                 variant="ghost" size="icon" title="Reset">
                        <x-icon name="close" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </form>

        @if ($import)
            <p class="flex items-center gap-1.5 border-b border-ink-100 px-4 py-2 text-xs text-ink-600">
                <x-icon name="import" class="h-4 w-4 text-ink-400" />
                Batch <a href="{{ route('admin.imports.show', $import) }}" class="font-medium text-ink-950 hover:underline">{{ $import->filename }}</a>
                · diunggah {{ $import->created_at->format('d/m/Y H:i') }}
            </p>
        @endif

        <div class="overflow-x-auto">
            <table class="erp-table min-w-full text-sm" data-packing-list>
                <thead>
                    <tr>
                        <th class="w-10 text-right">#</th>
                        <th>Barang</th>
                        <th class="text-right" title="Jumlah resi yang memuat barang ini">Resi</th>
                        <th class="text-right" title="Unit yang harus dikemas">Dikemas</th>
                        <th class="text-right" title="Unit yang sudah discan di QC Kemas">Sudah QC</th>
                        <th class="text-right" title="Unit yang belum discan di QC Kemas">Sisa</th>
                        <th class="w-40">Progres</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $rowPercent = $row['ordered'] > 0 ? (int) floor($row['scanned'] * 100 / $row['ordered']) : 0; @endphp
                        <tr class="align-top" x-data="{ open: false }">
                            <td class="text-right tabular-nums text-ink-400">{{ $loop->iteration }}</td>
                            <td>
                                <span @class(['block font-mono text-[13px]', 'text-ink-950' => $row['matched'], 'text-amber-700' => ! $row['matched']])>{{ $row['sku'] }}</span>
                                @if ($row['name'])
                                    <span class="block text-xs text-ink-500">{{ $row['name'] }}</span>
                                @endif
                                <div class="mt-0.5 flex flex-wrap gap-1">
                                    @unless ($row['matched'])
                                        <x-ui.badge variant="warning" title="SKU ini belum ada di master barang, jadi belum bisa discan di QC Kemas.">SKU belum terdaftar</x-ui.badge>
                                    @endunless
                                    @if ($row['from_bundle'])
                                        <x-ui.badge variant="outline" title="Sebagian unit berasal dari isi paket bundling.">Termasuk isi paket</x-ui.badge>
                                    @endif
                                </div>
                                @if ($row['open_resi'] !== [])
                                    <button type="button" class="mt-1 text-[11px] font-medium text-brand-700 hover:underline print:hidden" @click="open = ! open"
                                            x-text="open ? 'Sembunyikan resi' : 'Lihat {{ $row['open_orders'] }} resi belum tuntas'"></button>
                                    <p x-show="open" x-cloak class="mt-1 max-w-xl break-words font-mono text-[11px] text-ink-600">
                                        {{ implode(', ', $row['open_resi']) }}@if ($row['open_orders'] > count($row['open_resi'])), dan {{ $row['open_orders'] - count($row['open_resi']) }} lainnya @endif
                                    </p>
                                @endif
                            </td>
                            <td class="text-right tabular-nums text-ink-600">{{ $num($row['orders']) }}</td>
                            <td class="text-right font-medium tabular-nums text-ink-950">{{ $num($row['ordered']) }}</td>
                            <td class="text-right tabular-nums text-emerald-700">{{ $num($row['scanned']) }}</td>
                            <td @class(['text-right font-semibold tabular-nums', 'text-rose-700' => $row['remaining'] > 0, 'text-ink-400' => $row['remaining'] === 0])>{{ $num($row['remaining']) }}</td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-ink-100">
                                        <div @class(['h-full rounded-full', 'bg-emerald-500' => $rowPercent === 100, 'bg-brand-500' => $rowPercent < 100]) style="width: {{ $rowPercent }}%"></div>
                                    </div>
                                    <span class="w-9 text-right text-xs tabular-nums text-ink-500">{{ $rowPercent }}%</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                <x-ui.empty-state icon="box" title="Tidak ada barang untuk dikemas"
                                                  description="Belum ada resi aktif pada saringan ini. Coba ganti tanggal unggah atau pilih batch import." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot>
                        <tr class="font-semibold">
                            <td></td>
                            <td>Total {{ $rows->count() }} barang</td>
                            <td></td>
                            <td class="text-right tabular-nums">{{ $num($rows->sum('ordered')) }}</td>
                            <td class="text-right tabular-nums text-emerald-700">{{ $num($rows->sum('scanned')) }}</td>
                            <td class="text-right tabular-nums text-rose-700">{{ $num($rows->sum('remaining')) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</x-app-layout>

{{--
    Packing list: barang yang harus dikemas dari resi yang diimport, dan
    sisanya yang belum lolos QC Kemas. Hanya membaca.
--}}
@php
    use App\Http\Controllers\Admin\PackingListController;
    use App\Support\PackingList;

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
                      subtitle="Harian: resi yang stoknya siap, paling mendesak lebih dulu. Keseluruhan: semua resi yang belum mulai diproses. Jumlah tiap SKU dijumlahkan, berikut sisanya yang belum lolos QC Kemas.">
        <x-slot name="actions">
            {{-- Ikut saringan dan mode "Ringkas per" yang aktif. data-no-ajax: unduhan berkas tidak boleh lewat navigasi AJAX. --}}
            <x-ui.button :href="route('admin.orders.packing.excel', request()->query())"
                         variant="secondary" icon="document" data-no-ajax
                         title="Unduh daftar barang dan resi yang belum tuntas sesuai saringan yang aktif">
                Export Excel
            </x-ui.button>
            <x-ui.button :href="route('admin.orders.packing.pdf', request()->query())"
                         variant="secondary" icon="printer" data-no-ajax
                         title="PDF siap cetak untuk packer, dengan gambar barang dan kotak centang">
                Export PDF
            </x-ui.button>
            @can('outbounds.scan')
                <x-ui.button :href="route('admin.outbounds.marketplace')" icon="packing">QC Kemas</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    {{-- Jenis packing list: bagian dari halaman ini sendiri, bukan menu lain. --}}
    <div class="mb-4 flex flex-wrap items-center gap-1.5" data-packing-type>
        @foreach (PackingListController::TYPES as $value => $label)
            <a href="{{ route('admin.orders.packing', array_filter(['jenis' => $value, 'marketplace' => request('marketplace'), 'store' => request('store'), 'courier' => request('courier'), 'per' => request('per')])) }}"
               data-hint-title="{{ $label }}"
               data-hint="{{ match ($value) {
                   PackingListController::DAILY => 'Resi Belum QC yang seluruh barangnya kebagian stok, diurutkan dari yang paling mendesak: prioritas (Instan, besok sampai, dikunci menurut TikTok), lalu batas kirim terdekat.',
                   PackingListController::ALL => 'Semua resi yang belum dikirim dan belum mulai discan di QC Kemas, tanpa melihat stok. Untuk melihat total kebutuhan barang.',
                   default => 'Resi menurut tanggal unggah atau satu batch import, seperti sebelumnya.',
               } }}"
               @class(['inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm whitespace-nowrap transition',
                   'border-brand-500 bg-brand-50 font-medium text-brand-800' => $type === $value,
                   'border-ink-200 bg-white text-ink-600 hover:border-ink-300 hover:text-ink-950' => $type !== $value])>
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" data-packing-totals>
        <x-ui.stat-card label="Resi" :value="$num($totals['orders'])" icon="document" accent
                        :hint="$num($totals['orders_done']).' lolos QC · '.$num($totals['orders_open']).' belum'" />
        <x-ui.stat-card label="Unit Harus Dikemas" :value="$num($totals['ordered'])" icon="box"
                        :hint="$num($totals['skus']).' SKU berbeda'" />
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

            @if ($type === PackingListController::DAILY)
                <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start">
                    <span class="w-20 shrink-0 pt-1 text-xs text-ink-500">Batas kirim</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (PackingListController::DUE as $value => $label)
                            <a href="{{ route('admin.orders.packing', $keep(['batas'], ['batas' => $value])) }}"
                               @class([$pill, $pillOn => $due === $value, $pillOff => $due !== $value])>{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start">
                <span class="w-20 shrink-0 pt-1 text-xs text-ink-500">Ringkas per</span>
                <div class="flex flex-wrap gap-1.5">
                    <a href="{{ route('admin.orders.packing', $keep(['per'])) }}" title="SKU apa adanya seperti di resi. Paket bundling tetap satu baris."
                       @class([$pill, $pillOn => $mode === PackingList::BY_SKU, $pillOff => $mode !== PackingList::BY_SKU])>SKU di resi</a>
                    <a href="{{ route('admin.orders.packing', $keep(['per'], ['per' => PackingList::BY_PRODUCT])) }}" title="Barang yang diambil dari rak. Paket bundling dipecah jadi isinya."
                       @class([$pill, $pillOn => $mode === PackingList::BY_PRODUCT, $pillOff => $mode !== PackingList::BY_PRODUCT])>Barang di rak (paket dipecah)</a>
                </div>
            </div>

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
            @foreach (['marketplace', 'store', 'courier', 'show', 'per', 'jenis', 'batas'] as $field)
                @if (request()->filled($field))
                    <input type="hidden" name="{{ $field }}" value="{{ request($field) }}">
                @endif
            @endforeach

            @if ($type === PackingListController::BY_UPLOAD)
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
            @endif

            <div class="relative flex-1">
                <span class="pointer-events-none absolute left-0 top-0 flex h-8 w-8 items-center justify-center text-ink-400">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU atau nama barang..." class="form-control pl-8">
            </div>

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" icon="filter">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'import', 'from', 'to', 'range']))
                    <x-ui.button :href="route('admin.orders.packing', request()->only(['marketplace', 'store', 'courier', 'show', 'per', 'jenis', 'batas']))"
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
                        <th>SKU</th>
                        <th class="text-right" title="Jumlah resi yang memuat barang ini">Resi</th>
                        <th class="text-right" title="Jumlah SKU ini dari semua resi, dijumlahkan">Total Qty</th>
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
                                <div class="flex items-start gap-3">
                                @if ($row['image_url'])
                                    <a href="{{ $row['image_url'] }}" target="_blank" rel="noopener"
                                       class="block h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-ink-50 ring-1 ring-inset ring-ink-100">
                                        <img src="{{ $row['image_url'] }}" alt="{{ $row['name'] }}" loading="lazy" class="h-full w-full object-cover">
                                    </a>
                                @else
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-ink-50 text-ink-300 ring-1 ring-inset ring-ink-100">
                                        <x-icon name="box" class="h-4 w-4" />
                                    </span>
                                @endif
                                <div class="min-w-0">
                                <span @class(['block font-mono text-[13px]', 'text-ink-950' => $row['matched'], 'text-amber-700' => ! $row['matched']])>{{ $row['sku'] }}</span>
                                @if ($row['name'])
                                    <span class="block text-xs text-ink-500">{{ $row['name'] }}</span>
                                @endif
                                <div class="mt-0.5 flex flex-wrap gap-1">
                                    @unless ($row['matched'])
                                        <x-ui.badge variant="warning" title="SKU ini belum ada di master barang, jadi belum bisa discan di QC Kemas.">SKU belum terdaftar</x-ui.badge>
                                    @endunless
                                    @if ($row['from_bundle'])
                                        @if ($mode === PackingList::BY_SKU)
                                            <x-ui.badge variant="outline" title="SKU paket bundling. Lihat isinya dengan memilih Ringkas per: Barang di rak.">Paket bundling</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="outline" title="Sebagian unit berasal dari isi paket bundling.">Termasuk isi paket</x-ui.badge>
                                        @endif
                                    @endif
                                </div>
                                @if ($row['open_resi'] !== [])
                                    <button type="button" class="mt-1 text-[11px] font-medium text-brand-700 hover:underline print:hidden" @click="open = ! open"
                                            x-text="open ? 'Sembunyikan resi' : 'Lihat {{ $row['open_orders'] }} resi belum tuntas'"></button>
                                    <p x-show="open" x-cloak class="mt-1 max-w-xl break-words font-mono text-[11px] text-ink-600">
                                        {{ implode(', ', $row['open_resi']) }}@if ($row['open_orders'] > count($row['open_resi'])), dan {{ $row['open_orders'] - count($row['open_resi']) }} lainnya @endif
                                    </p>
                                @endif
                                </div>
                                </div>
                            </td>
                            <td class="text-right tabular-nums text-ink-600">{{ $num($row['orders']) }}</td>
                            <td class="text-right text-base font-semibold tabular-nums text-ink-950">{{ $num($row['ordered']) }}</td>
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
                                                  :description="$type === PackingListController::DAILY
                                                      ? 'Belum ada resi yang stoknya siap pada saringan ini. Cek resi Menunggu stok di halaman Pesanan.'
                                                      : 'Belum ada resi aktif pada saringan ini. Coba ganti tanggal unggah atau pilih batch import.'" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot>
                        <tr class="font-semibold">
                            <td></td>
                            <td>Total {{ $rows->count() }} SKU</td>
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

    @if ($resi->isNotEmpty())
        {{-- Label yang diambil dari rak tunggu, paling mendesak lebih dulu. --}}
        <div class="frappe-card mt-4" data-packing-resi>
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-4 py-3">
                <div>
                    <h2 class="text-sm font-semibold text-ink-950">Daftar resi {{ $type === PackingListController::DAILY ? 'yang dikerjakan' : 'belum diproses' }}</h2>
                    <p class="mt-0.5 text-xs text-ink-500">Urut prioritas lalu batas kirim terdekat. Ambil labelnya dari rak tunggu sesuai tanggal pesanan.
                        @if ($totals['orders'] > $resi->count()) Tampil {{ $num($resi->count()) }} dari {{ $num($totals['orders']) }} resi; Export Excel memuat semuanya. @endif
                    </p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="erp-table min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="w-10 text-right">#</th>
                            <th>No. Resi</th>
                            <th>Batas kirim</th>
                            <th>Tanggal pesanan</th>
                            <th>Isi</th>
                            @if ($type === PackingListController::ALL)
                                <th>Stok</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($resi as $order)
                            @php $state = $order->deadlineState(); @endphp
                            <tr class="align-top">
                                <td class="text-right tabular-nums text-ink-400">{{ $loop->iteration }}</td>
                                <td>
                                    <span class="font-mono text-[13px] font-medium text-ink-950">{{ $order->tracking_number }}</span>
                                    <div class="mt-0.5 flex flex-wrap gap-1">
                                        @if ($order->is_priority)
                                            <x-ui.badge variant="purple">Prioritas</x-ui.badge>
                                        @endif
                                        @if ($kind = \App\Http\Controllers\Admin\OrderController::orderKind($order))
                                            <x-ui.badge variant="outline">{{ $kind }}</x-ui.badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($order->ship_by)
                                        <span class="text-xs text-ink-700">{{ $order->ship_by->format('d/m/Y H:i') }}</span>
                                        <x-ui.badge :variant="$state[1]">{{ $state[0] }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="warning">Belum ada batas</x-ui.badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-xs text-ink-500">{{ $order->order_date?->format('d/m/Y H:i') }}</td>
                                <td class="text-xs">{{ $order->items->map(fn ($item) => $item->sku.' ×'.$item->quantity)->implode(', ') }}</td>
                                @if ($type === PackingListController::ALL)
                                    <td class="text-xs">
                                        @if ($order->stock_status === \App\Services\OrderPlanner::READY)
                                            <x-ui.badge variant="success">Siap</x-ui.badge>
                                        @elseif ($order->stock_status === \App\Services\OrderPlanner::WAITING)
                                            <x-ui.badge variant="warning">Menunggu stok</x-ui.badge>
                                        @elseif ($order->stock_status === \App\Services\OrderPlanner::UNKNOWN_SKU)
                                            <x-ui.badge variant="danger">SKU tidak dikenal</x-ui.badge>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-app-layout>

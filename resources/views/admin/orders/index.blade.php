{{--
    Pesanan marketplace, bergaya halaman Pesanan wmsxshopee.

    Tab status hanya tahapan proses internal gudang — dihitung dari dokumen
    gudangnya, bukan dari status marketplace. Status dari berkas import tetap
    ditampilkan per pesanan sebagai keterangan, tapi tidak dijadikan tab.
--}}
@php
    use App\Http\Controllers\Admin\OrderController;
    use App\Models\ShipmentOrder;

    $keep = fn (array $except, array $set = []) => array_filter(
        array_merge(request()->except([...$except, 'page']), $set),
        fn ($value) => filled($value),
    );

    $pill = 'inline-flex max-w-56 items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs whitespace-nowrap transition';
    $pillOn = 'border-brand-500 bg-brand-50 font-medium text-brand-800';
    $pillOff = 'border-ink-200 bg-white text-ink-600 hover:border-ink-300 hover:text-ink-950';
    $chip = 'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs whitespace-nowrap transition';

    $twinsOf = fn ($order) => collect($twins[(string) $order->order_number] ?? [])
        ->reject(fn (string $resi) => $resi === $order->tracking_number)
        ->values();
    $hints = OrderController::stageHints();
@endphp

<x-app-layout title="Pesanan">
    <x-ui.page-header title="Pesanan"
                      subtitle="Semua resi hasil import dan posisinya di gudang: belum QC, sudah QC (menunggu serah terima), dikirim, atau dibatalkan.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.orders.packing', request()->only(['marketplace', 'store', 'courier', 'from', 'to', 'range']))" variant="secondary" icon="list">Packing List</x-ui.button>
            @can('imports.create')
                <x-ui.button :href="route('admin.imports.create')" variant="secondary" icon="import">Import Resi</x-ui.button>
            @endcan
            @can('outbounds.scan')
                <x-ui.button :href="route('admin.outbounds.marketplace')" icon="packing">QC Kemas</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    {{-- Tab status: hanya proses internal gudang. --}}
    <div class="scrollbar-none mb-4 flex gap-1 overflow-x-auto border-b border-ink-200 text-sm" data-order-tabs>
        <a href="{{ route('admin.orders.index', $keep(['stage', 'status'])) }}" data-hint-title="Semua pesanan" data-hint="Seluruh pesanan sesuai filter yang sedang dipakai, dari Belum QC sampai Dibatalkan."
           @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2', 'border-brand-500 font-medium text-ink-950' => $stage === '', 'border-transparent text-ink-500 hover:text-ink-700' => $stage !== ''])>
            Semua <span class="ml-1 text-xs text-ink-400">{{ number_format(array_sum($counts), 0, ',', '.') }}</span>
        </a>
        @foreach (OrderController::STAGE_LABELS as $value => $label)
            <a href="{{ route('admin.orders.index', $keep(['stage', 'status'], ['stage' => $value])) }}" data-hint-title="{{ $label }}" data-hint="{{ $hints[$value] ?? '' }}" @if ($next = OrderController::stageNext()[$value] ?? null) data-hint-next="{{ $next }}" @endif
               @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2', 'border-brand-500 font-medium text-ink-950' => $stage === $value, 'border-transparent text-ink-500 hover:text-ink-700' => $stage !== $value])>
                {{ $label }} <span class="ml-1 text-xs text-ink-400">{{ number_format($counts[$value] ?? 0, 0, ',', '.') }}</span>
            </a>
        @endforeach
    </div>

    {{-- Penanda yang perlu dicek orang, bisa dipakai bersama tab mana pun. --}}
    <div class="mb-4 flex flex-wrap items-center gap-1.5">
        <span class="mr-1 inline-flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide text-ink-500">Perlu dicek <x-ui.hint text="Penanda pesanan yang perlu diperiksa orang. Bisa dipakai bersama tab status mana pun; klik lagi untuk melepas." /></span>
        @php $onlyDuplicates = request()->boolean('duplicate'); @endphp
        <a href="{{ route('admin.orders.index', $keep(['duplicate'], ['duplicate' => $onlyDuplicates ? null : 1])) }}"
           data-hint-title="Resi Duplikat" data-hint="Satu nomor pesanan punya lebih dari satu resi, biasanya karena resi dicetak ulang. Resi lama masih menunggu dipacking, jadi pesanannya berisiko dikirim dua kali." data-hint-next="Klik untuk menampilkan daftarnya, lalu tandai batal resi yang lama dari baris pesanannya."
           @class([$chip, 'font-medium',
               'border-rose-600 bg-rose-600 text-white' => $onlyDuplicates,
               'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300' => ! $onlyDuplicates && $duplicates,
               'border-ink-200 bg-white text-ink-400' => ! $onlyDuplicates && ! $duplicates])>
            <x-icon name="warning" class="h-3.5 w-3.5" /> Resi Duplikat
            @if ($onlyDuplicates)
                <x-icon name="close" class="h-3.5 w-3.5" />
            @else
                <span class="opacity-70">{{ $duplicates }}</span>
            @endif
        </a>
        @php $onlyUnmatched = request()->boolean('unmatched'); @endphp
        <a href="{{ route('admin.orders.index', $keep(['unmatched'], ['unmatched' => $onlyUnmatched ? null : 1])) }}"
           data-hint-title="SKU belum terdaftar" data-hint="Ada SKU pesanan yang belum terdaftar di master barang, jadi barangnya tidak bisa ditarik otomatis saat scan." data-hint-next="Daftarkan SKU-nya di menu Barang, lalu tekan Cocokkan Ulang di Import Resi."
           @class([$chip, 'font-medium',
               'border-amber-600 bg-amber-600 text-white' => $onlyUnmatched,
               'border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300' => ! $onlyUnmatched && $unmatched,
               'border-ink-200 bg-white text-ink-400' => ! $onlyUnmatched && ! $unmatched])>
            <x-icon name="box" class="h-3.5 w-3.5" /> SKU belum terdaftar
            @if ($onlyUnmatched)
                <x-icon name="close" class="h-3.5 w-3.5" />
            @else
                <span class="opacity-70">{{ $unmatched }}</span>
            @endif
        </a>
    </div>

    <div class="frappe-card">
        {{-- Filter sekali klik: marketplace, toko, dan kurir. --}}
        <div class="space-y-2 border-b border-ink-100 px-4 pb-3 pt-4 text-sm">
            @if ($marketplaces->isNotEmpty())
                <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start">
                    <span class="w-20 shrink-0 pt-1 text-xs text-ink-500">Marketplace</span>
                    <div class="flex flex-wrap gap-1.5">
                        <a href="{{ route('admin.orders.index', $keep(['marketplace'])) }}"
                           @class([$pill, $pillOn => ! request()->filled('marketplace'), $pillOff => request()->filled('marketplace')])>Semua</a>
                        @foreach ($marketplaces as $marketplace)
                            <a href="{{ route('admin.orders.index', $keep(['marketplace'], ['marketplace' => $marketplace])) }}"
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
                    <span class="w-20 shrink-0 pt-1 text-xs text-ink-500">Toko <x-ui.hint text="Nama toko dari berkas import Ginee. Pilih satu untuk menyaring pesanannya." /></span>
                    <div class="flex flex-wrap gap-1.5">
                        <a href="{{ route('admin.orders.index', $keep(['store'])) }}"
                           @class([$pill, $pillOn => ! request()->filled('store'), $pillOff => request()->filled('store')])>Semua</a>
                        @foreach ($stores as $store)
                            <a href="{{ route('admin.orders.index', $keep(['store'], ['store' => $store])) }}" title="{{ $store }}"
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
                        <a href="{{ route('admin.orders.index', $keep(['courier'])) }}"
                           @class([$pill, $pillOn => ! request()->filled('courier'), $pillOff => request()->filled('courier')])>Semua</a>
                        @foreach ($couriers as $courier)
                            <a href="{{ route('admin.orders.index', $keep(['courier'], ['courier' => $courier])) }}" title="{{ $courier }}"
                               @class([$pill, $pillOn => request('courier') === $courier, $pillOff => request('courier') !== $courier])>
                                <x-icon name="truck" class="h-3.5 w-3.5" />
                                <span class="truncate">{{ $courier }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Pencarian: satu kata kunci, atau tempel banyak nomor sekaligus (satu per baris). --}}
        <form method="GET" action="{{ route('admin.orders.index') }}" class="space-y-3 border-b border-ink-100 p-4"
              x-data="{ multi: @js($searchTerms > 1) }">
            @foreach (['stage', 'marketplace', 'store', 'courier', 'duplicate', 'unmatched'] as $field)
                @if (request()->filled($field))
                    <input type="hidden" name="{{ $field }}" value="{{ request($field) }}">
                @endif
            @endforeach

            <div class="flex flex-col gap-3 lg:flex-row lg:items-start">
                <div class="flex flex-1 items-start">
                    <select name="search_by" class="form-control w-36 shrink-0 rounded-r-none" aria-label="Cari berdasarkan">
                        @foreach (OrderController::SEARCH_FIELDS as $value => $label)
                            <option value="{{ $value }}" @selected((string) request('search_by') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="relative -ml-px flex-1">
                        <span x-show="! multi" class="pointer-events-none absolute left-0 top-0 flex h-8 w-8 items-center justify-center text-ink-400">
                            <x-icon name="search" class="h-4 w-4" />
                        </span>
                        <input type="search" name="search" value="{{ $searchTerms > 1 ? '' : request('search') }}" x-ref="single" x-show="! multi" :disabled="multi"
                               placeholder="Cari no. resi, no. pesanan, pembeli, toko, atau SKU..." class="form-control rounded-l-none pl-8 pr-9"
                               @paste="const text = $event.clipboardData.getData('text'); if (/[\r\n]/.test(text.trim())) { $event.preventDefault(); multi = true; $nextTick(() => { $refs.multi.value = text.trim(); $refs.multi.focus() }) }">
                        <textarea name="search" rows="4" x-ref="multi" x-show="multi" x-cloak :disabled="! multi"
                                  placeholder="Tempel banyak nomor resi / pesanan / SKU, satu per baris. Ctrl+Enter untuk cari."
                                  class="form-control rounded-l-none pr-9 font-mono text-xs"
                                  @keydown.ctrl.enter.prevent="$el.form.requestSubmit()">{{ $searchTerms > 1 ? request('search') : '' }}</textarea>
                        <button type="button" class="absolute right-1.5 top-1 rounded p-1 text-ink-400 hover:bg-ink-100 hover:text-ink-700"
                                :title="multi ? 'Kembali ke cari satu' : 'Cari banyak sekaligus (satu per baris)'"
                                @click="if (multi) { $refs.single.value = $refs.multi.value.split(/[\r\n]+/).filter(Boolean).join(', ') } else { $refs.multi.value = $refs.single.value.split(/\s*[,;]\s*/).filter(Boolean).join('\n') } multi = ! multi; $nextTick(() => (multi ? $refs.multi : $refs.single).focus())">
                            <x-icon name="list" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                <x-ui.date-filter label="Tanggal unggah berkas" />

                <div class="flex flex-wrap items-center gap-2 lg:ml-auto">
                    <label for="order-per-page" class="whitespace-nowrap text-xs text-ink-500">Tampilkan</label>
                    <select id="order-per-page" name="per_page" class="form-control w-32" onchange="this.form.requestSubmit()">
                        @foreach (OrderController::PER_PAGE as $size)
                            <option value="{{ $loop->first ? '' : $size }}" @selected($perPage === $size)>{{ $size }} / halaman</option>
                        @endforeach
                    </select>
                    <label for="order-sort" class="whitespace-nowrap text-xs text-ink-500">Urutkan</label>
                    <select id="order-sort" name="sort" class="form-control w-48" onchange="this.form.requestSubmit()">
                        @foreach (OrderController::SORTS as $value => $label)
                            <option value="{{ $value }}" @selected((string) request('sort') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-ui.button type="submit" icon="filter">Cari</x-ui.button>
                    @if (request()->hasAny(['search', 'search_by', 'from', 'to', 'range', 'sort']))
                        <x-ui.button :href="route('admin.orders.index', request()->only(['stage', 'marketplace', 'store', 'courier', 'per_page']))"
                                     variant="ghost" size="icon" title="Reset pencarian">
                            <x-icon name="close" class="h-4 w-4" />
                        </x-ui.button>
                    @endif
                </div>
            </div>

            @if ($searchTerms > 1)
                <p class="flex items-center gap-1 text-xs text-ink-500">
                    <x-icon name="info" class="h-4 w-4" /> Mencari {{ $searchTerms }} nomor sekaligus (cocok persis). Ditemukan {{ $orders->total() }} pesanan.
                </p>
            @endif
        </form>

        {{-- Tiap pesanan: baris judul (resi, toko, waktu) lalu baris isi (barang, pengiriman, status, aksi). --}}
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" data-order-list>
                <thead class="bg-ink-50 text-left text-[13px] text-ink-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal"><span class="inline-flex items-center gap-1">Barang Dipesan <x-ui.hint text="SKU dan jumlah per pesanan. SKU berwarna kuning belum terdaftar di master barang." /></span></th>
                        <th class="h-8 px-3 py-0 font-normal"><span class="inline-flex items-center gap-1">Pengiriman <x-ui.hint text="Ekspedisi, metode kirim, nama pembeli, dan catatan pembeli dari berkas import." /></span></th>
                        <th class="h-8 px-3 py-0 font-normal"><span class="inline-flex items-center gap-1">Status Gudang <x-ui.hint text="Posisi pesanan di proses gudang, dihitung dari dokumen gudangnya. Status marketplace hanya ditampilkan sebagai keterangan." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                @forelse ($orders as $order)
                    @php
                        $orderStage = $order->stage();
                        $twin = $twinsOf($order);
                        $unmatchedItems = $order->unmatchedItems();
                    @endphp
                    <tbody @class(['border-t border-ink-200', 'bg-rose-50/40' => $twin->isNotEmpty()])>
                        <tr class="bg-ink-50/70 text-xs">
                            <td colspan="4" class="px-4 py-2">
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                    <span class="font-mono text-[13px] font-semibold text-ink-950" title="Nomor resi">{{ $order->tracking_number }}</span>
                                    <span class="flex min-w-0 items-center gap-1.5 text-ink-700">
                                        <x-ui.marketplace-mark :marketplace="$order->marketplace" />
                                        <span class="max-w-56 truncate">{{ $order->store_name ?: ($order->marketplace ?: 'Tanpa toko') }}</span>
                                        @if ($order->order_number)
                                            <span class="font-mono text-ink-500" title="Nomor pesanan">· {{ $order->order_number }}</span>
                                        @endif
                                    </span>
                                    @if ($twin->isNotEmpty())
                                        <span class="inline-flex items-center gap-1 font-medium text-rose-700" title="Pesanan yang sama juga punya resi: {{ $twin->implode(', ') }}">
                                            <x-icon name="warning" class="h-3.5 w-3.5" /> Resi duplikat: <span class="font-mono">{{ $twin->implode(', ') }}</span>
                                        </span>
                                    @endif
                                    @if ($unmatchedItems->isNotEmpty())
                                        <span class="inline-flex items-center gap-1 font-medium text-amber-700">
                                            <x-icon name="warning" class="h-3.5 w-3.5" /> SKU belum terdaftar
                                        </span>
                                    @endif
                                    <span class="ml-auto flex items-center gap-4 whitespace-nowrap text-ink-500">
                                        @if ($order->order_date)
                                            <span title="Tanggal pesanan di marketplace">Dipesan <span class="text-ink-700">{{ $order->order_date->format('d/m/Y') }}</span></span>
                                        @endif
                                        <span title="Waktu resi diimport">Diunggah <span class="text-ink-700">{{ $order->created_at->format('d/m/Y H:i') }}</span></span>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <tr class="align-top">
                            <td class="px-4 py-3">
                                <ul class="w-64 max-w-full space-y-1">
                                    @foreach ($order->items as $item)
                                        <li class="flex items-start justify-between gap-3 text-xs">
                                            <span class="min-w-0">
                                                <span @class(['block truncate font-mono', 'text-ink-900' => $item->isMatched(), 'text-amber-700' => ! $item->isMatched()])
                                                      title="{{ $item->product_name }}">{{ $item->sku }}</span>
                                                @if ($item->product_name)
                                                    <span class="block truncate text-[11px] text-ink-400">{{ $item->product_name }}</span>
                                                @endif
                                            </span>
                                            <span class="shrink-0 font-medium tabular-nums text-ink-700">×{{ $item->quantity }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($order->items->count() > 1)
                                    <p class="mt-1.5 text-[11px] text-ink-400">{{ $order->items->count() }} SKU · {{ $order->totalQuantity() }} unit</p>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <p class="text-xs text-ink-800">{{ $order->courier ?: 'Tanpa ekspedisi' }}</p>
                                @if ($order->shipping_method)
                                    <p class="text-[11px] text-ink-500">{{ $order->shipping_method }}</p>
                                @endif
                                @if ($order->buyer_name)
                                    <p class="mt-1 flex items-center gap-1 text-[11px] text-ink-500"><x-icon name="user" class="h-3 w-3" /> {{ $order->buyer_name }}</p>
                                @endif
                                @if ($order->buyer_note)
                                    <p class="mt-1 max-w-56 text-[11px] italic text-ink-500" title="Catatan pembeli">“{{ \Illuminate\Support\Str::limit($order->buyer_note, 80) }}”</p>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <x-ui.badge :variant="OrderController::stageTone($orderStage)" class="cursor-help" data-hint-title="{{ OrderController::STAGE_LABELS[$orderStage] }}" data-hint="{{ $hints[$orderStage] ?? '' }}">{{ OrderController::STAGE_LABELS[$orderStage] }}</x-ui.badge>
                                @if ($twin->isNotEmpty())
                                    <x-ui.badge variant="danger" class="ml-1 cursor-help" data-hint-title="Resi duplikat" data-hint="Pesanan yang sama juga punya resi {{ $twin->implode(', ') }}. Pastikan hanya satu resi yang dikirim." data-hint-next="Tandai batal resi yang tidak dipakai.">Duplikat</x-ui.badge>
                                @endif
                                <p class="mt-1 max-w-56 text-[11px] text-ink-500">{{ $order->stageDetail() }}</p>
                                @if ($order->order_status)
                                    <p class="mt-1 text-[11px] text-ink-400" title="Status di marketplace saat diimport (hanya keterangan)">Marketplace: {{ $order->order_status }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-start justify-end gap-0.5">
                                    @if ($order->outbound)
                                        @if ($orderStage === ShipmentOrder::STAGE_AWAITING_QC && ! $order->outbound->isPosted())
                                            @can('outbounds.scan')
                                                <a href="{{ route('admin.outbounds.scan', $order->outbound) }}" class="btn btn-ghost btn-icon" title="Lanjutkan scan di QC Kemas">
                                                    <x-icon name="scan" class="h-4 w-4" />
                                                </a>
                                            @endcan
                                        @endif
                                        @can('outbounds.view')
                                            <a href="{{ route('admin.outbounds.show', $order->outbound) }}" class="btn btn-ghost btn-icon" title="Dokumen {{ $order->outbound->code }}">
                                                <x-icon name="eye" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                    @endif
                                    @if ($order->import)
                                        <a href="{{ route('admin.imports.show', $order->import) }}" class="btn btn-ghost btn-icon" title="Batch import asal resi ini">
                                            <x-icon name="import" class="h-4 w-4" />
                                        </a>
                                    @endif
                                    @if ($orderStage !== ShipmentOrder::STAGE_SHIPPED)
                                        @include('admin.imports.partials.cancel-control')
                                    @endif
                                </div>
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <tr>
                            <td colspan="4">
                                <x-ui.empty-state icon="shopping-cart" title="Belum ada pesanan"
                                                  description="Pesanan muncul di sini setelah berkas resi marketplace diimport. Coba ubah tanggal atau filter bila sudah pernah import." />
                            </td>
                        </tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        @if ($orders->total() > 0)
            <div class="flex flex-col gap-3 border-t border-ink-100 px-4 py-3 lg:flex-row lg:items-center">
                <span class="text-sm text-ink-500">Total {{ number_format($orders->total(), 0, ',', '.') }} pesanan</span>
                @if ($orders->hasPages())
                    <div class="flex-1">{{ $orders->links() }}</div>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>

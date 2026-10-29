<x-app-layout title="Import Resi">
    <x-ui.page-header title="Import Resi" icon="document"
                      subtitle="Data resi hasil eksport Ginee yang dipakai saat scan barang keluar dan retur.">
        <x-slot name="actions">
            @can('imports.create')
                <x-ui.button :href="route('admin.imports.create')" icon="plus">Import Excel</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    @include('admin.imports.partials.tabs', ['batchCount' => $summary['batches']])

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-ui.stat-card label="Resi Terdata" :value="number_format($summary['orders'], 0, ',', '.')" icon="document" accent
                        hint="Siap dipakai saat scan" />
        <x-ui.stat-card label="Baris Barang" :value="number_format($summary['items'], 0, ',', '.')" icon="box"
                        hint="Total baris SKU" />
        <x-ui.stat-card label="SKU Belum Cocok" :value="$summary['unmatched']" icon="warning"
                        hint="Belum ada di master barang" />
        <x-ui.stat-card label="Berkas Import" :value="$summary['batches']" icon="clock" hint="Lihat tab Riwayat Import"
                        :href="route('admin.imports.batches')" />
    </div>

    @if ($summary['unmatched'] > 0)
        <div class="mt-4 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-start">
            <x-icon name="warning" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-amber-900">{{ $summary['unmatched'] }} baris memiliki SKU yang belum terdaftar</p>
                <p class="mt-0.5 text-xs text-amber-800">
                    Pesanan dengan SKU tak dikenal tidak bisa menarik barang otomatis saat scan. Daftarkan SKU-nya di
                    menu Barang, lalu cocokkan ulang — pencocokan hanya terjadi saat berkas diunggah, jadi
                    barang yang baru didaftarkan tidak terbaca dengan sendirinya.
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <x-ui.button :href="route('admin.imports.index', ['match' => 'unmatched'])" variant="secondary" size="sm">
                    Lihat
                </x-ui.button>
                @can('imports.create')
                    {{--
                        Saringan yang sedang aktif ikut terkirim supaya daftar
                        kembali ke tampilan yang sama setelah dicocokkan —
                        di situlah hasilnya paling terlihat.
                    --}}
                    <form method="POST" action="{{ route('admin.imports.rematch') }}">
                        @csrf
                        @foreach (['search', 'marketplace', 'courier', 'match', 'from', 'to'] as $filter)
                            @if (request()->filled($filter))
                                <input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">
                            @endif
                        @endforeach
                        <x-ui.button type="submit" size="sm" icon="refresh">Cocokkan Ulang Semua</x-ui.button>
                    </form>
                @endcan
            </div>
        </div>
    @endif

    <div class="frappe-card mt-5">
        {{-- Saringan dalam satu bilah di atas daftar. --}}
        <form method="GET" action="{{ route('admin.imports.index') }}" data-auto-submit
              class="flex flex-col gap-3 border-b border-ink-100 p-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="relative flex-1">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-400">
                        <x-icon name="search" class="h-4 w-4" />
                    </span>
                    <x-text-input type="search" name="search" :value="request('search')"
                                  placeholder="Cari nomor resi, pesanan, SKU, atau pembeli..." class="pl-8" />
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:flex lg:items-center">
                    <x-ui.select name="marketplace" class="lg:w-40">
                        <option value="">Semua channel</option>
                        @foreach ($marketplaces as $marketplace)
                            <option value="{{ $marketplace }}" @selected(request('marketplace') === $marketplace)>{{ $marketplace }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select name="courier" class="lg:w-44">
                        <option value="">Semua ekspedisi</option>
                        @foreach ($couriers as $courier)
                            <option value="{{ $courier }}" @selected(request('courier') === $courier)>{{ $courier }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select name="match" class="col-span-2 sm:col-span-1 lg:w-44">
                        <option value="">Semua SKU</option>
                        <option value="matched" @selected(request('match') === 'matched')>SKU cocok semua</option>
                        <option value="unmatched" @selected(request('match') === 'unmatched')>Ada SKU belum cocok</option>
                    </x-ui.select>
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <x-ui.date-filter label="Tanggal unggah berkas" />

                <div class="flex items-center gap-2">
                    <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                    @if (request()->hasAny(['search', 'marketplace', 'match', 'courier', 'from', 'to']))
                        <x-ui.button :href="route('admin.imports.index')" variant="ghost" size="icon" title="Reset filter">
                            <x-icon name="refresh" class="h-4 w-4" />
                        </x-ui.button>
                    @endif
                </div>
            </div>
        </form>

        @if ($orders->isEmpty())
            <x-ui.empty-state icon="document" title="Belum ada data resi"
                              description="Import berkas eksport pesanan dari Ginee agar resi bisa dipakai saat scan.">
                @can('imports.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.imports.create')" icon="plus">Import Excel</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            {{-- Tiap resi: baris judul (resi, channel, pesanan, waktu) lalu baris isi (barang, pengiriman, jumlah). --}}
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-ink-50 text-left text-[13px] text-ink-500">
                        <tr>
                            <th class="h-8 px-4 py-0 font-normal">Barang</th>
                            <th class="h-8 px-3 py-0 font-normal">Pengiriman</th>
                            <th class="h-8 px-4 py-0 text-right font-normal">Jumlah</th>
                        </tr>
                    </thead>
                    @foreach ($orders as $order)
                        <tbody class="border-t border-ink-200">
                            <tr class="bg-ink-50/70 text-xs">
                                <td colspan="3" class="px-4 py-2">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                        <span class="break-all font-mono text-[13px] font-semibold text-ink-950">{{ $order->tracking_number }}</span>
                                        @if ($order->marketplace)
                                            <span class="inline-flex items-center gap-1.5 text-ink-700">
                                                <x-ui.marketplace-mark :marketplace="$order->marketplace" /> {{ $order->store_name ?: $order->marketplace }}
                                            </span>
                                        @elseif ($order->store_name)
                                            <span class="text-ink-700">{{ $order->store_name }}</span>
                                        @endif
                                        @if ($order->order_number)
                                            <span class="font-mono text-ink-500">· {{ $order->order_number }}</span>
                                        @endif
                                        @if ($order->isFullyMatched())
                                            <x-ui.badge variant="success" icon="check-circle">SKU cocok</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="warning" icon="warning">
                                                {{ $order->unmatchedItems()->count() }} SKU belum cocok
                                            </x-ui.badge>
                                        @endif
                                        <span class="ml-auto flex items-center gap-4 whitespace-nowrap text-ink-500">
                                            @if ($order->order_date)
                                                <span>Dipesan <span class="text-ink-700">{{ $order->order_date->translatedFormat('d M Y H:i') }}</span></span>
                                            @endif
                                            <span>Diunggah <span class="text-ink-700">{{ $order->created_at->translatedFormat('d M Y H:i') }}</span></span>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <div class="space-y-1.5">
                                        @foreach ($order->items as $item)
                                            <div class="flex min-w-[18rem] items-center gap-2">
                                                <x-ui.sku :value="$item->sku" :variant="$item->isMatched() ? 'soft' : 'danger'" />
                                                <span class="min-w-0 flex-1 truncate text-xs text-ink-700">
                                                    {{ $item->product?->name ?? $item->product_name ?? 'Nama produk tidak tersedia' }}
                                                </span>
                                                @unless ($item->isMatched())
                                                    <span class="whitespace-nowrap text-[11px] font-medium text-rose-600">belum ada di master barang</span>
                                                @endunless
                                                <span class="shrink-0 text-xs font-semibold tabular-nums text-ink-950">{{ $item->quantity }}×</span>
                                            </div>
                                        @endforeach
                                    </div>

                                    {{--
                                        Untuk kasus yang paling sering: barangnya baru saja
                                        didaftarkan, dan yang perlu diperbaiki hanya resi
                                        ini. Tombolnya hanya muncul saat memang ada yang
                                        menggantung, dan aman ditekan berulang — ia hanya
                                        mengisi baris yang kosong, tidak pernah mengubah
                                        yang sudah cocok.
                                    --}}
                                    @if (! $order->isFullyMatched())
                                        @can('imports.create')
                                            <div class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-amber-50/70 px-3 py-2">
                                                <p class="min-w-0 flex-1 text-[11px] text-amber-800">
                                                    Sudah mendaftarkan barangnya? Cocokkan ulang untuk memeriksa master barang terbaru.
                                                </p>
                                                <form method="POST" action="{{ route('admin.imports.orders.rematch', $order) }}">
                                                    @csrf
                                                    @foreach (['search', 'marketplace', 'courier', 'match', 'from', 'to'] as $filter)
                                                        @if (request()->filled($filter))
                                                            <input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">
                                                        @endif
                                                    @endforeach
                                                    <x-ui.button type="submit" variant="secondary" size="xs" icon="refresh">
                                                        Cocokkan Ulang
                                                    </x-ui.button>
                                                </form>
                                            </div>
                                        @endcan
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
                                        <p class="mt-1.5 max-w-xs rounded-md bg-amber-50 px-2 py-1 text-[11px] text-amber-800 ring-1 ring-inset ring-amber-200">
                                            <span class="font-semibold">Catatan pembeli:</span> {{ $order->buyer_note }}
                                        </p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <p class="text-sm font-semibold text-ink-950">{{ $order->totalQuantity() }} unit</p>
                                    <p class="text-[11px] text-ink-400">{{ $order->items->count() }} baris SKU</p>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>

            <x-ui.pagination :paginator="$orders" />
        @endif
    </div>
</x-app-layout>

{{--
    Daftar lengkap SKU yang belum dikenal sistem (peringatan di atas hanya
    menampilkan 12 teratas). Dibuka dari tombol "Lihat semua SKU"; isinya sama
    dengan saringan yang sedang aktif.
--}}
<div x-show="allSkus" x-cloak
     x-data="{ q: '', copied: false, skus: @js($unknownSkus->pluck('sku')->values()) }"
     @keydown.escape.window="allSkus = false"
     class="fixed inset-0 z-[70] flex items-end justify-center p-3 sm:items-start sm:p-4 sm:pt-[8vh]"
     role="dialog" aria-modal="true" aria-labelledby="unknown-skus-title">
    <div x-show="allSkus"
         x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0"
         x-transition:leave="ease-in duration-100" x-transition:leave-end="opacity-0"
         @click="allSkus = false" class="fixed inset-0 bg-ink-950/30"></div>

    <div x-show="allSkus"
         x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:leave="ease-in duration-100" x-transition:leave-end="opacity-0 translate-y-2"
         x-effect="if (allSkus) $nextTick(() => $refs.search.focus())"
         class="relative flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-lift">
        <div class="flex items-start justify-between gap-3 px-5 pt-4">
            <div class="min-w-0">
                <h3 id="unknown-skus-title" class="text-lg font-semibold text-ink-950">SKU belum cocok dengan master barang</h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    {{ $unknownSkus->count() }} SKU · {{ number_format($unmatched, 0, ',', '.') }} pesanan terdampak.
                    Mengikuti saringan tanggal, marketplace, kurir, dan pencarian yang sedang aktif.
                </p>
            </div>
            <button type="button" @click="allSkus = false" class="btn btn-ghost btn-icon -mr-2 shrink-0 text-ink-500">
                <x-icon name="close" class="h-4 w-4" />
                <span class="sr-only">Tutup</span>
            </button>
        </div>

        <div class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center">
            <div class="relative min-w-0 flex-1">
                <x-icon name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ink-400" />
                <input type="search" x-model="q" x-ref="search" placeholder="Cari SKU atau nama barang..."
                       class="form-control w-full pl-8">
            </div>
            <div class="flex shrink-0 gap-2">
                <x-ui.button type="button" variant="secondary" size="sm" icon="copy"
                             title="Salin semua SKU, satu per baris"
                             @click="navigator.clipboard.writeText(skus.join('\n')).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                    <span x-text="copied ? 'Tersalin' : 'Salin SKU'">Salin SKU</span>
                </x-ui.button>
                <x-ui.button :href="route('admin.orders.unknown-skus.export', request()->query())"
                             variant="secondary" size="sm" icon="excel" data-no-ajax
                             title="Unduh daftar SKU ini ke Excel">
                    Excel
                </x-ui.button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto border-t border-ink-100">
            <table class="erp-table w-full table-fixed text-sm">
                <thead class="sticky top-0 z-10 bg-white">
                    <tr>
                        <th class="w-12 text-right">No</th>
                        <th>SKU di resi</th>
                        <th class="w-20 text-right">Pesanan</th>
                        <th class="w-16 text-right">Unit</th>
                        <th class="w-48 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($unknownSkus as $unknown)
                        <tr x-show="! q || $el.dataset.find.includes(q.trim().toLowerCase())"
                            data-find="{{ mb_strtolower($unknown->sku.' '.$unknown->product_name) }}">
                            <td class="text-right text-ink-400">{{ $loop->iteration }}</td>
                            <td>
                                <div class="truncate font-mono font-medium text-ink-950" title="{{ $unknown->sku }}">{{ $unknown->sku }}</div>
                                @if ($unknown->product_name)
                                    <div class="truncate text-xs text-ink-500" title="{{ $unknown->product_name }}">{{ $unknown->product_name }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">{{ number_format($unknown->orders, 0, ',', '.') }}</td>
                            <td class="whitespace-nowrap text-right text-ink-500">{{ number_format($unknown->units, 0, ',', '.') }}</td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('admin.orders.index', $keep(['search', 'search_by', 'unmatched'], ['search' => $unknown->sku, 'search_by' => 'sku'])) }}"
                                       class="btn btn-ghost btn-xs" title="Tampilkan pesanan dengan SKU ini">Pesanan</a>
                                    @can('products.create')
                                        <a href="{{ route('admin.products.create', ['sku' => $unknown->sku, 'name' => $unknown->product_name]) }}"
                                           class="btn btn-default btn-xs" title="Daftarkan {{ $unknown->sku }} di master barang">
                                            <x-icon name="plus" class="h-3.5 w-3.5" /> Daftarkan
                                        </a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="border-t border-ink-100 bg-ink-50/60 px-5 py-2.5 text-xs text-ink-500">
            Setelah SKU didaftarkan (atau diperbaiki agar sama persis), tekan <strong>Cocokkan Ulang</strong> supaya pesanan lama ikut tersambung.
        </div>
    </div>
</div>

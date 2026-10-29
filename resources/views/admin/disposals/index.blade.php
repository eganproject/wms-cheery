{{--
    Barang rusak dalam satu menu dengan dua tab:

    - Saldo     : barang apa saja yang rusak dan masih ada fisiknya di gudang.
    - Riwayat   : dokumen pengeluaran — dibuang, dikembalikan ke pemasok,
                  diperbaiki, atau dipindah antar saldo.

    Keduanya tidak punya menu sendiri, jadi tab di sini tidak mengulang menu.
--}}
<x-app-layout title="Barang Rusak">
    <x-ui.page-header title="Barang Rusak" icon="trash"
                      subtitle="Saldo barang rusak, beserta dokumen setiap barang yang keluar di luar penjualan.">
        <x-slot name="actions">
            @can('disposals.create')
                {{--
                    Saldo asalnya dipilih di sini, bukan di dalam form: dengan
                    begitu daftar barang dan pilihan tindakannya sudah tepat
                    sejak halaman dibuka.
                --}}
                <x-ui.button :href="route('admin.disposals.create', ['bucket' => 'good'])"
                             variant="secondary" icon="logout">
                    Keluarkan Barang Layak Jual
                </x-ui.button>
                <x-ui.button :href="route('admin.disposals.create')" icon="plus">Tangani Barang Rusak</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-3">
        <x-ui.stat-card label="Unit Rusak" info="Unit rusak yang masih menunggu ditangani." :value="number_format($summary['units'], 0, ',', '.')" icon="warning" accent
                        hint="Masih tersimpan di gudang" />
        <x-ui.stat-card label="Jenis Barang" :value="$summary['skus']" icon="box" hint="SKU yang punya stok rusak" />
        <x-ui.stat-card label="Menunggu Persetujuan" info="Dokumen pengeluaran barang yang belum disetujui." :value="$summary['pending']" icon="clock"
                        hint="Dokumen pengeluaran" />
    </div>

    {{-- Tab --}}
    @php
        $tabs = [
            'saldo' => ['label' => 'Saldo Barang Rusak', 'count' => $summary['skus']],
            'riwayat' => ['label' => 'Riwayat Pengeluaran', 'count' => $summary['documents']],
        ];
    @endphp
    <div class="scrollbar-none mb-4 mt-5 flex gap-1 overflow-x-auto border-b border-ink-200 text-sm" data-disposal-tabs>
        @foreach ($tabs as $value => $item)
            <a href="{{ route('admin.disposals.index', ['tab' => $value]) }}"
               @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2', 'border-brand-500 font-medium text-ink-950' => $tab === $value, 'border-transparent text-ink-500 hover:text-ink-700' => $tab !== $value])>
                {{ $item['label'] }}
                <span class="ml-1 text-xs text-ink-400">{{ number_format($item['count'], 0, ',', '.') }}</span>
            </a>
        @endforeach
    </div>

    @if ($tab === 'saldo')
        {{-- ============================================== Saldo ========== --}}
        <form method="GET" action="{{ route('admin.disposals.index') }}"
              class="mb-4 flex flex-wrap items-center gap-2 rounded-2xl border border-ink-100 bg-white p-4">
            <input type="hidden" name="tab" value="saldo">
            <div class="min-w-0 flex-1 sm:max-w-sm">
                <input type="search" name="search" value="{{ request('search') }}" class="form-control"
                       placeholder="Cari SKU atau nama barang…">
            </div>
            <x-ui.button type="submit" variant="secondary" icon="search">Cari</x-ui.button>
            @if (request()->filled('search'))
                <x-ui.button :href="route('admin.disposals.index', ['tab' => 'saldo'])"
                             variant="ghost" size="icon" title="Reset pencarian">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </form>

        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
            @if ($damaged->isEmpty())
                <x-ui.empty-state icon="check-circle" title="Tidak ada barang rusak"
                                  description="Barang rusak masuk ke sini otomatis dari barang masuk, penerimaan retur, dan stok opname." />
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Barang</th>
                                <th>Kategori</th>
                                <th class="text-right">Stok Rusak</th>
                                <th class="text-right">Stok Layak Jual</th>
                                <th class="text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($damaged as $product)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.products.show', $product) }}"
                                           class="text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                            {{ $product->name }}
                                        </a>
                                        <div class="mt-1"><x-ui.sku :value="$product->sku" /></div>
                                    </td>
                                    <td class="text-sm text-ink-500">{{ $product->category ?: '—' }}</td>
                                    <td class="text-right">
                                        <span class="text-sm font-semibold tabular-nums text-red-600">
                                            {{ number_format($product->damaged_stock, 0, ',', '.') }}
                                        </span>
                                        <span class="text-xs text-ink-400">{{ $product->unit }}</span>
                                    </td>
                                    <td class="text-right text-sm tabular-nums text-ink-600">
                                        {{ number_format($product->stock, 0, ',', '.') }}
                                    </td>
                                    <td>
                                        <div class="flex justify-end">
                                            <a href="{{ route('admin.movements.index', ['bucket' => 'damaged', 'search' => $product->sku]) }}"
                                               title="Kartu stok rusak"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="eye" class="h-4 w-4" />
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$damaged" />
            @endif
        </div>
    @else
        {{-- ============================================== Riwayat ======== --}}
        <form method="GET" action="{{ route('admin.disposals.index') }}" data-auto-submit
              class="mb-4 space-y-3 rounded-2xl border border-ink-100 bg-white p-4">
            <input type="hidden" name="tab" value="riwayat">

            <div class="grid grid-cols-1 gap-3 sm:flex sm:items-center">
                <div class="min-w-0 sm:w-72">
                    <input type="search" name="search" value="{{ request('search') }}" class="form-control"
                           placeholder="Cari nomor dokumen atau catatan…">
                </div>

                <x-ui.select name="bucket" class="sm:w-48">
                    <option value="">Semua saldo asal</option>
                    <option value="damaged" @selected(request('bucket') === 'damaged')>Stok rusak</option>
                    <option value="good" @selected(request('bucket') === 'good')>Stok layak jual</option>
                </x-ui.select>

                <x-ui.select name="action" class="sm:w-56">
                    <option value="">Semua tindakan</option>
                    @foreach (App\Models\DamagedDisposal::actions() as $value => $label)
                        <option value="{{ $value }}" @selected(request('action') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <x-ui.date-filter label="Tanggal pengeluaran" />

                <div class="flex items-center gap-2">
                    <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                    @if (request()->hasAny(['search', 'bucket', 'action', 'from', 'to']))
                        <x-ui.button :href="route('admin.disposals.index', ['tab' => 'riwayat'])"
                                     variant="ghost" size="icon" title="Reset filter">
                            <x-icon name="refresh" class="h-4 w-4" />
                        </x-ui.button>
                    @endif
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
            @if ($disposals->isEmpty())
                <x-ui.empty-state icon="document" title="Belum ada pengeluaran"
                                  description="Buat dokumen untuk mengeluarkan barang dari gudang di luar penjualan." />
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Dokumen</th>
                                <th>Saldo Asal</th>
                                <th>Tindakan</th>
                                <th class="text-right">Barang</th>
                                <th class="text-right">Total Unit</th>
                                <th>Dibuat Oleh</th>
                                <th>Status</th>
                                <th class="text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($disposals as $disposal)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.disposals.show', $disposal) }}"
                                           class="font-mono text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                            {{ $disposal->code }}
                                        </a>
                                        <p class="text-xs text-ink-400">{{ $disposal->date->translatedFormat('d M Y') }}</p>
                                    </td>
                                    <td>
                                        <x-ui.badge :variant="$disposal->isFromDamaged() ? 'danger' : 'outline'">
                                            {{ $disposal->bucketLabel() }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="text-sm text-ink-700">{{ $disposal->actionLabel() }}</td>
                                    <td class="text-right text-sm tabular-nums text-ink-600">{{ $disposal->items_count }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums text-ink-950">
                                        {{ number_format((int) $disposal->items_sum_quantity, 0, ',', '.') }}
                                    </td>
                                    <td class="text-sm text-ink-600">{{ $disposal->user?->name ?? '—' }}</td>
                                    <td>
                                        <x-ui.badge :variant="$disposal->statusVariant()" :icon="$disposal->statusIcon()">
                                            {{ $disposal->statusLabel() }}
                                        </x-ui.badge>
                                    </td>
                                    <td>
                                        <div class="flex justify-end">
                                            <a href="{{ route('admin.disposals.show', $disposal) }}" title="Detail"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="eye" class="h-4 w-4" />
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$disposals" />
            @endif
        </div>
    @endif
</x-app-layout>

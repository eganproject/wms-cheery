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
            {{-- Isi berkas mengikuti tab yang terbuka: saldo rusak atau riwayat penanganan. --}}
            <x-ui.button :href="route('admin.disposals.export', array_merge(request()->query(), ['tab' => $tab]))"
                         variant="secondary" icon="document" data-no-ajax
                         title="Unduh isi tab yang sedang terbuka sesuai saringan">
                Export Excel
            </x-ui.button>
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
            'pemasok' => ['label' => 'Retur ke Pemasok', 'count' => $summary['open_returns']],
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
                                  description="Barang rusak masuk ke sini otomatis dari penerimaan barang, penerimaan retur, dan stok opname." />
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th><x-ui.sort key="name" for="stok">Barang</x-ui.sort></th>
                                <th><x-ui.sort key="category" for="stok">Kategori</x-ui.sort></th>
                                <th class="text-right"><x-ui.sort key="damaged" for="stok" first="desc">Stok Rusak</x-ui.sort></th>
                                <th class="text-right"><x-ui.sort key="stock" for="stok" first="desc">Stok Layak Jual</x-ui.sort></th>
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
                                        <div class="flex justify-end gap-1">
                                            @can('disposals.create')
                                                <x-ui.button :href="route('admin.disposals.create', ['product' => $product->id])"
                                                             variant="secondary" size="xs">
                                                    Tangani
                                                </x-ui.button>
                                            @endcan
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
    @elseif ($tab === 'pemasok')
        {{-- ======================================= Retur ke pemasok ====== --}}
        <form method="GET" action="{{ route('admin.disposals.index') }}" data-auto-submit
              class="mb-4 flex flex-wrap items-center gap-2 rounded-2xl border border-ink-100 bg-white p-4">
            <input type="hidden" name="tab" value="pemasok">
            <div class="min-w-0 flex-1 sm:max-w-xs">
                <input type="search" name="search" value="{{ request('search') }}" class="form-control"
                       placeholder="Cari nomor dokumen, catatan, atau pemasok…">
            </div>
            <x-ui.select name="return_status" class="sm:w-56">
                <option value="open" @selected(request('return_status', 'open') === 'open')>Belum selesai</option>
                @foreach (App\Models\DamagedDisposal::returnStatuses() as $value => $label)
                    <option value="{{ $value }}" @selected(request('return_status') === $value)>{{ $label }}</option>
                @endforeach
                <option value="all" @selected(request('return_status') === 'all')>Semua status</option>
            </x-ui.select>
            <x-ui.select name="supplier_id" class="sm:w-56">
                <option value="">Semua pemasok</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected((int) request('supplier_id') === $supplier->id)>{{ $supplier->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.button type="submit" variant="secondary" icon="filter">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'return_status', 'supplier_id']))
                <x-ui.button :href="route('admin.disposals.index', ['tab' => 'pemasok'])"
                             variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </form>

        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
            @if ($returns->isEmpty())
                <x-ui.empty-state icon="truck" title="Tidak ada retur ke pemasok"
                                  description="Retur muncul di sini setelah dokumen &quot;Dikembalikan ke pemasok&quot; diproses." />
            @else
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th><x-ui.sort key="date" for="retur" first="desc">Dokumen</x-ui.sort></th>
                                <th>Pemasok</th>
                                <th><x-ui.sort key="shipped" for="retur" first="desc">Dikirim</x-ui.sort></th>
                                <th class="text-right"><x-ui.sort key="quantity" for="retur" first="desc">Dikirim (unit)</x-ui.sort></th>
                                <th class="text-right">Diganti</th>
                                <th class="text-right">Ditolak</th>
                                <th class="text-right">Sisa Ditunggu</th>
                                <th><x-ui.sort key="status" for="retur">Status</x-ui.sort></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($returns as $return)
                                @php
                                    $sent = (int) $return->items_sum_quantity;
                                    $replaced = (int) $return->items_sum_replaced_quantity;
                                    $rejected = (int) $return->items_sum_rejected_quantity;
                                    $days = $return->daysWithSupplier();
                                @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.disposals.show', $return) }}"
                                           class="font-mono text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                            {{ $return->code }}
                                        </a>
                                        <p class="text-xs text-ink-400">{{ $return->date->translatedFormat('d M Y') }} · {{ $return->bucketLabel() }}</p>
                                    </td>
                                    <td class="text-sm text-ink-700">{{ $return->supplier?->name ?? '—' }}</td>
                                    <td class="text-sm text-ink-600">
                                        {{ $return->shipped_at?->translatedFormat('d M Y') ?? '—' }}
                                        @if ($return->tracking_number)
                                            <p class="font-mono text-xs text-ink-400">{{ $return->courier ? $return->courier.' · ' : '' }}{{ $return->tracking_number }}</p>
                                        @endif
                                        @if ($days !== null)
                                            <p @class(['text-xs', 'font-medium text-red-600' => $days > 14, 'text-ink-400' => $days <= 14])>{{ $days }} hari di pemasok</p>
                                        @endif
                                    </td>
                                    <td class="text-right text-sm tabular-nums">{{ number_format($sent, 0, ',', '.') }}</td>
                                    <td class="text-right text-sm tabular-nums text-emerald-700">{{ number_format($replaced, 0, ',', '.') }}</td>
                                    <td class="text-right text-sm tabular-nums text-red-600">{{ number_format($rejected, 0, ',', '.') }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums text-ink-950">{{ number_format(max(0, $sent - $replaced - $rejected), 0, ',', '.') }}</td>
                                    <td><x-ui.badge :variant="$return->returnStatusVariant()">{{ $return->returnStatusLabel() }}</x-ui.badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$returns" />
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
                           placeholder="Cari nomor dokumen, catatan, atau pemasok…">
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
                                <th><x-ui.sort key="date" for="dokumen" first="desc">Dokumen</x-ui.sort></th>
                                <th><x-ui.sort key="bucket" for="dokumen">Saldo Asal</x-ui.sort></th>
                                <th><x-ui.sort key="action" for="dokumen">Tindakan</x-ui.sort></th>
                                <th class="text-right"><x-ui.sort key="items" for="dokumen" first="desc">Barang</x-ui.sort></th>
                                <th class="text-right"><x-ui.sort key="quantity" for="dokumen" first="desc">Total Unit</x-ui.sort></th>
                                <th>Dibuat Oleh</th>
                                <th><x-ui.sort key="status" for="dokumen">Status</x-ui.sort></th>
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
                                    <td class="text-sm text-ink-700">
                                        {{ $disposal->actionLabel() }}
                                        @if ($disposal->supplier)
                                            <p class="text-xs text-ink-400">{{ $disposal->supplier->name }}</p>
                                        @endif
                                    </td>
                                    <td class="text-right text-sm tabular-nums text-ink-600">{{ $disposal->items_count }}</td>
                                    <td class="text-right text-sm font-semibold tabular-nums text-ink-950">
                                        {{ number_format((int) $disposal->items_sum_quantity, 0, ',', '.') }}
                                    </td>
                                    <td class="text-sm text-ink-600">{{ $disposal->user?->name ?? '—' }}</td>
                                    <td>
                                        <x-ui.badge :variant="$disposal->statusVariant()" :icon="$disposal->statusIcon()">
                                            {{ $disposal->statusLabel() }}
                                        </x-ui.badge>
                                        @if ($disposal->tracksSupplierReturn())
                                            <p class="mt-1 text-xs text-ink-400">{{ $disposal->returnStatusLabel() }}</p>
                                        @endif
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

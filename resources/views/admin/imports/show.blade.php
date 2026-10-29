<x-app-layout title="Detail Import">
    <x-ui.page-header :title="$import->filename" :subtitle="'Diimport '.$import->created_at->translatedFormat('d F Y H:i').' oleh '.($import->user?->name ?? 'sistem')"
                      :back="route('admin.imports.batches')">
        <x-slot name="actions">
            @if ($import->failed_row_count > 0)
                <x-ui.button :href="route('admin.imports.failures', $import)" variant="secondary" icon="download">Unduh Gagal Import</x-ui.button>
            @endif
            <x-ui.button :href="route('admin.orders.packing', ['import' => $import->id])" variant="secondary" icon="list">Packing List</x-ui.button>
            @can('imports.create')
                <x-ui.button :href="route('admin.imports.create')" variant="secondary" icon="plus">Import Lagi</x-ui.button>
            @endcan
            @can('imports.delete')
                <x-ui.confirm-delete :action="route('admin.imports.destroy', $import)"
                                     title="Hapus data import ini?"
                                     :description="'Seluruh '.$import->order_count.' resi dari berkas ini akan ikut terhapus.'">
                    <x-slot name="trigger">
                        <x-ui.button type="button" variant="danger-soft" icon="trash">Hapus</x-ui.button>
                    </x-slot>
                </x-ui.confirm-delete>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Resi" :value="$import->order_count" icon="document" accent />
        <x-ui.stat-card label="Baris Barang" :value="$import->item_count" icon="box" />
        <x-ui.stat-card label="SKU Belum Cocok" info="Baris barang di berkas ini yang SKU-nya belum ada di master barang." :value="$import->unmatched_sku_count" icon="warning" />
        <x-ui.stat-card label="Gagal Diimport" info="Baris berkas yang tidak masuk ke sistem, misalnya resi kosong atau resi yang sudah pernah diimport. Unduh daftarnya untuk melihat alasannya." :value="$import->failed_row_count" icon="x-circle" :hint="'dari '.$import->row_count.' baris dibaca'" />
    </div>

    @if ($import->failed_row_count > 0)
        <div class="mt-5 rounded-2xl border border-red-100 bg-red-50/60 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="flex items-center gap-2 text-sm font-semibold text-red-800">
                        <x-icon name="warning" class="h-4 w-4" /> {{ $import->failed_row_count }} baris gagal diimport
                    </p>
                    <p class="mt-1 text-xs text-red-700">Baris ini tidak masuk ke sistem. Unduh daftarnya untuk melihat nomor baris, resi, dan keterangan lengkapnya.</p>
                </div>
                <x-ui.button :href="route('admin.imports.failures', $import)" variant="secondary" icon="excel">Unduh Excel</x-ui.button>
            </div>

            <ul class="mt-4 flex flex-wrap gap-1.5">
                @foreach ($import->failureSummary as $group)
                    <li><x-ui.badge variant="danger">{{ $group->reason }} &middot; {{ $group->total }}</x-ui.badge></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($import->detected_columns)
        <div class="mt-5 rounded-2xl border border-ink-100 bg-white p-5">
            <p class="text-xs text-ink-400">Kolom yang terdeteksi</p>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($import->detected_columns as $column)
                    <x-ui.badge variant="outline" icon="check">{{ str($column)->replace('_', ' ')->title() }}</x-ui.badge>
                @endforeach
            </div>
        </div>
    @endif

    <div class="mt-6 overflow-hidden rounded-2xl border border-ink-100 bg-white">
        <div class="border-b border-ink-100 px-5 py-4 sm:px-6">
            <h2 class="text-sm font-semibold text-ink-950">Resi pada Berkas Ini</h2>
        </div>

        @if ($orders->isEmpty())
            <x-ui.empty-state icon="document" title="Tidak ada resi"
                              description="Berkas ini tidak menghasilkan data resi apa pun." />
        @else
            <div class="divide-y divide-ink-50">
                @foreach ($orders as $order)
                    <div class="p-5 sm:px-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="break-all font-mono text-sm font-semibold text-ink-950">{{ $order->tracking_number }}</p>
                                    @if ($order->marketplace)
                                        <x-ui.badge variant="dark">{{ $order->marketplace }}</x-ui.badge>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-ink-500">
                                    {{ $order->order_number ?: 'Tanpa nomor pesanan' }}
                                    @if ($order->buyer_name) &middot; {{ $order->buyer_name }} @endif
                                </p>
                            </div>
                            <p class="shrink-0 text-sm font-semibold text-ink-950">{{ $order->totalQuantity() }} unit</p>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($order->items as $item)
                                <span class="inline-flex items-center gap-1.5 rounded-lg border border-ink-100 px-2 py-1">
                                    <x-ui.sku :value="$item->sku" :variant="$item->isMatched() ? 'soft' : 'danger'" :label="false" />
                                    <span class="text-[11px] text-ink-500">{{ $item->quantity }}×</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$orders" />
        @endif
    </div>
</x-app-layout>

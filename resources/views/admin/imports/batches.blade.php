<x-app-layout title="Riwayat Import">
    <x-ui.page-header title="Import Resi"
                      subtitle="Data resi hasil eksport TikTok Shop yang dipakai saat scan barang keluar dan retur.">
        <x-slot name="actions">
            @can('imports.create')
                <x-ui.button :href="route('admin.imports.create')" icon="plus">Import Excel</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    @include('admin.imports.partials.tabs', ['batchCount' => $imports->total()])

    <div class="frappe-card overflow-hidden">
        @if ($imports->isEmpty())
            <x-ui.empty-state icon="clock" title="Belum ada riwayat import"
                              description="Berkas yang Anda import akan tercatat di sini." />
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Berkas</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Resi <x-ui.hint text="Jumlah resi yang terbaca dari berkas ini." /></span></th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Baris <x-ui.hint text="Jumlah baris barang (SKU) dari berkas ini." /></span></th>
                            <th><span class="inline-flex items-center gap-1">SKU <x-ui.hint text="Apakah semua SKU di berkas sudah terdaftar di master barang." /></span></th>
                            <th>Diimport</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($imports as $import)
                            <tr>
                                <td>
                                    <p class="max-w-xs truncate text-sm font-medium text-ink-950">{{ $import->filename }}</p>
                                    <p class="text-[11px] text-ink-400">{{ $import->source }}</p>
                                </td>
                                <td class="text-right">
                                    <p class="text-sm font-semibold text-ink-950">{{ $import->order_count }}</p>
                                    @if ($import->failed_row_count > 0)
                                        <a href="{{ route('admin.imports.failures', $import) }}" class="text-[11px] font-medium text-red-600 hover:underline" title="Unduh baris yang gagal diimport">{{ $import->failed_row_count }} gagal</a>
                                    @endif
                                </td>
                                <td class="text-right text-sm text-ink-600">{{ $import->item_count }}</td>
                                <td>
                                    @if ($import->unmatched_sku_count > 0)
                                        <x-ui.badge variant="warning" icon="warning">{{ $import->unmatched_sku_count }} belum cocok</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="success" icon="check-circle">Cocok semua</x-ui.badge>
                                    @endif
                                </td>
                                <td>
                                    <p class="text-xs text-ink-600">{{ $import->created_at->translatedFormat('d M Y H:i') }}</p>
                                    <p class="text-[11px] text-ink-400">{{ $import->user?->name ?? '—' }}</p>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.imports.show', $import) }}" title="Detail" class="btn btn-ghost btn-icon">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                        @can('imports.delete')
                                            <x-ui.confirm-delete :action="route('admin.imports.destroy', $import)"
                                                                 title="Hapus data import ini?"
                                                                 :description="'Seluruh '.$import->order_count.' resi dari berkas ini akan ikut terhapus.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-100 md:hidden">
                @foreach ($imports as $import)
                    <a href="{{ route('admin.imports.show', $import) }}" class="block p-4 transition hover:bg-ink-50/50">
                        <p class="truncate text-sm font-medium text-ink-950">{{ $import->filename }}</p>
                        <p class="mt-0.5 text-xs text-ink-400">{{ $import->created_at->translatedFormat('d M Y H:i') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <x-ui.badge variant="outline">{{ $import->order_count }} resi</x-ui.badge>
                            <x-ui.badge variant="outline">{{ $import->item_count }} baris</x-ui.badge>
                            @if ($import->unmatched_sku_count > 0)
                                <x-ui.badge variant="warning">{{ $import->unmatched_sku_count }} SKU belum cocok</x-ui.badge>
                            @endif
                            @if ($import->failed_row_count > 0)
                                <x-ui.badge variant="danger">{{ $import->failed_row_count }} gagal</x-ui.badge>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$imports" />
        @endif
    </div>
</x-app-layout>

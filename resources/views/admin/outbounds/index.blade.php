<x-app-layout title="Penjualan Manual">
    <x-ui.page-header title="Penjualan Manual" icon="logout"
                      subtitle="Penjualan di luar marketplace. Pesanan marketplace dibuka dari menu Pesanan.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.outbounds.export', request()->query())"
                         variant="secondary" icon="document" data-no-ajax
                         title="Unduh dokumen dan detail barang sesuai saringan yang aktif">
                Export Excel
            </x-ui.button>
            @can('outbounds.create')
                <x-ui.button :href="route('admin.outbounds.create')" icon="plus">Buat Dokumen</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>


    <form method="GET" action="{{ route('admin.outbounds.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nomor dokumen, penerima, atau resi..." class="pl-8" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            {{-- Pilihannya persis sama dengan badge di kolom Status, satu lawan
                 satu. Sebelumnya hanya ada Draft dan Terkirim, sehingga paket
                 yang menunggu persetujuan atau ditolak tidak bisa dicari sama
                 sekali, dan "Draft" ikut memuat paket yang sebenarnya sudah
                 menunggu serah terima. --}}
            <x-ui.select name="status" class="col-span-2 sm:w-48">
                <option value="">Semua status</option>
                @foreach (App\Models\Outbound::manualStages() as $value => $stage)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $stage['label'] }}</option>
                @endforeach
            </x-ui.select>
        </div>

        {{-- Rentang tanggal berbaris sendiri: kolomnya membawa pintasan periode
             di bawahnya, dan itu tidak muat disisipkan di antara dropdown. --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.date-filter label="Tanggal dokumen" />

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'status', 'from', 'to']))
                    <x-ui.button :href="route('admin.outbounds.index')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($outbounds->isEmpty())
            <x-ui.empty-state icon="logout" title="Belum ada dokumen"
                              description="Buat dokumen penjualan manual untuk mencatat barang yang terjual di luar marketplace.">
                @can('outbounds.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.outbounds.create')" icon="plus">Buat Dokumen</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Dokumen</th>
                            <th>Penerima</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Total Unit <x-ui.hint text="Jumlah seluruh unit barang di dokumen ini." /></span></th>
                            <th><span class="inline-flex items-center gap-1">Status <x-ui.hint text="Draft masih bisa diubah. Menunggu = perlu persetujuan. Disetujui = stok sudah berkurang." /></span></th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($outbounds as $outbound)
                            <tr>
                                <td>
                                    <p class="font-mono text-sm font-medium text-ink-950">{{ $outbound->code }}</p>
                                    <p class="text-xs text-ink-400">{{ $outbound->date->translatedFormat('d M Y') }}</p>
                                </td>
                                <td>
                                    <p class="text-sm text-ink-800">{{ $outbound->recipient }}</p>
                                    @if ($outbound->tracking_number)
                                        <p class="font-mono text-[11px] text-ink-400">{{ $outbound->tracking_number }}</p>
                                    @endif
                                </td>
                                <td class="text-right text-sm font-semibold text-ink-950">
                                    {{ number_format((int) $outbound->items_sum_quantity, 0, ',', '.') }}
                                </td>
                                <td>
                                    {{-- Tahap, bukan kolom status: lihat Outbound::stage(). --}}
                                    <x-ui.badge :variant="$outbound->stageVariant()" :icon="$outbound->stageIcon()">
                                        {{ $outbound->stageLabel() }}
                                    </x-ui.badge>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.outbounds.show', $outbound) }}" title="Detail"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                        @can('outbounds.update')
                                            @if ($outbound->isEditable())
                                                <a href="{{ route('admin.outbounds.edit', $outbound) }}" title="Ubah"
                                                   class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                    <x-icon name="pencil" class="h-4 w-4" />
                                                </a>
                                            @endif
                                        @endcan
                                        @can('outbounds.delete')
                                            @if ($outbound->isEditable())
                                                <x-ui.confirm-delete :action="route('admin.outbounds.destroy', $outbound)"
                                                                     title="Hapus dokumen ini?"
                                                                     :description="'Draft '.$outbound->code.' akan dihapus permanen.'" />
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($outbounds as $outbound)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-ink-950">{{ $outbound->code }}</p>
                                <p class="truncate text-xs text-ink-400">
                                    {{ $outbound->date->translatedFormat('d M Y') }} &middot; {{ $outbound->recipient }}
                                </p>
                            </div>
                            {{-- Badge yang sama persis dengan tabel: dokumen yang
                                 sama tidak boleh bercerita berbeda hanya karena
                                 layarnya lebih sempit. --}}
                            <x-ui.badge :variant="$outbound->stageVariant()" :icon="$outbound->stageIcon()">
                                {{ $outbound->stageLabel() }}
                            </x-ui.badge>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <x-ui.badge variant="outline">{{ (int) $outbound->items_sum_quantity }} unit</x-ui.badge>
                        </div>

                        <div class="mt-3 flex items-center justify-end gap-1 border-t border-ink-50 pt-3">
                            <x-ui.button :href="route('admin.outbounds.show', $outbound)" variant="secondary" size="sm" icon="eye">Detail</x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$outbounds" />
        @endif
    </div>
</x-app-layout>

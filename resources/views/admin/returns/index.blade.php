<x-app-layout title="Penerimaan Retur">
    <x-ui.page-header title="Penerimaan Retur" icon="refresh"
                      subtitle="Barang yang dikembalikan pembeli, dari marketplace maupun pengiriman biasa.">
        <x-slot name="actions">
            @can('returns.create')
                <x-ui.button :href="route('admin.returns.marketplace')" variant="secondary" icon="search">
                    Scan Retur Marketplace
                </x-ui.button>
                <x-ui.button :href="route('admin.returns.create')" icon="plus">Buat Dokumen</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <form method="GET" action="{{ route('admin.returns.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nomor dokumen, pengirim, resi, atau pesanan..." class="pl-8" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="type" class="sm:w-44">
                <option value="">Semua jenis</option>
                <option value="regular" @selected(request('type') === 'regular')>Non-marketplace</option>
                <option value="marketplace" @selected(request('type') === 'marketplace')>Marketplace</option>
            </x-ui.select>

            <x-ui.select name="marketplace" class="sm:w-40">
                <option value="">Semua toko</option>
                @foreach ($marketplaces as $marketplace)
                    <option value="{{ $marketplace }}" @selected(request('marketplace') === $marketplace)>{{ $marketplace }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="status" class="sm:w-36">
                <option value="">Semua status</option>
                <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                <option value="posted" @selected(request('status') === 'posted')>Diterima</option>
            </x-ui.select>
        </div>

        {{-- Rentang tanggal berbaris sendiri: kolomnya membawa pintasan periode
             di bawahnya, dan itu tidak muat disisipkan di antara dropdown. --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.date-filter label="Tanggal retur" />

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'status', 'type', 'marketplace', 'from', 'to']))
                    <x-ui.button :href="route('admin.returns.index')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($returns->isEmpty())
            <x-ui.empty-state icon="refresh" title="Belum ada dokumen retur"
                              description="Catat barang yang dikembalikan pembeli agar stok kembali akurat.">
                @can('returns.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.returns.create')" icon="plus">Buat Dokumen</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Dokumen</th>
                            <th>Pengirim</th>
                            <th>Jenis</th>
                            <th>Alasan</th>
                            <th class="text-right">Total Unit</th>
                            <th>Status</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($returns as $return)
                            <tr>
                                <td>
                                    <p class="font-mono text-sm font-medium text-ink-950">{{ $return->code }}</p>
                                    <p class="text-xs text-ink-400">{{ $return->date->translatedFormat('d M Y') }}</p>
                                </td>
                                <td>
                                    <p class="text-sm text-ink-800">{{ $return->sender }}</p>
                                    @if ($return->tracking_number)
                                        <p class="font-mono text-[11px] text-ink-400">{{ $return->tracking_number }}</p>
                                    @endif
                                </td>
                                <td>
                                    @if ($return->isMarketplace())
                                        <x-ui.badge variant="dark" icon="sparkles">{{ $return->marketplace }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="outline">Non-marketplace</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-sm text-ink-600">{{ $return->reason ?: '—' }}</td>
                                <td class="text-right text-sm font-semibold text-ink-950">
                                    {{ number_format((int) $return->items_sum_quantity, 0, ',', '.') }}
                                </td>
                                <td>
                                    @if ($return->isEditable() && $return->requiresResiScan() && ! $return->isResiVerified())
                                        <x-ui.badge variant="neutral" icon="key">Perlu scan resi</x-ui.badge>
                                    @else
                                        <x-ui.badge :variant="$return->statusVariant()" :icon="$return->statusIcon()">
                                            {{ $return->statusLabel() }}
                                        </x-ui.badge>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($return->isEditable() && $return->requiresResiScan() && ! $return->isResiVerified())
                                            @can('returns.scan')
                                                <a href="{{ route('admin.returns.scan', $return) }}" title="Scan resi retur"
                                                   class="btn btn-primary">
                                                    <x-icon name="search" class="h-3.5 w-3.5" /> Scan
                                                </a>
                                            @endcan
                                        @endif
                                        <a href="{{ route('admin.returns.show', $return) }}" title="Detail"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                        @can('returns.update')
                                            @if ($return->isEditable())
                                                <a href="{{ route('admin.returns.edit', $return) }}" title="Ubah"
                                                   class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                    <x-icon name="pencil" class="h-4 w-4" />
                                                </a>
                                            @endif
                                        @endcan
                                        @can('returns.delete')
                                            @if ($return->isEditable())
                                                <x-ui.confirm-delete :action="route('admin.returns.destroy', $return)"
                                                                     title="Hapus dokumen retur ini?"
                                                                     :description="'Draft '.$return->code.' akan dihapus permanen.'" />
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
                @foreach ($returns as $return)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-ink-950">{{ $return->code }}</p>
                                <p class="truncate text-xs text-ink-400">
                                    {{ $return->date->translatedFormat('d M Y') }} &middot; {{ $return->sender }}
                                </p>
                            </div>
                            <x-ui.badge :variant="$return->statusVariant()">{{ $return->statusLabel() }}</x-ui.badge>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            @if ($return->isMarketplace())
                                <x-ui.badge variant="dark" icon="sparkles">{{ $return->marketplace }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="outline">Non-marketplace</x-ui.badge>
                            @endif
                            <x-ui.badge variant="outline">{{ (int) $return->items_sum_quantity }} unit</x-ui.badge>
                        </div>

                        <div class="mt-3 flex items-center justify-end gap-1 border-t border-ink-50 pt-3">
                            @if ($return->isEditable() && $return->requiresResiScan() && ! $return->isResiVerified())
                                @can('returns.scan')
                                    <x-ui.button :href="route('admin.returns.scan', $return)" size="sm" icon="search">Scan</x-ui.button>
                                @endcan
                            @endif
                            <x-ui.button :href="route('admin.returns.show', $return)" variant="secondary" size="sm" icon="eye">Detail</x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$returns" />
        @endif
    </div>
</x-app-layout>

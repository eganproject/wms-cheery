<x-app-layout title="Substitusi SKU">
    <x-ui.page-header title="Substitusi SKU" icon="refresh"
                      subtitle="Barang pesanan yang dikirim dengan barang lain saat QC Kemas. Stok yang berkurang adalah stok barang pengganti.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.orders.substitutions.export', request()->query())" variant="secondary" icon="excel" data-no-ajax>
                Export Excel
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <form method="GET" action="{{ route('admin.orders.substitutions') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 lg:flex-row lg:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari SKU, nama barang, resi, atau no. dokumen..." class="pl-8" />
        </div>

        <x-ui.select name="reason" class="lg:w-48">
            <option value="">Semua alasan</option>
            @foreach ($reasons as $value => $label)
                <option value="{{ $value }}" @selected(request('reason') === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.date-filter label="Tanggal substitusi" />

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 lg:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'reason', 'from', 'to', 'range']))
                <x-ui.button :href="route('admin.orders.substitutions')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($rows->isEmpty())
            <x-ui.empty-state icon="refresh" title="Belum ada substitusi pada tanggal ini"
                              description="Substitusi dilakukan di QC Kemas: scan barang pengganti, pilih barang pesanan yang diganti, lalu alasannya." />
        @else
            <div class="flex items-center justify-between border-b border-ink-100 px-4 py-2.5 text-xs text-ink-500">
                <span>{{ number_format($rows->total(), 0, ',', '.') }} substitusi</span>
                <span>{{ number_format($totalUnits, 0, ',', '.') }} unit diganti</span>
            </div>
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th><x-ui.sort key="date" first="desc">Waktu</x-ui.sort></th>
                            <th>Resi</th>
                            <th>Barang Pesanan</th>
                            <th>Diganti Dengan</th>
                            <th class="text-right"><x-ui.sort key="quantity" first="desc">Jumlah</x-ui.sort></th>
                            <th>Alasan</th>
                            <th>Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="whitespace-nowrap text-sm text-ink-700">{{ $row->substituted_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    @if ($row->outbound)
                                        <a href="{{ route('admin.outbounds.show', $row->outbound) }}" class="font-mono text-sm font-semibold text-ink-950 hover:underline">
                                            {{ $row->outbound->tracking_number ?: $row->outbound->code }}
                                        </a>
                                        <p class="text-[11px] text-ink-400">
                                            {{ $row->outbound->code }} &middot; {{ $row->outbound->isPosted() ? 'Dikirim' : 'Belum dikirim' }}
                                        </p>
                                    @endif
                                </td>
                                <td>
                                    <x-ui.sku :value="$row->substitutedFrom?->sku" />
                                    <p class="mt-0.5 max-w-xs truncate text-xs text-ink-500">{{ $row->substitutedFrom?->name }}</p>
                                </td>
                                <td>
                                    <x-ui.sku :value="$row->product?->sku" />
                                    <p class="mt-0.5 max-w-xs truncate text-xs text-ink-500">{{ $row->product?->name }}</p>
                                </td>
                                <td class="text-right text-sm font-semibold tabular-nums">
                                    {{ number_format($row->quantity, 0, ',', '.') }}
                                    <span class="text-xs font-normal text-ink-400">{{ $row->product?->unit }}</span>
                                </td>
                                <td class="text-sm text-ink-700">{{ $row->substitutionReasonLabel() }}</td>
                                <td class="text-sm text-ink-500">{{ $row->substitutedBy?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pagination :paginator="$rows" />
        @endif
    </div>
</x-app-layout>

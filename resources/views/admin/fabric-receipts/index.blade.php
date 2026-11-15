<x-app-layout title="Kain Masuk">
    <x-ui.page-header title="Kain Masuk" icon="inbound"
                      subtitle="Kain yang diterima dari pemasok, dicatat per roll. Setiap roll mendapat label QR sendiri.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.fabric-receipts.export', request()->query())" variant="secondary" icon="excel" data-no-ajax>
                Export Excel
            </x-ui.button>
            @can('fabrics.receive')
                <x-ui.button :href="route('admin.fabric-receipts.create')" icon="plus">Catat Kain Masuk</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <form method="GET" action="{{ route('admin.fabric-receipts.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 lg:flex-row lg:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari no. dokumen, surat jalan, atau kode roll..." class="pl-8" />
        </div>

        <x-ui.select name="supplier_id" class="lg:w-48">
            <option value="">Semua pemasok</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}" @selected((int) request('supplier_id') === $supplier->id)>{{ $supplier->name }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.date-filter label="Tanggal terima" />

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 lg:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'supplier_id', 'from', 'to', 'range']))
                <x-ui.button :href="route('admin.fabric-receipts.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($receipts->isEmpty())
            <x-ui.empty-state icon="inbound" title="Belum ada kain masuk pada tanggal ini"
                              description="Catat kain yang diterima per roll, lalu cetak dan tempel label QR-nya.">
                @can('fabrics.receive')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.fabric-receipts.create')" icon="plus">Catat Kain Masuk</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th><x-ui.sort key="code">No. Dokumen</x-ui.sort></th>
                            <th><x-ui.sort key="date" first="desc">Tanggal</x-ui.sort></th>
                            <th>Pemasok</th>
                            <th class="text-right"><x-ui.sort key="rolls" first="desc">Roll</x-ui.sort></th>
                            <th>Dicatat oleh</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipts as $receipt)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.fabric-receipts.show', $receipt) }}" class="font-mono text-sm font-semibold text-ink-950 hover:underline">{{ $receipt->code }}</a>
                                    @if ($receipt->reference)
                                        <p class="text-[11px] text-ink-400">SJ {{ $receipt->reference }}</p>
                                    @endif
                                </td>
                                <td class="text-sm text-ink-700">{{ $receipt->date->format('d/m/Y') }}</td>
                                <td class="text-sm text-ink-700">{{ $receipt->supplier?->name ?? '—' }}</td>
                                <td class="text-right text-sm font-semibold tabular-nums">{{ $receipt->rolls_count }}</td>
                                <td class="text-sm text-ink-500">{{ $receipt->user?->name ?? '—' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.fabric-receipts.show', $receipt) }}" title="Detail"
                                       class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                        <x-icon name="eye" class="h-4 w-4" />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pagination :paginator="$receipts" />
        @endif
    </div>
</x-app-layout>

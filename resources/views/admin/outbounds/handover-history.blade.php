{{--
    Riwayat serah terima: paket yang sudah diserahkan ke kurir, menurut waktu
    serah terimanya. Bisa diunduh sebagai manifest per kurir.
--}}
@php
    $pill = 'inline-flex max-w-56 items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs whitespace-nowrap transition';
    $pillOn = 'border-brand-500 bg-brand-50 font-medium text-brand-800';
    $pillOff = 'border-ink-200 bg-white text-ink-600 hover:border-ink-300 hover:text-ink-950';
    $keep = fn (array $except, array $set = []) => array_filter(
        array_merge(request()->except([...$except, 'page']), $set),
        fn ($value) => filled($value),
    );
@endphp

<x-app-layout title="Riwayat Serah Terima">
    <x-ui.page-header title="Serah Terima Paket" icon="outbound"
                      subtitle="Riwayat paket yang sudah diserahkan ke kurir, menurut waktu serah terimanya.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.outbounds.handover.history.export', request()->query())"
                         variant="secondary" icon="document" data-no-ajax
                         title="Unduh riwayat serah terima sesuai saringan, bisa dipakai sebagai manifest kurir">
                Export Excel
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @include('admin.outbounds.partials.handover-tabs')

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div class="frappe-card px-4 py-3">
            <p class="text-xs text-ink-500">Paket diserahkan</p>
            <p class="mt-0.5 text-lg font-semibold text-ink-950" data-history-total>{{ number_format($total, 0, ',', '.') }}</p>
        </div>
        <div class="frappe-card px-4 py-3">
            <p class="text-xs text-ink-500">Total unit</p>
            <p class="mt-0.5 text-lg font-semibold text-ink-950">{{ number_format($units, 0, ',', '.') }}</p>
        </div>
        <div class="frappe-card col-span-2 px-4 py-3 sm:col-span-1">
            <p class="text-xs text-ink-500">Periode</p>
            <p class="mt-0.5 text-sm font-medium text-ink-950">{{ $range->label() }}</p>
        </div>
    </div>

    <div class="frappe-card">
        {{-- Per kurir: jumlah paket pada periode ini, klik untuk menyaring. --}}
        @if ($summary->isNotEmpty())
            <div class="flex flex-col gap-1.5 border-b border-ink-100 px-4 pb-3 pt-4 text-sm sm:flex-row sm:items-start">
                <span class="w-20 shrink-0 pt-1 text-xs text-ink-500">Kurir</span>
                <div class="flex flex-wrap gap-1.5">
                    <a href="{{ route('admin.outbounds.handover.history', $keep(['courier'])) }}"
                       @class([$pill, $pillOn => ! request()->filled('courier'), $pillOff => request()->filled('courier')])>Semua <span class="opacity-70">{{ $total }}</span></a>
                    @foreach ($summary as $row)
                        <a href="{{ route('admin.outbounds.handover.history', $keep(['courier'], ['courier' => $row->courier_name])) }}"
                           @class([$pill, $pillOn => request('courier') === $row->courier_name, $pillOff => request('courier') !== $row->courier_name])>
                            <x-icon name="truck" class="h-3.5 w-3.5" />
                            <span class="truncate">{{ $row->courier_name === '-' ? 'Tanpa kurir' : $row->courier_name }}</span>
                            <span class="opacity-70">{{ $row->packages }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <form method="GET" action="{{ route('admin.outbounds.handover.history') }}"
              class="flex flex-col gap-3 border-b border-ink-100 p-4 lg:flex-row lg:items-start">
            @foreach (['courier', 'per_page'] as $field)
                @if (request()->filled($field))
                    <input type="hidden" name="{{ $field }}" value="{{ request($field) }}">
                @endif
            @endforeach
            <x-ui.date-filter label="Waktu serah terima" />
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <x-text-input type="search" name="search" :value="request('search')"
                              placeholder="Cari no. resi, no. dokumen, atau penerima..." class="pl-8" />
            </div>
            <x-ui.select name="marketplace" class="lg:w-44" onchange="this.form.requestSubmit()">
                <option value="">Semua marketplace</option>
                @foreach ($marketplaces as $marketplace)
                    <option value="{{ $marketplace }}" @selected(request('marketplace') === $marketplace)>{{ $marketplace }}</option>
                @endforeach
            </x-ui.select>
            @if ($stores->count() > 1)
                <x-ui.select name="store" class="lg:w-44" onchange="this.form.requestSubmit()">
                    <option value="">Semua toko</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store }}" @selected(request('store') === $store)>{{ $store }}</option>
                    @endforeach
                </x-ui.select>
            @endif
            @if ($handlers->isNotEmpty() || request()->filled('by'))
                <x-ui.select name="by" class="lg:w-44" onchange="this.form.requestSubmit()">
                    <option value="">Semua petugas</option>
                    @foreach ($handlers as $id => $name)
                        <option value="{{ $id }}" @selected((string) request('by') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            @endif
            <div class="flex items-center gap-2">
                <x-ui.button type="submit" icon="filter">Cari</x-ui.button>
                @if (request()->hasAny(['search', 'marketplace', 'courier', 'store', 'by', 'from', 'to', 'range']))
                    <x-ui.button :href="route('admin.outbounds.handover.history')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="close" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" data-handover-history>
                <thead class="bg-ink-50 text-left text-[13px] text-ink-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Serah Terima</th>
                        <th class="h-8 px-3 py-0 font-normal">Resi</th>
                        <th class="h-8 px-3 py-0 font-normal">Kurir</th>
                        <th class="h-8 px-3 py-0 font-normal">Toko</th>
                        <th class="h-8 px-3 py-0 text-right font-normal">Qty</th>
                        <th class="h-8 px-3 py-0 font-normal">QC Kemas</th>
                        <th class="h-8 px-3 py-0 font-normal">Diserahkan Oleh</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse ($outbounds as $outbound)
                        @php $order = $outbound->shipmentOrder; @endphp
                        <tr class="align-top hover:bg-ink-50/50">
                            <td class="whitespace-nowrap px-4 py-2.5 text-xs text-ink-800">{{ $outbound->posted_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2.5">
                                <p class="font-mono text-xs font-semibold text-ink-950">{{ $outbound->tracking_number }}</p>
                                <p class="font-mono text-[11px] text-ink-400">{{ $outbound->code }}@if ($order?->order_number) · {{ $order->order_number }}@endif</p>
                            </td>
                            <td class="px-3 py-2.5 text-xs text-ink-800">{{ $order?->courier ?: 'Tanpa kurir' }}</td>
                            <td class="px-3 py-2.5 text-xs">
                                <span class="flex min-w-0 items-center gap-1.5 text-ink-700">
                                    <x-ui.marketplace-mark :marketplace="$order?->marketplace" />
                                    <span class="max-w-44 truncate">{{ $order?->store_name ?: ($order?->marketplace ?: '-') }}</span>
                                </span>
                                @if ($outbound->recipient)
                                    <p class="mt-0.5 text-[11px] text-ink-400">{{ $outbound->recipient }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right text-xs tabular-nums text-ink-800">{{ number_format((int) $outbound->items_sum_quantity, 0, ',', '.') }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-xs text-ink-600">
                                {{ $outbound->resi_verified_at?->format('d/m/Y H:i') ?? '-' }}
                                @if ($outbound->user)
                                    <p class="text-[11px] text-ink-400">{{ $outbound->user->name }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-xs text-ink-600">{{ $outbound->submitter?->name ?? '-' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('admin.outbounds.show', $outbound) }}" class="btn btn-ghost btn-icon" title="Dokumen {{ $outbound->code }}">
                                    <x-icon name="eye" class="h-4 w-4" />
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-ui.empty-state icon="outbound" title="Belum ada serah terima"
                                                  description="Tidak ada paket yang diserahkan ke kurir pada periode dan saringan ini. Coba ubah tanggal." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui.pagination :paginator="$outbounds" unit="paket" />
    </div>
</x-app-layout>

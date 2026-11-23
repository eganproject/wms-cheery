{{--
    Rekap harian: per SKU, berapa unit dikemas di QC Kemas dan berapa unit
    diserahkan ke kurir pada periode yang dipilih (bawaan: hari ini).
--}}
@php
    $fmt = fn (int $value) => number_format($value, 0, ',', '.');
@endphp

<x-app-layout title="Rekap Harian">
    <x-ui.page-header title="Serah Terima Paket" icon="outbound"
                      subtitle="Rekap per SKU: unit yang dikemas di QC Kemas dan unit yang diserahkan ke kurir.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.outbounds.handover.daily.export', request()->query())"
                         variant="secondary" icon="document" data-no-ajax>
                Export Excel
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @include('admin.outbounds.partials.handover-tabs')

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="frappe-card px-4 py-3">
            <p class="text-xs text-ink-500">Dikemas (QC)</p>
            <p class="mt-0.5 text-lg font-semibold text-ink-950" data-daily-packed>{{ $fmt($totals['packed_units']) }} unit</p>
            <p class="text-xs text-ink-400">{{ $fmt($totals['packed_packages']) }} paket</p>
        </div>
        <div class="frappe-card px-4 py-3">
            <p class="text-xs text-ink-500">Diserahkan ke kurir</p>
            <p class="mt-0.5 text-lg font-semibold text-ink-950" data-daily-handed>{{ $fmt($totals['handed_units']) }} unit</p>
            <p class="text-xs text-ink-400">{{ $fmt($totals['handed_packages']) }} paket</p>
        </div>
        <div class="frappe-card px-4 py-3">
            <p class="text-xs text-ink-500">Dikemas, belum diserahkan</p>
            <p class="mt-0.5 text-lg font-semibold text-ink-950">{{ $fmt((int) $rows->sum('waiting')) }} unit</p>
            <p class="text-xs text-ink-400">{{ $fmt($totals['waiting_packages']) }} paket masih di antrean</p>
        </div>
        <div class="frappe-card px-4 py-3">
            <p class="text-xs text-ink-500">Periode</p>
            <p class="mt-0.5 text-sm font-medium text-ink-950">{{ $range->label() }}</p>
            <p class="text-xs text-ink-400">{{ $fmt($rows->count()) }} SKU</p>
        </div>
    </div>

    <div class="frappe-card">
        <form method="GET" action="{{ route('admin.outbounds.handover.daily') }}"
              class="flex flex-col gap-3 border-b border-ink-100 p-4 lg:flex-row lg:items-start">
            <x-ui.date-filter label="Tanggal" />
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <x-text-input type="search" name="search" :value="request('search')"
                              placeholder="Cari SKU atau nama barang..." class="pl-8" />
            </div>
            <x-ui.select name="marketplace" class="lg:w-44" onchange="this.form.requestSubmit()">
                <option value="">Semua marketplace</option>
                @foreach ($marketplaces as $marketplace)
                    <option value="{{ $marketplace }}" @selected(request('marketplace') === $marketplace)>{{ $marketplace }}</option>
                @endforeach
            </x-ui.select>
            <div class="flex items-center gap-2">
                <x-ui.button type="submit" icon="filter">Cari</x-ui.button>
                @if (request()->hasAny(['search', 'marketplace', 'from', 'to', 'range']))
                    <x-ui.button :href="route('admin.outbounds.handover.daily')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="close" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" data-handover-daily>
                <thead class="bg-ink-50 text-left text-[13px] text-ink-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Barang</th>
                        <th class="h-8 px-3 py-0 text-right font-normal">
                            <span class="inline-flex items-center gap-1">Dikemas (QC) <x-ui.hint text="Unit yang discan di QC Kemas, menurut waktu resinya discan. Pesanan yang dibatalkan tidak dihitung." /></span>
                        </th>
                        <th class="h-8 px-3 py-0 text-right font-normal">
                            <span class="inline-flex items-center gap-1">Diserahkan <x-ui.hint text="Unit yang diserahkan ke kurir (stoknya sudah berkurang), menurut waktu serah terima." /></span>
                        </th>
                        <th class="h-8 px-4 py-0 text-right font-normal">
                            <span class="inline-flex items-center gap-1">Belum diserahkan <x-ui.hint text="Dari unit yang dikemas pada periode ini, yang paketnya masih di antrean Serah Terima." /></span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-ink-50/50">
                            <td class="px-4 py-2.5">
                                <div class="flex max-w-md flex-wrap items-center gap-x-2 gap-y-1">
                                    <x-ui.sku :value="$row->sku" />
                                    <span class="line-clamp-2 break-words text-ink-950" title="{{ $row->name }}">{{ $row->name }}</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium text-ink-950">{{ $fmt($row->packed) }} <span class="text-xs font-normal text-ink-400">{{ $row->unit }}</span></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium text-ink-950">{{ $fmt($row->handed) }} <span class="text-xs font-normal text-ink-400">{{ $row->unit }}</span></td>
                            <td @class(['whitespace-nowrap px-4 py-2.5 text-right', 'font-medium text-amber-700' => $row->waiting > 0, 'text-ink-400' => $row->waiting === 0])>{{ $fmt($row->waiting) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-sm text-ink-500">
                                Belum ada barang yang dikemas atau diserahkan pada {{ $range->label() }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot class="border-t border-ink-200 bg-ink-50 text-sm">
                        <tr>
                            <td class="px-4 py-2.5 font-medium text-ink-700">Total</td>
                            <td class="px-3 py-2.5 text-right font-semibold text-ink-950">{{ $fmt((int) $rows->sum('packed')) }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold text-ink-950">{{ $fmt((int) $rows->sum('handed')) }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-ink-950">{{ $fmt((int) $rows->sum('waiting')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</x-app-layout>

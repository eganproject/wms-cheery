{{--
    Laporan Owner: satu menu, isinya dipisah tab.

    Tab di sini bukan halaman lain yang punya menu sendiri — semuanya hanya
    ada di sini, jadi aturan "tab tidak boleh mengulang menu" tetap terjaga.
--}}
@php
    $n = fn ($value) => number_format((float) $value, 0, ',', '.');
    $rp = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');
    $pct = fn ($value) => $value === null ? '—' : number_format((float) $value, 1, ',', '.').'%';
    $tabUrl = fn (string $key) => route('admin.reports.owner', array_filter(
        ['tab' => $key, 'from' => $range->from, 'to' => $range->to],
        fn ($value) => filled($value),
    ));
    $current = $tabs[$tab];
@endphp

<x-app-layout title="Laporan Owner">
    <x-ui.page-header title="Laporan Owner" icon="report"
                      subtitle="Jualan, ketepatan kirim, barang laku dan tidak laku, retur, umur stok, dan kinerja tim dalam satu halaman.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.reports.owner.export', request()->query() + ['tab' => $tab])"
                         variant="secondary" icon="document" data-no-ajax
                         title="Unduh isi tab {{ $current['label'] }} sesuai periode yang aktif">
                Export Excel
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <div class="scrollbar-none mb-4 flex gap-1 overflow-x-auto border-b border-ink-200 text-sm">
        @foreach ($tabs as $key => $meta)
            <a href="{{ $tabUrl($key) }}"
               @class(['-mb-px inline-flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2', 'border-brand-500 font-medium text-ink-950' => $tab === $key, 'border-transparent text-ink-500 hover:text-ink-700' => $tab !== $key])>
                <x-icon :name="$meta['icon']" class="h-3.5 w-3.5" />
                {{ $meta['label'] }}
            </a>
        @endforeach
    </div>

    @if ($tab !== 'stok')
        <form method="GET" action="{{ route('admin.reports.owner') }}" data-auto-submit
              class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <x-ui.date-filter :range="$range" :allow-all="false"
                              :label="$tab === 'kirim' ? 'Tanggal batas kirim' : ($tab === 'retur' ? 'Tanggal retur' : ($tab === 'tim' ? 'Tanggal QC Kemas' : 'Tanggal pesanan'))" />

            @if ($tab === 'penjualan')
                <x-ui.select name="per" class="sm:w-36">
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}" @selected($per === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                @if ($options['marketplaces']->count() > 1)
                    <x-ui.select name="marketplace" class="sm:w-44">
                        <option value="">Semua marketplace</option>
                        @foreach ($options['marketplaces'] as $option)
                            <option value="{{ $option }}" @selected(request('marketplace') === $option)>{{ $option }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
                @if ($options['stores']->count() > 1)
                    <x-ui.select name="store" class="sm:w-44">
                        <option value="">Semua toko</option>
                        @foreach ($options['stores'] as $option)
                            <option value="{{ $option }}" @selected(request('store') === $option)>{{ $option }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
            @elseif ($tab === 'kirim')
                <x-ui.select name="jenis" class="sm:w-44">
                    <option value="">PO dan Normal</option>
                    <option value="po" @selected(request('jenis') === 'po')>Hanya PO</option>
                    <option value="normal" @selected(request('jenis') === 'normal')>Hanya Normal</option>
                </x-ui.select>
            @endif

            <x-ui.button type="submit" variant="secondary" icon="filter">Terapkan</x-ui.button>
        </form>
    @endif

    @include('admin.reports.owner.'.$tab)
</x-app-layout>

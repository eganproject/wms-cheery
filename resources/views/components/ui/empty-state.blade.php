@props([
    'icon' => 'box',
    'title' => 'Belum ada data',
    'description' => null,
    /*
        Daftar ini disaring rentang tanggal (bawaannya hari ini). Daftar kosong
        karena saringan tanggal tidak boleh terlihat seperti "belum ada data
        sama sekali" — orang mengira datanya hilang. Maka kalimatnya menyebut
        rentangnya dan ada tombol untuk membuka semua tanggal.
    */
    'dateFiltered' => false,
])

@php
    $range = $dateFiltered ? \App\Support\DateRange::fromRequest(request()) : null;
    $narrowed = $range && ! $range->isAll();

    if ($narrowed) {
        $title = 'Tidak ada data pada '.($range->isToday() ? 'hari ini' : $range->label());
        $description = 'Daftar sedang disaring menurut tanggal. Data dari tanggal lain tetap ada — buka semua tanggal atau ubah rentangnya.';
        $allUrl = request()->fullUrlWithQuery(['range' => \App\Support\DateRange::ALL, 'from' => null, 'to' => null, 'page' => null]);
    }
@endphp

{{-- Keadaan kosong list view ERPNext: ikon abu-abu, satu kalimat, satu tombol. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <x-icon :name="$icon" class="h-10 w-10 text-ink-300" stroke="1.25" />
    <h3 class="mt-3 text-base font-medium text-ink-950">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-ink-500">{{ $description }}</p>
    @endif
    @if ($narrowed)
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            <x-ui.button :href="$allUrl" variant="secondary" icon="calendar">Lihat semua tanggal</x-ui.button>
            @isset($action){{ $action }}@endisset
        </div>
    @elseif (isset($action))
        <div class="mt-4">{{ $action }}</div>
    @endif
</div>

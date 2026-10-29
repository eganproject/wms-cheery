@props(['title', 'subtitle' => null, 'icon' => null, 'back' => null, 'status' => null, 'statusVariant' => 'neutral', 'tabs' => false])

{{--
    Kepala halaman ala wmsxshopee: judul 20px dengan keterangan singkat di
    bawahnya, dan tombol aksi di kanan.

    Tidak ada lagi bilah tab ke halaman sekelompok: setiap halaman itu sudah
    punya menunya sendiri di menu samping, jadi tab hanya mengulang menu.
--}}
<div {{ $attributes->merge(['class' => 'mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between']) }}>
    <div data-page-head class="flex min-w-0 items-start gap-2">
        @if ($back)
            <a href="{{ $back }}" title="Kembali"
               class="btn btn-ghost btn-icon -ml-1.5 mt-[-2px] shrink-0 text-ink-600">
                <x-icon name="arrow-left" class="h-4 w-4" />
                <span class="sr-only">Kembali</span>
            </a>
        @endif

        <div class="min-w-0">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <h1 class="min-w-0 truncate text-xl font-semibold text-ink-950">{{ $title }}</h1>
                @if ($status)
                    <x-ui.badge :variant="$statusVariant" class="shrink-0">{{ $status }}</x-ui.badge>
                @endif
                {{ $indicator ?? '' }}
            </div>
            @if ($subtitle)
                <p class="mt-0.5 text-sm text-ink-500">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    @isset($actions)
        {{--
            Di ponsel tombol aksi turun ke baris baru alih-alih digulir
            mendatar, supaya tidak ada tombol yang tersembunyi di luar layar.
        --}}
        <div data-page-actions
             class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">{{ $actions }}</div>
    @endisset
</div>

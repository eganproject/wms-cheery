@props(['title', 'subtitle' => null, 'icon' => null, 'back' => null, 'status' => null, 'statusVariant' => 'neutral', 'tabs' => true])

{{--
    Kepala halaman bergaya ERPNext: judul tebal 20px dengan indikator status,
    tombol aksi di kanan, dan menempel di bawah navbar saat digulir. Di bawahnya
    keterangan singkat dan — untuk halaman utama sebuah menu — tab ke halaman
    sekelompok.
--}}
<div {{ $attributes->merge(['class' => 'mb-4']) }}>
    <div data-page-head
         class="sticky top-navbar z-20 -mx-4 flex min-h-[56px] flex-wrap items-center gap-x-3 gap-y-2 bg-white px-4 py-2.5 sm:-mx-5 sm:px-5 lg:-ml-6 lg:pl-6">
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <button type="button" @click="$dispatch('toggle-sidebar')" title="Tampilkan/sembunyikan menu"
                    class="btn btn-ghost btn-icon -ml-1.5 hidden shrink-0 text-ink-600 lg:inline-flex">
                <x-icon name="menu" class="h-[18px] w-[18px]" />
                <span class="sr-only">Tampilkan/sembunyikan menu</span>
            </button>

            @if ($back)
                <a href="{{ $back }}" title="Kembali"
                   class="btn btn-ghost btn-icon -ml-1.5 shrink-0 text-ink-600 lg:ml-0">
                    <x-icon name="arrow-left" class="h-[18px] w-[18px]" />
                    <span class="sr-only">Kembali</span>
                </a>
            @endif

            <h1 class="min-w-0 truncate text-xl font-bold tracking-[0.01em] text-ink-950 sm:text-2xl">{{ $title }}</h1>

            @if ($status)
                <x-ui.badge :variant="$statusVariant" class="shrink-0">{{ $status }}</x-ui.badge>
            @endif
            {{ $indicator ?? '' }}
        </div>

        @isset($actions)
            {{--
                Di ponsel tombol aksi turun ke baris baru alih-alih digulir
                mendatar, supaya tidak ada tombol yang tersembunyi di luar layar.
            --}}
            <div data-page-actions
                 class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:justify-end">{{ $actions }}</div>
        @endisset
    </div>

    @if ($subtitle)
        <p class="-mt-1 text-sm text-ink-500">{{ $subtitle }}</p>
    @endif

    @if ($tabs)
        <x-ui.tabs class="mt-3" />
    @endif
</div>

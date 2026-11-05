{{--
    Tab halaman: halaman yang saling berkaitan berada di bawah satu menu
    samping, dan dibuka lewat tab ini. Isinya dibaca dari
    App\Support\Navigation::tabs(); tab yang tidak boleh dibuka pengguna
    tidak ditampilkan.

    Bentuknya kapsul (bukan garis bawah) supaya tidak tertukar dengan tab
    status di dalam halaman, misalnya tab tahap di halaman Pesanan.
--}}
@php
    $pageTabs = \App\Support\Navigation::tabs();
@endphp

@if ($pageTabs)
    <nav data-page-tabs aria-label="Tab halaman" class="scrollbar-none -mt-1 mb-4 overflow-x-auto">
        <div class="inline-flex min-w-max gap-0.5 rounded-lg bg-ink-100 p-0.5 text-[13px]">
            @foreach ($pageTabs as $tab)
                <a href="{{ $tab['href'] }}" @if ($tab['active']) aria-current="page" @endif
                   @class([
                       'inline-flex h-7 items-center gap-1.5 whitespace-nowrap rounded-md px-3 transition',
                       'bg-white font-medium text-ink-950 shadow-sm ring-1 ring-ink-200' => $tab['active'],
                       'text-ink-600 hover:text-ink-950' => ! $tab['active'],
                   ])>
                    <x-icon :name="$tab['icon']" class="h-3.5 w-3.5 shrink-0 {{ $tab['active'] ? 'text-ink-950' : 'text-ink-400' }}" />
                    {{ $tab['label'] }}
                    @if ($tab['badge'])
                        <span class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-medium leading-none text-white tabular-nums">{{ $tab['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </nav>
@endif

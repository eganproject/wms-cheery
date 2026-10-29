@props(['group' => null])

@php
    $tabs = \App\Support\Navigation::tabs();
@endphp

{{--
    Tab bergaris bawah ala form ERPNext, berisi halaman lain di kelompok menu
    yang sama. Setiap tab adalah tautan ke route-nya sendiri, jadi hanya data
    tab yang dibuka yang di-query.
--}}
@if (count($tabs) > 1)
    <div {{ $attributes->merge(['class' => 'scrollbar-none -mx-4 overflow-x-auto border-b border-ink-100 px-4 sm:mx-0 sm:px-0']) }}>
        <nav data-page-tabs class="flex min-w-max items-center gap-5" aria-label="Halaman terkait">
            @foreach ($tabs as $tab)
                <a href="{{ $tab['href'] }}" @if ($tab['active']) aria-current="page" @endif
                   @class([
                       '-mb-px inline-flex h-9 shrink-0 items-center gap-1.5 border-b-2 text-sm transition-colors',
                       'border-ink-950 font-medium text-ink-950' => $tab['active'],
                       'border-transparent text-ink-500 hover:text-ink-950' => ! $tab['active'],
                   ])>
                    {{ $tab['label'] }}

                    @if ($tab['badge'])
                        <span class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-control px-1.5 text-[11px] font-medium leading-none text-ink-700 tabular-nums">{{ $tab['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
    </div>
@endif

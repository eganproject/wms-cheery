@props(['key', 'for' => 'page', 'first' => 'asc'])

@php
    /*
     * Judul kolom yang bisa diklik untuk mengurutkan. Dipakai di dalam <th>:
     *   <th><x-ui.sort key="name">Nama</x-ui.sort></th>
     *
     * Klik pertama memakai arah $first (pakai "desc" untuk tanggal/angka
     * supaya yang terbaru/terbesar muncul dulu), klik berikutnya membalik.
     * Bila controller tidak mengizinkan kunci ini, judul tampil biasa.
     */
    $state = request()->attributes->get("listing.{$for}.sort");
    $sortable = $state && in_array($key, $state['keys'], true);
    $active = $sortable && $state['key'] === $key;
    $dir = $active ? $state['dir'] : null;
    $fixed = $sortable ? ($state['fixed'][$key] ?? null) : null;
    $next = $fixed ?? ($active ? ($dir === 'asc' ? 'desc' : 'asc') : ($first === 'desc' ? 'desc' : 'asc'));
    $pageName = $for;
    $url = $sortable ? request()->fullUrlWithQuery([
        $state['sort_param'] => $key,
        $state['dir_param'] => $fixed ? null : $next,
        $pageName => null,
    ]) : null;
@endphp

@if ($sortable)
    <a href="{{ $url }}"
       title="{{ $fixed ? 'Urutkan menurut kolom ini' : 'Urutkan '.($next === 'asc' ? 'naik (A→Z, kecil→besar)' : 'turun (Z→A, besar→kecil)') }}"
       {{ $attributes->merge(['class' => 'group/sort -mx-1 inline-flex items-center gap-1 rounded px-1 transition-colors hover:text-ink-950 '.($active ? 'font-medium text-ink-950' : '')]) }}>
        <span>{{ $slot }}</span>
        @if ($active)
            <x-icon :name="$dir === 'asc' ? 'chevron-up' : 'chevron-down'" class="h-3.5 w-3.5 shrink-0 text-brand-600" stroke="2.25" />
        @else
            <x-icon name="chevrons-up-down" class="h-3.5 w-3.5 shrink-0 text-ink-300 transition-colors group-hover/sort:text-ink-500" />
        @endif
    </a>
@else
    {{ $slot }}
@endif

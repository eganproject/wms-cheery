{{-- Breadcrumb bilah atas (ikon beranda › kelompok › halaman); ditukar ulang setiap navigasi AJAX. --}}
@php
    $crumbs = \App\Support\Navigation::breadcrumbs($pageTitle ?? null);
@endphp

<nav data-breadcrumbs aria-label="Breadcrumb" class="flex min-w-0 items-center gap-1.5 text-sm text-ink-500">
    <a href="{{ route('admin.dashboard') }}" class="hidden shrink-0 transition hover:text-ink-950 sm:inline" title="Beranda">
        <x-icon name="home" class="h-4 w-4" />
        <span class="sr-only">Beranda</span>
    </a>
    @foreach ($crumbs as $crumb)
        <x-icon name="chevron-right" class="hidden h-3.5 w-3.5 shrink-0 text-ink-400 sm:block" />
        @if ($crumb['href'])
            <a href="{{ $crumb['href'] }}" class="hidden truncate transition hover:text-ink-950 sm:inline">{{ $crumb['label'] }}</a>
        @elseif ($loop->last)
            <span id="page-heading" class="truncate font-medium text-ink-700" aria-current="page">{{ $crumb['label'] }}</span>
        @else
            <span class="truncate hidden {{ count($crumbs) > 2 ? 'md:inline' : 'sm:inline' }}">{{ $crumb['label'] }}</span>
        @endif
    @endforeach
</nav>

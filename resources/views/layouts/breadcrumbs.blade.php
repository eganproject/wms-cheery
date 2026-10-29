{{-- Breadcrumb navbar; ditukar ulang setiap navigasi AJAX. --}}
@php
    $crumbs = \App\Support\Navigation::breadcrumbs($pageTitle ?? null);
@endphp

<nav data-breadcrumbs aria-label="Breadcrumb" class="hidden min-w-0 items-center text-sm sm:flex">
    @foreach ($crumbs as $crumb)
        <x-icon name="chevron-right" class="mx-1 h-3.5 w-3.5 shrink-0 text-ink-400" />
        @if ($crumb['href'])
            <a href="{{ $crumb['href'] }}" class="truncate text-ink-500 transition hover:text-ink-950">{{ $crumb['label'] }}</a>
        @elseif ($loop->last)
            <span id="page-heading" class="truncate font-medium text-ink-950" aria-current="page">{{ $crumb['label'] }}</span>
        @else
            <span class="truncate text-ink-500 {{ $loop->first && count($crumbs) > 2 ? 'hidden md:inline' : '' }}">{{ $crumb['label'] }}</span>
        @endif
    @endforeach
</nav>

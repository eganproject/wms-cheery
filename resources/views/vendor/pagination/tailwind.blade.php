@if ($paginator->hasPages())
    {{-- Paginasi list view ERPNext: keterangan di kiri, tombol halaman abu-abu di kanan. --}}
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between gap-3">
        <p class="text-xs text-ink-500">
            <span class="text-ink-950">{{ $paginator->firstItem() }}</span>–<span class="text-ink-950">{{ $paginator->lastItem() }}</span>
            dari <span class="text-ink-950">{{ $paginator->total() }}</span>
        </p>

        {{-- Ponsel --}}
        <div class="flex items-center gap-1 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="btn btn-default btn-icon pointer-events-none opacity-40"><x-icon name="chevron-left" class="h-4 w-4" /></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Sebelumnya" class="btn btn-default btn-icon">
                    <x-icon name="chevron-left" class="h-4 w-4" />
                </a>
            @endif
            <span class="px-2 text-xs text-ink-600">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Selanjutnya" class="btn btn-default btn-icon">
                    <x-icon name="chevron-right" class="h-4 w-4" />
                </a>
            @else
                <span class="btn btn-default btn-icon pointer-events-none opacity-40"><x-icon name="chevron-right" class="h-4 w-4" /></span>
            @endif
        </div>

        {{-- Layar lebar --}}
        <div class="hidden items-center gap-1 sm:flex">
            @if ($paginator->onFirstPage())
                <span class="btn btn-ghost btn-icon pointer-events-none text-ink-300"><x-icon name="chevron-left" class="h-4 w-4" /></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Sebelumnya" class="btn btn-ghost btn-icon">
                    <x-icon name="chevron-left" class="h-4 w-4" />
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="inline-flex h-7 min-w-7 items-center justify-center px-1 text-xs text-ink-400">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="btn btn-default min-w-[28px] px-2 font-medium text-ink-950 sm:px-2">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="btn btn-ghost min-w-[28px] px-2 sm:px-2">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Selanjutnya" class="btn btn-ghost btn-icon">
                    <x-icon name="chevron-right" class="h-4 w-4" />
                </a>
            @else
                <span class="btn btn-ghost btn-icon pointer-events-none text-ink-300"><x-icon name="chevron-right" class="h-4 w-4" /></span>
            @endif
        </div>
    </nav>
@endif

@if ($paginator->hasPages())
    @php
        $pageName = $paginator->getPageName();
        $navBtn = 'inline-flex h-7 min-w-7 items-center justify-center rounded-md px-1.5 text-[13px] text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-200';
        $navOff = 'inline-flex h-7 min-w-7 items-center justify-center rounded-md px-1.5 text-ink-300';
    @endphp

    {{-- Navigasi halaman: « ‹ 1 … 4 5 6 … 20 › » + lompat ke halaman. --}}
    <nav role="navigation" aria-label="Navigasi halaman" class="flex flex-wrap items-center gap-x-3 gap-y-2">
        {{-- Ponsel: cukup sebelumnya / berikutnya. --}}
        <div class="flex items-center gap-1 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="btn btn-default btn-icon pointer-events-none opacity-40"><x-icon name="chevron-left" class="h-4 w-4" /></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Halaman sebelumnya" class="btn btn-default btn-icon">
                    <x-icon name="chevron-left" class="h-4 w-4" />
                </a>
            @endif
            <span class="px-2 text-[13px] text-ink-600">Hal. <span class="font-medium text-ink-950">{{ $paginator->currentPage() }}</span> / {{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Halaman berikutnya" class="btn btn-default btn-icon">
                    <x-icon name="chevron-right" class="h-4 w-4" />
                </a>
            @else
                <span class="btn btn-default btn-icon pointer-events-none opacity-40"><x-icon name="chevron-right" class="h-4 w-4" /></span>
            @endif
        </div>

        {{-- Layar lebar --}}
        <div class="hidden items-center gap-0.5 sm:flex">
            @if ($paginator->onFirstPage())
                <span class="{{ $navOff }}" aria-hidden="true"><x-icon name="chevron-left" class="h-4 w-4" /></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Halaman sebelumnya" title="Sebelumnya" class="{{ $navBtn }}">
                    <x-icon name="chevron-left" class="h-4 w-4" />
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="inline-flex h-7 min-w-7 items-center justify-center text-[13px] text-ink-400">…</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-ink-950 px-1.5 text-[13px] font-medium text-white">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="Halaman {{ $page }}" class="{{ $navBtn }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Halaman berikutnya" title="Berikutnya" class="{{ $navBtn }}">
                    <x-icon name="chevron-right" class="h-4 w-4" />
                </a>
            @else
                <span class="{{ $navOff }}" aria-hidden="true"><x-icon name="chevron-right" class="h-4 w-4" /></span>
            @endif
        </div>

        {{-- Lompat ke halaman, hanya bila halamannya cukup banyak. --}}
        @if ($paginator->lastPage() > 5)
            <label class="hidden items-center gap-1.5 text-[13px] text-ink-500 sm:flex">
                <span>Ke hal.</span>
                <input type="number" min="1" max="{{ $paginator->lastPage() }}" inputmode="numeric"
                       data-page-jump data-url="{{ request()->fullUrlWithQuery([$pageName => null]) }}"
                       data-param="{{ $pageName }}" data-max="{{ $paginator->lastPage() }}"
                       placeholder="{{ $paginator->currentPage() }}" aria-label="Lompat ke halaman (tekan Enter)"
                       title="Ketik nomor halaman lalu tekan Enter"
                       class="form-control h-7 w-14 rounded-md px-2 text-center text-[13px] [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
            </label>
        @endif
    </nav>
@endif

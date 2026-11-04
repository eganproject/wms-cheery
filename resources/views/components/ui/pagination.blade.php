@props(['paginator', 'bare' => false, 'unit' => 'data'])

@php

    /*
     * Kaki tabel bersama: keterangan "Menampilkan 1–25 dari 240", pilihan
     * jumlah baris per halaman, dan navigasi halaman (termasuk lompat ke
     * halaman tertentu bila halamannya banyak).
     *
     * Pilihan jumlah baris hanya muncul bila controller memakai
     * Listing::perPage(), supaya pilihan yang tampil memang berpengaruh.
     */
    $pageName = $paginator->getPageName();
    $total = $paginator->total();
    $perPageState = request()->attributes->get("listing.{$pageName}.per_page");
    $showPerPage = $perPageState && $total > min($perPageState['options']);
    $fmt = fn ($n) => number_format((int) $n, 0, ',', '.');
@endphp

@if ($total > 0)
    <div {{ $attributes->merge(['class' => $bare
        ? 'mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between'
        : 'flex flex-col gap-3 border-t border-ink-100 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between']) }}>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px] text-ink-500">
            <span>
                Menampilkan
                <span class="font-medium text-ink-950">{{ $fmt($paginator->firstItem()) }}–{{ $fmt($paginator->lastItem()) }}</span>
                dari <span class="font-medium text-ink-950">{{ $fmt($total) }}</span> {{ $unit }}
            </span>

            @if ($showPerPage)
                <label class="flex items-center gap-2">
                    <span class="whitespace-nowrap">Baris per halaman</span>
                    <select data-plain data-per-page
                            data-url="{{ request()->fullUrlWithQuery([$pageName => null]) }}"
                            data-param="{{ $perPageState['param'] }}"
                            class="form-control h-7 w-[72px] rounded-md text-[13px]">
                        @foreach ($perPageState['options'] as $size)
                            <option value="{{ $size }}" @selected($perPageState['value'] === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>

        @if ($paginator->hasPages())
            {{ $paginator->onEachSide(1)->links() }}
        @endif
    </div>
@endif

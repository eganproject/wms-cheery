@props(['paginator', 'bare' => false])

{{--
    Navigasi halaman.

    Pembungkusnya hanya dirender bila memang ada halaman lain, supaya daftar
    yang muat dalam satu halaman tidak berakhir dengan strip kosong.
--}}
@if ($paginator->hasPages())
    @if ($bare)
        <div {{ $attributes->merge(['class' => 'mt-4']) }}>
            {{ $paginator->links() }}
        </div>
    @else
        <div {{ $attributes->merge(['class' => 'border-t border-ink-100 px-4 py-2.5']) }}>
            {{ $paginator->links() }}
        </div>
    @endif
@endif

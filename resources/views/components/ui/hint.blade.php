{{--
    Ikon info kecil yang membuka tooltip penjelasan.

    <x-ui.hint text="Stok fisik dikurangi stok yang dipesan." />
    <x-ui.hint title="Belum QC" text="..." next="Langkah berikutnya yang disarankan." />

    Elemen lain juga bisa langsung diberi tooltip dengan atribut
    data-hint (dan opsional data-hint-title / data-hint-next). Isinya
    ditampilkan oleh resources/js/hints.js: muncul saat disorot kursor,
    difokus keyboard, atau diketuk di layar sentuh.
--}}
@props(['text', 'title' => null, 'next' => null])

<button type="button" {{ $attributes->class('hint-icon') }}
        data-hint="{{ $text }}" @if ($title) data-hint-title="{{ $title }}" @endif @if ($next) data-hint-next="{{ $next }}" @endif
        aria-label="{{ $title ? $title.': ' : 'Info: ' }}{{ $text }}">
    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" /></svg>
</button>

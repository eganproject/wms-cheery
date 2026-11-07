@props(['product' => null, 'size' => 'md', 'url' => null, 'name' => null, 'sku' => null, 'group' => ''])

@php
    // Satu-satunya tempat thumbnail barang digambar, supaya ukuran dan
    // tampilan kosongnya sama di seluruh aplikasi. Bisa diberi model
    // (product) atau langsung url/name/sku untuk data yang berupa array.
    // Klik membuka pratinjau besar (resources/js/image-lightbox.js); grup
    // yang sama bisa digeser dengan panah.
    $sizes = [
        '2xs' => 'h-6 w-6 rounded',
        'xs' => 'h-8 w-8 rounded-md',
        'sm' => 'h-10 w-10 rounded-lg',
        'md' => 'h-12 w-12 rounded-lg',
        'lg' => 'h-16 w-16 rounded-xl',
        'xl' => 'h-40 w-40 rounded-xl',
    ];
    $icons = ['2xs' => 'h-3 w-3', 'xs' => 'h-3.5 w-3.5', 'sm' => 'h-4 w-4', 'md' => 'h-5 w-5', 'lg' => 'h-6 w-6', 'xl' => 'h-10 w-10'];
    $box = $sizes[$size] ?? $sizes['md'];
    $icon = $icons[$size] ?? $icons['md'];
    $url ??= $product?->image_url;
    $name ??= $product?->name;
    $sku ??= $product?->sku;
@endphp

@if ($url)
    <a href="{{ $url }}" target="_blank" rel="noopener" data-thumb data-lightbox="{{ $group }}"
       data-lightbox-title="{{ $name ?: $sku }}" data-lightbox-caption="{{ $name ? $sku : '' }}"
       title="Lihat gambar {{ $name ?: $sku }}"
       {{ $attributes->merge(['class' => "relative block shrink-0 cursor-zoom-in overflow-hidden bg-ink-50 ring-1 ring-inset ring-ink-100 transition hover:ring-ink-300 {$box}"]) }}>
        <img src="{{ $url }}" alt="{{ $name ?: $sku }}" loading="lazy" class="h-full w-full object-cover">
        <span class="thumb-fallback h-full w-full items-center justify-center text-ink-300">
            <x-icon name="box" class="{{ $icon }}" />
        </span>
    </a>
@else
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center bg-ink-50 text-ink-300 ring-1 ring-inset ring-ink-100 {$box}"]) }}
          title="Belum ada gambar">
        <x-icon name="box" class="{{ $icon }}" />
    </span>
@endif

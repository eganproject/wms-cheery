@props(['product', 'size' => 'md'])

@php
    // Satu-satunya tempat thumbnail barang digambar, supaya ukuran dan
    // tampilan kosongnya sama di seluruh aplikasi.
    $sizes = [
        'xs' => 'h-8 w-8 rounded-md',
        'sm' => 'h-10 w-10 rounded-lg',
        'md' => 'h-12 w-12 rounded-lg',
        'lg' => 'h-16 w-16 rounded-xl',
        'xl' => 'h-40 w-40 rounded-xl',
    ];
    $icons = ['xs' => 'h-3.5 w-3.5', 'sm' => 'h-4 w-4', 'md' => 'h-5 w-5', 'lg' => 'h-6 w-6', 'xl' => 'h-10 w-10'];
    $box = $sizes[$size] ?? $sizes['md'];
    $url = $product?->image_url;
@endphp

@if ($url)
    <a href="{{ $url }}" target="_blank" rel="noopener" title="Lihat gambar {{ $product->name }}"
       {{ $attributes->merge(['class' => "block shrink-0 overflow-hidden bg-ink-50 ring-1 ring-inset ring-ink-100 {$box}"]) }}>
        <img src="{{ $url }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-cover">
    </a>
@else
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center bg-ink-50 text-ink-300 ring-1 ring-inset ring-ink-100 {$box}"]) }}
          title="Belum ada gambar">
        <x-icon name="box" class="{{ $icons[$size] ?? $icons['md'] }}" />
    </span>
@endif

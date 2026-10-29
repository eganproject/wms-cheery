@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'submit',
    'icon' => null,
])

@php
    // Tombol ERPNext: setinggi 28px, huruf 13px biasa, hitam untuk aksi utama
    // dan abu-abu muda untuk sisanya.
    $variants = [
        'primary' => 'btn-primary',
        'secondary' => 'btn-default',
        'ghost' => 'btn-ghost',
        'danger' => 'btn-danger',
        'danger-soft' => 'btn-danger-soft',
    ];

    $sizes = [
        'xs' => 'btn-xs',
        'sm' => '',
        'md' => '',
        'lg' => 'btn-lg',
        'icon' => 'btn-icon',
    ];

    $classes = trim('btn '.($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? ''));
    $iconSize = $size === 'lg' ? 'h-4 w-4' : 'h-3.5 w-3.5';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)
            <x-icon :name="$icon" class="{{ $iconSize }} shrink-0" />
        @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)
            <x-icon :name="$icon" class="{{ $iconSize }} shrink-0" />
        @endif
        {{ $slot }}
    </button>
@endif

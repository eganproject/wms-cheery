@props(['variant' => 'neutral', 'icon' => null, 'dot' => true])

@php
    // Pasangan warna indikator ERPNext (--bg-*/--text-on-*).
    $variants = [
        'neutral' => 'bg-control text-ink-700',
        'gray' => 'bg-control text-ink-700',
        'dark' => 'bg-blue-100 text-blue-700',
        'info' => 'bg-blue-100 text-blue-700',
        'blue' => 'bg-blue-100 text-blue-700',
        'success' => 'bg-green-100 text-green-800',
        'green' => 'bg-green-100 text-green-800',
        'danger' => 'bg-red-100 text-red-700',
        'red' => 'bg-red-100 text-red-700',
        'warning' => 'bg-orange-100 text-orange-700',
        'orange' => 'bg-orange-100 text-orange-700',
        'yellow' => 'bg-yellow-100 text-yellow-700',
        'purple' => 'bg-[#f5f0ff] text-[#6e44c4]',
        'outline' => 'bg-white text-ink-600 ring-1 ring-inset ring-ink-200',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'indicator-pill '.($dot && $variant !== 'outline' ? '' : 'no-dot ').($variants[$variant] ?? $variants['neutral']),
]) }}>{{ $slot }}</span>

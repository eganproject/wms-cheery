@props(['variant' => 'neutral', 'icon' => null, 'dot' => false])

@php
    // Lencana status ala wmsxshopee: latar pucat, teks berwarna, tanpa titik.
    $variants = [
        'neutral' => 'bg-ink-100 text-ink-600',
        'gray' => 'bg-ink-100 text-ink-600',
        'dark' => 'bg-sky-50 text-sky-700',
        'info' => 'bg-sky-50 text-sky-700',
        'blue' => 'bg-sky-50 text-sky-700',
        'success' => 'bg-emerald-50 text-emerald-700',
        'green' => 'bg-emerald-50 text-emerald-700',
        'danger' => 'bg-rose-50 text-rose-700',
        'red' => 'bg-rose-50 text-rose-700',
        'warning' => 'bg-amber-50 text-amber-700',
        'orange' => 'bg-amber-50 text-amber-700',
        'yellow' => 'bg-yellow-50 text-yellow-700',
        'purple' => 'bg-violet-50 text-violet-700',
        'outline' => 'bg-white text-ink-600 ring-1 ring-inset ring-ink-200',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'indicator-pill '.($dot && $variant !== 'outline' ? 'with-dot ' : '').($variants[$variant] ?? $variants['neutral']),
]) }}>@if ($icon)<x-icon :name="$icon" class="h-3.5 w-3.5 shrink-0" />@endif{{ $slot }}</span>

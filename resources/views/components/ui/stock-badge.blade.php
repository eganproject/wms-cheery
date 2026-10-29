@props(['product'])

@php
    $config = match ($product->stockStatus()) {
        'habis' => ['variant' => 'danger', 'label' => 'Habis'],
        'menipis' => ['variant' => 'warning', 'label' => 'Menipis'],
        default => ['variant' => 'success', 'label' => 'Aman'],
    };
@endphp

<x-ui.badge :variant="$config['variant']" {{ $attributes }}>
    {{ $config['label'] }}
</x-ui.badge>

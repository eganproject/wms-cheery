@props(['value', 'variant' => 'soft', 'label' => true])

@php
    // SKU adalah kunci utama pencocokan barang, jadi selalu ditampilkan
    // dengan gaya yang sama di seluruh aplikasi agar mudah dikenali.
    $variants = [
        'soft' => 'bg-control text-ink-700',
        'solid' => 'bg-ink-950 text-white',
        'outline' => 'bg-white text-ink-700 ring-1 ring-inset ring-ink-200',
        'danger' => 'bg-red-100 text-red-700',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex max-w-full items-center gap-1 rounded-md px-1.5 py-px font-mono text-[11px] leading-4 '
        .($variants[$variant] ?? $variants['soft']),
]) }} title="SKU: {{ $value }}">
    @if ($label)
        <span class="opacity-60">SKU</span>
    @endif
    <span class="truncate">{{ $value ?: '—' }}</span>
</span>

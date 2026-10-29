@props(['name' => '', 'size' => 'md'])

@php
    $sizes = [
        'xs' => 'h-5 w-5 text-[9px]',
        'sm' => 'h-7 w-7 text-[11px]',
        'md' => 'h-8 w-8 text-xs',
        'lg' => 'h-12 w-12 text-base',
        'xl' => 'h-16 w-16 text-xl',
    ];

    $initials = collect(preg_split('/\s+/', trim($name)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');

    // Warna avatar ERPNext dipilih dari nama, supaya tiap orang mudah dikenali.
    $palette = [
        'bg-blue-100 text-blue-700',
        'bg-green-100 text-green-800',
        'bg-orange-100 text-orange-700',
        'bg-yellow-100 text-yellow-700',
        'bg-red-100 text-red-700',
        'bg-[#f5f0ff] text-[#6e44c4]',
        'bg-[#e6f7f5] text-[#0b7a6c]',
    ];
    $tone = $palette[abs(crc32($name)) % count($palette)];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex shrink-0 items-center justify-center rounded-full font-medium uppercase '.$tone.' '.($sizes[$size] ?? $sizes['md']),
]) }} title="{{ $name }}">
    {{ $initials ?: '?' }}
</span>

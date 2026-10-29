@props(['variant' => 'horizontal'])
{{--
    Logo CheeryChic.

    variant="horizontal" (bawaan): monogram CC di samping tulisan, untuk navbar
    dan menu samping.
    variant="full": monogram di atas tulisan "CheeryChic", untuk halaman masuk.
    variant="mark": monogram CC saja.

    Berkasnya PNG berlatar transparan di public/images, dibuat dari logo resmi.
    Tinggi diatur lewat class (mis. h-7); lebarnya mengikuti (w-auto).
--}}
@php
    $src = match ($variant) {
        'full' => 'images/cheerychic-logo.png',
        'mark' => 'images/cheerychic-mark.png',
        default => 'images/cheerychic-horizontal.png',
    };
@endphp
<img src="{{ asset($src) }}" alt="{{ config('app.name', 'CheeryChic') }}"
     {{ $attributes->merge(['class' => 'select-none']) }} draggable="false">

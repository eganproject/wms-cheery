@props(['marketplace' => null])

{{-- Tanda kecil marketplace (huruf awal berwarna), pengganti logo resmi. --}}
@php
    $key = \App\Http\Controllers\Admin\OrderController::marketplaceKey($marketplace);
    [$letter, $tone] = match ($key) {
        'shopee' => ['S', 'bg-[#ee4d2d] text-white'],
        'tiktok' => ['T', 'bg-ink-950 text-white'],
        'tokopedia' => ['T', 'bg-[#03ac0e] text-white'],
        'lazada' => ['L', 'bg-[#0f146d] text-white'],
        default => [mb_strtoupper(mb_substr((string) $marketplace, 0, 1)) ?: '?', 'bg-ink-200 text-ink-700'],
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-[4px] text-[9px] font-bold leading-none '.$tone]) }}
      title="{{ $marketplace ?: 'Tanpa marketplace' }}" aria-hidden="true">{{ $letter }}</span>

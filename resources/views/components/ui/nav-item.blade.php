@props(['href' => '#', 'icon' => 'circle', 'active' => false, 'badge' => null, 'disabled' => false])

@php
    $state = $active
        ? 'bg-white font-medium text-ink-950 shadow-sm ring-1 ring-ink-200'
        : ($disabled ? 'cursor-not-allowed text-ink-300' : 'text-ink-700 hover:bg-ink-100');

    $iconState = $active ? 'text-ink-950' : ($disabled ? 'text-ink-300' : 'text-ink-500');

    $classes = 'group relative flex h-8 items-center gap-2 whitespace-nowrap rounded-lg px-2 text-[13px] transition lg:h-7 lg:collapsed:justify-center lg:collapsed:px-0 '.$state;
@endphp

@if ($disabled)
    <span {{ $attributes->merge(['class' => $classes]) }}>
        <x-icon :name="$icon" class="h-4 w-4 shrink-0 {{ $iconState }}" />
        <span class="flex-1 truncate lg:collapsed:sr-only">{{ $slot }}</span>
    </span>
@else
    <a href="{{ $href }}" @if ($active) aria-current="page" @endif title="{{ trim($slot) }}" {{ $attributes->merge(['class' => $classes]) }}>
        <x-icon :name="$icon" class="h-4 w-4 shrink-0 {{ $iconState }}" />
        <span class="flex-1 truncate lg:collapsed:sr-only">{{ $slot }}</span>
        @if ($badge)
            <span class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-medium leading-none text-white tabular-nums lg:collapsed:absolute lg:collapsed:-right-0.5 lg:collapsed:-top-0.5 lg:collapsed:h-2 lg:collapsed:min-w-0 lg:collapsed:w-2 lg:collapsed:p-0 lg:collapsed:text-[0px]">{{ $badge }}</span>
        @endif
    </a>
@endif

@props(['href' => '#', 'icon' => 'circle', 'active' => false, 'badge' => null, 'disabled' => false])

@php
    $state = $active
        ? 'bg-control font-medium text-ink-950'
        : ($disabled ? 'cursor-not-allowed text-ink-300' : 'text-ink-700 hover:bg-control hover:text-ink-950');

    $iconState = $active ? 'text-ink-950' : ($disabled ? 'text-ink-300' : 'text-ink-600 group-hover:text-ink-950');

    $classes = 'group flex h-8 items-center gap-2.5 rounded-lg px-2 text-sm transition-colors duration-100 lg:h-7 '.$state;
@endphp

@if ($disabled)
    <span {{ $attributes->merge(['class' => $classes]) }}>
        <x-icon :name="$icon" class="h-4 w-4 shrink-0 {{ $iconState }}" />
        <span class="flex-1 truncate">{{ $slot }}</span>
    </span>
@else
    <a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->merge(['class' => $classes]) }}>
        <x-icon :name="$icon" class="h-4 w-4 shrink-0 {{ $iconState }}" />
        <span class="flex-1 truncate">{{ $slot }}</span>
        @if ($badge)
            <span class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-medium leading-none text-white tabular-nums">{{ $badge }}</span>
        @endif
    </a>
@endif

@props(['label', 'value', 'icon' => 'chart', 'hint' => null, 'accent' => false, 'href' => null, 'info' => null])

{{-- Number card ERPNext: label kecil abu-abu, angka besar, keterangan di bawah. --}}
@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge([
    'class' => 'frappe-card block min-w-0 px-4 py-3.5 transition-colors'.($href ? ' hover:border-ink-200 hover:bg-ink-50' : ''),
]) }}>
    <div class="flex items-center justify-between gap-2">
        <p class="flex min-w-0 items-center gap-1 text-sm text-ink-500">
            <span class="truncate">{{ $label }}</span>
            @if ($info)
                <x-ui.hint :text="$info" :title="$label" />
            @endif
        </p>
        <x-icon :name="$icon" class="h-4 w-4 shrink-0 {{ $accent ? 'text-ink-950' : 'text-ink-400' }}" />
    </div>
    <p class="mt-1.5 truncate text-2xl font-semibold text-ink-950 sm:text-[26px] sm:leading-8">{{ $value }}</p>
    @if ($hint)
        <p class="mt-0.5 truncate text-xs text-ink-500">{{ $hint }}</p>
    @endif
</{{ $tag }}>

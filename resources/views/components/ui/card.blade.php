@props(['title' => null, 'subtitle' => null, 'padding' => 'p-4 sm:p-5'])

{{-- Kartu/seksi bergaris tepi tipis seperti widget dan seksi form ERPNext. --}}
<div {{ $attributes->merge(['class' => 'frappe-card']) }}>
    @if ($title || $subtitle || isset($actions))
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-ink-100 px-4 py-3 sm:px-5">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-base font-semibold text-ink-950">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 text-xs text-ink-500">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="{{ $padding }}">
        {{ $slot }}
    </div>
</div>

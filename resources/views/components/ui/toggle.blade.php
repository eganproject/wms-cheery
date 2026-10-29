@props(['name', 'checked' => false, 'label' => null, 'description' => null])

{{-- Saklar kecil ERPNext (26×16px). --}}
<label class="flex cursor-pointer items-start gap-2.5">
    <input type="hidden" name="{{ $name }}" value="0">
    <span class="relative mt-0.5 inline-flex shrink-0">
        <input type="checkbox" name="{{ $name }}" value="1" @checked($checked)
               {{ $attributes->merge(['class' => 'peer h-4 w-[26px] cursor-pointer appearance-none rounded-full border-0 bg-ink-300 bg-none transition-colors checked:bg-ink-950 checked:bg-none focus:ring-2 focus:ring-ink-300 focus:ring-offset-1 focus:ring-offset-white']) }}>
        <span class="pointer-events-none absolute left-0.5 top-0.5 h-3 w-3 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-2.5"></span>
    </span>

    @if ($label || $description)
        <span class="min-w-0">
            @if ($label)
                <span class="block text-sm text-ink-950">{{ $label }}</span>
            @endif
            @if ($description)
                <span class="block text-xs text-ink-500">{{ $description }}</span>
            @endif
        </span>
    @endif
</label>

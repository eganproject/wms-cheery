@props(['icon' => null])

<a {{ $attributes->merge([
    'class' => 'flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-start text-sm text-ink-700 transition hover:bg-control hover:text-ink-950 focus:bg-control focus:outline-none',
]) }}>
    @if ($icon)
        <x-icon :name="$icon" class="h-4 w-4 text-ink-500" />
    @endif
    {{ $slot }}
</a>

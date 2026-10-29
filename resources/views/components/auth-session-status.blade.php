@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'flex items-start gap-2 rounded-lg bg-green-100 px-3 py-2.5 text-sm text-green-800']) }}>
        <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
        <span>{{ $status }}</span>
    </div>
@endif

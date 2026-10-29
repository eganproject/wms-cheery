@props(['label' => null, 'for' => null, 'hint' => null, 'error' => null, 'required' => false, 'info' => null])

<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    @if ($label)
        <x-input-label :for="$for" :value="$label" :required="$required" :info="$info" />
    @endif

    {{ $slot }}

    @if ($hint)
        <p class="form-help">{{ $hint }}</p>
    @endif

    <x-input-error :messages="$error" class="mt-1" />
</div>

@props(['name' => 'password', 'id' => null, 'placeholder' => '••••••••'])

<div x-data="{ show: false }" class="relative">
    <input :type="show ? 'text' : 'password'"
           type="password"
           name="{{ $name }}"
           id="{{ $id ?? $name }}"
           placeholder="{{ $placeholder }}"
           {{ $attributes->merge(['class' => 'form-control pr-9']) }}>

    <button type="button" @click="show = !show" tabindex="-1"
            class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-ink-500 transition hover:text-ink-950">
        <x-icon name="eye" class="h-3.5 w-3.5" x-show="!show" />
        <x-icon name="eye-off" class="h-3.5 w-3.5" x-show="show" x-cloak />
        <span class="sr-only">Tampilkan kata sandi</span>
    </button>
</div>

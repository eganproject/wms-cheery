@props(['value' => null, 'required' => false, 'info' => null])

{{-- Label kontrol: 12px abu-abu, bintang merah untuk kolom wajib, ikon info bila ada penjelasan. --}}
<label {{ $attributes->merge(['class' => 'form-label']) }}>
    {{ $value ?? $slot }}
    @if ($required)
        <span class="text-red-400">*</span>
    @endif
    @if ($info)
        <x-ui.hint :text="$info" />
    @endif
</label>

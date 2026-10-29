@props(['value' => null, 'required' => false])

{{-- Label kontrol ERPNext: 12px abu-abu, bintang merah untuk kolom wajib. --}}
<label {{ $attributes->merge(['class' => 'form-label']) }}>
    {{ $value ?? $slot }}
    @if ($required)
        <span class="text-red-400">*</span>
    @endif
</label>

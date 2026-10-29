@props(['icon' => 'box', 'title' => 'Belum ada data', 'description' => null])

{{-- Keadaan kosong list view ERPNext: ikon abu-abu, satu kalimat, satu tombol. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <x-icon :name="$icon" class="h-10 w-10 text-ink-300" stroke="1.25" />
    <h3 class="mt-3 text-base font-medium text-ink-950">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-ink-500">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>

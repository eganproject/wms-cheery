{{--
    Tab halaman Import Resi: data resi dan riwayat berkas import.

    Keduanya satu menu ("Import Resi"), jadi tab ini bukan pengulang menu
    samping — riwayat tidak punya menunya sendiri.
--}}
@php
    $tabs = [
        ['label' => 'Data Resi', 'icon' => 'document', 'href' => route('admin.imports.index'), 'active' => request()->routeIs('admin.imports.index'), 'count' => null],
        ['label' => 'Riwayat Import', 'icon' => 'clock', 'href' => route('admin.imports.batches'), 'active' => request()->routeIs('admin.imports.batches'), 'count' => $batchCount ?? null],
    ];
@endphp

<nav data-import-tabs aria-label="Bagian Import Resi"
     class="scrollbar-none mb-5 flex gap-1 overflow-x-auto border-b border-ink-200 text-sm">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['href'] }}" @if ($tab['active']) aria-current="page" @endif
           @class([
               '-mb-px inline-flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2',
               'border-brand-500 font-medium text-ink-950' => $tab['active'],
               'border-transparent text-ink-500 hover:text-ink-700' => ! $tab['active'],
           ])>
            <x-icon :name="$tab['icon']" class="h-4 w-4" />
            {{ $tab['label'] }}
            @if ($tab['count'] !== null)
                <span class="text-xs text-ink-400">{{ number_format($tab['count'], 0, ',', '.') }}</span>
            @endif
        </a>
    @endforeach
</nav>

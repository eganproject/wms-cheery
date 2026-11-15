<x-app-layout :title="$receipt->code">
    <x-ui.page-header :title="'Kain Masuk '.$receipt->code"
                      :subtitle="$receipt->date->translatedFormat('d F Y').($receipt->supplier ? ' · '.$receipt->supplier->name : '').($receipt->reference ? ' · SJ '.$receipt->reference : '')"
                      :back="route('admin.fabric-receipts.index')">
        <x-slot name="actions">
            @can('fabrics.receive')
                @unless ($used)
                    <x-ui.confirm-delete :action="route('admin.fabric-receipts.destroy', $receipt)" title="Hapus dokumen kain masuk ini?"
                                         :description="'Semua '.$receipt->rolls->count().' roll di dokumen ini ikut dihapus dari stok. Label QR yang sudah tertempel tidak bisa dipakai lagi.'" />
                @endunless
            @endcan
        </x-slot>
    </x-ui.page-header>

    @php
        $perFabric = $receipt->rolls->groupBy('fabric_id');
    @endphp

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Roll" :value="$receipt->rolls->count()" icon="qr-code" accent hint="Tiap roll punya label QR sendiri" />
        @foreach ($perFabric->take(3) as $rolls)
            <x-ui.stat-card :label="$rolls->first()->fabric->sku" :value="$rolls->first()->fabric->format($rolls->sum('initial_quantity'))"
                            icon="layers" :hint="$rolls->count().' roll · '.$rolls->first()->fabric->displayName()" />
        @endforeach
    </div>

    @if ($receipt->note)
        <p class="mt-4 rounded-xl border border-ink-100 bg-white px-4 py-3 text-sm text-ink-700">{{ $receipt->note }}</p>
    @endif

    <x-ui.card class="mt-5" title="Roll &amp; Label QR" subtitle="Cetak label QR lalu tempel di setiap roll. Isi QR = kode roll, sehingga saat discan langsung terbaca SKU kain dan sisanya." padding="p-0">
        @include('admin.fabric-labels.rolls', ['rolls' => $receipt->rolls, 'showFabric' => true])
    </x-ui.card>

    <p class="mt-3 text-xs text-ink-400">Dicatat oleh {{ $receipt->user?->name ?? '—' }} pada {{ $receipt->created_at->format('d/m/Y H:i') }}.
        @if ($used) Dokumen tidak bisa dihapus karena sebagian roll sudah keluar atau dikoreksi. @endif</p>
</x-app-layout>

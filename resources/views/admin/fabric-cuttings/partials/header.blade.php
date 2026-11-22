{{-- Judul, ringkasan, dan pilihan tampilan Cutting / WIP Jahit / Rekap. --}}
<x-ui.page-header title="Cutting & Jahit" icon="clipboard"
                  subtitle="Kain keluar masuk ke Cutting. Hasil potong per varian disimpan di gudang atau langsung ke penjahit (WIP Jahit), lalu selesai saat barang jadi diterima.">
    <x-slot name="actions">
        @can('fabrics.issue')
            <x-ui.button :href="route('admin.fabric-scan')" icon="scan">Scan Kain Keluar</x-ui.button>
        @endcan
    </x-slot>
</x-ui.page-header>

<div class="mb-5 grid grid-cols-2 gap-4 xl:grid-cols-4">
    <x-ui.stat-card label="Cutting Berjalan" :value="number_format($summary['cutting'], 0, ',', '.')" icon="clipboard" accent
                    hint="Kain sudah keluar, belum selesai dipotong" :href="route('admin.fabric-cuttings.index')" />
    <x-ui.stat-card label="Hasil Potong di Gudang" :value="number_format($summary['warehouse'], 0, ',', '.').' pcs'" icon="box"
                    hint="Belum dikirim ke penjahit" :href="route('admin.fabric-cuttings.index', ['lihat' => 'jahit', 'status' => 'gudang'])" />
    <x-ui.stat-card label="Batch di Penjahit" :value="number_format($summary['batches'], 0, ',', '.')" icon="truck"
                    hint="Batch WIP Jahit yang belum diterima lengkap" :href="route('admin.fabric-cuttings.index', ['lihat' => 'jahit', 'status' => 'penjahit'])" />
    <x-ui.stat-card label="Pcs di Penjahit" :value="number_format($summary['wip'], 0, ',', '.').' pcs'" icon="layers"
                    hint="Belum diterima sebagai barang jadi" />
</div>

<div class="mb-4 flex rounded-xl bg-control p-1 sm:w-[30rem]">
    <a href="{{ route('admin.fabric-cuttings.index') }}"
       class="flex-1 rounded-lg px-3 py-2 text-center text-sm font-semibold {{ $view === 'cutting' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500' }}">Cutting</a>
    <a href="{{ route('admin.fabric-cuttings.index', ['lihat' => 'jahit']) }}"
       class="flex-1 rounded-lg px-3 py-2 text-center text-sm font-semibold {{ $view === 'jahit' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500' }}">Hasil Potong</a>
    <a href="{{ route('admin.fabric-cuttings.index', ['lihat' => 'rekap']) }}"
       class="flex-1 rounded-lg px-3 py-2 text-center text-sm font-semibold {{ $view === 'rekap' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500' }}">Rekap</a>
</div>


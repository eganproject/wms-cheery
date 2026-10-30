<x-app-layout title="Buat Penjualan Manual">
    <x-ui.page-header title="Buat Penjualan Manual" subtitle="Catat penjualan di luar marketplace yang barangnya keluar dari gudang."
                      :back="route('admin.outbounds.index')" />

    @include('admin.outbounds.partials.form', [
        'outbound' => null,
        'code' => $code,
        'products' => $products,
        'defaultType' => $defaultType,
    ])
</x-app-layout>

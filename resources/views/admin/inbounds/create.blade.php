<x-app-layout title="Buat Penerimaan Barang">
    <x-ui.page-header title="Buat Penerimaan Barang" subtitle="Catat penerimaan barang baru dari pemasok."
                      :back="route('admin.inbounds.index')" />

    @include('admin.inbounds.partials.form', ['inbound' => null, 'code' => $code, 'products' => $products])
</x-app-layout>

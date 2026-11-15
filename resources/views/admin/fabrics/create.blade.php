<x-app-layout title="Tambah Jenis Kain">
    <x-ui.page-header title="Tambah Jenis Kain" subtitle="SKU kain dipakai sebagai awalan kode QR setiap roll."
                      :back="route('admin.fabrics.index')" />

    @include('admin.fabrics.partials.form')
</x-app-layout>

<x-app-layout title="Ubah Jenis Kain">
    <x-ui.page-header :title="'Ubah '.$fabric->sku" :subtitle="$fabric->displayName()"
                      :back="route('admin.fabrics.show', $fabric)" />

    @include('admin.fabrics.partials.form')
</x-app-layout>

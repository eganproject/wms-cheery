<x-app-layout title="Tambah Karyawan">
    <x-ui.page-header title="Tambah Karyawan" subtitle="Daftarkan karyawan dan pilih cara absennya." :back="route('admin.employees.index')" />
    @include('admin.employees.form')
</x-app-layout>

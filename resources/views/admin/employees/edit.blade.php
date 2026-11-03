<x-app-layout title="Ubah Karyawan">
    <x-ui.page-header :title="'Ubah '.$employee->name" subtitle="Perubahan jam kerja berlaku juga untuk hari-hari yang sudah lewat." :back="route('admin.employees.index')" />
    @include('admin.employees.form')
</x-app-layout>

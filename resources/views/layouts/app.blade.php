@php
    $pageTitle = $title ?? 'Beranda';
    $documentTitle = $pageTitle.' — '.config('app.name', 'WMS');
@endphp

{{-- Permintaan AJAX hanya menerima potongan halaman, bukan dokumen penuh. --}}
@if (request()->header('X-Page-Fragment') === '1')
    <div id="page-fragment" data-title="{{ $documentTitle }}" data-heading="{{ $pageTitle }}">
        <template data-fragment="nav">@include('layouts.nav')</template>
        <template data-fragment="bottom-nav">@include('layouts.bottom-nav')</template>
        <template data-fragment="breadcrumbs">@include('layouts.breadcrumbs', ['pageTitle' => $pageTitle])</template>
        <template data-fragment="notifications">@include('layouts.notifications')</template>
        <template data-fragment="flash"><x-ui.flash /></template>
        <div data-fragment="content">{{ $slot }}</div>
    </div>
@else
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#ffffff">

    <title>{{ $documentTitle }}</title>

    <link rel="icon" href="{{ asset('favicon-32x32.png') }}" type="image/png" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">


    {{-- Sidebar diciutkan dipasang sebelum tampil agar tidak bergeser. --}}
    <script>
        try { if (localStorage.getItem('wms.sidebar') === 'collapsed') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-white font-sans">

{{-- Indikator loading: garis tipis di paling atas layar --}}
<div id="page-progress" aria-hidden="true"></div>

{{--
    Kerangka panel admin ala wmsxshopee: menu samping abu-abu muda menempel
    di kiri (bisa diciutkan jadi ikon saja dengan Ctrl+B), bilah atas putih
    setinggi 48px berisi breadcrumb dan aksi akun, lalu isi halaman.
--}}
<div x-data="{
        sidebarOpen: false,
        sidebarCollapsed: document.documentElement.classList.contains('sidebar-collapsed'),
        toggleSidebar() {
            if (window.matchMedia('(min-width: 1024px)').matches) {
                this.sidebarCollapsed = ! this.sidebarCollapsed;
                document.documentElement.classList.toggle('sidebar-collapsed', this.sidebarCollapsed);
                try { localStorage.setItem('wms.sidebar', this.sidebarCollapsed ? 'collapsed' : 'open') } catch (e) {}
            } else {
                this.sidebarOpen = ! this.sidebarOpen;
            }
        },
     }"
     @page-navigated.window="sidebarOpen = false"
     @toggle-sidebar.window="toggleSidebar()"
     @keydown.ctrl.b.window.prevent="toggleSidebar()"
     @keydown.escape.window="sidebarOpen = false"
     class="min-h-full">

    {{-- Latar gelap saat menu dibuka di ponsel. --}}
    <div x-show="sidebarOpen" x-cloak
         x-transition:enter="transition-opacity ease-linear duration-150" x-transition:enter-start="opacity-0"
         x-transition:leave="transition-opacity ease-linear duration-150" x-transition:leave-end="opacity-0"
         @click="sidebarOpen = false"
         class="fixed inset-0 z-40 bg-black/40 lg:hidden"></div>

    {{-- Menu samping: laci di ponsel, kolom tetap di layar lebar. --}}
    <aside data-app-sidebar
           :class="sidebarOpen && 'translate-x-0'"
           class="fixed inset-y-0 left-0 z-50 flex w-sidebar max-w-[85%] -translate-x-full flex-col border-r border-ink-200 bg-ink-50 p-2 transition-[transform,width] duration-200 lg:max-w-none lg:translate-x-0 lg:collapsed:w-14"
           aria-label="Menu utama">
        @include('layouts.sidebar')
    </aside>

    <div class="transition-[padding] duration-200 lg:pl-sidebar lg:collapsed:pl-14">
        @include('layouts.navbar', ['pageTitle' => $pageTitle])

        <main data-app-main class="px-4 pb-28 pt-5 sm:px-6 lg:pb-10">
            <div id="page-content" class="mx-auto w-full max-w-7xl">
                {{ $slot }}
            </div>
        </main>

        <footer data-app-footer class="hidden px-4 pb-5 text-xs text-ink-400 sm:px-6 lg:block">
            <div class="mx-auto max-w-7xl border-t border-ink-100 pt-3">
                &copy; {{ date('Y') }} {{ config('app.name', 'CheeryChic') }}
            </div>
        </footer>
    </div>

    @include('layouts.bottom-nav')

    @include('layouts.command-palette')

    <div id="flash-slot"><x-ui.flash /></div>
</div>
</body>
</html>
@endif

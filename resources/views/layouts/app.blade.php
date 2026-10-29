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


    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-white font-sans">

{{-- Indikator loading: garis tipis di paling atas layar --}}
<div id="page-progress" aria-hidden="true"></div>

<div x-data="{
        sidebarOpen: false,
        sidebarCollapsed: (() => { try { return localStorage.getItem('wms.sidebar') === 'collapsed' } catch (e) { return false } })(),
        toggleSidebar() {
            if (window.matchMedia('(min-width: 1024px)').matches) {
                this.sidebarCollapsed = ! this.sidebarCollapsed;
                try { localStorage.setItem('wms.sidebar', this.sidebarCollapsed ? 'collapsed' : 'open') } catch (e) {}
            } else {
                this.sidebarOpen = ! this.sidebarOpen;
            }
        },
     }"
     @page-navigated.window="sidebarOpen = false"
     @toggle-sidebar.window="toggleSidebar()"
     @keydown.escape.window="sidebarOpen = false"
     class="min-h-full">

    @include('layouts.navbar', ['pageTitle' => $pageTitle])

    <div class="mx-auto flex w-full max-w-[1440px]">
        {{-- Latar gelap saat menu dibuka di ponsel. --}}
        <div x-show="sidebarOpen" x-cloak
             x-transition:enter="transition-opacity ease-linear duration-150" x-transition:enter-start="opacity-0"
             x-transition:leave="transition-opacity ease-linear duration-150" x-transition:leave-end="opacity-0"
             @click="sidebarOpen = false"
             class="fixed inset-0 z-40 bg-ink-950/30 lg:hidden"></div>

        {{-- Menu samping: laci di ponsel, kolom tetap di layar lebar. --}}
        <aside data-app-sidebar
               :class="{ '-translate-x-full': ! sidebarOpen, 'translate-x-0 shadow-lift': sidebarOpen, 'lg:hidden': sidebarCollapsed }"
               class="fixed inset-y-0 left-0 z-50 flex w-[260px] max-w-[85%] -translate-x-full flex-col bg-white transition-transform duration-200 ease-out
                      lg:sticky lg:top-navbar lg:z-20 lg:h-[calc(100vh-var(--navbar-height))] lg:w-[232px] lg:shrink-0 lg:pl-3 lg:max-w-none lg:translate-x-0 lg:shadow-none lg:transition-none"
               aria-label="Menu utama">
            @include('layouts.sidebar')
        </aside>

        <main data-app-main class="min-w-0 flex-1 px-4 pb-28 sm:px-5 lg:pb-10 lg:pl-6 lg:pr-5">
            <div id="page-content" class="mx-auto w-full">
                {{ $slot }}
            </div>
        </main>
    </div>

    @include('layouts.bottom-nav')

    @include('layouts.command-palette')

    <div id="flash-slot"><x-ui.flash /></div>
</div>
</body>
</html>
@endif

@php
    $pageTitle = $title ?? 'Beranda';
    $documentTitle = $pageTitle.' — '.config('app.name', 'WMS');
    // Versi aset hasil build. Tab yang masih memuat skrip lama (dibuka
    // sebelum aplikasi diperbarui) dimuat ulang penuh saat versinya beda.
    $assetVersion = once(fn () => is_file($manifest = public_path('build/manifest.json')) ? substr(md5_file($manifest), 0, 12) : '');
@endphp

{{-- Permintaan AJAX hanya menerima potongan halaman, bukan dokumen penuh. --}}
@if (request()->header('X-Page-Fragment') === '1')
    <div id="page-fragment" data-title="{{ $documentTitle }}" data-heading="{{ $pageTitle }}" data-assets="{{ $assetVersion }}">
        <template data-fragment="nav">@include('layouts.nav')</template>
        <template data-fragment="bottom-nav">@include('layouts.bottom-nav')</template>
        <template data-fragment="breadcrumbs">@include('layouts.breadcrumbs', ['pageTitle' => $pageTitle])</template>
        <template data-fragment="notifications">@include('layouts.notifications')</template>
        <template data-fragment="flash"><x-ui.flash /></template>
        <div data-fragment="content">@include('layouts.page-tabs'){{ $slot }}</div>
    </div>
@else
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="asset-version" content="{{ $assetVersion }}">
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
                @include('layouts.page-tabs')
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

{{-- Tooltip penjelasan untuk semua elemen [data-hint] dan ikon <x-ui.hint> (resources/js/hints.js). --}}
<div id="hint-tip" role="tooltip" aria-hidden="true"
     class="pointer-events-none fixed left-0 top-0 z-[90] hidden w-72 max-w-[calc(100vw-1.5rem)] rounded-xl border border-ink-200 bg-white p-3 text-left opacity-0 shadow-lift transition-opacity duration-150">
    <span data-part="arrow" class="absolute h-2.5 w-2.5 rotate-45 border-l border-t border-ink-200 bg-white"></span>
    <p data-part="title" class="relative text-[13px] font-semibold text-ink-950"></p>
    <p data-part="body" class="relative whitespace-pre-line text-xs leading-relaxed text-ink-600"></p>
    <p data-part="next" class="relative mt-2 flex gap-1.5 border-t border-ink-100 pt-2 text-xs leading-relaxed text-ink-700">
        <svg class="mt-px h-4 w-4 shrink-0 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="m12 16 4-4-4-4" /><path d="M8 12h8" /></svg>
        <span data-part="next-text"></span>
    </p>
</div>
{{-- Pratinjau gambar barang untuk semua link [data-lightbox] (resources/js/image-lightbox.js). --}}
<div id="image-lightbox" hidden role="dialog" aria-modal="true" aria-label="Pratinjau gambar"
     class="fixed inset-0 z-[95] flex flex-col bg-ink-950/85 backdrop-blur-sm print:hidden">
    <div class="flex items-start gap-3 px-4 py-3 text-white sm:px-6">
        <div class="min-w-0 flex-1">
            <p data-part="title" class="truncate text-sm font-semibold"></p>
            <p data-part="caption" class="truncate font-mono text-xs text-white/70"></p>
        </div>
        <span data-part="counter" class="shrink-0 pt-0.5 text-xs tabular-nums text-white/70"></span>
        <a data-part="original" href="#" target="_blank" rel="noopener" data-no-ajax
           class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-xs text-white/80 transition hover:bg-white/10 hover:text-white"
           title="Buka gambar ukuran asli di tab baru">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6" /><path d="M10 14 21 3" /><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /></svg>
            <span class="hidden sm:inline">Ukuran asli</span>
        </a>
        <button type="button" data-part="close" data-action="close"
                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white/80 transition hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60"
                title="Tutup (Esc)" aria-label="Tutup">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
        </button>
    </div>
    <div class="relative flex min-h-0 flex-1 items-center justify-center px-4 pb-6 sm:px-16" data-action="close">
        <img data-part="image" alt="" hidden
             class="max-h-full max-w-full rounded-xl bg-white object-contain shadow-2xl" onclick="event.stopPropagation()">
        <p data-part="loading" class="text-sm text-white/70">Memuat gambar…</p>
        <p data-part="error" hidden class="rounded-lg bg-white/10 px-4 py-3 text-sm text-white">Gambar tidak bisa dimuat. Berkasnya mungkin sudah dihapus.</p>
        <button type="button" data-part="prev" data-action="prev" title="Sebelumnya (←)" aria-label="Gambar sebelumnya"
                class="absolute left-2 top-1/2 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 sm:left-4">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
        </button>
        <button type="button" data-part="next" data-action="next" title="Berikutnya (→)" aria-label="Gambar berikutnya"
                class="absolute right-2 top-1/2 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 sm:right-4">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
        </button>
    </div>
</div>
</body>
</html>
@endif

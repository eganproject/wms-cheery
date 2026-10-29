{{-- Kepala menu: logo CheeryChic, dan tombol tutup di ponsel. --}}
<div class="flex shrink-0 items-center justify-between gap-2 px-1.5 pb-2 pt-1 lg:collapsed:justify-center lg:collapsed:px-0">
    <a href="{{ route('admin.dashboard') }}" class="flex min-w-0 items-center gap-2 rounded-lg p-1 hover:bg-ink-100" title="Beranda">
        <x-application-logo class="h-7 w-auto lg:collapsed:hidden" />
        <x-application-logo variant="mark" class="hidden h-7 w-auto lg:collapsed:block" />
    </a>
    <button type="button" @click="sidebarOpen = false" class="btn btn-ghost btn-icon lg:hidden" title="Tutup menu">
        <x-icon name="close" class="h-4 w-4" />
        <span class="sr-only">Tutup menu</span>
    </button>
</div>

@include('layouts.nav')

{{-- Akun: kartu kecil di dasar menu, seperti wmsxshopee. --}}
<a href="{{ route('profile.edit') }}" title="{{ auth()->user()->name }}"
   class="flex shrink-0 items-center gap-2 rounded-lg p-1.5 hover:bg-ink-100 lg:collapsed:justify-center lg:collapsed:px-0">
    <x-ui.avatar :name="auth()->user()->name" size="sm" class="shrink-0" />
    <span class="min-w-0 flex-1 leading-tight lg:collapsed:hidden">
        <span class="block truncate text-[13px] font-medium text-ink-950">{{ auth()->user()->name }}</span>
        <span class="block truncate text-xs text-ink-500">{{ auth()->user()->role?->name ?? 'Tanpa role' }}</span>
    </span>
</a>

{{-- Kepala laci menu — hanya di ponsel; di layar lebar logo sudah ada di navbar. --}}
<div class="flex h-navbar shrink-0 items-center justify-between border-b border-ink-100 px-4 lg:hidden">
    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
        <x-application-logo class="h-8 w-auto" />
    </a>
    <button type="button" @click="sidebarOpen = false" class="btn btn-ghost btn-icon -mr-1" title="Tutup menu">
        <x-icon name="close" class="h-[18px] w-[18px]" />
        <span class="sr-only">Tutup menu</span>
    </button>
</div>

@include('layouts.nav')

{{-- Akun — hanya di laci ponsel, karena di sana menu akun navbar sulit dijangkau. --}}
<div class="shrink-0 border-t border-ink-100 p-2 lg:hidden">
    <div class="flex items-center gap-2.5 rounded-lg px-2 py-1.5">
        <x-ui.avatar :name="auth()->user()->name" size="sm" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-ink-950">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-ink-500">{{ auth()->user()->role?->name ?? 'Tanpa role' }}</p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" title="Keluar" class="btn btn-ghost btn-icon">
                <x-icon name="logout" class="h-4 w-4" />
                <span class="sr-only">Keluar</span>
            </button>
        </form>
    </div>
</div>

{{--
    Bilah atas ala wmsxshopee: tombol ciutkan menu, breadcrumb, kotak
    pencarian/perintah, lonceng persetujuan, dan menu akun. Tingginya 48px dan
    menempel di atas kolom isi (logo ada di menu samping).
--}}
<header data-app-navbar class="sticky top-0 z-30 h-navbar border-b border-ink-200 bg-white">
    <div class="flex h-full w-full items-center gap-3 px-4 sm:px-5">
        <button type="button" @click="sidebarOpen = true"
                class="btn btn-ghost btn-icon -ml-1.5 lg:hidden" title="Buka menu">
            <x-icon name="menu" class="h-4 w-4" />
            <span class="sr-only">Buka menu</span>
        </button>
        <button type="button" @click="toggleSidebar()"
                class="btn btn-ghost btn-icon -ml-1.5 hidden lg:inline-flex"
                :title="(sidebarCollapsed ? 'Lebarkan' : 'Ciutkan') + ' sidebar (Ctrl+B)'" :aria-pressed="sidebarCollapsed.toString()">
            <x-icon name="panel-left" class="h-4 w-4" />
            <span class="sr-only">Ciutkan/lebarkan menu</span>
        </button>

        <div class="min-w-0 flex-1">
            @include('layouts.breadcrumbs', ['pageTitle' => $pageTitle])
        </div>

        <div class="flex items-center gap-1 sm:gap-2">
            {{-- Kotak pencarian: membuka palet perintah (Ctrl+K / Ctrl+G). --}}
            <button type="button" @click="$dispatch('open-command-palette')"
                    class="hidden h-8 w-56 items-center gap-2 rounded-lg bg-control px-2.5 text-left text-sm text-ink-500 transition hover:bg-ink-200/70 md:flex xl:w-72">
                <x-icon name="search" class="h-3.5 w-3.5 shrink-0" />
                <span class="flex-1 truncate">Cari atau ketik perintah</span>
                <kbd class="kbd">Ctrl K</kbd>
            </button>
            <button type="button" @click="$dispatch('open-command-palette')"
                    class="btn btn-ghost btn-icon md:hidden" title="Cari">
                <x-icon name="search" class="h-[18px] w-[18px]" />
                <span class="sr-only">Cari</span>
            </button>

            @include('layouts.notifications')

            <x-dropdown align="right" width="56">
                <x-slot name="trigger">
                    <button type="button" class="flex items-center gap-1.5 rounded-lg p-1 transition hover:bg-ink-100"
                            title="{{ auth()->user()->name }}">
                        <x-ui.avatar :name="auth()->user()->name" size="sm" />
                        <x-icon name="chevron-down" class="hidden h-3.5 w-3.5 text-ink-500 sm:block" />
                        <span class="sr-only">Menu akun</span>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <div class="px-2.5 pb-2 pt-1.5">
                        <p class="truncate text-sm font-medium text-ink-950">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-ink-500">{{ auth()->user()->email }}</p>
                        <p class="mt-1 truncate text-xs text-ink-500">{{ auth()->user()->role?->name ?? 'Tanpa role' }}</p>
                    </div>
                    <div class="my-1 border-t border-ink-100"></div>

                    <x-dropdown-link :href="route('profile.edit')" icon="user">Profil Saya</x-dropdown-link>
                    <button type="button" @click="$dispatch('toggle-sidebar')"
                            class="hidden w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-start text-sm text-ink-700 transition hover:bg-control lg:flex">
                        <x-icon name="panel-left" class="h-4 w-4 text-ink-500" />
                        Tampilkan/Sembunyikan Sidebar
                    </button>
                    <button type="button" @click="$dispatch('open-command-palette')"
                            class="flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-start text-sm text-ink-700 transition hover:bg-control">
                        <x-icon name="command" class="h-4 w-4 text-ink-500" />
                        <span class="flex-1">Pencarian Cepat</span>
                        <kbd class="kbd hidden sm:inline-flex">Ctrl K</kbd>
                    </button>

                    <div class="my-1 border-t border-ink-100"></div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')" icon="logout"
                                         onclick="event.preventDefault(); this.closest('form').requestSubmit();">
                            Keluar
                        </x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>
    </div>
</header>

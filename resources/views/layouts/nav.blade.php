{{--
    Menu samping bergaya sidebar workspace ERPNext.

    Isinya dibaca dari App\Support\Navigation: halaman dikelompokkan menurut
    pekerjaannya, dan setiap halaman bisa dibuka dengan satu klik. Kelompok
    bisa dilipat; kelompok yang memuat halaman aktif selalu terbuka.

    Ditukar ulang setiap navigasi AJAX supaya status menu aktif tetap akurat.
--}}
@php
    $sections = \App\Support\Navigation::sections();
@endphp

<nav data-sidebar-nav class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-2 py-3 lg:px-0 lg:py-4 lg:pr-2">
    @foreach ($sections as $section)
        @if ($section['label'] === null)
            <div class="mb-3 space-y-px">
                @foreach ($section['items'] as $item)
                    <x-ui.nav-item :href="$item['href']" :icon="$item['icon']" :active="$item['active']" :badge="$item['badge']">
                        {{ $item['label'] }}
                    </x-ui.nav-item>
                @endforeach
            </div>
        @else
            <div x-data="{
                    storageKey: 'wms.nav.{{ $section['key'] }}',
                    open: true,
                    init() {
                        @if (! $section['active'])
                            try { this.open = localStorage.getItem(this.storageKey) !== 'closed' } catch (e) {}
                        @endif
                    },
                    toggle() {
                        this.open = ! this.open;
                        try { localStorage.setItem(this.storageKey, this.open ? 'open' : 'closed') } catch (e) {}
                    },
                 }"
                 class="mb-2">
                <button type="button" @click="toggle()" :aria-expanded="open.toString()"
                        class="group flex w-full items-center gap-1 rounded-md px-2 py-1 text-left text-xs font-medium uppercase tracking-[0.04em] text-ink-500 transition hover:text-ink-950">
                    <span class="flex-1 truncate">{{ $section['label'] }}</span>
                    <x-icon name="chevron-down" class="h-3.5 w-3.5 shrink-0 transition-transform duration-150"
                            x-bind:class="open ? '' : '-rotate-90'" />
                </button>

                <div x-show="open" class="mt-0.5 space-y-px">
                    @foreach ($section['items'] as $item)
                        <x-ui.nav-item :href="$item['href']" :icon="$item['icon']" :active="$item['active']" :badge="$item['badge']">
                            {{ $item['label'] }}
                        </x-ui.nav-item>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</nav>

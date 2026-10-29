{{--
    Pencarian cepat (awesomebar ERPNext): Ctrl+K atau Ctrl+G membuka daftar
    seluruh halaman yang boleh dibuka, pintasan "buat baru", serta pencarian
    barang dan resi langsung dari kata yang diketik.
--}}
@php
    $user = auth()->user();

    $paletteConfig = [
        'items' => \App\Support\Navigation::searchIndex()->values()->all(),
        'searches' => array_values(array_filter([
            $user->can('products.view') ? [
                'label' => 'Cari barang ":q"',
                'group' => 'Cari',
                'icon' => 'box',
                'href' => route('admin.products.index').'?search=',
            ] : null,
            $user->can('imports.view') ? [
                'label' => 'Lacak resi ":q"',
                'group' => 'Cari',
                'icon' => 'waybill',
                'href' => route('admin.imports.status').'?range='.\App\Support\DateRange::ALL.'&search=',
            ] : null,
            $user->can('outbounds.view') ? [
                'label' => 'Cari barang keluar ":q"',
                'group' => 'Cari',
                'icon' => 'outbound',
                'href' => route('admin.outbounds.index').'?range='.\App\Support\DateRange::ALL.'&search=',
            ] : null,
        ])),
    ];
@endphp

<div x-data="commandPalette(@js($paletteConfig))"
     @open-command-palette.window="show()"
     @keydown.window="shortcut($event)">
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-start justify-center px-3 pt-[12vh] sm:px-4"
             role="dialog" aria-modal="true" aria-label="Pencarian cepat">
            <div x-show="open" x-transition.opacity.duration.100ms @click="hide()" class="fixed inset-0 bg-ink-950/25"></div>

            <div x-show="open" x-transition.opacity.duration.100ms
                 class="relative w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-lift">
                <div class="flex items-center gap-2 border-b border-ink-100 px-3">
                    <x-icon name="search" class="h-4 w-4 shrink-0 text-ink-500" />
                    <input x-ref="input" x-model="query" @keydown="navigate($event)" type="text"
                           autocomplete="off" spellcheck="false" placeholder="Cari halaman, perintah, barang, atau resi…"
                           class="h-12 w-full border-0 bg-transparent px-0 text-base text-ink-950 focus:bg-transparent focus:shadow-none focus:ring-0">
                    <kbd class="kbd hidden sm:inline-flex">Esc</kbd>
                </div>

                <ul x-ref="list" class="scrollbar-thin max-h-[60vh] overflow-y-auto p-1.5">
                    <template x-for="(entry, index) in results" :key="entry.group + entry.label + index">
                        <li>
                            <template x-if="index === 0 || results[index - 1].group !== entry.group">
                                <p class="px-2 pb-1 pt-2 text-[11px] font-medium uppercase tracking-[0.04em] text-ink-500" x-text="entry.group"></p>
                            </template>
                            <a :href="entry.href" @click.prevent="go(entry)" @mousemove="active = index"
                               :data-active="active === index"
                               :class="active === index ? 'bg-control text-ink-950' : 'text-ink-700'"
                               class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-ink-100 bg-white text-ink-600"
                                      x-html="icon(entry.icon)"></span>
                                <span class="min-w-0 flex-1 truncate" x-text="entry.label"></span>
                                <x-icon name="arrow-right" class="h-3.5 w-3.5 shrink-0 text-ink-400" x-show="active === index" />
                            </a>
                        </li>
                    </template>

                    <li x-show="results.length === 0" class="px-3 py-8 text-center text-sm text-ink-500">
                        Tidak ada yang cocok dengan "<span x-text="query"></span>".
                    </li>
                </ul>

                <div class="hidden items-center gap-4 border-t border-ink-100 bg-ink-50 px-3 py-2 text-[11px] text-ink-500 sm:flex">
                    <span class="flex items-center gap-1"><kbd class="kbd">↑</kbd><kbd class="kbd">↓</kbd> pilih</span>
                    <span class="flex items-center gap-1"><kbd class="kbd">Enter</kbd> buka</span>
                    <span class="flex items-center gap-1"><kbd class="kbd">Ctrl K</kbd> buka/tutup</span>
                </div>
            </div>
        </div>
    </template>

    {{-- Ikon yang dipakai daftar, dirender sekali lalu disalin oleh Alpine. --}}
    <div class="hidden" x-ref="icons" aria-hidden="true">
        @foreach (collect($paletteConfig['items'])->pluck('icon')->merge(collect($paletteConfig['searches'])->pluck('icon'))->unique() as $iconName)
            <span data-icon="{{ $iconName }}"><x-icon :name="$iconName" class="h-3.5 w-3.5" /></span>
        @endforeach
    </div>
</div>

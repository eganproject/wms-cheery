{{--
    Menu samping ala wmsxshopee: judul kelompok kecil abu-abu, item setinggi
    28px, dan item aktif berupa "kartu" putih bergaris tipis.

    Isinya dibaca dari App\Support\Navigation. Ditukar ulang setiap navigasi
    AJAX supaya status menu aktif tetap akurat.
--}}
@php
    $sections = \App\Support\Navigation::sections();
@endphp

<nav data-sidebar-nav class="scrollbar-thin min-h-0 flex-1 overflow-y-auto overflow-x-hidden">
    @foreach ($sections as $section)
        <div class="mt-1">
            @if ($section['label'])
                <p class="whitespace-nowrap px-2 pb-1 pt-3 text-xs text-ink-500 lg:collapsed:hidden">{{ $section['label'] }}</p>
                <hr class="mx-2 my-2 hidden border-ink-200 lg:collapsed:block">
            @endif
            <ul class="space-y-px">
                @foreach ($section['items'] as $item)
                    <li>
                        <x-ui.nav-item :href="$item['href']" :icon="$item['icon']" :active="$item['active']" :badge="$item['badge']">
                            {{ $item['label'] }}
                        </x-ui.nav-item>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>

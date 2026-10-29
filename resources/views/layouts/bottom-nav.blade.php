{{--
    Navigasi bawah khusus ponsel.

    Di gudang, layar dipegang satu tangan sambil tangan lain memegang barang
    atau scanner. Bilah ini menaruh pekerjaan yang paling sering dibuka di
    jempol; slot terakhir selalu "Menu" supaya seluruh halaman lain tetap
    terjangkau.
--}}
@php
    $user = auth()->user();

    $items = collect([
        [
            'label' => 'Beranda',
            'icon' => 'home',
            'href' => route('admin.dashboard'),
            'active' => request()->routeIs('admin.dashboard'),
            'can' => null,
        ],
        [
            'label' => 'Packing',
            'icon' => 'packing',
            'href' => $user->can('outbounds.scan') ? route('admin.outbounds.marketplace') : null,
            'active' => request()->routeIs('admin.outbounds.marketplace', 'admin.outbounds.scan', 'admin.outbounds.handover'),
            'can' => 'outbounds.scan',
        ],
        [
            'label' => 'Retur',
            'icon' => 'return',
            'href' => $user->can('returns.scan') ? route('admin.returns.marketplace') : null,
            'active' => request()->routeIs('admin.returns.*'),
            'can' => 'returns.scan',
        ],
        [
            'label' => 'Barang',
            'icon' => 'box',
            'href' => $user->can('products.view') ? route('admin.products.index') : null,
            'active' => request()->routeIs('admin.products.*'),
            'can' => 'products.view',
        ],
    ])->filter(fn (array $item) => $item['can'] === null || $user->can($item['can']));
@endphp

<nav data-bottom-nav aria-label="Navigasi utama"
     class="fixed inset-x-0 bottom-0 z-30 border-t border-ink-100 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden">
    <div class="flex items-stretch justify-around">
        @foreach ($items as $item)
            <a href="{{ $item['href'] }}" @if ($item['active']) aria-current="page" @endif
               @class([
                   'flex min-w-0 flex-1 flex-col items-center gap-1 px-1 pb-2 pt-2.5 text-[11px] transition',
                   'font-medium text-ink-950' => $item['active'],
                   'text-ink-500' => ! $item['active'],
               ])>
                <span @class([
                    'relative inline-flex h-6 w-12 items-center justify-center rounded-full transition',
                    'bg-control' => $item['active'],
                ])>
                    <x-icon :name="$item['icon']" class="h-[18px] w-[18px]" />
                </span>
                <span class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach

        <button type="button" @click="sidebarOpen = true"
                class="flex min-w-0 flex-1 flex-col items-center gap-1 px-1 pb-2 pt-2.5 text-[11px] text-ink-500 transition">
            <span class="inline-flex h-6 w-12 items-center justify-center rounded-full">
                <x-icon name="menu" class="h-[18px] w-[18px]" />
            </span>
            <span class="truncate">Menu</span>
        </button>
    </div>
</nav>

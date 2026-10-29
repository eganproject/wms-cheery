{{--
    Beranda bergaya workspace ERPNext: kartu angka, pintasan, ringkasan,
    lalu kartu tautan ke setiap kelompok menu.
--}}
<x-app-layout title="Beranda">
    <x-ui.page-header title="Beranda" icon="home"
                      subtitle="Halo, {{ str(auth()->user()->name)->before(' ') }}. Ringkasan kondisi gudang dan aktivitas terakhir.">
        <x-slot name="actions">
            @can('inbounds.create')
                <x-ui.button :href="route('admin.inbounds.create')" variant="secondary" icon="inbound">Barang Masuk</x-ui.button>
            @endcan
            @can('outbounds.create')
                <x-ui.button :href="route('admin.outbounds.create')" icon="outbound">Barang Keluar</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    {{-- Pesanan menunggu --}}
    @if ($stats['outbound_pending'] > 0)
        @can('outbounds.view')
            <a href="{{ route('admin.outbounds.index', ['status' => 'draft']) }}"
               class="mb-4 flex items-center gap-3 rounded-lg bg-orange-100 px-4 py-3 text-orange-900 transition hover:bg-orange-200">
                <x-icon name="clock" class="h-4 w-4 shrink-0 text-orange-600" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">{{ $stats['outbound_pending'] }} pengiriman menunggu diproses</p>
                    <p class="mt-0.5 text-xs text-orange-800">
                        @if ($stats['marketplace_pending'] > 0)
                            {{ $stats['marketplace_pending'] }} di antaranya pesanan marketplace yang butuh verifikasi scan.
                        @else
                            Buka daftar barang keluar untuk menindaklanjuti.
                        @endif
                    </p>
                </div>
                <x-icon name="chevron-right" class="h-4 w-4 shrink-0" />
            </a>
        @endcan
    @endif

    {{-- Kartu angka --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5 max-xl:[&>*:last-child:nth-child(odd)]:col-span-2">
        <x-ui.stat-card label="Total Unit Stok" info="Jumlah seluruh unit layak jual di gudang saat ini, dari semua barang aktif." :value="number_format($stats['stock_units'], 0, ',', '.')" icon="box" accent
                        :hint="$stats['products'].' jenis barang'"
                        :href="auth()->user()->can('products.view') ? route('admin.products.index') : null" />
        @can('disposals.view')
            <x-ui.stat-card label="Barang Rusak" info="Unit rusak yang masih tercatat dan belum ditangani (dimusnahkan atau dikembalikan)." :value="number_format($stats['damaged_units'], 0, ',', '.')" icon="damaged"
                            hint="Unit menunggu ditangani" :href="route('admin.disposals.index')" />
        @endcan
        <x-ui.stat-card label="Stok Menipis" info="Barang yang stoknya sudah di bawah atau sama dengan batas minimum." :value="$stats['low_stock']" icon="warning"
                        hint="Perlu segera direstok"
                        :href="auth()->user()->can('reports.view') ? route('admin.reports.restock') : null" />
        <x-ui.stat-card label="Masuk Bulan Ini" info="Dokumen barang masuk yang sudah disetujui bulan ini." :value="$stats['inbound_month']" icon="inbound"
                        hint="Dokumen diposting"
                        :href="auth()->user()->can('inbounds.view') ? route('admin.inbounds.index') : null" />
        <x-ui.stat-card label="Keluar Bulan Ini" info="Dokumen barang keluar yang sudah disetujui bulan ini." :value="$stats['outbound_month']" icon="outbound"
                        hint="Dokumen dikirim"
                        :href="auth()->user()->can('outbounds.view') ? route('admin.outbounds.index') : null" />
    </div>

    {{-- Pintasan --}}
    @php
        $shortcuts = collect([
            ['label' => 'Stasiun Packing', 'icon' => 'packing', 'route' => 'admin.outbounds.marketplace', 'can' => 'outbounds.scan'],
            ['label' => 'Serah Terima Paket', 'icon' => 'outbound', 'route' => 'admin.outbounds.handover', 'can' => 'outbounds.view'],
            ['label' => 'Retur Marketplace', 'icon' => 'scan', 'route' => 'admin.returns.marketplace', 'can' => 'returns.scan'],
            ['label' => 'Import Resi', 'icon' => 'import', 'route' => 'admin.imports.create', 'can' => 'imports.create'],
            ['label' => 'Barang', 'icon' => 'box', 'route' => 'admin.products.index', 'can' => 'products.view'],
            ['label' => 'Laporan Stok', 'icon' => 'chart', 'route' => 'admin.reports.stock', 'can' => 'reports.view'],
        ])->filter(fn ($shortcut) => auth()->user()->can($shortcut['can']));
    @endphp

    @if ($shortcuts->isNotEmpty())
        <section class="mt-6">
            <h2 class="section-head">Pintasan</h2>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
                @foreach ($shortcuts as $shortcut)
                    <a href="{{ route($shortcut['route']) }}"
                       class="group flex min-w-0 items-center gap-2.5 rounded-lg border border-ink-100 bg-white px-3 py-2.5 text-sm font-medium text-ink-800 transition hover:border-ink-200 hover:bg-ink-50">
                        <x-icon :name="$shortcut['icon']" class="h-4 w-4 shrink-0 text-ink-500 group-hover:text-ink-950" />
                        <span class="truncate">{{ $shortcut['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- Pergerakan stok terakhir --}}
        <x-ui.card class="lg:col-span-2" title="Pergerakan Stok Terakhir"
                   subtitle="Aktivitas masuk dan keluar terbaru" padding="p-0">
            <x-slot name="actions">
                @can('products.view')
                    <x-ui.button :href="route('admin.products.index')" variant="ghost" size="sm">
                        Lihat stok <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                    </x-ui.button>
                @endcan
            </x-slot>

            @forelse ($movements as $movement)
                <div class="flex items-center gap-3 border-b border-ink-50 px-4 py-3 last:border-0">
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $movement->isIncoming() ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600' }}">
                        <x-icon :name="$movement->isIncoming() ? 'inbound' : 'outbound'" class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <x-ui.sku :value="$movement->product->sku" :label="false" />
                            <p class="truncate text-sm font-medium text-ink-950">{{ $movement->product->name }}</p>
                        </div>
                        <p class="truncate text-xs text-ink-400">{{ $movement->description }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-sm font-semibold {{ $movement->isIncoming() ? 'text-emerald-600' : 'text-red-600' }}">
                            {{ $movement->isIncoming() ? '+' : '−' }}{{ $movement->quantity }}
                        </p>
                        <p class="text-[11px] text-ink-400">saldo {{ $movement->balance_after }}</p>
                    </div>
                </div>
            @empty
                <x-ui.empty-state icon="refresh" title="Belum ada pergerakan stok"
                                  description="Buat dokumen barang masuk untuk mulai mengisi stok gudang." />
            @endforelse
        </x-ui.card>

        {{-- Status resi: tahapan alur gudang, dihitung dari dokumennya --}}
        <x-ui.card title="Status Resi" subtitle="Posisi resi hasil import di alur gudang">
            @php
                $stages = [
                    \App\Models\ShipmentOrder::STAGE_AWAITING_QC => ['label' => 'Belum QC', 'icon' => 'clock',
                        'hint' => 'Resi atau barangnya belum tuntas discan', 'tone' => 'text-amber-700 bg-amber-50'],
                    \App\Models\ShipmentOrder::STAGE_HANDOVER => ['label' => 'Serah Terima', 'icon' => 'check-circle',
                        'hint' => 'QC selesai, menunggu diproses', 'tone' => 'text-blue-700 bg-blue-100'],
                    \App\Models\ShipmentOrder::STAGE_SHIPPED => ['label' => 'Dikirim', 'icon' => 'outbound',
                        'hint' => 'Disetujui, stok sudah berkurang', 'tone' => 'text-emerald-700 bg-emerald-50'],
                    \App\Models\ShipmentOrder::STAGE_CANCELLED => ['label' => 'Dibatalkan', 'icon' => 'x-circle',
                        'hint' => 'Batal sebelum berangkat — jangan dikirim', 'tone' => 'text-red-700 bg-red-50'],
                ];
                $waybillTotal = max(array_sum($waybills), 1);
            @endphp

            <div class="space-y-4">
                @foreach ($stages as $key => $meta)
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-1.5 text-sm font-medium text-ink-800">
                                <x-icon :name="$meta['icon']" class="h-3.5 w-3.5 shrink-0 text-ink-400" />
                                <span class="truncate">{{ $meta['label'] }}</span>
                            </span>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold {{ $meta['tone'] }}">
                                {{ number_format($waybills[$key], 0, ',', '.') }}
                            </span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full bg-blue-500"
                                 style="width: {{ round($waybills[$key] / $waybillTotal * 100) }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] text-ink-400">{{ $meta['hint'] }}</p>
                    </div>
                @endforeach
            </div>

            @can('imports.view')
                <a href="{{ route('admin.imports.status') }}"
                   class="mt-4 flex items-center justify-between rounded-lg bg-control px-3 py-2 text-sm font-medium text-ink-800 transition hover:bg-ink-200">
                    Lihat status resi
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            @endcan
        </x-ui.card>

    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Pesanan per ekspedisi --}}
        <x-ui.card title="Pesanan per Ekspedisi" subtitle="Dari data resi hasil import">
            @php $courierTotal = max($couriers->sum('total'), 1); @endphp

            <div class="space-y-4">
                @forelse ($couriers as $courier)
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-1.5 text-sm font-medium text-ink-800">
                                <x-icon name="outbound" class="h-3.5 w-3.5 shrink-0 text-ink-400" />
                                <span class="truncate">{{ $courier->courier ?: 'Tanpa ekspedisi' }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-semibold text-ink-950">{{ $courier->total }}</span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full bg-blue-500"
                                 style="width: {{ round($courier->total / $courierTotal * 100) }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] text-ink-400">
                            {{ $courier->shipped }} sudah dikirim &middot;
                            <span class="font-medium text-ink-600">{{ $courier->total - $courier->shipped }} menunggu</span>
                        </p>
                    </div>
                @empty
                    <div class="py-6 text-center">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg bg-control text-ink-400">
                            <x-icon name="document" class="h-5 w-5" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-ink-950">Belum ada data resi</p>
                        <p class="mt-0.5 text-xs text-ink-500">Import berkas Ginee untuk melihat sebaran ekspedisi.</p>
                    </div>
                @endforelse
            </div>

            @can('imports.view')
                <a href="{{ route('admin.imports.index') }}"
                   class="mt-4 flex items-center justify-between rounded-lg bg-control px-3 py-2 text-sm font-medium text-ink-800 transition hover:bg-ink-200">
                    Lihat data resi
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            @endcan
        </x-ui.card>
        {{-- Stok menipis --}}
        <x-ui.card title="Perlu Direstok" subtitle="Stok pada atau di bawah batas minimum">
            <div class="space-y-4">
                @forelse ($lowStockProducts as $product)
                    <a href="{{ route('admin.products.show', $product) }}" class="block">
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-1.5">
                                <x-ui.sku :value="$product->sku" :label="false" />
                                <span class="truncate text-sm font-medium text-ink-800">{{ $product->name }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-semibold {{ $product->isOutOfStock() ? 'text-red-600' : 'text-amber-600' }}">
                                {{ $product->stock }}/{{ $product->min_stock }}
                            </span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full {{ $product->isOutOfStock() ? 'bg-red-500' : 'bg-amber-500' }}"
                                 style="width: {{ $product->min_stock > 0 ? min(100, round($product->stock / $product->min_stock * 100)) : 100 }}%"></div>
                        </div>
                    </a>
                @empty
                    <div class="py-6 text-center">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg bg-green-100 text-green-700">
                            <x-icon name="check-circle" class="h-5 w-5" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-ink-950">Semua stok aman</p>
                        <p class="mt-0.5 text-xs text-ink-500">Tidak ada barang di bawah batas minimum.</p>
                    </div>
                @endforelse
            </div>
        </x-ui.card>
    </div>

    {{-- Kartu tautan: setiap kelompok menu beserta halamannya --}}
    @php
        $linkSections = collect(\App\Support\Navigation::sections())
            ->filter(fn ($section) => $section['label'] !== null);
    @endphp

    @if ($linkSections->isNotEmpty())
        <section class="mt-6">
            <h2 class="section-head">Menu &amp; Laporan</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($linkSections as $section)
                    <div class="frappe-card p-4">
                        <h3 class="mb-2 text-sm font-semibold text-ink-950">{{ $section['label'] }}</h3>
                        <ul class="space-y-0.5">
                            @foreach ($section['items'] as $item)
                                <li>
                                    <a href="{{ $item['href'] }}"
                                       class="-mx-2 flex items-center gap-2 rounded-md px-2 py-1 text-sm text-ink-700 transition hover:bg-ink-50 hover:text-ink-950">
                                        <x-icon :name="$item['icon']" class="h-3.5 w-3.5 shrink-0 text-ink-400" />
                                        <span class="truncate">{{ $item['label'] }}</span>
                                        @if ($item['badge'])
                                            <span class="ml-auto rounded-full bg-red-100 px-1.5 text-[11px] font-medium text-red-700">{{ $item['badge'] }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</x-app-layout>

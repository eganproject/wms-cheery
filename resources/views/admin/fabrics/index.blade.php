{{--
    Saldo Kain: sisa kain per jenis (jumlah sisa semua roll yang masih berisi).
--}}
<x-app-layout title="Saldo Kain">
    <x-ui.page-header title="Saldo Kain" icon="layers"
                      subtitle="Sisa kain per jenis dari semua roll yang masih berisi. Kain hanya keluar lewat Scan Kain.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.fabrics.export', request()->query())" variant="secondary" icon="excel" data-no-ajax>
                Export Excel
            </x-ui.button>
            @can('fabrics.manage')
                <x-ui.button :href="route('admin.fabrics.create')" icon="plus">Tambah Jenis Kain</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Jenis Kain Aktif" :value="number_format($summary['fabrics'], 0, ',', '.')" icon="layers"
                        :hint="$summary['per_unit']->map(fn ($total, $unit) => \App\Models\Fabric::formatQuantity($total, $unit))->implode(' · ') ?: 'Belum ada stok'" />
        <x-ui.stat-card label="Roll Berisi" :value="number_format($summary['rolls'], 0, ',', '.')" icon="qr-code"
                        hint="Roll yang masih punya sisa kain" />
        <x-ui.stat-card label="Di Bawah Minimum" :value="number_format($summary['low'], 0, ',', '.')" icon="warning"
                        :href="route('admin.fabrics.index', ['stok' => 'menipis'])" hint="Sisa kurang dari stok minimum" />
        <x-ui.stat-card label="Habis" :value="number_format($summary['empty'], 0, ',', '.')" icon="x-circle"
                        :href="route('admin.fabrics.index', ['stok' => 'habis'])" hint="Tidak ada roll berisi" />
    </div>

    <form method="GET" action="{{ route('admin.fabrics.index') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari SKU kain, nama, atau warna..." class="pl-8" />
        </div>

        <x-ui.select name="stok" class="sm:w-44">
            <option value="">Semua stok</option>
            <option value="menipis" @selected(request('stok') === 'menipis')>Di bawah minimum</option>
            <option value="habis" @selected(request('stok') === 'habis')>Habis</option>
        </x-ui.select>

        <x-ui.select name="status" class="sm:w-40">
            <option value="active" @selected(request('status', 'active') === 'active')>Aktif</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            <option value="all" @selected(request('status') === 'all')>Semua status</option>
        </x-ui.select>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'stok', 'status']))
                <x-ui.button :href="route('admin.fabrics.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($fabrics->isEmpty())
            <x-ui.empty-state icon="layers" title="Belum ada jenis kain"
                              description="Daftarkan jenis kain (SKU kain, satuan, warna) lalu catat kain masuk per roll.">
                @can('fabrics.manage')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.fabrics.create')" icon="plus">Tambah Jenis Kain</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th><x-ui.sort key="sku">Kain</x-ui.sort></th>
                            <th>Satuan</th>
                            <th class="text-right"><x-ui.sort key="rolls" first="desc">Roll Berisi</x-ui.sort></th>
                            <th class="text-right"><x-ui.sort key="stock" first="desc">Sisa</x-ui.sort></th>
                            <th class="text-right">Minimum</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Dipakai <x-ui.hint text="Banyaknya barang yang sudah diatur pemakaian kainnya. Dipakai untuk menghitung Kebutuhan Kain." /></span></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fabrics as $fabric)
                            @php
                                $stock = (float) $fabric->stock;
                                $low = $fabric->min_stock > 0 && $stock < $fabric->min_stock;
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('admin.fabrics.show', $fabric) }}" class="group block min-w-0">
                                        <span class="font-mono text-xs font-semibold text-ink-800">{{ $fabric->sku }}</span>
                                        <p class="truncate text-sm font-medium text-ink-950 group-hover:underline">{{ $fabric->displayName() }}</p>
                                        @if ($fabric->supplier)
                                            <p class="truncate text-[11px] text-ink-400">{{ $fabric->supplier->name }}</p>
                                        @endif
                                    </a>
                                </td>
                                <td class="text-sm text-ink-700">{{ $fabric->unit }}</td>
                                <td class="text-right text-sm tabular-nums">{{ number_format((int) $fabric->open_rolls, 0, ',', '.') }}</td>
                                <td class="text-right">
                                    <span class="text-sm font-semibold tabular-nums {{ $stock <= 0 ? 'text-rose-600' : ($low ? 'text-amber-700' : 'text-ink-950') }}">
                                        {{ $fabric->format($stock) }}
                                    </span>
                                    @if (! $fabric->is_active)
                                        <x-ui.badge variant="gray" class="ml-1">Nonaktif</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-right text-sm tabular-nums text-ink-500">{{ $fabric->min_stock > 0 ? $fabric->format($fabric->min_stock) : '—' }}</td>
                                <td class="text-right text-sm tabular-nums text-ink-500">{{ $fabric->usages_count ? $fabric->usages_count.' barang' : '—' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.fabrics.show', $fabric) }}" title="Detail"
                                       class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                        <x-icon name="eye" class="h-4 w-4" />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pagination :paginator="$fabrics" />
        @endif
    </div>
</x-app-layout>

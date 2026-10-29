<x-app-layout title="Produk Varian">
    <x-ui.page-header title="Produk Varian" icon="layers"
                      subtitle="Produk induk beserta variannya. Setiap varian tetap satu SKU dengan stoknya sendiri di menu Barang.">
        <x-slot name="actions">
            @can('products.create')
                <x-ui.button :href="route('admin.product-groups.create')" icon="plus">Tambah Produk Varian</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <form method="GET" action="{{ route('admin.product-groups.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')" placeholder="Cari nama produk atau SKU varian..." class="pl-8" />
        </div>
        <x-ui.select name="category" class="sm:w-44">
            <option value="">Semua kategori</option>
            @foreach ($categories as $category)
                <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.button type="submit" variant="secondary" icon="filter">Terapkan</x-ui.button>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($groups->isEmpty())
            <x-ui.empty-state icon="layers" title="Belum ada produk varian"
                              description="Buat produk induk lalu susun variannya (mis. warna dan ukuran). Barang yang sudah ada juga bisa digabungkan sebagai varian.">
                @can('products.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.product-groups.create')" icon="plus">Tambah Produk Varian</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Produk</th>
                            <th>Kategori</th>
                            <th>Atribut</th>
                            <th class="text-right">Varian</th>
                            <th class="text-right">Total Stok</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groups as $group)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.product-groups.show', $group) }}" class="flex items-center gap-3">
                                        @if ($group->imageUrl())
                                            <img src="{{ $group->imageUrl() }}" alt="" class="h-10 w-10 shrink-0 rounded-lg border border-ink-100 object-cover">
                                        @else
                                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-ink-50 text-ink-300">
                                                <x-icon name="layers" class="h-4 w-4" />
                                            </span>
                                        @endif
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-ink-950">{{ $group->name }}</span>
                                            @unless ($group->is_active)
                                                <x-ui.badge variant="gray">Nonaktif</x-ui.badge>
                                            @endunless
                                        </span>
                                    </a>
                                </td>
                                <td class="text-sm text-ink-600">{{ $group->category ?: '—' }}</td>
                                <td class="text-sm text-ink-600">{{ collect([$group->option1_name, $group->option2_name])->filter()->implode(' × ') }}</td>
                                <td class="text-right text-sm font-semibold text-ink-950">{{ $group->variants_count }}</td>
                                <td class="text-right">
                                    <span class="text-sm font-semibold text-ink-950">{{ number_format((int) $group->total_stock, 0, ',', '.') }}</span>
                                    <span class="text-xs text-ink-400">{{ $group->unit }}</span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.product-groups.show', $group) }}" title="Detail"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="document" class="h-4 w-4" />
                                        </a>
                                        @can('products.update')
                                            <a href="{{ route('admin.product-groups.edit', $group) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ui.pagination :paginator="$groups" />
        @endif
    </div>
</x-app-layout>

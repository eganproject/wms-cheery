<x-app-layout title="Produk Varian">
    <x-ui.page-header :title="$group->name"
                      :subtitle="collect([$group->category, $group->variants->count().' varian'])->filter()->implode(' · ')"
                      :back="route('admin.product-groups.index')">
        <x-slot name="actions">
            @can('products.delete')
                <x-ui.confirm-delete :action="route('admin.product-groups.destroy', $group)"
                                     title="Hapus produk induk ini?"
                                     description="Hanya induknya yang dihapus. Semua varian tetap ada sebagai barang biasa beserta stok dan riwayatnya." />
            @endcan
            @can('products.update')
                <x-ui.button :href="route('admin.product-groups.edit', $group)" icon="pencil">Ubah Produk</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="space-y-4">
            <div class="frappe-card overflow-hidden">
                @if ($group->imageUrl())
                    <img src="{{ $group->imageUrl() }}" alt="{{ $group->name }}" class="aspect-square w-full object-cover">
                @else
                    <div class="flex aspect-square w-full items-center justify-center bg-ink-50 text-ink-300">
                        <x-icon name="layers" class="h-10 w-10" />
                    </div>
                @endif
                <div class="space-y-2 p-4 text-sm">
                    <p class="text-xs text-ink-500">Total stok semua varian</p>
                    <p class="text-3xl font-semibold text-ink-950">
                        {{ number_format($group->variants->sum('stock'), 0, ',', '.') }}
                        <span class="text-base font-normal text-ink-500">{{ $group->unit }}</span>
                    </p>
                    <p class="text-xs text-ink-500">Atribut: {{ collect([$group->option1_name, $group->option2_name])->filter()->implode(' × ') }}</p>
                    @if ($group->description)
                        <p class="whitespace-pre-line pt-2 text-ink-600">{{ $group->description }}</p>
                    @endif
                </div>
            </div>

            @can('products.update')
                <x-ui.card title="Gabungkan Barang yang Ada" subtitle="Jadikan barang yang sudah terdaftar sebagai varian. SKU, stok, dan riwayatnya tidak berubah.">
                    <form method="POST" action="{{ route('admin.product-groups.attach', $group) }}" class="space-y-3">
                        @csrf
                        <x-ui.field label="Barang" for="product_id" :error="$errors->get('product_id')" required>
                            <x-ui.select id="product_id" name="product_id" required>
                                <option value="">Pilih barang tanpa varian…</option>
                                @foreach ($candidates as $candidate)
                                    <option value="{{ $candidate->id }}" @selected(old('product_id') == $candidate->id)>{{ $candidate->sku }} — {{ $candidate->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.field :label="$group->option1_name" for="attach_option1" :error="$errors->get('option1')" required>
                                <x-text-input id="attach_option1" name="option1" type="text" :value="old('option1')" required />
                            </x-ui.field>
                            @if ($group->hasSecondOption())
                                <x-ui.field :label="$group->option2_name" for="attach_option2" :error="$errors->get('option2')" required>
                                    <x-text-input id="attach_option2" name="option2" type="text" :value="old('option2')" required />
                                </x-ui.field>
                            @endif
                        </div>
                        <x-ui.button type="submit" variant="secondary" icon="plus" class="w-full">Jadikan Varian</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan
        </div>

        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Varian" padding="p-0" subtitle="Stok, lokasi, dan kartu stok tetap per SKU varian.">
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Varian</th>
                                <th>SKU</th>
                                <th>Lokasi</th>
                                <th class="text-right">Stok</th>
                                <th class="text-right"><span class="inline-flex items-center gap-1">Dijanjikan <x-ui.hint text="Unit yang sudah tercantum di dokumen barang keluar yang belum diproses." /></span></th>
                                <th>Status</th>
                                <th class="text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($group->variants as $variant)
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <x-ui.product-image :product="$variant" size="sm" />
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-ink-950">{{ $variant->variantLabel() ?: '—' }}</p>
                                                @unless ($variant->is_active)
                                                    <x-ui.badge variant="gray">Nonaktif</x-ui.badge>
                                                @endunless
                                            </div>
                                        </div>
                                    </td>
                                    <td><x-ui.sku :value="$variant->sku" /></td>
                                    <td class="font-mono text-xs text-ink-500">{{ $variant->location ?: '—' }}</td>
                                    <td class="text-right text-sm font-semibold text-ink-950">{{ number_format($variant->stock, 0, ',', '.') }}</td>
                                    <td class="text-right text-sm text-ink-600">{{ number_format($committed[$variant->id] ?? 0, 0, ',', '.') }}</td>
                                    <td><x-ui.stock-badge :product="$variant" /></td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('admin.products.show', $variant) }}" title="Kartu stok"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="document" class="h-4 w-4" />
                                            </a>
                                            @can('products.update')
                                                <a href="{{ route('admin.products.edit', $variant) }}" title="Ubah varian"
                                                   class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                    <x-icon name="pencil" class="h-4 w-4" />
                                                </a>
                                                <form method="POST" action="{{ route('admin.product-groups.detach', [$group, $variant]) }}"
                                                      onsubmit="return confirm('Lepas {{ $variant->sku }} dari produk ini? Barangnya tetap ada sebagai barang tanpa varian.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" title="Lepas dari produk"
                                                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-rose-600">
                                                        <x-icon name="close" class="h-4 w-4" />
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-6 text-center text-sm text-ink-500">Belum ada varian.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            @can('products.create')
                <x-ui.card title="Tambah Varian Baru" subtitle="Setiap baris menjadi SKU baru dengan stok nol. Stoknya diisi lewat Barang Masuk.">
                    <form method="POST" action="{{ route('admin.product-groups.variants.store', $group) }}"
                          x-data="variantBuilder({{ Js::from([
                              'option1Name' => $group->option1_name,
                              'option2Name' => $group->option2_name ?? '',
                              'rows' => collect(old('variants', [['option1' => '', 'option2' => '', 'sku' => '', 'barcode' => '', 'location' => '', 'min_stock' => 0]]))->values()->all(),
                          ]) }})">
                        @csrf
                        <x-input-error :messages="$errors->get('variants')" class="mb-3" />
                        <div class="overflow-x-auto">
                            <table class="erp-table">
                                <thead>
                                    <tr>
                                        <th>{{ $group->option1_name }}</th>
                                        @if ($group->hasSecondOption())
                                            <th>{{ $group->option2_name }}</th>
                                        @endif
                                        <th>SKU</th>
                                        <th>Barcode</th>
                                        <th>Lokasi</th>
                                        <th class="w-24">Stok Min.</th>
                                        <th class="w-10"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(row, index) in rows" :key="index">
                                        <tr>
                                            <td><input type="text" class="form-control" :name="`variants[${index}][option1]`" x-model="row.option1" required></td>
                                            @if ($group->hasSecondOption())
                                                <td><input type="text" class="form-control" :name="`variants[${index}][option2]`" x-model="row.option2" required></td>
                                            @endif
                                            <td><input type="text" class="form-control font-mono uppercase" :name="`variants[${index}][sku]`" x-model="row.sku" required></td>
                                            <td><input type="text" class="form-control font-mono" :name="`variants[${index}][barcode]`" x-model="row.barcode"></td>
                                            <td><input type="text" class="form-control font-mono" :name="`variants[${index}][location]`" x-model="row.location"></td>
                                            <td><input type="number" min="0" class="form-control" :name="`variants[${index}][min_stock]`" x-model="row.min_stock"></td>
                                            <td>
                                                <button type="button" class="btn btn-ghost btn-icon text-ink-400" title="Hapus baris" x-on:click="removeRow(index)">
                                                    <x-icon name="trash" class="h-4 w-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        @if ($errors->has('variants.*'))
                            <ul class="mt-3 space-y-1 text-xs text-rose-600">
                                @foreach (collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'variants.'))->flatten()->unique() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <x-ui.button type="button" variant="ghost" size="sm" icon="plus" x-on:click="addRow()">Tambah baris</x-ui.button>
                            <x-ui.button type="submit" icon="check">Simpan Varian</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endcan
        </div>
    </div>
</x-app-layout>

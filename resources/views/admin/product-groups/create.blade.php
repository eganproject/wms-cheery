@php
    $initial = [
        'option1Name' => old('option1_name', 'Warna'),
        'option2Name' => old('option2_name', 'Ukuran'),
        'rows' => collect(old('variants', []))->values()->all(),
    ];
@endphp

<x-app-layout title="Tambah Produk Varian">
    <x-ui.page-header title="Tambah Produk Varian"
                      subtitle="Buat produk induk dan semua variannya sekaligus. Setiap varian adalah SKU sendiri dengan stoknya sendiri."
                      :back="route('admin.product-groups.index')" />

    <form method="POST" action="{{ route('admin.product-groups.store') }}" enctype="multipart/form-data"
          x-data="variantBuilder({{ Js::from($initial) }})" class="space-y-6">
        @csrf

        @include('admin.product-groups.partials.fields', ['group' => null])

        <x-ui.card title="Varian" subtitle="Isi nilai tiap atribut dipisah koma, lalu buat kombinasinya. SKU harus sama persis dengan Seller SKU di TikTok Shop.">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <x-ui.field label="Awalan SKU" for="sku_prefix" hint="Mis. KAOS → KAOS-MERAH-L">
                    <x-text-input id="sku_prefix" type="text" x-model="skuPrefix" class="font-mono uppercase" placeholder="KAOS" />
                </x-ui.field>
                <x-ui.field for="values1" class="sm:col-span-1">
                    <x-input-label for="values1"><span x-text="(option1Name || 'Atribut 1') + ' (pisah koma)'"></span></x-input-label>
                    <x-text-input id="values1" type="text" x-model="values1" placeholder="Merah, Biru, Hitam" />
                </x-ui.field>
                <x-ui.field for="values2" x-show="hasSecond">
                    <x-input-label for="values2"><span x-text="(option2Name || 'Atribut 2') + ' (pisah koma)'"></span></x-input-label>
                    <x-text-input id="values2" type="text" x-model="values2" placeholder="S, M, L, XL" />
                </x-ui.field>
                <div class="flex items-end gap-2">
                    <x-ui.button type="button" variant="secondary" icon="refresh" x-on:click="generate()">Buat Kombinasi</x-ui.button>
                </div>
            </div>

            <x-input-error :messages="$errors->get('variants')" class="mt-3" />

            <div class="mt-5 overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th x-text="option1Name || 'Atribut 1'"></th>
                            <th x-show="hasSecond" x-text="option2Name"></th>
                            <th>SKU</th>
                            <th>Barcode</th>
                            <th>Lokasi Rak</th>
                            <th class="w-24">Stok Min.</th>
                            <th class="w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, index) in rows" :key="index">
                            <tr>
                                <td><input type="text" class="form-control" :name="`variants[${index}][option1]`" x-model="row.option1" required></td>
                                <td x-show="hasSecond"><input type="text" class="form-control" :name="`variants[${index}][option2]`" x-model="row.option2" :required="hasSecond" :disabled="! hasSecond"></td>
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
                        <tr x-show="rows.length === 0">
                            <td colspan="7" class="py-6 text-center text-sm text-ink-500">Belum ada varian. Isi nilai atribut lalu klik Buat Kombinasi, atau tambah baris manual.</td>
                        </tr>
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

            <div class="mt-3">
                <x-ui.button type="button" variant="ghost" size="sm" icon="plus" x-on:click="addRow()">Tambah baris</x-ui.button>
            </div>
        </x-ui.card>

        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
            <x-ui.button :href="route('admin.product-groups.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" icon="check">Simpan Produk & Varian</x-ui.button>
        </div>
    </form>
</x-app-layout>

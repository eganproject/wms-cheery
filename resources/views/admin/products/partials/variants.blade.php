{{--
    Barang bervarian, seperti di Shopee: centang "Produk ini punya varian",
    isi atributnya, lalu setiap kombinasi menjadi SKU sendiri dengan stok,
    barcode, lokasi, dan gambarnya sendiri. Saat dicentang, formulir ini
    dikirim sebagai produk induk beserta variannya.
--}}
<x-ui.card title="Varian" subtitle="Untuk produk yang dijual dalam beberapa pilihan, mis. warna dan ukuran. Setiap varian menjadi SKU sendiri dengan stoknya sendiri.">
    <label class="flex cursor-pointer items-start gap-3">
        <input type="checkbox" name="has_variants" value="1" x-model="hasVariants"
               class="mt-0.5 h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950">
        <span>
            <span class="block text-sm font-medium text-ink-950">Produk ini punya varian</span>
            <span class="block text-xs text-ink-500">SKU, barcode, lokasi, dan stok minimum lalu diisi per varian di tabel bawah.</span>
        </span>
    </label>

    <div x-show="isVariant" x-cloak x-data="variantBuilder({{ Js::from($variantInitial) }})" class="mt-5 space-y-5 border-t border-ink-100 pt-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field label="Nama Atribut Pertama" for="option1_name" :error="$errors->get('option1_name')" required
                        info="Jenis pembeda varian, mis. Warna, Model, atau Motif.">
                <x-text-input id="option1_name" name="option1_name" type="text" x-model="option1Name" x-bind:disabled="! isVariant" required />
            </x-ui.field>
            <x-ui.field label="Nama Atribut Kedua" for="option2_name" :error="$errors->get('option2_name')"
                        info="Opsional, mis. Ukuran. Kosongkan bila varian hanya dibedakan satu atribut.">
                <x-text-input id="option2_name" name="option2_name" type="text" x-model="option2Name" x-bind:disabled="! isVariant" />
            </x-ui.field>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
            <x-ui.field label="Awalan SKU" for="sku_prefix" hint="Mis. KAOS → KAOS-MERAH-L">
                <x-text-input id="sku_prefix" type="text" x-model="skuPrefix" class="font-mono uppercase" placeholder="KAOS" />
            </x-ui.field>
            <div>
                <x-input-label for="values1"><span x-text="(option1Name || 'Atribut 1') + ' (pisah koma)'"></span></x-input-label>
                <x-text-input id="values1" type="text" x-model="values1" placeholder="Merah, Biru, Hitam" />
            </div>
            <div x-show="hasSecond">
                <x-input-label for="values2"><span x-text="(option2Name || 'Atribut 2') + ' (pisah koma)'"></span></x-input-label>
                <x-text-input id="values2" type="text" x-model="values2" placeholder="S, M, L, XL" />
            </div>
            <div class="flex items-end">
                <x-ui.button type="button" variant="secondary" icon="refresh" x-on:click="generate()">Buat Kombinasi</x-ui.button>
            </div>
        </div>

        <x-input-error :messages="$errors->get('variants')" />

        <div class="overflow-x-auto">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th class="w-16">Gambar</th>
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
                    <template x-for="(row, index) in rows" :key="row.uid">
                        <tr x-data="{ preview: null }">
                            <td>
                                <label class="relative flex h-10 w-10 cursor-pointer items-center justify-center overflow-hidden rounded-lg bg-ink-50 text-ink-300 ring-1 ring-inset ring-ink-100 hover:ring-ink-300" title="Pilih gambar varian">
                                    <template x-if="preview"><img :src="preview" alt="" class="h-full w-full object-cover"></template>
                                    <x-icon name="camera" class="h-4 w-4" x-show="! preview" />
                                    <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only"
                                           :name="`variants[${index}][image]`" :disabled="! isVariant"
                                           x-on:change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
                                </label>
                            </td>
                            <td><input type="text" class="form-control min-w-[6rem]" :name="`variants[${index}][option1]`" x-model="row.option1" :disabled="! isVariant" required></td>
                            <td x-show="hasSecond"><input type="text" class="form-control min-w-[6rem]" :name="`variants[${index}][option2]`" x-model="row.option2" :required="hasSecond" :disabled="! isVariant || ! hasSecond"></td>
                            <td><input type="text" class="form-control min-w-[10rem] font-mono uppercase" :name="`variants[${index}][sku]`" x-model="row.sku" :disabled="! isVariant" required></td>
                            <td><input type="text" class="form-control min-w-[8rem] font-mono" :name="`variants[${index}][barcode]`" x-model="row.barcode" :disabled="! isVariant"></td>
                            <td><input type="text" class="form-control min-w-[6rem] font-mono" :name="`variants[${index}][location]`" x-model="row.location" :disabled="! isVariant"></td>
                            <td><input type="number" min="0" class="form-control min-w-[4.5rem]" :name="`variants[${index}][min_stock]`" x-model="row.min_stock" :disabled="! isVariant"></td>
                            <td>
                                <button type="button" class="btn btn-ghost btn-icon text-ink-400" title="Hapus baris" x-on:click="removeRow(index)">
                                    <x-icon name="trash" class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="rows.length === 0">
                        <td colspan="8" class="py-6 text-center text-sm text-ink-500">Isi nilai atribut lalu klik Buat Kombinasi, atau tambah baris manual.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($errors->has('variants.*'))
            <ul class="space-y-1 text-xs text-rose-600">
                @foreach (collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'variants.'))->flatten()->unique() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <x-ui.button type="button" variant="ghost" size="sm" icon="plus" x-on:click="addRow()">Tambah baris</x-ui.button>
    </div>
</x-ui.card>

@php
    $isEdit = (bool) $fabric;
    $currentUnit = old('unit', $fabric?->unit ?? 'meter');
    $isPreset = array_key_exists($currentUnit, $units);
@endphp

<form method="POST" enctype="multipart/form-data" action="{{ $isEdit ? route('admin.fabrics.update', $fabric) : route('admin.fabrics.store') }}"
      x-data="{ unit: '{{ $isPreset ? $currentUnit : 'lainnya' }}' }">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Identitas Kain">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="SKU Kain" for="sku" :error="$errors->get('sku')" required
                                hint="Tanpa spasi. Kode roll = SKU + nomor roll, contoh TYB-HTM-001.">
                        <x-text-input id="sku" name="sku" type="text" class="font-mono" :value="old('sku', $fabric?->sku)"
                                      required autofocus placeholder="TYB-HTM" />
                    </x-ui.field>

                    <x-ui.field label="Nama Kain" for="name" :error="$errors->get('name')" required>
                        <x-text-input id="name" name="name" type="text" :value="old('name', $fabric?->name)"
                                      required placeholder="Toyobo" />
                    </x-ui.field>

                    <x-ui.field label="Warna" for="color" :error="$errors->get('color')">
                        <x-text-input id="color" name="color" type="text" :value="old('color', $fabric?->color)" placeholder="Hitam" />
                    </x-ui.field>

                    <x-ui.field label="Satuan" for="unit" :error="$errors->get('unit')" required
                                hint="Satuan untuk panjang/berat roll dan pemakaian kain.">
                        <div class="flex gap-2">
                            <x-ui.select id="unit" name="unit" x-model="unit" class="flex-1">
                                @foreach ($units as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                                <option value="lainnya">Lainnya…</option>
                            </x-ui.select>
                            <x-text-input name="unit_other" type="text" x-show="unit === 'lainnya'" x-cloak class="flex-1"
                                          :value="old('unit_other', $isPreset ? '' : $currentUnit)" placeholder="mis. lembar" list="fabric-units" />
                            <datalist id="fabric-units">
                                @foreach ($otherUnits as $other)
                                    <option value="{{ $other }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Stok Minimum" for="min_stock" :error="$errors->get('min_stock')"
                                hint="Kosongkan bila tidak perlu pengingat.">
                        <x-text-input id="min_stock" name="min_stock" type="number" step="0.01" min="0"
                                      :value="old('min_stock', $fabric && $fabric->min_stock > 0 ? $fabric->min_stock : '')" placeholder="0" />
                    </x-ui.field>

                    <x-ui.field label="Pemasok Utama" for="supplier_id" :error="$errors->get('supplier_id')">
                        <x-ui.select id="supplier_id" name="supplier_id">
                            <option value="">—</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', $fabric?->supplier_id) === $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Catatan" for="note" :error="$errors->get('note')" class="sm:col-span-2">
                        <x-text-input id="note" name="note" type="text" :value="old('note', $fabric?->note)" placeholder="Lebar kain, gramasi, dsb." />
                    </x-ui.field>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Foto Kain" subtitle="Membantu mengenali kain di gudang dan saat scan. JPG, PNG, atau WEBP, maks. 4 MB.">
                <div x-data="{
                        preview: {{ Js::from($fabric?->image_url) }},
                        hasSaved: {{ Js::from((bool) $fabric?->image_url) }},
                        remove: false,
                        pick(event) {
                            const file = event.target.files[0];
                            if (! file) return;
                            this.preview = URL.createObjectURL(file);
                            this.remove = false;
                        },
                        clear() {
                            this.$refs.image.value = '';
                            this.preview = null;
                            this.remove = this.hasSaved;
                        },
                     }" class="space-y-3">
                    <div class="flex aspect-square w-full items-center justify-center overflow-hidden rounded-xl bg-ink-50 ring-1 ring-inset ring-ink-100">
                        <template x-if="preview">
                            <img :src="preview" alt="Pratinjau foto kain" class="h-full w-full object-cover">
                        </template>
                        <div x-show="! preview" class="flex flex-col items-center gap-2 text-ink-300">
                            <x-icon name="camera" class="h-10 w-10" />
                            <span class="text-xs text-ink-400">Belum ada foto</span>
                        </div>
                    </div>

                    <input type="file" name="image" x-ref="image" accept="image/jpeg,image/png,image/webp"
                           class="sr-only" id="image" x-on:change="pick($event)">
                    <input type="hidden" name="remove_image" :value="remove ? 1 : 0">

                    <div class="flex gap-2">
                        <label for="image" class="btn btn-default flex-1 cursor-pointer justify-center">
                            <x-icon name="upload" class="h-3.5 w-3.5" />
                            <span x-text="preview ? 'Ganti Foto' : 'Pilih Foto'">Pilih Foto</span>
                        </label>
                        <button type="button" x-show="preview" x-cloak x-on:click="clear()"
                                class="btn btn-default btn-icon" title="Hapus foto">
                            <x-icon name="trash" class="h-4 w-4" />
                        </button>
                    </div>
                    <p x-show="remove" x-cloak class="text-[11px] text-amber-700">Foto akan dihapus saat disimpan.</p>
                    <x-input-error :messages="$errors->get('image')" />
                </div>
            </x-ui.card>

            <x-ui.card title="Status">
                <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4">
                    <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $fabric?->is_active ?? true)"
                                 label="Kain aktif"
                                 description="Kain nonaktif tidak muncul saat mencatat kain masuk." />
                </div>
            </x-ui.card>

            <div class="flex flex-col gap-2 sm:flex-row">
                <x-ui.button type="submit" icon="check" class="flex-1">
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Kain' }}
                </x-ui.button>
                <x-ui.button :href="$isEdit ? route('admin.fabrics.show', $fabric) : route('admin.fabrics.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
            </div>
        </div>
    </div>
</form>

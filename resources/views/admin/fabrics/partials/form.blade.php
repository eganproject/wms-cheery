@php
    $isEdit = (bool) $fabric;
    $currentUnit = old('unit', $fabric?->unit ?? 'meter');
    $isPreset = array_key_exists($currentUnit, $units);
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.fabrics.update', $fabric) : route('admin.fabrics.store') }}"
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

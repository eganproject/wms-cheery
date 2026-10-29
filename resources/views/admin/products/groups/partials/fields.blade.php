@php
    $group = $group ?? null;
@endphp

<x-ui.card title="Produk Induk" subtitle="Nama, kategori, dan gambar utama yang dipakai bersama semua varian.">
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <x-ui.field label="Nama Produk" for="name" :error="$errors->get('name')" required class="sm:col-span-2"
                    info="Nama varian dibentuk otomatis dari nama ini, mis. &quot;Kaos Polos - Merah / L&quot;.">
            <x-text-input id="name" name="name" type="text" :value="old('name', $group?->name)" required autofocus
                          placeholder="Contoh: Kaos Polos Cotton Combed" />
        </x-ui.field>

        <x-ui.field label="Kategori" for="category" :error="$errors->get('category')">
            <x-text-input id="category" name="category" type="text" :value="old('category', $group?->category)" placeholder="Atasan" />
        </x-ui.field>

        <x-ui.field label="Satuan" for="unit" :error="$errors->get('unit')" required
                    info="Satuan hitung semua varian, mis. pcs atau set.">
            <x-text-input id="unit" name="unit" type="text" :value="old('unit', $group?->unit ?? 'pcs')" required />
        </x-ui.field>

        <x-ui.field label="Nama Atribut Pertama" for="option1_name" :error="$errors->get('option1_name')" required
                    info="Jenis pembeda varian, mis. Warna, Model, atau Motif.">
            <x-text-input id="option1_name" name="option1_name" type="text" required
                          :value="old('option1_name', $group?->option1_name ?? 'Warna')" x-model="option1Name" />
        </x-ui.field>

        <x-ui.field label="Nama Atribut Kedua" for="option2_name" :error="$errors->get('option2_name')"
                    info="Opsional, mis. Ukuran. Kosongkan bila varian hanya dibedakan satu atribut.">
            <x-text-input id="option2_name" name="option2_name" type="text"
                          :value="old('option2_name', $group?->option2_name ?? ($group ? '' : 'Ukuran'))" x-model="option2Name" />
        </x-ui.field>

        <x-ui.field label="Deskripsi" for="description" :error="$errors->get('description')" class="sm:col-span-2">
            <textarea id="description" name="description" rows="2" class="form-control"
                      placeholder="Catatan bahan, ukuran, dll. (opsional)">{{ old('description', $group?->description) }}</textarea>
        </x-ui.field>

        <x-ui.field label="Gambar Utama" for="image" :error="$errors->get('image')" class="sm:col-span-2"
                    hint="JPG, PNG, atau WEBP, maks. 4 MB. Dipakai varian yang tidak punya gambar sendiri.">
            <div class="flex items-center gap-4">
                @if ($group?->imageUrl())
                    <img src="{{ $group->imageUrl() }}" alt="{{ $group->name }}" class="h-16 w-16 rounded-lg border border-ink-100 object-cover">
                @endif
                <div class="min-w-0 flex-1 space-y-2">
                    <input id="image" name="image" type="file" accept="image/*" class="form-control">
                    @if ($group?->imageUrl())
                        <label class="flex items-center gap-2 text-xs text-ink-600">
                            <input type="checkbox" name="remove_image" value="1" class="h-4 w-4 rounded border-ink-300">
                            Hapus gambar utama
                        </label>
                    @endif
                </div>
            </div>
        </x-ui.field>

        <div class="sm:col-span-2">
            <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $group?->is_active ?? true)"
                         label="Produk aktif" description="Varian baru mengikuti status ini." />
        </div>
    </div>
</x-ui.card>

@php
    $product = $product ?? null;
    $isEdit = (bool) $product;
    $catalog = $catalog ?? collect();

    $type = old('type', $product?->type ?? \App\Models\Product::TYPE_SINGLE);

    // Varian mengambil nama dan kategori dari produk induknya, dan selalu
    // barang biasa.
    $variantGroup = $product?->isVariant() ? $product->group : null;

    // "Produk ini punya varian" hanya ditawarkan saat menambah barang baru.
    // Varian yang sudah ada diatur dari halaman produk induknya.
    $hasVariants = ! $isEdit && (bool) old('has_variants', request('type') === 'variant');
    $variantInitial = [
        'option1Name' => old('option1_name', 'Warna'),
        'option2Name' => old('option2_name', 'Ukuran'),
        'rows' => collect(old('variants', []))->map(fn ($row) => collect($row)->except('image')->all())->values()->all(),
    ];

    // Barang yang sudah punya saldo atau jejak mutasi tidak bisa lagi menjadi
    // paket — paket tidak punya saldo, dan yang tersisa akan menggantung tanpa
    // cara menggerakkannya. Pilihannya dikunci di layar, dan ditolak lagi di
    // server oleh UpdateProductRequest.
    $lockedToSingle = $isEdit
        && ! $product->isBundle()
        && ($product->stock > 0 || $product->damaged_stock > 0 || $product->movements()->exists());

    $initialComponents = collect(old('components', $product?->bundleComponents
        ->map(fn ($item) => ['component_id' => $item->component_id, 'quantity' => $item->quantity])
        ->all() ?? []))->values();
@endphp

<form method="POST" enctype="multipart/form-data" action="{{ $isEdit ? route('admin.products.update', $product) : route('admin.products.store') }}"
      x-data="bundleRecipe({{ Js::from($initialComponents) }}, {{ Js::from($catalog) }}, '{{ $type }}', {{ Js::from($hasVariants) }})"
      @unless ($isEdit)
          {{-- Barang bervarian disimpan sebagai produk induk beserta SKU variannya. --}}
          x-bind:action="isVariant ? @js(route('admin.products.groups.store')) : @js(route('admin.products.store'))"
      @endunless>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Jenis barang --}}
            @if ($variantGroup)
                <input type="hidden" name="type" value="single">
                <x-ui.card title="Varian" subtitle="Nama dan kategori mengikuti produk induk. Ubah nilai atribut varian di sini.">
                    <div class="mb-4 flex items-center gap-3 rounded-xl border border-ink-100 bg-ink-50/50 p-3 text-sm">
                        <x-icon name="layers" class="h-4 w-4 shrink-0 text-ink-400" />
                        <span class="min-w-0 flex-1">Varian dari
                            <a href="{{ route('admin.products.groups.show', $variantGroup) }}" class="font-medium text-ink-950 underline underline-offset-4">{{ $variantGroup->name }}</a>
                        </span>
                    </div>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-ui.field :label="$variantGroup->option1_name" for="option1" :error="$errors->get('option1')" required>
                            <x-text-input id="option1" name="option1" type="text" :value="old('option1', $product->option1)" required />
                        </x-ui.field>
                        @if ($variantGroup->hasSecondOption())
                            <x-ui.field :label="$variantGroup->option2_name" for="option2" :error="$errors->get('option2')" required>
                                <x-text-input id="option2" name="option2" type="text" :value="old('option2', $product->option2)" required />
                            </x-ui.field>
                        @endif
                    </div>
                    <x-input-error :messages="$errors->get('type')" class="mt-3" />
                </x-ui.card>
            @else
            <x-ui.card title="Jenis" subtitle="Paket bundling dijual sebagai satu SKU, tetapi yang ada di rak hanya barang isinya.">
                @if ($lockedToSingle)
                    <input type="hidden" name="type" value="single">
                    <div class="flex items-start gap-2.5 rounded-xl border border-ink-100 bg-ink-50/50 p-4 text-xs text-ink-600">
                        <x-icon name="info" class="mt-px h-4 w-4 shrink-0 text-ink-300" />
                        <span>
                            <span class="font-medium text-ink-950">{{ $product->sku }}</span> tidak bisa dijadikan paket
                            karena sudah punya stok atau riwayat pergerakan. Buat paketnya sebagai barang baru, lalu
                            nonaktifkan yang lama.
                        </span>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ([
                            ['value' => 'single', 'icon' => 'box', 'label' => 'Barang', 'desc' => 'Punya wujud di rak dan punya stok sendiri.'],
                            ['value' => 'bundle', 'icon' => 'sparkles', 'label' => 'Paket Bundling', 'desc' => 'Tidak punya stok — dihitung dari isinya.'],
                        ] as $option)
                            <label class="relative flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 transition"
                                   :class="type === '{{ $option['value'] }}' ? 'border-ink-950 bg-white ring-1 ring-ink-950' : 'border-ink-200 hover:border-ink-300 hover:bg-ink-50'">
                                <input type="radio" name="type" value="{{ $option['value'] }}" x-model="type" class="sr-only">
                                <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition"
                                      :class="type === '{{ $option['value'] }}' ? 'bg-ink-950 text-white' : 'bg-control text-ink-700'">
                                    <x-icon :name="$option['icon']" class="h-4 w-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{ $option['label'] }}</span>
                                    <span class="block text-xs" :class="type === '{{ $option['value'] }}' ? 'text-ink-500' : 'text-ink-500'">
                                        {{ $option['desc'] }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif

                <x-input-error :messages="$errors->get('type')" class="mt-3" />
            </x-ui.card>
            @endif

            <x-ui.card title="Identitas Barang" subtitle="SKU dan barcode dipakai saat proses scan QC Kemas.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    @if ($variantGroup)
                        <x-ui.field label="Nama Barang" class="sm:col-span-2" hint="Dibentuk otomatis dari nama produk induk dan atribut varian.">
                            <x-text-input type="text" :value="$product->name" disabled />
                        </x-ui.field>
                    @else
                    <x-ui.field for="name" :error="$errors->get('name')" class="sm:col-span-2">
                        <x-input-label for="name" required><span x-text="isVariant ? 'Nama Produk' : 'Nama Barang'">Nama Barang</span></x-input-label>
                        <x-text-input id="name" name="name" type="text" :value="old('name', $product?->name ?? request('name'))"
                                      required autofocus placeholder="Contoh: Filter Oli Standar" />
                    </x-ui.field>
                    @endif

                    <div x-show="! isVariant">
                    <x-ui.field label="SKU" info="Kode unik barang. Harus sama persis dengan SKU di TikTok Shop agar pesanan bisa dicocokkan otomatis." for="sku" :error="$errors->get('sku')" required>
                        <x-text-input id="sku" name="sku" type="text" :value="old('sku', $product?->sku ?? request('sku'))"
                                      required class="font-mono uppercase" placeholder="FLT-OLI-STD"
                                      x-bind:disabled="isVariant" />
                        <p class="mt-1.5 text-[11px] text-ink-500" x-show="isBundle" x-cloak>
                            Isi persis seperti SKU paket di marketplace — inilah yang dicocokkan saat berkas TikTok diimport.
                        </p>
                        <p class="mt-1.5 text-[11px] text-ink-500" x-show="! isBundle">
                            Kode internal, otomatis dibuat huruf besar.
                        </p>
                    </x-ui.field>
                    </div>

                    {{--
                        Paket tidak punya wujud, jadi tidak ada yang bisa
                        ditempeli barcode. Kolomnya dimatikan, bukan sekadar
                        disembunyikan: barang yang berubah menjadi paket harus
                        benar-benar kehilangan barcodenya, supaya tidak ada
                        label lama yang masih bisa terbaca scanner.
                    --}}
                    <div x-show="! isBundle && ! isVariant">
                        <x-ui.field label="Barcode" info="Kode batang pada kemasan. Bisa discan sebagai pengganti SKU di QC Kemas." for="barcode" :error="$errors->get('barcode')"
                                    hint="Kode yang dibaca scanner saat verifikasi.">
                            <x-text-input id="barcode" name="barcode" type="text" :value="old('barcode', $product?->barcode)"
                                          class="font-mono" placeholder="8991234500035"
                                          x-bind:disabled="isBundle || isVariant" />
                        </x-ui.field>
                    </div>
                </div>
            </x-ui.card>

            {{-- Isi paket --}}
            <div x-show="isBundle" x-cloak>
                @include('admin.products.partials.components')
            </div>

            {{-- Varian, seperti di Shopee: centang lalu susun kombinasinya. --}}
            @unless ($isEdit)
                <div x-show="! isBundle">
                    @include('admin.products.partials.variants')
                </div>
            @endunless

            <x-ui.card title="Klasifikasi & Penyimpanan">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                    <x-ui.field label="Kategori" info="Pengelompokan barang untuk memudahkan pencarian dan laporan." for="category" :error="$errors->get('category')">
                        <x-text-input id="category" name="category" type="text" :value="old('category', $product?->category)"
                                      placeholder="Filter" list="category-options" :disabled="(bool) $variantGroup" />
                        <datalist id="category-options">
                            @foreach (['Pelumas', 'Filter', 'Pengereman', 'Kelistrikan', 'Aksesoris', 'Ban'] as $option)
                                <option value="{{ $option }}"></option>
                            @endforeach
                        </datalist>
                    </x-ui.field>

                    <x-ui.field label="Satuan" info="Satuan hitung barang, mis. pcs, set, atau box." for="unit" :error="$errors->get('unit')" required>
                        <x-text-input id="unit" name="unit" type="text" :value="old('unit', $product?->unit ?? 'pcs')"
                                      required placeholder="pcs" list="unit-options" />
                        <datalist id="unit-options">
                            @foreach (['pcs', 'box', 'set', 'botol', 'unit', 'liter', 'paket'] as $option)
                                <option value="{{ $option }}"></option>
                            @endforeach
                        </datalist>
                    </x-ui.field>

                    <div x-show="! isBundle && ! isVariant">
                        <x-ui.field label="Lokasi Rak" info="Posisi barang di gudang, mis. A-01-03. Ditampilkan saat packing supaya mudah diambil." for="location" :error="$errors->get('location')">
                            <x-text-input id="location" name="location" type="text" :value="old('location', $product?->location)"
                                          class="font-mono" placeholder="A-01-01"
                                          x-bind:disabled="isBundle || isVariant" />
                        </x-ui.field>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Gambar Barang" subtitle="Membantu packer mengenali barang. JPG, PNG, atau WEBP, maks. 4 MB. Untuk barang bervarian, ini gambar utama yang dipakai varian tanpa gambar sendiri.">
                <div x-data="{
                        preview: {{ Js::from($product?->image_url) }},
                        hasSaved: {{ Js::from((bool) $product?->image_url) }},
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
                            <img :src="preview" alt="Pratinjau gambar" class="h-full w-full object-contain">
                        </template>
                        <div x-show="! preview" class="flex flex-col items-center gap-2 text-ink-300">
                            <x-icon name="camera" class="h-10 w-10" />
                            <span class="text-xs text-ink-400">Belum ada gambar</span>
                        </div>
                    </div>

                    <input type="file" name="image" x-ref="image" accept="image/jpeg,image/png,image/webp"
                           class="sr-only" id="image" x-on:change="pick($event)">
                    <input type="hidden" name="remove_image" :value="remove ? 1 : 0">

                    <div class="flex gap-2">
                        <label for="image" class="btn btn-default flex-1 cursor-pointer justify-center">
                            <x-icon name="upload" class="h-3.5 w-3.5" />
                            <span x-text="preview ? 'Ganti Gambar' : 'Pilih Gambar'">Pilih Gambar</span>
                        </label>
                        <button type="button" x-show="preview" x-cloak x-on:click="clear()"
                                class="btn btn-default btn-icon" title="Hapus gambar">
                            <x-icon name="trash" class="h-4 w-4" />
                        </button>
                    </div>
                    <p x-show="remove" x-cloak class="text-[11px] text-amber-700">Gambar akan dihapus saat disimpan.</p>

                    <x-input-error :messages="$errors->get('image')" />
                </div>
            </x-ui.card>

            <x-ui.card title="Pengaturan Stok">
                <div class="space-y-5">
                    {{--
                        Batas menipis adalah setelan atas saldo di rak. Paket
                        tidak punya rak, jadi kolomnya tidak ditampilkan —
                        tetapi tetap terkirim sebagai nol supaya aturan
                        validasinya tidak perlu bercabang.
                    --}}
                    <div x-show="! isBundle && ! isVariant">
                        <x-ui.field label="Stok Minimum" info="Batas stok menipis. Barang muncul di peringatan dan Kebutuhan Restock bila stok tersedia sudah sampai batas ini." for="min_stock" :error="$errors->get('min_stock')"
                                    hint="Sistem menandai barang sebagai menipis bila stok mencapai angka ini." required>
                            <x-text-input id="min_stock" name="min_stock" type="number" min="0"
                                          :value="old('min_stock', $product?->min_stock ?? 0)"
                                          x-bind:disabled="isBundle || isVariant" x-bind:required="! isBundle && ! isVariant" />
                        </x-ui.field>
                    </div>
                    {{--
                        Dua kolom bernama sama, dan tepat satu di antaranya
                        yang aktif: kolom yang disabled tidak ikut terkirim.
                        Menyembunyikannya saja tidak cukup — x-show hanya
                        menyembunyikan, isinya tetap dikirim, dan dua nilai
                        untuk satu nama membuat yang menang bergantung pada
                        urutan penulisan.
                    --}}
                    <input type="hidden" name="min_stock" value="0" x-bind:disabled="! isBundle || isVariant">

                    <template x-if="isBundle">
                        <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4">
                            <p class="text-xs text-ink-400">Bisa dirakit sekarang</p>
                            <p class="mt-1 text-2xl font-semibold text-ink-950">
                                <span x-text="availability"></span>
                                <span class="text-sm font-normal text-ink-400">paket</span>
                            </p>
                            <p class="mt-2 flex items-start gap-1.5 text-[11px] text-ink-500">
                                <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                                Dihitung dari stok komponen, tidak pernah disimpan. Angkanya ikut berubah setiap kali komponennya bergerak.
                            </p>
                        </div>
                    </template>

                    @if ($isEdit)
                        <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4" x-show="! isBundle">
                            <p class="text-xs text-ink-400">Stok saat ini</p>
                            <p class="mt-1 text-2xl font-semibold text-ink-950">
                                {{ number_format($product->stock, 0, ',', '.') }}
                                <span class="text-sm font-normal text-ink-400">{{ $product->unit }}</span>
                            </p>
                            <p class="mt-2 flex items-start gap-1.5 text-[11px] text-ink-500">
                                <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                                Stok hanya berubah lewat dokumen stok seperti penerimaan barang, pesanan marketplace, dan penjualan manual.
                            </p>
                        </div>
                    @endif

                    <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4">
                        <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $product?->is_active ?? true)"
                                     label="Barang aktif"
                                     description="Barang nonaktif tidak muncul di dokumen baru." />
                    </div>
                </div>
            </x-ui.card>

            <div class="flex flex-col gap-2 sm:flex-row">
                <x-ui.button type="submit" icon="check" class="flex-1">
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Barang' }}
                </x-ui.button>
                <x-ui.button :href="route('admin.products.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
            </div>
        </div>
    </div>
</form>

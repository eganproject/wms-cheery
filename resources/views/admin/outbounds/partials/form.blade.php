@php
    $outbound = $outbound ?? null;
    $isEdit = (bool) $outbound;
    $type = $outbound?->type ?? $defaultType;

    /*
        Paket punya barisnya sendiri, terpisah dari baris barang.

        Baris barang yang ditampilkan karenanya hanya yang dipesan lepas —
        sisanya setelah dikurangi apa yang dijanjikan paket. Tanpa pemisahan
        ini, menyunting dokumen berisi paket berarti menyunting isinya satu
        per satu, dan menyimpan akan menghitung barangnya dua kali.
    */
    $bundleCatalog = $products->filter(fn ($product) => $product->isBundle())->values();
    $itemCatalog = $products->reject(fn ($product) => $product->isBundle())->values();

    $bundleRows = collect(old('bundles', $outbound?->bundles
        ->map(fn ($bundle) => ['bundle_id' => $bundle->bundle_id, 'quantity' => $bundle->quantity])
        ->all() ?? []))->values();

    $lineItems = $outbound?->looseItems();
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.outbounds.update', $outbound) : route('admin.outbounds.store') }}"
      class="space-y-6"
      x-data="{ type: '{{ $type }}', tracking: '{{ old('tracking_number', $outbound?->tracking_number) }}' }">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Jenis dokumen tidak dipilih di sini: dokumen baru selalu
                 penjualan manual, dan dokumen pesanan marketplace (dibuat QC
                 Kemas) tetap marketplace. Server memaksakan hal yang sama. --}}
            <input type="hidden" name="type" value="{{ $type }}">

            {{-- Informasi dokumen --}}
            <x-ui.card title="Informasi Dokumen">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="Nomor Dokumen" hint="Dibuat otomatis oleh sistem.">
                        <x-text-input type="text" :value="$code" class="font-mono" disabled />
                    </x-ui.field>

                    <x-ui.field label="Tanggal" info="Tanggal barang dikirim dari gudang. Mutasi stok dicatat pada tanggal ini." for="date" :error="$errors->get('date')" required>
                        <x-text-input id="date" name="date" type="date"
                                      :value="old('date', $outbound?->date?->format('Y-m-d') ?? now()->format('Y-m-d'))" required />
                    </x-ui.field>

                    <x-ui.field label="Penerima" info="Nama pembeli, toko, atau pihak yang menerima barang." for="recipient" :error="$errors->get('recipient')" required
                                class="sm:col-span-2">
                        <x-text-input id="recipient" name="recipient" type="text" :value="old('recipient', $outbound?->recipient)"
                                      required placeholder="Nama pembeli, toko, atau cabang tujuan" />
                    </x-ui.field>

                    {{-- Field khusus marketplace --}}
                    <template x-if="type === 'marketplace'">
                        <div class="grid grid-cols-1 gap-5 sm:col-span-2 sm:grid-cols-2">
                            <x-ui.field label="Nomor Resi" info="Nomor resi pengiriman. Bila diisi, resi ini bisa discan dan dicocokkan dengan data import TikTok. Nama marketplace diambil otomatis dari data import itu." for="tracking_number" :error="$errors->get('tracking_number')"
                                        hint="Dicari otomatis ke data import TikTok. Nama marketplace diambil dari sana." required class="sm:col-span-2">
                                <x-text-input id="tracking_number" name="tracking_number" type="text"
                                              x-model="tracking"
                                              class="font-mono uppercase" placeholder="SPXID01234567890" />

                                @include('admin.partials.resi-lookup')
                            </x-ui.field>
                        </div>
                    </template>

                    <x-ui.field label="Catatan" for="note" :error="$errors->get('note')" class="sm:col-span-2">
                        <textarea id="note" name="note" rows="2" placeholder="Catatan tambahan (opsional)"
                                  class="form-control">{{ old('note', $outbound?->note) }}</textarea>
                    </x-ui.field>
                </div>
            </x-ui.card>
        </div>

        {{-- Aksi --}}
        <x-ui.card title="Aksi">
            <div class="space-y-3">
                <template x-if="type === 'marketplace'">
                    <div>
                        <x-ui.button type="submit" icon="search" class="w-full">
                            {{ $isEdit ? 'Simpan & Lanjut Scan' : 'Simpan & Mulai Scan' }}
                        </x-ui.button>
                        <p class="mt-3 flex items-start gap-2 rounded-lg bg-blue-50 p-3 text-xs leading-relaxed text-blue-800">
                            <x-icon name="shield" class="mt-px h-3.5 w-3.5 shrink-0 text-blue-600" />
                            @if ($isEdit)
                                Setelah disimpan, Anda kembali ke halaman verifikasi. Hasil scan yang masih cocok tetap tersimpan; hanya baris yang berubah — dan resi bila nomornya diganti — yang perlu diulang.
                            @else
                                Pesanan marketplace tidak bisa langsung diproses. Setelah disimpan, Anda diarahkan ke halaman verifikasi untuk scan resi lalu scan barang.
                            @endif
                        </p>
                    </div>
                </template>

                <template x-if="type === 'regular'">
                    <div class="space-y-3">
                        @can('outbounds.post')
                            <x-ui.button type="submit" name="submit" value="1" icon="check" class="w-full">
                                {{ auth()->user()->can('outbounds.approve') ? 'Simpan & Setujui' : 'Simpan & Ajukan' }}
                            </x-ui.button>
                        @endcan

                        <x-ui.button type="submit" variant="secondary" icon="document" class="w-full">
                            Simpan sebagai Draft
                        </x-ui.button>
                    </div>
                </template>

                <x-ui.button :href="$isEdit ? route('admin.outbounds.show', $outbound) : route('admin.outbounds.index')" variant="ghost" class="w-full">Batal</x-ui.button>
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-6">
        @include('admin.outbounds.partials.bundles', [
            'bundles' => $bundleCatalog,
            'rows' => $bundleRows,
        ])

        @include('admin.partials.line-items', [
            'products' => $itemCatalog,
            'items' => $lineItems,
            'mode' => 'outbound',
        ])
    </div>
</form>

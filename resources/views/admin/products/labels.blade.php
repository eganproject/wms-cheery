<x-app-layout title="Cetak Label QR">
    <x-ui.page-header title="Cetak Label QR"
                      subtitle="Label berisi QR code SKU barang dan tiap variannya. Bisa langsung discan di semua layar scan aplikasi."
                      :back="route('admin.products.index')" />

    {{--
        PDF dibuka di tab baru supaya halaman ini (beserta daftar pilihannya)
        tetap ada untuk mencetak ulang atau mengubah jumlah. data-no-ajax:
        navigasi AJAX tidak bisa menampilkan PDF.
    --}}
    <form method="POST" action="{{ route('admin.products.labels.pdf') }}" target="_blank" data-no-ajax
          x-data="qrLabels({
              items: @js($items),
              perPage: @js($layouts->map(fn ($layout) => $layout['per_page'])),
              stacked: @js($layouts->map(fn ($layout) => $layout['stacked'])),
              layout: @js(old('layout', $defaultLayout)),
              maxLabels: {{ $maxLabels }},
              maxCopies: {{ $maxCopies }},
          })"
          class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        @csrf

        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Barang yang dicetak" subtitle="Satu label per salinan. Untuk produk bervarian, pilih produknya untuk menambah semua variannya sekaligus.">
                <div class="space-y-3">
                    <select data-placeholder="Cari SKU atau nama barang / produk bervarian..."
                            x-on:change="addFromSelect($event.target)">
                        <option value=""></option>
                        @if ($groups->isNotEmpty())
                            <optgroup label="Produk bervarian — tambah semua varian">
                                @foreach ($groups as $group)
                                    <option value="group-{{ $group['id'] }}" data-variants="{{ json_encode($group['variants']) }}">
                                        {{ $group['name'] }} — semua varian ({{ count($group['variants']) }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        <optgroup label="Barang / varian (per SKU)">
                            @foreach ($catalog as $product)
                                <option value="{{ $product->id }}"
                                        data-sku="{{ $product->sku }}"
                                        data-name="{{ $product->isVariant() ? ($product->group?->name ?? $product->name) : $product->name }}"
                                        data-variant="{{ $product->variantLabel() }}">
                                    {{ $product->sku }} — {{ $product->name }}
                                </option>
                            @endforeach
                        </optgroup>
                    </select>

                    <x-input-error :messages="$errors->get('items')" />
                </div>

                <template x-if="items.length === 0">
                    <div class="mt-4 rounded-xl border border-dashed border-ink-200 px-6 py-10 text-center">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-control text-ink-600">
                            <x-icon name="qr-code" class="h-5 w-5" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-ink-950">Belum ada barang</p>
                        <p class="mt-1 text-sm text-ink-500">
                            Cari barang di atas, atau centang beberapa barang di daftar Barang lalu tekan Cetak QR.
                        </p>
                    </div>
                </template>

                <div x-show="items.length > 0" x-cloak class="mt-4 overflow-hidden rounded-xl border border-ink-100">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 bg-ink-50/60 px-3 py-2">
                        <p class="text-xs text-ink-500"><span x-text="items.length"></span> barang</p>
                        <div class="flex items-center gap-2">
                            <label class="flex items-center gap-2 rounded-lg bg-white px-2.5 py-1 ring-1 ring-inset ring-ink-200">
                                <span class="whitespace-nowrap text-xs text-ink-500">Label per barang</span>
                                <input type="number" min="1" max="{{ $maxCopies }}" x-model="bulkCopies" inputmode="numeric"
                                       class="w-14 border-0 bg-transparent p-0 text-center text-sm font-semibold shadow-none focus:ring-0">
                            </label>
                            <button type="button" x-on:click="applyCopies()" class="btn btn-default">Terapkan ke semua</button>
                            <button type="button" x-on:click="clear()" class="btn btn-ghost text-ink-500">Kosongkan</button>
                        </div>
                    </div>

                    <ul class="max-h-[60vh] divide-y divide-ink-50 overflow-y-auto">
                        <template x-for="(item, index) in items" :key="item.id">
                            <li class="flex items-center gap-3 px-3 py-2.5">
                                <input type="hidden" :name="`items[${index}][id]`" :value="item.id">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950" x-text="item.sku"></p>
                                    <p class="truncate text-sm text-ink-600">
                                        <span x-text="item.name"></span>
                                        <template x-if="item.variant">
                                            <span class="font-medium text-ink-950" x-text="` — ${item.variant}`"></span>
                                        </template>
                                    </p>
                                </div>
                                <label class="flex shrink-0 items-center gap-1.5">
                                    <span class="sr-only">Jumlah label</span>
                                    <input type="number" min="1" max="{{ $maxCopies }}" required inputmode="numeric"
                                           :name="`items[${index}][copies]`" x-model.number="item.copies"
                                           class="form-control w-20 text-center">
                                    <span class="text-xs text-ink-400">label</span>
                                </label>
                                <button type="button" x-on:click="remove(index)" title="Hapus dari daftar"
                                        class="btn btn-ghost btn-icon shrink-0 text-ink-400 hover:text-ink-950">
                                    <x-icon name="close" class="h-4 w-4" />
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="Ukuran label">
                <div class="space-y-2">
                    @foreach ($layouts as $key => $layout)
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
                               :class="layout === @js($key) ? 'border-ink-950 bg-ink-50/60' : 'border-ink-100 hover:border-ink-300'">
                            <input type="radio" name="layout" value="{{ $key }}" x-model="layout"
                                   class="mt-0.5 h-4 w-4 border-ink-300 text-ink-950 focus:ring-ink-950">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-ink-950">{{ $layout['label'] }}</span>
                                <span class="mt-0.5 block text-xs text-ink-500">{{ $layout['hint'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-4 border-t border-ink-100 pt-4">
                    <p class="text-sm text-ink-950">Posisi tulisan SKU & nama</p>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach (['below' => ['Di bawah QR', 'QR di atas, tulisan rata tengah'], 'right' => ['Di kanan QR', 'QR di kiri, tulisan di sampingnya']] as $value => [$title, $hint])
                            <label class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border p-3 text-center transition"
                                   :class="position === @js($value) ? 'border-ink-950 bg-ink-50/60' : 'border-ink-100 hover:border-ink-300'">
                                <input type="radio" name="text_position" value="{{ $value }}" x-model="position"
                                       x-on:change="positionTouched = true" class="sr-only">
                                {{-- Gambar kecil tata letaknya. --}}
                                @if ($value === 'below')
                                    <span class="flex h-10 w-14 flex-col items-center justify-center gap-1 rounded border border-ink-200 bg-white">
                                        <span class="h-4 w-4 bg-ink-950"></span>
                                        <span class="h-0.5 w-8 rounded bg-ink-400"></span>
                                    </span>
                                @else
                                    <span class="flex h-10 w-14 items-center justify-center gap-1 rounded border border-ink-200 bg-white">
                                        <span class="h-5 w-5 bg-ink-950"></span>
                                        <span class="flex flex-col gap-1"><span class="h-0.5 w-5 rounded bg-ink-400"></span><span class="h-0.5 w-4 rounded bg-ink-300"></span></span>
                                    </span>
                                @endif
                                <span class="text-sm font-medium text-ink-950">{{ $title }}</span>
                                <span class="text-[11px] leading-tight text-ink-500">{{ $hint }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="mt-4 space-y-3 border-t border-ink-100 pt-4">
                    <x-ui.toggle name="show_name" :checked="true" label="Tampilkan nama barang & varian"
                                 description="Matikan untuk label kecil yang hanya berisi QR dan SKU." />

                    <label x-show="sheet" class="block">
                        <span class="text-sm text-ink-950">Lewati label pertama</span>
                        <span class="block text-xs text-ink-500">Untuk memakai lembar stiker A4 yang sebagian sudah terpakai.</span>
                        <input type="number" name="skip" min="0" max="99" x-model="skip" inputmode="numeric"
                               class="form-control mt-1.5 w-24">
                    </label>
                </div>
            </x-ui.card>

            <div class="frappe-card p-4 sm:p-5">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <p class="text-xs text-ink-500">Total label</p>
                        <p class="text-2xl font-semibold text-ink-950" x-text="total.toLocaleString('id-ID')">0</p>
                    </div>
                    <p class="text-right text-xs text-ink-500" x-show="total > 0">
                        <span x-text="pages.toLocaleString('id-ID')"></span>
                        <span x-text="sheet ? 'lembar A4' : 'label di printer'"></span>
                    </p>
                </div>

                <p x-show="tooMany" x-cloak class="mt-2 text-xs text-red-600">
                    Maksimal {{ number_format($maxLabels, 0, ',', '.') }} label sekali cetak. Kurangi jumlahnya atau bagi menjadi beberapa kali cetak.
                </p>

                <button type="submit" class="btn btn-primary mt-4 w-full" :disabled="items.length === 0 || tooMany">
                    <x-icon name="printer" class="h-4 w-4" />
                    Buat PDF Label
                </button>
                <p class="mt-2 text-center text-xs text-ink-400">PDF terbuka di tab baru, siap dicetak. Saat mencetak, pilih skala 100% / ukuran asli.</p>
            </div>
        </div>
    </form>
</x-app-layout>

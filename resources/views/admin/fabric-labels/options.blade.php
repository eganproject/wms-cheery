{{-- Pilihan kertas label, sama dengan Cetak Label QR barang. --}}
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
                    <x-ui.toggle name="show_name" :checked="true" label="Tampilkan nama kain & panjang awal"
                                 description="Matikan untuk label kecil yang hanya berisi QR dan SKU." />

                    <label x-show="gapDefault !== null" x-cloak class="block">
                        <span class="text-sm text-ink-950">Jarak antar kolom (mm)</span>
                        <span class="block text-xs text-ink-500">Celah kosong di antara dua label dalam satu baris rol. Ukur dengan penggaris bila hasil cetak bergeser ke samping.</span>
                        <input type="number" name="gap" min="0" max="10" step="0.5" x-model="gap" inputmode="decimal"
                               :disabled="gapDefault === null" class="form-control mt-1.5 w-24">
                    </label>

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
                        <span x-text="sheet ? 'lembar A4' : (perPage[layout] > 1 ? 'baris label di printer' : 'label di printer')"></span>
                    </p>
                </div>

                <p x-show="tooMany" x-cloak class="mt-2 text-xs text-red-600">
                    Maksimal {{ number_format($maxLabels, 0, ',', '.') }} label sekali cetak. Kurangi jumlahnya atau bagi menjadi beberapa kali cetak.
                </p>

                <button type="submit" class="btn btn-primary mt-4 w-full" :disabled="items.length === 0 || tooMany">
                    <x-icon name="printer" class="h-4 w-4" />
                    Buat PDF Label Roll
                </button>
                <p class="mt-2 text-center text-xs text-ink-400">PDF terbuka di tab baru, siap dicetak. Saat mencetak, pilih skala 100% / ukuran asli.</p>
                <div x-show="gapDefault !== null" x-cloak class="mt-3 rounded-lg bg-ink-50 p-3 text-xs leading-relaxed text-ink-600">
                    <p class="font-medium text-ink-950">Zebra ZD230, rol 33 × 15 mm <span x-text="perPage[layout]"></span> line</p>
                    <p class="mt-1">Di driver ZDesigner (Printing Preferences → Page Setup) atur Width <b x-text="(perPage[layout] * 33 + (perPage[layout] - 1) * (parseFloat(gap) || 0)).toLocaleString('id-ID')"></b> mm × Height 15 mm, kertas <i>Labels with gaps</i>, Darkness ±20. Lalu cetak PDF dengan skala 100% / Actual size.</p>
                </div>
            </div>

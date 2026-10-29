{{--
    Stasiun hitung stok opname.

    Petugas berjalan di gudang membawa layar ini: scan barang, ketik jumlah,
    lanjut ke rak berikutnya. Tidak ada daftar yang harus digulir dan tidak ada
    halaman yang dimuat ulang di antara dua barang.

    Saldo tercatat sengaja tidak muncul di mana pun. Angka sistem yang terlihat
    membuat petugas menyalinnya alih-alih menghitung, dan opname kehilangan
    satu-satunya gunanya: mengetahui isi rak yang sebenarnya.
--}}
<x-app-layout title="Stasiun Hitung">
    <x-ui.page-header :title="$opname->code"
                      :subtitle="$opname->scopeLabel().' · '.$opname->date->translatedFormat('d F Y')"
                      :back="route('admin.opnames.index')">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.opnames.show', $opname)" variant="secondary" icon="document">
                Daftar &amp; Hasil
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <div x-data="opnameStation({
            urls: {
                lookup: '{{ route('admin.opnames.station.lookup', $opname) }}',
                count: '{{ route('admin.opnames.station.count', $opname) }}',
                progress: '{{ route('admin.opnames.station.progress', $opname) }}',
            },
            progress: {{ Js::from($progress) }},
         })"
         x-on:camera-scan.window="code = $event.detail.code; submit()"
         class="mx-auto w-full max-w-3xl">

        {{-- Panel hitung: satu kolom untuk kode maupun jumlah. --}}
        <div class="frappe-card overflow-hidden">
            <div class="px-5 pt-6 text-center sm:px-10">
                <span class="indicator-pill no-dot bg-control text-ink-700">
                    <span x-text="`${progress.counted} dari ${progress.total} SKU dihitung`"></span>
                </span>

                <h2 class="mt-3 text-xl font-semibold text-ink-950 sm:text-2xl" x-text="title"></h2>

                <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed"
                   :class="conflict ? 'text-orange-700' : 'text-ink-500'"
                   x-text="hint"></p>
            </div>

            <div class="p-5 sm:p-8 sm:pt-6">
                <div class="flex items-stretch gap-2">
                    @include('admin.partials.camera-scan', [
                        'scanTitle' => "isOpen ? 'Isi jumlah untuk ' + card.sku : 'Scan barcode barang'",
                        'scanHint' => "progress.remaining + ' SKU lagi belum dihitung.'",
                    ])

                    <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center justify-center text-ink-500">
                                <x-icon name="search" class="h-5 w-5" />
                            </span>

                            <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                   spellcheck="false" :disabled="busy" :placeholder="placeholder"
                                   class="block h-14 w-full rounded-lg pl-12 pr-28 font-mono text-lg text-ink-950 placeholder:font-sans placeholder:text-sm disabled:opacity-60">

                            <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                <button type="submit" :disabled="busy"
                                        class="btn btn-primary h-10 px-4 sm:h-10">
                                    <span x-show="! busy" x-text="actionLabel"></span>
                                    <span x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- Kartu barang yang sedang dihitung. --}}
                <template x-if="card">
                    <div class="mt-4 rounded-lg border border-ink-100 bg-ink-50 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-ink-950" x-text="card.sku"></p>
                                <p class="mt-0.5 text-sm text-ink-600" x-text="card.name"></p>

                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <template x-if="card.location">
                                        <span class="indicator-pill no-dot bg-white text-ink-700 ring-1 ring-inset ring-ink-200">
                                            <x-icon name="box" class="h-3 w-3" />
                                            <span x-text="card.location"></span>
                                        </span>
                                    </template>
                                    <template x-if="card.category">
                                        <span class="indicator-pill no-dot bg-white text-ink-700 ring-1 ring-inset ring-ink-200" x-text="card.category"></span>
                                    </template>
                                </div>
                            </div>

                            <button type="button" x-on:click="cancel()"
                                    class="btn btn-ghost">
                                Batal
                            </button>
                        </div>

                        {{--
                            Barang yang ditemukan di rak tetapi tidak ikut
                            terpotret saat sesi dibuka. Temuannya tidak dibuang:
                            barisnya ditambahkan begitu jumlahnya disimpan.
                        --}}
                        <template x-if="isOutOfScope">
                            <p class="mt-4 flex items-start gap-2 rounded-lg bg-orange-100 px-3 py-2.5 text-xs leading-relaxed text-orange-800">
                                <x-icon name="warning" class="mt-px h-4 w-4 shrink-0" />
                                <span>Barang ini di luar cakupan sesi. Menyimpan hitungannya sekaligus menambahkan barisnya ke sesi ini.</span>
                            </p>
                        </template>

                        {{--
                            Kabar yang datang selagi kartunya terbuka: rekan
                            menghitung barang yang sama. Diberitahukan sekarang,
                            supaya petugas bisa memutuskan sebelum mengetik —
                            bukan setelah raknya ditinggalkan.
                        --}}
                        <template x-if="taken && ! conflict">
                            <p class="mt-4 flex items-start gap-2 rounded-lg bg-orange-100 px-3 py-2.5 text-xs leading-relaxed text-orange-800">
                                <x-icon name="users" class="mt-px h-4 w-4 shrink-0" />
                                <span x-text="taken"></span>
                            </p>
                        </template>

                        {{--
                            Bentrokan dengan rekan sesama petugas: keputusannya
                            diserahkan ke yang sedang berdiri di depan rak.
                        --}}
                        <template x-if="conflict">
                            <div class="mt-4 rounded-lg bg-orange-100 p-3">
                                <p class="text-xs leading-relaxed text-orange-800" x-text="conflict"></p>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button type="button" x-on:click="overwrite()"
                                            class="btn bg-orange-500 text-white hover:bg-orange-600">
                                        <span x-text="`Timpa dengan ${counted === '' ? 'kosong' : counted}`"></span>
                                    </button>
                                    <button type="button" x-on:click="keepTheirs()"
                                            class="btn btn-default bg-white">
                                        Pakai angka rekan
                                    </button>
                                </div>
                            </div>
                        </template>

                        {{--
                            Kolomnya untuk yang memakai sentuhan. Dengan scanner
                            dan papan ketik, jumlahnya cukup diketik di kolom
                            kode di atas — fokusnya tidak perlu berpindah.
                        --}}
                        <div class="mt-4 flex gap-3">
                            <div class="flex-1">
                                <label for="station-counted"
                                       class="form-label">Bagus</label>
                                <input id="station-counted" x-model="counted" type="number" min="0" max="999999"
                                       inputmode="numeric" placeholder="—"
                                       @keydown.enter.prevent="save()"
                                       class="h-11 w-full rounded-lg bg-white text-center text-base font-semibold tabular-nums text-ink-950">
                            </div>

                            {{--
                                Rusak dicatat terpisah, bukan dikurangkan dari
                                hitungan bagus: selisih yang tidak dijelaskan
                                terbaca sebagai barang hilang, padahal hilang
                                perlu diselidiki dan rusak bisa diklaim.
                            --}}
                            <div class="flex-1">
                                <label for="station-damaged"
                                       class="form-label text-red-600">Rusak</label>
                                <input id="station-damaged" x-model="damaged" type="number" min="0" max="999999"
                                       inputmode="numeric" placeholder="0"
                                       @keydown.enter.prevent="save()"
                                       class="h-11 w-full rounded-lg bg-white text-center text-base font-semibold tabular-nums text-red-600">
                            </div>

                            <div class="flex items-end">
                                <button type="button" x-on:click="save()" :disabled="busy"
                                        class="btn btn-primary h-11 px-5 sm:h-11">
                                    <x-icon name="check" class="h-4 w-4" />
                                    <span x-text="isOutOfScope ? 'Tambah &amp; Simpan' : 'Simpan'"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- Hasil scan terakhir. --}}
                <template x-if="feedback">
                    <div class="mt-4 flex items-start gap-3 rounded-lg p-3.5"
                         :class="feedback.type === 'success' ? 'bg-green-100' : 'bg-red-100'">
                        <template x-if="feedback.type === 'success'">
                            <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-green-700" />
                        </template>
                        <template x-if="feedback.type === 'error'">
                            <x-icon name="x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                        </template>
                        <p class="text-sm"
                           :class="feedback.type === 'success' ? 'text-green-800' : 'text-red-700'"
                           x-text="feedback.message"></p>
                    </div>
                </template>

                {{-- Kemajuan sesi, bukan selisihnya. --}}
                <div class="mt-6">
                    <div class="flex items-baseline justify-between gap-3 text-xs text-ink-500">
                        <span>Kemajuan sesi</span>
                        <span class="tabular-nums" x-text="`${progress.percentage}%`"></span>
                    </div>
                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-control">
                        <div class="h-full rounded-full bg-blue-500 transition-all duration-500"
                             :style="`width: ${progress.percentage}%`"></div>
                    </div>
                    <p class="mt-2 text-[11px] text-ink-500">
                        <span x-text="`Anda menghitung ${progress.mine} SKU`"></span>
                        &middot;
                        <span x-text="`${progress.remaining} belum dihitung siapa pun`"></span>
                    </p>
                </div>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            {{--
                Riwayat: satu SKU satu baris. Scan ulang mengganti angkanya,
                jadi tidak pernah ada dua hitungan untuk barang yang sama —
                dan salah ketik cukup dipanggil ulang dari sini.
            --}}
            <x-ui.card title="Baru Saja Dihitung" subtitle="Tekan barisnya untuk menghitung ulang">
                <template x-if="! history.length">
                    <p class="py-6 text-center text-xs text-ink-400">Belum ada barang yang dihitung di layar ini.</p>
                </template>

                <ul class="divide-y divide-ink-100">
                    <template x-for="entry in history" :key="entry.id">
                        <li>
                            <button type="button" x-on:click="recount(entry)"
                                    class="-mx-2 flex w-[calc(100%+1rem)] items-center gap-3 rounded-md px-2 py-2.5 text-left transition hover:bg-ink-50">
                                <div class="min-w-0 flex-1">
                                    <p class="font-mono text-xs font-semibold text-ink-950" x-text="entry.sku"></p>
                                    <p class="truncate text-[11px] text-ink-400" x-text="entry.name"></p>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p class="text-sm font-semibold tabular-nums text-ink-950">
                                        <span x-text="entry.counted === null ? '—' : entry.counted"></span>
                                        <span class="text-[11px] font-normal text-ink-400" x-text="entry.unit"></span>
                                    </p>
                                    <p class="text-[10px] text-ink-400">
                                        <span x-text="entry.at"></span>
                                        <template x-if="entry.damaged > 0">
                                            <span class="text-red-500" x-text="` · ${entry.damaged} rusak`"></span>
                                        </template>
                                    </p>
                                </div>
                            </button>
                        </li>
                    </template>
                </ul>
            </x-ui.card>

            {{--
                Siapa lagi yang sedang mengerjakan batch ini. Tanpa panel ini
                dua petugas bisa menyisir rak yang sama sepanjang pagi tanpa
                pernah tahu — dan bentrokannya baru ketahuan saat menyimpan.
            --}}
            <x-ui.card title="Petugas di Sesi Ini" subtitle="Ikut berubah saat rekan menghitung">
                <x-slot name="actions">
                    {{-- Penanda bahwa angkanya hidup, bukan potret saat halaman dibuka. --}}
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-medium"
                          :class="polling ? 'text-green-700' : 'text-ink-400'">
                        <span class="relative flex h-2 w-2">
                            <span x-show="polling" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full"
                                  :class="polling ? 'bg-green-500' : 'bg-ink-300'"></span>
                        </span>
                        Langsung
                    </span>
                </x-slot>

                <template x-if="! progress.counters.length">
                    <p class="py-6 text-center text-xs text-ink-400">Belum ada yang menghitung di sesi ini.</p>
                </template>

                <ul class="divide-y divide-ink-100">
                    <template x-for="counter in progress.counters" :key="counter.name">
                        <li class="flex items-center gap-3 py-3">
                            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-[11px] font-medium uppercase text-blue-700"
                                  x-text="counter.name.slice(0, 2)"></span>

                            <p class="min-w-0 flex-1 truncate text-sm font-medium text-ink-950" x-text="counter.name"></p>

                            <span class="indicator-pill no-dot bg-control tabular-nums text-ink-700"
                                  x-text="`${counter.counted} SKU`"></span>
                        </li>
                    </template>
                </ul>

                <p class="mt-4 border-t border-ink-100 pt-3 text-[11px] leading-relaxed text-ink-400">
                    Angkanya disegarkan sendiri tiap sepuluh detik selama layar ini terlihat.
                    Bila rekan menghitung barang yang sedang Anda pegang, kabarnya muncul di
                    kartu sebelum Anda sempat mengetik.
                </p>
            </x-ui.card>
        </div>

        <p class="mt-5 text-center text-xs text-ink-500">
            Selesai menghitung?
            <a href="{{ route('admin.opnames.show', $opname) }}" class="font-medium text-ink-950 underline underline-offset-2">
                Buka daftar hasil
            </a>
            untuk memeriksa sisa yang belum dihitung dan mengajukannya.
        </p>
    </div>
</x-app-layout>

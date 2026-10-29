<x-app-layout title="Import Berkas Resi">
    <x-ui.page-header title="Import Berkas Resi" subtitle="Unggah hasil eksport pesanan TikTok Shop."
                      :back="route('admin.imports.index')" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            {{-- Form upload harus multipart, jadi dikirim biasa tanpa AJAX. --}}
            <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data" data-no-ajax
                  x-data="{ name: '', drag: false }">
                @csrf

                <x-ui.card title="Berkas Eksport TikTok Shop" subtitle="Unggah langsung berkas Excel (.xlsx / .xls) hasil eksport — CSV juga diterima. Maksimal 20 MB.">
                    <label class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-12 text-center transition"
                           :class="drag ? 'border-ink-950 bg-ink-50' : 'border-ink-200 hover:border-ink-300 hover:bg-ink-50/50'"
                           @dragover.prevent="drag = true" @dragleave.prevent="drag = false"
                           @drop="drag = false; name = $event.dataTransfer.files[0]?.name ?? ''">
                        <input type="file" name="file" accept=".xlsx,.xls,.csv,.txt" class="sr-only" required
                               @change="name = $event.target.files[0]?.name ?? ''">

                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-lg bg-control text-ink-700">
                            <x-icon name="document" class="h-6 w-6" />
                        </span>

                        <span class="mt-4 block text-sm font-semibold text-ink-950" x-show="! name">
                            Klik untuk memilih berkas Excel
                        </span>
                        <span class="mt-4 block break-all font-mono text-sm font-semibold text-ink-950" x-show="name" x-cloak x-text="name"></span>

                        <span class="mt-1 block text-xs text-ink-500" x-show="! name">
                            atau tarik berkasnya ke sini &middot; .xlsx, .xls, .csv
                        </span>
                        <span class="mt-1 block text-xs text-emerald-600" x-show="name" x-cloak>
                            Berkas siap diimport
                        </span>
                    </label>

                    <x-input-error :messages="$errors->get('file')" class="mt-3" />

                    <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                        <x-ui.button type="submit" icon="check" class="flex-1">Proses Import</x-ui.button>
                        <x-ui.button :href="route('admin.imports.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
                    </div>
                </x-ui.card>
            </form>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Kolom yang Dibaca" subtitle="Hanya kolom ini yang disimpan. Data pembeli, alamat, dan harga tidak ikut masuk.">
                <ul class="space-y-2.5 text-sm">
                    @foreach ([
                        ['Tracking ID', 'Wajib. Nomor resi, kunci pencocokan saat scan.', true],
                        ['Seller SKU', 'Wajib. Kunci ke master barang; bila kosong dipakai Variation.', true],
                        ['Quantity', 'Kuantitas per baris SKU.', false],
                        ['Order ID', 'Ditampilkan sebagai referensi.', false],
                        ['Product Name, Variation', 'Cadangan bila SKU belum terdaftar.', false],
                        ['Order Status, Shipping Provider Name, Delivery Option, Created Time, Buyer Message', 'Informasi pelengkap.', false],
                    ] as [$column, $desc, $required])
                        <li class="flex items-start gap-2.5">
                            <x-icon :name="$required ? 'check-circle' : 'circle'"
                                    class="mt-0.5 h-4 w-4 shrink-0 {{ $required ? 'text-ink-950' : 'text-ink-300' }}" />
                            <span class="min-w-0">
                                <span class="block text-xs font-semibold text-ink-950">
                                    {{ $column }}
                                    @if ($required)
                                        <span class="ml-1 rounded bg-red-100 px-1 py-px text-[11px] font-medium text-red-700">wajib</span>
                                    @endif
                                </span>
                                <span class="block text-xs text-ink-500">{{ $desc }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            <div class="rounded-2xl border border-ink-100 bg-ink-50/60 p-5">
                <p class="flex items-center gap-2 text-xs text-ink-400">
                    <x-icon name="info" class="h-3.5 w-3.5" /> Cara kerja
                </p>
                <ul class="mt-3 space-y-2 text-xs leading-relaxed text-ink-600">
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">1.</span> Berkas Excel dibaca langsung — tidak perlu diubah ke CSV lebih dulu.</li>
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">2.</span> Baris keterangan kolom dari TikTok otomatis dilewati.</li>
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">3.</span> Beberapa baris dengan nomor resi sama digabung menjadi satu pesanan.</li>
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">4.</span> Setiap SKU dicocokkan ke master barang. SKU yang belum ada ditandai.</li>
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">5.</span> Resi yang sudah ada di sistem, resi yang dipakai beberapa pesanan, atau pesanan yang sudah punya resi lain dicegat dan tidak mengubah data lama.</li>
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">6.</span> Baris yang gagal (mis. Tracking ID kosong karena belum Atur Pengiriman) bisa diunduh beserta alasannya dari halaman detail import.</li>
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">7.</span> Saat scan resi, sistem mengambil daftar barang dari data ini.</li>
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>

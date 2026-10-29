{{--
    Stasiun packing pesanan marketplace.

    Tata letaknya sama dengan stasiun retur: rail langkah, panel scan, dan
    daftar barang punya ruang bertinggi tetap. Yang berubah antar tahap hanya
    isinya, sehingga kolom input tidak pernah berpindah posisi.
--}}
<x-app-layout title="Stasiun Packing">
    <div x-data="packingStation({
            url: '{{ route('admin.outbounds.marketplace.store') }}',
            handoverUrl: '{{ route('admin.outbounds.handover') }}',
         })"
         {{-- Hasil pindaian kamera diperlakukan sama persis dengan scanner genggam. --}}
         x-on:camera-scan.window="code = $event.detail.code; submit()"
         class="w-full">

        <x-ui.page-header title="Stasiun Packing"
                          subtitle="Scan resi, lalu scan barang satu per satu. Paket yang lengkap masuk antrean serah terima.">
            <x-slot name="indicator">
                {{-- Langkah kerja, ditaruh di kepala halaman supaya selalu terlihat. --}}
                <ol class="ml-1 hidden items-center gap-1.5 md:flex" aria-label="Langkah">
                    @foreach ([1 => 'Resi', 2 => 'Barang', 3 => 'Selesai'] as $number => $label)
                        @if (! $loop->first)
                            <li aria-hidden="true" class="h-px w-4 shrink-0 bg-ink-200"></li>
                        @endif
                        <li class="flex shrink-0 items-center gap-1.5">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-medium transition"
                                  :class="step > {{ $number }} ? 'bg-green-600 text-white'
                                        : (step === {{ $number }} ? 'bg-ink-950 text-white' : 'bg-control text-ink-500')">
                                <template x-if="step > {{ $number }}"><x-icon name="check" class="h-3 w-3" stroke="2.5" /></template>
                                <template x-if="step <= {{ $number }}"><span>{{ $number }}</span></template>
                            </span>
                            <span class="text-sm transition" :class="step === {{ $number }} ? 'font-medium text-ink-950' : 'text-ink-500'">{{ $label }}</span>
                        </li>
                    @endforeach
                </ol>
            </x-slot>

            <x-slot name="actions">
                <div class="flex items-center gap-4 pr-1 text-right">
                    <div>
                        <p class="text-base font-semibold leading-tight text-ink-950" x-text="completed.length"></p>
                        <p class="text-[11px] text-ink-500">paket</p>
                    </div>
                    <div>
                        <p class="text-base font-semibold leading-tight text-green-700" x-text="sessionUnits"></p>
                        <p class="text-[11px] text-ink-500">unit discan</p>
                    </div>
                </div>
                <x-ui.button :href="route('admin.outbounds.handover')" variant="secondary" icon="outbound">
                    Serah Terima Paket
                    <span class="rounded-full bg-white px-1.5 text-[11px] font-medium tabular-nums text-ink-700">{{ $handoverCount }}</span>
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {{-- ─────────────── Panel scan: menempel, tinggi tetap ─────────────── --}}
            <div class="lg:col-span-5">
                <div class="space-y-3 lg:sticky lg:top-[7.5rem]">
                    <div class="frappe-card flex h-auto flex-col overflow-hidden transition-colors lg:h-[20rem]"
                         :class="stage === 'done' ? 'border-green-500 ring-1 ring-green-500' : ''">

                        <div class="px-4 pt-4 sm:px-5">
                            <p class="flex h-5 items-center gap-2 text-xs text-ink-500">
                                <span x-text="'Langkah ' + step + ' dari 3'"></span>
                                <span x-show="outbound" x-cloak class="truncate font-mono text-ink-400"
                                      x-text="outbound?.code"></span>
                            </p>

                            <h2 class="mt-1 line-clamp-1 text-lg font-semibold sm:text-xl"
                                :class="stage === 'done' ? 'text-green-700' : 'text-ink-950'"
                                x-text="title"></h2>

                            <p class="mt-0.5 h-8 overflow-hidden text-xs leading-4 text-ink-500" x-text="hint"></p>
                        </div>

                        <div class="flex items-stretch gap-2 px-4 pt-3 sm:px-5">
                            @include('admin.partials.camera-scan', [
                                // Tahap "paket lengkap" ikut dihitung sebagai langkah 2:
                                // bagi operator yang penting hanya resi versus barang.
                                'scanStep' => 'Math.min(2, step)',
                                'scanTitle' => "isResiStage
                                    ? 'Scan label resi'
                                    : (progress && progress.ready ? 'Paket lengkap' : 'Scan barcode barang')",
                                'scanHint' => "isResiStage
                                    ? 'Arahkan ke label resi pada paket.'
                                    : (progress && progress.ready
                                        ? 'Masuk antrean serah terima. Arahkan ke resi berikutnya.'
                                        : 'Sisa ' + remaining + ' unit lagi pada paket ini.')",
                                'scanLines' => 'isResiStage ? [] : lines',
                            ])

                            {{--
                                Pengali jumlah. Hanya muncul saat memindai barang:
                                pada tahap resi angkanya tidak punya arti.
                            --}}
                            <label x-show="isItemStage" x-cloak
                                   class="flex shrink-0 items-center gap-1 self-stretch rounded-lg bg-control px-2"
                                   data-hint-title="Pengali scan" data-hint="Jumlah yang ditambahkan sekali scan. Berguna untuk barang yang dikirim banyak sekaligus." data-hint-next="Kembalikan ke 1 setelah selesai supaya scan berikutnya tidak terhitung ganda.">
                                <span class="text-sm text-ink-500">&times;</span>
                                <input type="number" min="1" max="9999" inputmode="numeric" x-model.number="bulk"
                                       class="w-10 border-0 bg-transparent p-0 text-center text-base font-semibold text-ink-950 focus:bg-transparent focus:shadow-none focus:ring-0">
                            </label>

                            <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-500">
                                        <x-icon name="search" class="h-[18px] w-[18px]" />
                                    </span>

                                    <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                           spellcheck="false" :disabled="stage === 'done'"
                                           :placeholder="placeholder"
                                           class="block h-12 w-full rounded-lg pl-10 pr-24 font-mono text-base text-ink-950 placeholder:font-sans placeholder:text-sm disabled:opacity-50">

                                    <div class="absolute inset-y-0 right-0 flex items-center pr-1.5">
                                        <button type="submit" :disabled="stage === 'done' || ! code.trim()"
                                                class="btn btn-primary h-9 px-3.5 sm:h-9">
                                            <span x-show="! busy" x-text="actionLabel"></span>
                                            <span x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        {{-- Ruang umpan balik: selalu ada, hanya isinya berganti --}}
                        <div class="mt-3 flex flex-1 items-start px-4 pb-4 sm:px-5">
                            <div class="flex min-h-[3.25rem] w-full items-center gap-2.5 rounded-lg px-3 py-2 transition"
                                 :class="feedback
                                    ? (feedback.type === 'success' ? 'bg-green-100' : 'bg-red-100')
                                    : 'bg-ink-50'">
                                <template x-if="feedback && feedback.type === 'success'">
                                    <x-icon name="check-circle" class="h-4 w-4 shrink-0 text-green-700" />
                                </template>
                                <template x-if="feedback && feedback.type === 'error'">
                                    <x-icon name="x-circle" class="h-4 w-4 shrink-0 text-red-600" />
                                </template>

                                <p class="overflow-hidden text-sm leading-5"
                                   :class="feedback
                                        ? (feedback.type === 'success' ? 'text-green-800' : 'text-red-700')
                                        : 'text-ink-400'"
                                   x-text="feedback ? feedback.message : 'Hasil scan muncul di sini.'"></p>
                            </div>
                        </div>
                    </div>

                    {{-- Identitas paket: tinggi tetap, kosong saat menunggu --}}
                    <div class="frappe-card flex h-16 items-center gap-3 px-4">
                        <template x-if="outbound">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700">
                                    <x-icon name="outbound" class="h-4 w-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950" x-text="outbound.tracking_number"></p>
                                    <p class="truncate text-[11px] text-ink-400"
                                       x-text="[outbound.marketplace, outbound.recipient, outbound.order_number].filter(Boolean).join(' · ')"></p>
                                </div>
                            </div>
                        </template>

                        <template x-if="! outbound">
                            <p class="flex items-center gap-2 text-xs text-ink-400">
                                <x-icon name="info" class="h-4 w-4 shrink-0 text-ink-300" />
                                Paket yang sedang dikerjakan tampil di sini.
                            </p>
                        </template>

                        <button type="button" x-on:click="reset()" x-show="outbound" x-cloak
                                title="Lepas paket ini"
                                class="btn btn-ghost btn-icon shrink-0 text-ink-500">
                            <x-icon name="close" class="h-4 w-4" />
                        </button>
                    </div>

                    <label class="flex cursor-pointer items-center gap-2 px-1 text-sm text-ink-600">
                        <input type="checkbox" x-model="autoContinue">
                        Lanjut otomatis ke resi berikutnya
                        <x-ui.hint text="Bila dicentang, setelah satu paket lengkap discan, kolom scan langsung siap untuk resi berikutnya tanpa perlu klik apa pun." />
                    </label>
                </div>
            </div>

            {{-- ─────────────── Daftar barang: tinggi tetap, gulir sendiri ─────────────── --}}
            <div class="lg:col-span-7">
                <div class="frappe-card flex max-h-[70vh] flex-col overflow-hidden lg:h-[32rem] lg:max-h-none">

                    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-ink-100 px-4 py-3 sm:px-5">
                        <div class="min-w-0">
                            <p class="text-base font-semibold text-ink-950">Barang yang Harus Discan</p>
                            <p class="truncate text-xs text-ink-500"
                               x-text="progress
                                    ? `${items.length} SKU · ${progress.scanned}/${totalUnits} unit discan`
                                    : 'Menunggu resi discan'"></p>
                        </div>

                        <span class="indicator-pill no-dot bg-control text-ink-700"
                              x-show="progress" x-cloak x-text="progress ? `${progress.percentage}%` : ''"></span>
                    </div>

                    {{--
                        Paket yang sedang dirakit.

                        Daftar di bawah berisi barang lepas hasil pemecahan,
                        dan tanpa baris ini jumlahnya tidak cocok dengan apa
                        pun yang tertulis di label: operator memegang satu
                        label bertulis "Paket Servis 10K ×2" sementara layar
                        meminta tujuh barang yang seolah tak berhubungan.
                    --}}
                    <template x-if="bundles.length">
                        <div class="shrink-0 border-b border-blue-200 bg-blue-50 px-5 py-2.5 text-blue-800">
                            <p class="text-xs text-blue-700">Isi paket yang dirakit</p>
                            <ul class="mt-1.5 space-y-1">
                                <template x-for="bundle in bundles" :key="bundle.sku">
                                    <li class="flex items-center justify-between gap-3 text-xs">
                                        <span class="min-w-0 truncate">
                                            <span class="font-mono text-blue-700" x-text="bundle.sku"></span>
                                            <span class="text-blue-400">·</span>
                                            <span x-text="bundle.name"></span>
                                        </span>
                                        <span class="shrink-0 font-semibold" x-text="`×${bundle.quantity}`"></span>
                                    </li>
                                </template>
                            </ul>
                            <p class="mt-1.5 text-[11px] leading-relaxed text-blue-700">
                                Yang discan tetap barangnya satu per satu — paketnya sendiri tidak punya barcode.
                            </p>
                        </div>
                    </template>

                    {{--
                        Stok kurang diberitahukan begitu resi discan, bukan saat
                        paket hendak diproses. Scan tetap boleh jalan; yang
                        dihindari adalah operator baru tahu setelah selesai.
                    --}}
                    <template x-if="shortages.length">
                        <div class="shrink-0 border-b border-red-200 bg-red-50 px-5 py-2.5">
                            <p class="flex items-start gap-2 text-[11px] leading-relaxed text-red-800">
                                <x-icon name="x-circle" class="mt-px h-3.5 w-3.5 shrink-0 text-red-500" />
                                <span>
                                    <span class="font-semibold">Stok tidak mencukupi:</span>
                                    <span class="font-mono" x-text="shortages.map((line) => line.sku).join(', ')"></span>.
                                    Barang ini tidak bisa discan sampai stoknya dicatat lewat barang masuk.
                                </span>
                            </p>
                        </div>
                    </template>

                    {{-- Satu-satunya bagian yang menggulir --}}
                    <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                        <template x-if="! progress">
                            <div class="flex h-full flex-col items-center justify-center px-6 py-16 text-center">
                                <x-icon name="box" class="h-10 w-10 text-ink-300" stroke="1.25" />
                                <p class="mt-3 text-sm font-medium text-ink-950">Belum ada paket aktif</p>
                                <p class="mt-1 max-w-xs text-xs leading-relaxed text-ink-500">
                                    Scan resi pada panel di samping. Daftar barangnya diambil otomatis dari data import.
                                </p>
                            </div>
                        </template>

                        <template x-if="progress">
                            <ul class="divide-y divide-ink-100">
                                <template x-for="line in lines" :key="line.id">
                                    <li class="px-4 py-3 transition sm:px-5"
                                        :class="line.completed ? 'bg-green-50' : (line.short && 'bg-red-50')">
                                        <div class="flex items-start gap-3">
                                            <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full transition"
                                                  :class="line.completed ? 'bg-green-600 text-white'
                                                        : (line.short ? 'bg-red-100 text-red-600' : 'bg-control text-ink-400')">
                                                <template x-if="line.completed"><x-icon name="check" class="h-3 w-3" /></template>
                                            </span>

                                            <div class="min-w-0 flex-1">
                                                <span class="inline-flex max-w-full items-center gap-1 rounded-md bg-control px-1.5 py-px font-mono text-[11px] leading-4 text-ink-700">
                                                    <span class="opacity-60">SKU</span>
                                                    <span class="truncate" x-text="line.sku"></span>
                                                </span>
                                                <p class="mt-0.5 truncate text-sm font-medium text-ink-950" x-text="line.name"></p>

                                                <p class="flex flex-wrap items-center gap-x-1.5 text-[11px]">
                                                    <span class="truncate font-mono text-ink-400"
                                                          x-text="line.barcode ? `Barcode ${line.barcode}` : 'Tanpa barcode — scan pakai SKU'"></span>
                                                    <span class="text-ink-200">&middot;</span>
                                                    <span :class="line.short ? 'font-semibold text-red-600' : 'text-ink-400'"
                                                          x-text="line.short
                                                                ? `stok ${line.stock} ${line.unit}, kurang ${line.quantity - line.stock}`
                                                                : `stok ${line.stock} ${line.unit}`"></span>
                                                </p>

                                                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-control">
                                                    <div class="h-full rounded-full transition-all duration-300"
                                                         :class="line.completed ? 'bg-green-500' : 'bg-blue-500'"
                                                         :style="`width: ${Math.min(100, line.scanned / line.quantity * 100)}%`"></div>
                                                </div>
                                            </div>

                                            <div class="flex shrink-0 flex-col items-end gap-1.5">
                                                <p class="text-sm font-semibold text-ink-950">
                                                    <span x-text="line.scanned"></span><span class="text-ink-300" x-text="`/${line.quantity}`"></span>
                                                </p>
                                                <p class="text-[11px]" :class="line.completed ? 'text-green-700' : 'text-ink-500'"
                                                   x-text="line.completed ? 'selesai' : `sisa ${line.quantity - line.scanned}`"></p>

                                                {{--
                                                    Satu ketuk menuntaskan sisa baris — untuk barang yang
                                                    datang dalam dus tersegel dan dihitung dari labelnya.
                                                --}}
                                                <button type="button" x-show="! line.completed && ! line.short" x-cloak
                                                        x-on:click="fillLine(line)" :disabled="busy"
                                                        class="btn btn-default btn-xs"
                                                        x-text="`Tandai ${line.quantity - line.scanned}`"></button>
                                            </div>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </template>
                    </div>

                    {{-- Kaki kartu: aksi utama, posisinya tidak pernah berubah --}}
                    <div class="shrink-0 border-t border-ink-100 bg-white px-4 py-3">
                        <div class="mb-2 flex h-5 items-center justify-between gap-3 text-xs">
                            <span x-show="progress" x-cloak
                                  :class="shortages.length ? 'font-medium text-red-600' : 'text-ink-500'"
                                  x-text="shortages.length
                                        ? `${shortages.length} barang stoknya kurang`
                                        : (progress?.ready ? 'Lengkap — masuk antrean serah terima' : `Sisa ${remaining} unit`)"></span>
                        </div>

                        {{--
                            Tidak ada tombol proses di sini: stasiun ini hanya
                            memverifikasi isi paket. Tombolnya cuma untuk
                            melepas paket lebih awal bila perlu.
                        --}}
                        <button type="button" x-on:click="reset()"
                                :disabled="busy || ! outbound"
                                class="btn btn-lg w-full disabled:cursor-not-allowed"
                                :class="stage === 'done' ? 'bg-green-600 text-white hover:bg-green-700' : 'btn-default'">
                            <x-icon name="arrow-right" class="h-4 w-4" />
                            <span x-text="stage === 'done' ? 'Lanjut ke Resi Berikutnya' : 'Lewati Paket Ini'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ─────────────── Riwayat sesi: tinggi tetap, gulir sendiri ─────────────── --}}
        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="frappe-card flex h-56 flex-col overflow-hidden">
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-ink-100 px-4 py-2.5">
                    <p class="text-sm font-semibold text-ink-950">Selesai Sesi Ini</p>
                    <a href="{{ route('admin.outbounds.handover') }}"
                       class="text-xs text-ink-500 underline-offset-4 hover:text-ink-950 hover:underline">
                        Lihat antrean serah terima
                    </a>
                </div>
                <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                    <template x-if="! completed.length">
                        <p class="px-5 py-10 text-center text-xs text-ink-400">Belum ada paket yang selesai discan.</p>
                    </template>

                    <ul class="divide-y divide-ink-100">
                        <template x-for="entry in completed" :key="entry.id">
                            <li class="flex items-center gap-3 px-4 py-2.5">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700">
                                    <x-icon name="check" class="h-3 w-3" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950" x-text="entry.code"></p>
                                    <p class="truncate font-mono text-[11px] text-ink-400" x-text="entry.tracking_number"></p>
                                </div>
                                <span class="shrink-0 text-[11px] font-semibold text-green-700" x-text="`${entry.units} unit`"></span>
                                <span class="shrink-0 font-mono text-[11px] text-ink-400" x-text="entry.at"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <div class="frappe-card flex h-56 flex-col overflow-hidden">
                <div class="shrink-0 border-b border-ink-100 px-4 py-2.5">
                    <p class="text-sm font-semibold text-ink-950">Scan Terakhir</p>
                </div>
                <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                    <template x-if="! history.length">
                        <p class="px-5 py-10 text-center text-xs text-ink-400">Riwayat scan muncul di sini.</p>
                    </template>

                    <ul class="divide-y divide-ink-100">
                        <template x-for="entry in history" :key="entry.id">
                            <li class="flex items-center gap-3 px-4 py-2.5">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                      :class="entry.type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                                    <template x-if="entry.type === 'success'"><x-icon name="check" class="h-3 w-3" /></template>
                                    <template x-if="entry.type === 'error'"><x-icon name="close" class="h-3 w-3" /></template>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs text-ink-700" x-text="entry.message"></p>
                                    <p class="truncate font-mono text-[11px] text-ink-400" x-text="entry.code"></p>
                                </div>
                                <span class="shrink-0 font-mono text-[11px] text-ink-400" x-text="entry.at"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Pesanan yang sempat tertunda --}}
        @if ($pending->isNotEmpty())
            <div class="frappe-card mt-4 overflow-hidden">
                <div class="border-b border-ink-100 px-4 py-2.5">
                    <p class="text-sm font-semibold text-ink-950">Belum Selesai Discan</p>
                </div>
                <ul class="divide-y divide-ink-100">
                    @foreach ($pending as $draft)
                        <li>
                            <a href="{{ route('admin.outbounds.scan', $draft) }}"
                               class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-ink-50">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950">{{ $draft->tracking_number }}</p>
                                    <p class="truncate text-[11px] text-ink-400">{{ $draft->code }} &middot; {{ $draft->recipient }}</p>
                                </div>
                                <x-ui.badge :variant="$draft->isResiVerified() ? 'warning' : 'neutral'">
                                    {{ $draft->isResiVerified() ? 'Scan barang' : 'Perlu scan resi' }}
                                </x-ui.badge>
                                <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-ink-300" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mt-5 text-center text-xs text-ink-500">
            <a href="{{ route('admin.outbounds.index') }}" class="text-ink-700 underline-offset-4 hover:underline">
                Daftar barang keluar
            </a>
            &middot;
            <a href="{{ route('admin.outbounds.create', ['type' => 'marketplace']) }}" class="text-ink-700 underline-offset-4 hover:underline">
                Buat dokumen manual
            </a>
        </p>
    </div>
</x-app-layout>
